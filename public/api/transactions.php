<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    hb_api_require_scope($auth, 'transactions:read');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id !== null) {
        hb_api_json(['transaction' => hb_api_transaction_row($pdo, $householdId, $id)]);
    }

    $where = ['t.household_id = :hid'];
    $params = ['hid' => $householdId];

    $from = hb_api_date($_GET['date_from'] ?? null, 'date_from');
    $to = hb_api_date($_GET['date_to'] ?? null, 'date_to');
    if ($from !== null) {
        $where[] = 't.booking_date >= :date_from';
        $params['date_from'] = $from;
    }
    if ($to !== null) {
        $where[] = 't.booking_date <= :date_to';
        $params['date_to'] = $to;
    }
    $type = (string)($_GET['type'] ?? '');
    if ($type !== '') {
        if (!in_array($type, ['income', 'expense', 'transfer'], true)) {
            hb_api_json(['error' => 'type is invalid'], 400);
        }
        $where[] = 't.type = :type';
        $params['type'] = $type;
    }
    $reviewed = (string)($_GET['reviewed'] ?? '');
    if ($reviewed !== '') {
        $where[] = 't.is_reviewed = :reviewed';
        $params['reviewed'] = hb_api_bool($reviewed) ? '1' : '0';
    }
    foreach (['account_id', 'category_id', 'payee_id'] as $field) {
        $value = hb_api_int_or_null($_GET[$field] ?? null);
        if ($value !== null) {
            $where[] = 't.' . $field . ' = :' . $field;
            $params[$field] = $value;
        }
    }
    $tagId = hb_api_int_or_null($_GET['tag_id'] ?? null);
    if ($tagId !== null) {
        hb_api_assert_tag($pdo, $householdId, $tagId);
        $where[] = 'exists (select 1 from transaction_tags tt where tt.transaction_id = t.id and tt.tag_id = :tag_id)';
        $params['tag_id'] = $tagId;
    }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(p.name ilike :q or t.counterparty_name ilike :q or t.note ilike :q or t.external_id ilike :q)';
        $params['q'] = '%' . $q . '%';
    }

    $limit = hb_api_limit($_GET['limit'] ?? null);
    $offset = hb_api_offset($_GET['offset'] ?? null);
    $sqlWhere = implode(' and ', $where);
    $countStmt = $pdo->prepare("select count(*) from transactions t left join payees p on p.id = t.payee_id where $sqlWhere");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "select t.id, t.type, t.booking_date, t.amount_cents, t.currency_code,
                t.account_id, a.name as account_name,
                t.category_id, c.name as category_name,
                t.payee_id, p.name as payee_name,
                t.counterparty_name, t.note, t.is_reviewed, t.external_id,
                t.import_hash, t.planned_payment_id, t.created_at, t.updated_at
           from transactions t
      left join accounts a on a.id = t.account_id
      left join categories c on c.id = t.category_id
      left join payees p on p.id = t.payee_id
          where $sqlWhere
          order by t.booking_date desc, t.id desc
          limit :limit offset :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $transactions = [];
    foreach ($rows as $row) {
        $row['tags'] = [];
        $transactions[] = hb_api_format_transaction($row);
    }
    hb_api_json([
        'transactions' => $transactions,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
    ]);
}

