<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

function hb_group_idempotency_start(PDO $pdo, array $auth): array
{
    $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $body = file_get_contents('php://input') ?: '';
    if (!$key) {
        return [null, $body, null];
    }
    $hash = hash('sha256', $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $body);
    $cached = hb_api_idempotency_check($pdo, $auth, $key, $hash);
    if ($cached) {
        hb_api_send_cached_idempotent($cached);
    }
    return [$key, $body, $hash];
}

function hb_group_validate_splits(PDO $pdo, int $householdId, array $splits, int $totalAmountCents): array
{
    if (!$splits) {
        hb_api_json(['error' => 'At least one split is required'], 400);
    }
    $normalized = [];
    $sum = 0;
    foreach (array_values($splits) as $idx => $split) {
        if (!is_array($split)) {
            hb_api_json(['error' => 'splits must contain objects'], 400);
        }
        $amountCents = hb_api_amount_cents($split['amount'] ?? null, 'splits.amount');
        if ($amountCents === null || $amountCents <= 0) {
            hb_api_json(['error' => 'splits.amount must be greater than zero'], 400);
        }
        $categoryId = hb_api_int_or_null($split['category_id'] ?? null);
        hb_api_assert_category($pdo, $householdId, $categoryId);
        $normalized[] = [
            'amount_cents' => $amountCents,
            'category_id' => $categoryId,
            'note' => trim((string)($split['note'] ?? '')) ?: null,
            'sort_order' => array_key_exists('sort_order', $split) ? max(0, (int)$split['sort_order']) : $idx,
        ];
        $sum += $amountCents;
    }
    if ($sum !== $totalAmountCents) {
        hb_api_error('split_total_mismatch', 'Sum of splits does not match group total', 400);
    }
    return $normalized;
}

function hb_group_insert_splits(PDO $pdo, int $householdId, int $groupId, array $splits): void
{
    $ins = $pdo->prepare(
        'insert into transaction_splits
            (household_id, transaction_group_id, transaction_id, amount_cents, category_id, note, sort_order)
         values
            (:hid, :gid, null, :amount, :category_id, :note, :sort_order)'
    );
    foreach ($splits as $split) {
        $ins->execute([
            'hid' => $householdId,
            'gid' => $groupId,
            'amount' => $split['amount_cents'],
            'category_id' => $split['category_id'],
            'note' => $split['note'],
            'sort_order' => $split['sort_order'],
        ]);
    }
}

function hb_group_create_header_transaction(PDO $pdo, int $householdId, int $groupId): ?int
{
    $stmt = $pdo->prepare(
        'select id, receipt_id, account_id, payee_id, payee, booking_date, total_amount_cents,
                currency_code, type, notes, matched_transaction_id
           from transaction_groups
          where household_id = :hid and id = :id'
    );
    $stmt->execute(['hid' => $householdId, 'id' => $groupId]);
    $group = $stmt->fetch();
    if (!$group) {
        hb_api_json(['error' => 'Transaction group not found'], 404);
    }
    if (!empty($group['matched_transaction_id'])) {
        return (int)$group['matched_transaction_id'];
    }
    if (empty($group['account_id'])) {
        hb_api_json(['error' => 'account_id is required when booking a transaction group'], 400);
    }
    $ins = $pdo->prepare(
        'insert into transactions
            (household_id, type, booking_date, amount_cents, currency_code, account_id, category_id, payee_id, note, is_reviewed, counterparty_name, receipt_id, split_group_id)
         values
            (:hid, :type, :booking_date, :amount, :currency, :account_id, null, :payee_id, :note, false, :counterparty, :receipt_id, :split_group_id)
         returning id'
    );
    $ins->execute([
        'hid' => $householdId,
        'type' => (string)$group['type'],
        'booking_date' => (string)$group['booking_date'],
        'amount' => (int)$group['total_amount_cents'],
        'currency' => (string)$group['currency_code'],
        'account_id' => (int)$group['account_id'],
        'payee_id' => $group['payee_id'] !== null ? (int)$group['payee_id'] : null,
        'note' => $group['notes'] !== null ? (string)$group['notes'] : null,
        'counterparty' => $group['payee'] !== null ? (string)$group['payee'] : null,
        'receipt_id' => $group['receipt_id'] !== null ? (int)$group['receipt_id'] : null,
        'split_group_id' => $groupId,
    ]);
    $transactionId = (int)$ins->fetchColumn();
    $pdo->prepare(
        'update transaction_splits
            set transaction_id = :transaction_id,
                updated_at = now()
          where household_id = :hid
            and transaction_group_id = :group_id
            and transaction_id is null'
    )->execute(['transaction_id' => $transactionId, 'hid' => $householdId, 'group_id' => $groupId]);
    $pdo->prepare(
        'update transaction_groups
            set matched_transaction_id = :transaction_id,
                updated_at = now()
          where household_id = :hid and id = :group_id'
    )->execute(['transaction_id' => $transactionId, 'hid' => $householdId, 'group_id' => $groupId]);
    return $transactionId;
}

