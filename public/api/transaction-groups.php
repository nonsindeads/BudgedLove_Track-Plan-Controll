<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$db = hb_dbal_household();
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

function hb_group_validate_splits(\Doctrine\DBAL\Connection $db, int $householdId, array $splits, int $totalAmountCents): array
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
        hb_api_dbal_assert_category($db, $householdId, $categoryId);
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

function hb_group_insert_splits(\Doctrine\DBAL\Connection $db, int $householdId, int $groupId, array $splits): void
{
    foreach ($splits as $split) {
        $db->insert('transaction_splits', [
            'household_id' => $householdId,
            'transaction_group_id' => $groupId,
            'transaction_id' => null,
            'amount_cents' => $split['amount_cents'],
            'category_id' => $split['category_id'],
            'note' => $split['note'],
            'sort_order' => $split['sort_order'],
        ]);
    }
}

function hb_group_create_header_transaction(\Doctrine\DBAL\Connection $db, int $householdId, int $groupId): ?int
{
    $group = $db->fetchAssociative(
        'select id, receipt_id, account_id, payee_id, payee, booking_date, total_amount_cents,
                currency_code, type, notes, matched_transaction_id
           from transaction_groups
          where household_id = :hid and id = :id',
        ['hid' => $householdId, 'id' => $groupId]
    );
    if (!$group) {
        hb_api_json(['error' => 'Transaction group not found'], 404);
    }
    if (!empty($group['matched_transaction_id'])) {
        return (int)$group['matched_transaction_id'];
    }
    if (empty($group['account_id'])) {
        hb_api_json(['error' => 'account_id is required when booking a transaction group'], 400);
    }
    $transactionId = hb_dbal_insert_and_get_id($db, 'transactions', [
        'household_id' => $householdId,
        'type' => (string)$group['type'],
        'booking_date' => (string)$group['booking_date'],
        'amount_cents' => (int)$group['total_amount_cents'],
        'currency_code' => (string)$group['currency_code'],
        'account_id' => (int)$group['account_id'],
        'category_id' => null,
        'payee_id' => $group['payee_id'] !== null ? (int)$group['payee_id'] : null,
        'note' => $group['notes'] !== null ? (string)$group['notes'] : null,
        'is_reviewed' => false,
        'counterparty_name' => $group['payee'] !== null ? (string)$group['payee'] : null,
        'receipt_id' => $group['receipt_id'] !== null ? (int)$group['receipt_id'] : null,
        'split_group_id' => $groupId,
    ], 'id', ['is_reviewed' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

    $db->executeStatement(
        'update transaction_splits
            set transaction_id = :transaction_id,
                updated_at = :updated_at
          where household_id = :hid
            and transaction_group_id = :group_id
            and transaction_id is null',
        [
            'transaction_id' => $transactionId,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'hid' => $householdId,
            'group_id' => $groupId,
        ]
    );
    $db->update(
        'transaction_groups',
        ['matched_transaction_id' => $transactionId, 'updated_at' => gmdate('Y-m-d H:i:s')],
        ['household_id' => $householdId, 'id' => $groupId]
    );
    return $transactionId;
}

try {
    if ($method === 'GET') {
        hb_api_require_scope($auth, 'transactions:read');
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id !== null) {
            hb_api_json(['transaction_group' => hb_api_transaction_group_row_dbal($db, $householdId, $id)]);
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
        $total = (int)$db->fetchOne("select count(*) from transaction_groups tg where {$sqlWhere}", $params);
        $groupIds = $db->fetchFirstColumn(
            "select tg.id
               from transaction_groups tg
              where {$sqlWhere}
              order by tg.booking_date desc, tg.id desc
              limit :limit offset :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
        $groups = [];
        foreach ($groupIds as $groupId) {
            $groups[] = hb_api_transaction_group_row_dbal($db, $householdId, (int)$groupId);
        }
        hb_api_json([
            'transaction_groups' => $groups,
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
        ]);
    }

    if ($method === 'POST') {
        hb_api_require_scope($auth, 'transactions:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $data = hb_api_read_json();

        $receiptId = hb_api_int_or_null($data['receipt_id'] ?? null);
        $accountId = hb_api_int_or_null($data['account_id'] ?? null);
        $payeeId = hb_api_int_or_null($data['payee_id'] ?? null);
        hb_api_dbal_assert_receipt($db, $householdId, $receiptId);
        hb_api_dbal_assert_account($db, $householdId, $accountId);
        $payeeId = hb_api_dbal_payee_id($db, $householdId, $payeeId, $data['payee'] ?? null);
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
        $splits = hb_group_validate_splits($db, $householdId, $data['splits'] ?? [], $totalAmountCents);

        $db->beginTransaction();
        try {
            $groupId = hb_dbal_insert_and_get_id($db, 'transaction_groups', [
                'household_id' => $householdId,
                'receipt_id' => $receiptId,
                'account_id' => $accountId,
                'payee_id' => $payeeId,
                'payee' => trim((string)($data['payee'] ?? '')) ?: null,
                'booking_date' => $bookingDate,
                'total_amount_cents' => $totalAmountCents,
                'currency_code' => $currencyCode,
                'type' => $type,
                'notes' => trim((string)($data['notes'] ?? '')) ?: null,
                'status' => $status,
            ]);
            hb_group_insert_splits($db, $householdId, $groupId, $splits);
            if ($receiptId !== null && $status === 'booked') {
                $db->update(
                    'receipts',
                    ['status' => 'matched', 'updated_at' => gmdate('Y-m-d H:i:s')],
                    ['household_id' => $householdId, 'id' => $receiptId]
                );
            }
            if ($status === 'booked') {
                hb_group_create_header_transaction($db, $householdId, $groupId);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $responseData = ['transaction_group' => hb_api_transaction_group_row_dbal($db, $householdId, $groupId)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData, 201);
        hb_api_json($responseData, 201);
    }

    if ($method === 'PATCH') {
        hb_api_require_scope($auth, 'transactions:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        hb_api_transaction_group_row_dbal($db, $householdId, $id);
        $data = hb_api_read_json();

        $updates = [];
        $totalAmountCents = null;
        if (array_key_exists('total_amount', $data) || array_key_exists('amount', $data)) {
            $totalAmountCents = hb_api_amount_cents($data['total_amount'] ?? $data['amount'] ?? null, 'total_amount');
            if ($totalAmountCents === null || $totalAmountCents <= 0) {
                hb_api_json(['error' => 'total_amount must be greater than zero'], 400);
            }
            $updates['total_amount_cents'] = $totalAmountCents;
        }
        if (array_key_exists('receipt_id', $data)) {
            $receiptId = hb_api_int_or_null($data['receipt_id']);
            hb_api_dbal_assert_receipt($db, $householdId, $receiptId);
            $updates['receipt_id'] = $receiptId;
        }
        if (array_key_exists('account_id', $data)) {
            $accountId = hb_api_int_or_null($data['account_id']);
            hb_api_dbal_assert_account($db, $householdId, $accountId);
            $updates['account_id'] = $accountId;
        }
        if (array_key_exists('payee_id', $data) || array_key_exists('payee', $data)) {
            $updates['payee_id'] = hb_api_dbal_payee_id($db, $householdId, $data['payee_id'] ?? null, $data['payee'] ?? null);
            $updates['payee'] = trim((string)($data['payee'] ?? '')) ?: null;
        }
        if (array_key_exists('booking_date', $data) || array_key_exists('date', $data)) {
            $updates['booking_date'] = hb_api_date($data['booking_date'] ?? $data['date'] ?? null, 'booking_date', true);
        }
        if (array_key_exists('status', $data)) {
            $status = (string)$data['status'];
            if (!in_array($status, ['draft', 'booked', 'archived'], true)) {
                hb_api_json(['error' => 'status is invalid'], 400);
            }
            $updates['status'] = $status;
        }
        if (array_key_exists('notes', $data)) {
            $updates['notes'] = trim((string)$data['notes']) ?: null;
        }
        if (array_key_exists('splits', $data)) {
            if ($totalAmountCents === null) {
                $current = hb_api_transaction_group_row_dbal($db, $householdId, $id);
                $totalAmountCents = (int)$current['total_amount_cents'];
            }
            $splits = hb_group_validate_splits($db, $householdId, $data['splits'], $totalAmountCents);
        } else {
            $splits = null;
        }

        $db->beginTransaction();
        try {
            if ($updates) {
                $updates['updated_at'] = gmdate('Y-m-d H:i:s');
                $db->update('transaction_groups', $updates, ['household_id' => $householdId, 'id' => $id]);
            }
            if ($splits !== null) {
                $db->delete('transaction_splits', ['household_id' => $householdId, 'transaction_group_id' => $id]);
                hb_group_insert_splits($db, $householdId, $id, $splits);
            }
            if (($data['status'] ?? null) === 'booked') {
                hb_group_create_header_transaction($db, $householdId, $id);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $responseData = ['transaction_group' => hb_api_transaction_group_row_dbal($db, $householdId, $id)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    if ($method === 'DELETE') {
        hb_api_require_scope($auth, 'transactions:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        hb_api_transaction_group_row_dbal($db, $householdId, $id);
        $db->update(
            'transaction_groups',
            ['status' => 'archived', 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['household_id' => $householdId, 'id' => $id]
        );
        $responseData = ['deleted' => true, 'transaction_group' => hb_api_transaction_group_row_dbal($db, $householdId, $id)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    hb_api_json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    error_log('API Error (transaction-groups.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
}

function hb_api_transaction_group_row_dbal(\Doctrine\DBAL\Connection $db, int $householdId, int $id): array
{
    $row = $db->fetchAssociative(
        'select tg.id, tg.receipt_id, tg.account_id, a.name as account_name,
                tg.payee_id, p.name as payee_name, tg.payee, tg.booking_date,
                tg.total_amount_cents, tg.currency_code, tg.type, tg.notes, tg.status,
                tg.external_id, tg.import_hash, tg.matched_transaction_id,
                tg.created_at, tg.updated_at
           from transaction_groups tg
      left join accounts a on a.id = tg.account_id
      left join payees p on p.id = tg.payee_id
          where tg.household_id = :hid and tg.id = :id',
        ['hid' => $householdId, 'id' => $id]
    );
    if (!$row) {
        hb_api_json(['error' => 'Transaction group not found'], 404);
    }
    $row['splits'] = $db->fetchAllAssociative(
        'select ts.id, ts.transaction_id, ts.amount_cents, ts.category_id, c.name as category_name,
                ts.note, ts.sort_order, ts.created_at, ts.updated_at
           from transaction_splits ts
      left join categories c on c.id = ts.category_id
          where ts.household_id = :hid and ts.transaction_group_id = :gid
          order by ts.sort_order asc, ts.id asc',
        ['hid' => $householdId, 'gid' => $id]
    );
    return hb_api_format_transaction_group($row);
}