if ($method === 'POST') {
    hb_api_require_scope($auth, 'transactions:write');

    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $requestBody = file_get_contents('php://input');
    if ($idempotencyKey) {
        $requestHash = hash('sha256', $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $requestBody);
        $cached = hb_api_idempotency_check($pdo, $auth, $idempotencyKey, $requestHash);
        if ($cached) {
            http_response_code($cached['status_code']);
            header('Content-Type: application/json; charset=utf-8');
            echo $cached['response_body'];
            exit;
        }
    }

    $data = hb_api_read_json();
    $type = (string)($data['type'] ?? 'expense');
    if (!in_array($type, ['income', 'expense'], true)) {
        hb_api_json(['error' => 'type is invalid'], 400);
    }
    $date = hb_api_date((string)($data['date'] ?? ''), 'date', true);
    $amountCents = hb_api_amount_cents($data['amount'] ?? null);
    $accountId = hb_api_int_or_null($data['account_id'] ?? null);
    if ($accountId === null) {
        hb_api_json(['error' => 'account_id is required'], 400);
    }
    $categoryId = hb_api_int_or_null($data['category_id'] ?? null);
    hb_api_assert_account($pdo, $householdId, $accountId);
    hb_api_assert_category($pdo, $householdId, $categoryId);
    $payeeId = hb_api_payee_id($pdo, $householdId, $data['payee_id'] ?? null, $data['payee'] ?? null);
    $counterparty = trim((string)($data['counterparty_name'] ?? $data['payee'] ?? ''));

    $ins = $pdo->prepare(
        "insert into transactions
            (household_id, type, booking_date, amount_cents, currency_code, account_id, category_id, payee_id, counterparty_name, note, is_reviewed)
         values
            (:hid, :type, :d, :amount, 'EUR', :acc, :cat, :payee, :counterparty, :note, :reviewed)
         returning id"
    );
    $ins->execute([
        'hid' => $householdId,
        'type' => $type,
        'd' => $date,
        'amount' => $amountCents,
        'acc' => $accountId,
        'cat' => $categoryId,
        'payee' => $payeeId,
        'counterparty' => $counterparty !== '' ? $counterparty : null,
        'note' => (string)($data['notes'] ?? ''),
        'reviewed' => array_key_exists('is_reviewed', $data) ? hb_api_bool($data['is_reviewed']) : true,
    ]);
    $id = (int)$ins->fetchColumn();
    if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
        hb_api_set_transaction_tags($pdo, $householdId, $id, $data['tag_ids']);
    }
    $responseData = ['transaction' => hb_api_transaction_row($pdo, $householdId, $id)];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 201);
    }
    hb_api_json($responseData, 201);
}

if ($method === 'PATCH') {
    hb_api_require_scope($auth, 'transactions:write');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }
    hb_api_transaction_row($pdo, $householdId, $id);
    $data = hb_api_read_json();
    $sets = [];
    $params = ['hid' => $householdId, 'id' => $id];

    if (array_key_exists('type', $data)) {
        $type = (string)$data['type'];
        if (!in_array($type, ['income', 'expense'], true)) {
            hb_api_json(['error' => 'type is invalid'], 400);
        }
        $sets[] = 'type = :type';
        $params['type'] = $type;
    }
    if (array_key_exists('date', $data)) {
        $sets[] = 'booking_date = :date';
        $params['date'] = hb_api_date((string)$data['date'], 'date', true);
    }
    if (array_key_exists('amount', $data)) {
        $sets[] = 'amount_cents = :amount';
        $params['amount'] = hb_api_amount_cents($data['amount']);
    }
    foreach (['account_id' => 'account_id', 'category_id' => 'category_id'] as $input => $column) {
        if (array_key_exists($input, $data)) {
            $value = hb_api_int_or_null($data[$input]);
            if ($column === 'account_id') {
                hb_api_assert_account($pdo, $householdId, $value);
            } else {
                hb_api_assert_category($pdo, $householdId, $value);
            }
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }
    }
    if (array_key_exists('payee_id', $data) || array_key_exists('payee', $data)) {
        $sets[] = 'payee_id = :payee_id';
        $params['payee_id'] = hb_api_payee_id($pdo, $householdId, $data['payee_id'] ?? null, $data['payee'] ?? null);
    }
    if (array_key_exists('counterparty_name', $data)) {
        $sets[] = 'counterparty_name = :counterparty';
        $params['counterparty'] = trim((string)$data['counterparty_name']) ?: null;
    }
    if (array_key_exists('notes', $data)) {
        $sets[] = 'note = :note';
        $params['note'] = (string)$data['notes'];
    }
    if (array_key_exists('is_reviewed', $data)) {
        $sets[] = 'is_reviewed = :reviewed';
        $params['reviewed'] = hb_api_bool($data['is_reviewed']);
    }
    if ($sets) {
        $sql = 'update transactions set ' . implode(', ', $sets) . ', updated_at = now() where household_id = :hid and id = :id';
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
    }
    if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
        hb_api_set_transaction_tags($pdo, $householdId, $id, $data['tag_ids']);
    }
    hb_api_json(['transaction' => hb_api_transaction_row($pdo, $householdId, $id)]);
}

if ($method === 'DELETE') {
    hb_api_require_scope($auth, 'transactions:delete');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }
    $stmt = $pdo->prepare('delete from transactions where household_id = :hid and id = :id');
    $stmt->execute(['hid' => $householdId, 'id' => $id]);
    hb_api_json(['deleted' => $stmt->rowCount() > 0]);
}

hb_api_json(['error' => 'Method not allowed'], 405);