if ($method === 'GET') {
    hb_api_require_scope($auth, 'transactions:read');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id !== null) {
        hb_api_json(['transaction_group' => hb_api_transaction_group_row($pdo, $householdId, $id)]);
    }

    $where = ['tg.household_id = :hid'];
    $params = ['hid' => $householdId];
    foreach (['receipt_id', 'account_id', 'payee_id'] as $field) {
        $value = hb_api_int_or_null($_GET[$field] ?? null);
        if ($value !== null) {
            $where[] = 'tg.' . $field . ' = :' . $field;
            $params[$field] = $value;
        }
    }
    $from = hb_api_date($_GET['date_from'] ?? null, 'date_from');
    $to = hb_api_date($_GET['date_to'] ?? null, 'date_to');
    if ($from !== null) {
        $where[] = 'tg.booking_date >= :date_from';
        $params['date_from'] = $from;
    }
    if ($to !== null) {
        $where[] = 'tg.booking_date <= :date_to';
        $params['date_to'] = $to;
    }
    $limit = hb_api_limit($_GET['limit'] ?? null);
    $offset = hb_api_offset($_GET['offset'] ?? null);
    $sqlWhere = implode(' and ', $where);
    $countStmt = $pdo->prepare("select count(*) from transaction_groups tg where $sqlWhere");
    $countStmt->execute($params);
    $stmt = $pdo->prepare(
        "select tg.id
           from transaction_groups tg
          where $sqlWhere
          order by tg.booking_date desc, tg.id desc
          limit :limit offset :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $groupId) {
        $groups[] = hb_api_transaction_group_row($pdo, $householdId, (int)$groupId);
    }
    hb_api_json([
        'transaction_groups' => $groups,
        'limit' => $limit,
        'offset' => $offset,
        'total' => (int)$countStmt->fetchColumn(),
    ]);
}

if ($method === 'POST') {
    hb_api_require_scope($auth, 'transactions:write');
    [$idempotencyKey, $requestBody, $requestHash] = hb_group_idempotency_start($pdo, $auth);
    $data = json_decode($requestBody, true);
    if (!is_array($data)) {
        hb_api_json(['error' => 'Invalid JSON'], 400);
    }

    $receiptId = hb_api_int_or_null($data['receipt_id'] ?? null);
    $accountId = hb_api_int_or_null($data['account_id'] ?? null);
    $payeeId = hb_api_int_or_null($data['payee_id'] ?? null);
    hb_api_assert_receipt($pdo, $householdId, $receiptId);
    hb_api_assert_account($pdo, $householdId, $accountId);
    $payeeId = hb_api_payee_id($pdo, $householdId, $payeeId, $data['payee'] ?? null);
    $bookingDate = hb_api_date((string)($data['booking_date'] ?? $data['date'] ?? ''), 'booking_date', true);
    $totalAmountCents = hb_api_amount_cents($data['total_amount'] ?? $data['amount'] ?? null, 'total_amount');
    if ($totalAmountCents === null || $totalAmountCents <= 0) {
        hb_api_json(['error' => 'total_amount must be greater than zero'], 400);
    }
    $type = (string)($data['type'] ?? 'expense');
    if (!in_array($type, ['expense', 'income'], true)) {
        hb_api_json(['error' => 'type is invalid'], 400);
    }
    $currencyCode = strtoupper(trim((string)($data['currency_code'] ?? 'EUR')));
    if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
        hb_api_json(['error' => 'currency_code is invalid'], 400);
    }
    $status = (string)($data['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'booked'], true)) {
        hb_api_json(['error' => 'status is invalid'], 400);
    }
    $splits = hb_group_validate_splits($pdo, $householdId, $data['splits'] ?? [], $totalAmountCents);

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'insert into transaction_groups
                (household_id, receipt_id, account_id, payee_id, payee, booking_date, total_amount_cents, currency_code, type, notes, status)
             values
                (:hid, :receipt_id, :account_id, :payee_id, :payee, :booking_date, :total_amount, :currency, :type, :notes, :status)
             returning id'
        );
        $ins->execute([
            'hid' => $householdId,
            'receipt_id' => $receiptId,
            'account_id' => $accountId,
            'payee_id' => $payeeId,
            'payee' => trim((string)($data['payee'] ?? '')) ?: null,
            'booking_date' => $bookingDate,
            'total_amount' => $totalAmountCents,
            'currency' => $currencyCode,
            'type' => $type,
            'notes' => trim((string)($data['notes'] ?? '')) ?: null,
            'status' => $status,
        ]);
        $groupId = (int)$ins->fetchColumn();
        hb_group_insert_splits($pdo, $householdId, $groupId, $splits);
        if ($receiptId !== null && $status === 'booked') {
            $pdo->prepare('update receipts set status = :status, updated_at = now() where household_id = :hid and id = :id')
                ->execute(['status' => 'matched', 'hid' => $householdId, 'id' => $receiptId]);
        }
        if ($status === 'booked') {
            hb_group_create_header_transaction($pdo, $householdId, $groupId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $responseData = ['transaction_group' => hb_api_transaction_group_row($pdo, $householdId, $groupId)];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 201);
    }
    hb_api_json($responseData, 201);
}

