<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

function hb_api_planned_payment_row(PDO $pdo, int $householdId, int $id): array {
    $stmt = $pdo->prepare(
        "select id, name, direction, amount_cents, planned_date, status, priority, is_optional,
                account_id, category_id, payee_id, note, resolved_at, created_at, updated_at
           from planned_payments
          where household_id = :hid and id = :id"
    );
    $stmt->execute(['hid' => $householdId, 'id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        hb_api_json(['error' => 'Planned payment not found'], 404);
    }
    return hb_api_format_planned_payment($row);
}

function hb_api_planned_payment_status(?string $status, string $field = 'status'): string
{
    $value = trim((string)$status);
    $allowed = ['open', 'done', 'resolved', 'skipped', 'overdue', 'suggested', 'cancelled'];
    if (!in_array($value, $allowed, true)) {
        hb_api_json(['error' => $field . ' is invalid'], 400);
    }
    return $value;
}

try {

if ($method === 'GET') {
    hb_api_require_scope($auth, 'planned:read');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id !== null) {
        hb_api_json(['planned_payment' => hb_api_planned_payment_row($pdo, $householdId, $id)]);
    }

    $where = ['household_id = :hid'];
    $params = ['hid' => $householdId];

    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '') {
        $where[] = 'status = :status';
        $params['status'] = hb_api_planned_payment_status($status);
    }

    $from = hb_api_date($_GET['date_from'] ?? null, 'date_from');
    $to = hb_api_date($_GET['date_to'] ?? null, 'date_to');
    if ($from !== null) {
        $where[] = 'planned_date >= :date_from';
        $params['date_from'] = $from;
    }
    if ($to !== null) {
        $where[] = 'planned_date <= :date_to';
        $params['date_to'] = $to;
    }

    foreach (['account_id', 'category_id', 'payee_id'] as $field) {
        $value = hb_api_int_or_null($_GET[$field] ?? null);
        if ($value !== null) {
            $where[] = $field . ' = :' . $field;
            $params[$field] = $value;
        }
    }

    $direction = trim((string)($_GET['direction'] ?? ''));
    if ($direction !== '') {
        if (!in_array($direction, ['income', 'expense'], true)) {
            hb_api_json(['error' => 'direction is invalid'], 400);
        }
        $where[] = 'direction = :direction';
        $params['direction'] = $direction;
    }

    $limit = hb_api_limit($_GET['limit'] ?? null);
    $offset = hb_api_offset($_GET['offset'] ?? null);
    $sqlWhere = implode(' and ', $where);

    $countStmt = $pdo->prepare("select count(*) from planned_payments where $sqlWhere");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "select id, name, direction, amount_cents, planned_date, status, priority, is_optional,
                account_id, category_id, payee_id, note, resolved_at, created_at, updated_at
           from planned_payments
          where $sqlWhere
          order by planned_date asc, id asc
          limit :limit offset :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $planned = array_map(static fn($p) => hb_api_format_planned_payment($p), $rows);

    hb_api_json([
        'planned_payments' => $planned,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
    ]);
}

if ($method === 'POST') {
    hb_api_require_scope($auth, 'planned:write');

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
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        hb_api_json(['error' => 'name is required'], 400);
    }
    $direction = (string)($data['direction'] ?? 'expense');
    if (!in_array($direction, ['income', 'expense'], true)) {
        hb_api_json(['error' => 'direction is invalid'], 400);
    }
    $plannedDate = hb_api_date((string)($data['planned_date'] ?? $data['date'] ?? ''), 'planned_date', true);
    $amountCents = hb_api_amount_cents($data['amount'] ?? null);
    $status = hb_api_planned_payment_status((string)($data['status'] ?? 'open'));
    $priority = hb_api_int_or_null($data['priority'] ?? 3);
    if ($priority === null || $priority < 1 || $priority > 99) {
        hb_api_json(['error' => 'priority is invalid'], 400);
    }
    $accountId = hb_api_int_or_null($data['account_id'] ?? null);
    if ($accountId === null) {
        hb_api_json(['error' => 'account_id is required'], 400);
    }
    hb_api_assert_account($pdo, $householdId, $accountId);
    $categoryId = hb_api_int_or_null($data['category_id'] ?? null);
    hb_api_assert_category($pdo, $householdId, $categoryId);
    $payeeId = hb_api_payee_id($pdo, $householdId, $data['payee_id'] ?? null, $data['payee'] ?? null);
    $note = trim((string)($data['note'] ?? $data['notes'] ?? ''));
    $isOptional = array_key_exists('is_optional', $data) ? hb_api_bool($data['is_optional']) : false;
    $resolvedAt = $status === 'done' ? date('Y-m-d H:i:s') : null;

    $ins = $pdo->prepare(
        "insert into planned_payments
            (household_id, account_id, category_id, payee_id, direction, name, note, amount_cents, planned_date, status, priority, is_optional, resolved_at)
         values
            (:hid, :account_id, :category_id, :payee_id, :direction, :name, :note, :amount_cents, :planned_date, :status, :priority, :is_optional, :resolved_at)
         returning id"
    );
    $ins->execute([
        'hid' => $householdId,
        'account_id' => $accountId,
        'category_id' => $categoryId,
        'payee_id' => $payeeId,
        'direction' => $direction,
        'name' => $name,
        'note' => $note !== '' ? $note : null,
        'amount_cents' => $amountCents,
        'planned_date' => $plannedDate,
        'status' => $status,
        'priority' => $priority,
        'is_optional' => $isOptional ? 'true' : 'false',
        'resolved_at' => $resolvedAt,
    ]);
    $id = (int)$ins->fetchColumn();
    $responseData = ['planned_payment' => hb_api_planned_payment_row($pdo, $householdId, $id)];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 201);
    }
    hb_api_json($responseData, 201);
}