if ($method === 'PATCH') {
    hb_api_require_scope($auth, 'transactions:write');
    [$idempotencyKey, $requestBody, $requestHash] = hb_group_idempotency_start($pdo, $auth);
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }
    hb_api_transaction_group_row($pdo, $householdId, $id);
    $data = json_decode($requestBody, true);
    if (!is_array($data)) {
        hb_api_json(['error' => 'Invalid JSON'], 400);
    }

    $sets = [];
    $params = ['hid' => $householdId, 'id' => $id];
    $totalAmountCents = null;
    if (array_key_exists('total_amount', $data) || array_key_exists('amount', $data)) {
        $totalAmountCents = hb_api_amount_cents($data['total_amount'] ?? $data['amount'] ?? null, 'total_amount');
        if ($totalAmountCents === null || $totalAmountCents <= 0) {
            hb_api_json(['error' => 'total_amount must be greater than zero'], 400);
        }
        $sets[] = 'total_amount_cents = :total_amount';
        $params['total_amount'] = $totalAmountCents;
    }
    if (array_key_exists('receipt_id', $data)) {
        $receiptId = hb_api_int_or_null($data['receipt_id']);
        hb_api_assert_receipt($pdo, $householdId, $receiptId);
        $sets[] = 'receipt_id = :receipt_id';
        $params['receipt_id'] = $receiptId;
    }
    if (array_key_exists('account_id', $data)) {
        $accountId = hb_api_int_or_null($data['account_id']);
        hb_api_assert_account($pdo, $householdId, $accountId);
        $sets[] = 'account_id = :account_id';
        $params['account_id'] = $accountId;
    }
    if (array_key_exists('payee_id', $data) || array_key_exists('payee', $data)) {
        $sets[] = 'payee_id = :payee_id';
        $sets[] = 'payee = :payee';
        $params['payee_id'] = hb_api_payee_id($pdo, $householdId, $data['payee_id'] ?? null, $data['payee'] ?? null);
        $params['payee'] = trim((string)($data['payee'] ?? '')) ?: null;
    }
    if (array_key_exists('booking_date', $data) || array_key_exists('date', $data)) {
        $sets[] = 'booking_date = :booking_date';
        $params['booking_date'] = hb_api_date($data['booking_date'] ?? $data['date'] ?? null, 'booking_date', true);
    }
    if (array_key_exists('status', $data)) {
        $status = (string)$data['status'];
        if (!in_array($status, ['draft', 'booked', 'archived'], true)) {
            hb_api_json(['error' => 'status is invalid'], 400);
        }
        $sets[] = 'status = :status';
        $params['status'] = $status;
    }
    if (array_key_exists('notes', $data)) {
        $sets[] = 'notes = :notes';
        $params['notes'] = trim((string)$data['notes']) ?: null;
    }
    if (array_key_exists('splits', $data)) {
        if ($totalAmountCents === null) {
            $current = hb_api_transaction_group_row($pdo, $householdId, $id);
            $totalAmountCents = (int)$current['total_amount_cents'];
        }
        $splits = hb_group_validate_splits($pdo, $householdId, $data['splits'], $totalAmountCents);
    } else {
        $splits = null;
    }

    $pdo->beginTransaction();
    try {
        if ($sets) {
            $sql = 'update transaction_groups set ' . implode(', ', $sets) . ', updated_at = now() where household_id = :hid and id = :id';
            $pdo->prepare($sql)->execute($params);
        }
        if ($splits !== null) {
            $pdo->prepare('delete from transaction_splits where household_id = :hid and transaction_group_id = :id')
                ->execute(['hid' => $householdId, 'id' => $id]);
            hb_group_insert_splits($pdo, $householdId, $id, $splits);
        }
        if (($data['status'] ?? null) === 'booked') {
            hb_group_create_header_transaction($pdo, $householdId, $id);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $responseData = ['transaction_group' => hb_api_transaction_group_row($pdo, $householdId, $id)];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 200);
    }
    hb_api_json($responseData);
}

if ($method === 'DELETE') {
    hb_api_require_scope($auth, 'transactions:write');
    [$idempotencyKey, , $requestHash] = hb_group_idempotency_start($pdo, $auth);
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }
    hb_api_transaction_group_row($pdo, $householdId, $id);
    $stmt = $pdo->prepare('update transaction_groups set status = :archived, updated_at = now() where household_id = :hid and id = :id');
    $stmt->execute(['archived' => 'archived', 'hid' => $householdId, 'id' => $id]);
    $responseData = ['deleted' => true, 'transaction_group' => hb_api_transaction_group_row($pdo, $householdId, $id)];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 200);
    }
    hb_api_json($responseData);
}

hb_api_json(['error' => 'Method not allowed'], 405);