if ($method === 'PATCH') {
    hb_api_require_scope($auth, 'planned:write');

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

    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }
    hb_api_planned_payment_row($pdo, $householdId, $id);
    $data = hb_api_read_json();
    $sets = [];
    $params = ['hid' => $householdId, 'id' => $id];

    if (array_key_exists('name', $data)) {
        $name = trim((string)$data['name']);
        if ($name === '') {
            hb_api_json(['error' => 'name is required'], 400);
        }
        $sets[] = 'name = :name';
        $params['name'] = $name;
    }
    if (array_key_exists('direction', $data)) {
        $direction = (string)$data['direction'];
        if (!in_array($direction, ['income', 'expense'], true)) {
            hb_api_json(['error' => 'direction is invalid'], 400);
        }
        $sets[] = 'direction = :direction';
        $params['direction'] = $direction;
    }
    if (array_key_exists('planned_date', $data) || array_key_exists('date', $data)) {
        $sets[] = 'planned_date = :planned_date';
        $params['planned_date'] = hb_api_date((string)($data['planned_date'] ?? $data['date']), 'planned_date', true);
    }
    if (array_key_exists('amount', $data)) {
        $sets[] = 'amount_cents = :amount_cents';
        $params['amount_cents'] = hb_api_amount_cents($data['amount']);
    }
    if (array_key_exists('status', $data)) {
        $status = hb_api_planned_payment_status((string)$data['status']);
        $sets[] = 'status = :status';
        $params['status'] = $status;
        $sets[] = 'resolved_at = :resolved_at';
        $params['resolved_at'] = in_array($status, ['done', 'resolved'], true) ? date('Y-m-d H:i:s') : null;
    }
    if (array_key_exists('priority', $data)) {
        $priority = hb_api_int_or_null($data['priority']);
        if ($priority === null || $priority < 1 || $priority > 99) {
            hb_api_json(['error' => 'priority is invalid'], 400);
        }
        $sets[] = 'priority = :priority';
        $params['priority'] = $priority;
    }
    if (array_key_exists('is_optional', $data)) {
        $sets[] = 'is_optional = :is_optional';
        $params['is_optional'] = hb_api_bool($data['is_optional']) ? 'true' : 'false';
    }
    if (array_key_exists('account_id', $data)) {
        $accountId = hb_api_int_or_null($data['account_id']);
        if ($accountId === null) {
            hb_api_json(['error' => 'account_id is required'], 400);
        }
        hb_api_assert_account($pdo, $householdId, $accountId);
        $sets[] = 'account_id = :account_id';
        $params['account_id'] = $accountId;
    }
    if (array_key_exists('category_id', $data)) {
        $categoryId = hb_api_int_or_null($data['category_id']);
        hb_api_assert_category($pdo, $householdId, $categoryId);
        $sets[] = 'category_id = :category_id';
        $params['category_id'] = $categoryId;
    }
    if (array_key_exists('payee_id', $data) || array_key_exists('payee', $data)) {
        $sets[] = 'payee_id = :payee_id';
        $params['payee_id'] = hb_api_payee_id($pdo, $householdId, $data['payee_id'] ?? null, $data['payee'] ?? null);
    }
    if (array_key_exists('note', $data) || array_key_exists('notes', $data)) {
        $sets[] = 'note = :note';
        $note = trim((string)($data['note'] ?? $data['notes'] ?? ''));
        $params['note'] = $note !== '' ? $note : null;
    }
    if ($sets) {
        $sql = 'update planned_payments set ' . implode(', ', $sets) . ', updated_at = now() where household_id = :hid and id = :id';
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
    }
    $responseData = ['planned_payment' => hb_api_planned_payment_row($pdo, $householdId, $id)];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 200);
    }
    hb_api_json($responseData);
}

if ($method === 'DELETE') {
    hb_api_require_scope($auth, 'planned:write');

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

    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }
    $stmt = $pdo->prepare('delete from planned_payments where household_id = :hid and id = :id');
    $stmt->execute(['hid' => $householdId, 'id' => $id]);
    $responseData = ['deleted' => $stmt->rowCount() > 0];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 200);
    }
    hb_api_json($responseData);
}

hb_api_json(['error' => 'Method not allowed'], 405);
} catch (PDOException $e) {
    error_log('API Error (planned_payments.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
} catch (Exception $e) {
    error_log('API Error (planned_payments.php): ' . $e->getMessage());
    hb_api_json(['error' => 'An error occurred. Please try again.'], 500);
}
