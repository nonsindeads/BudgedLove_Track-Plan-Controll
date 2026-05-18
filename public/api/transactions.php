<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$db = hb_dbal_household();
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        hb_api_require_scope($auth, 'transactions:read');
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id !== null) {
            hb_api_json(['transaction' => hb_api_transaction_row_dbal($db, $householdId, $id)]);
        }

        $where = ['t.household_id = :hid'];
        $params = ['hid' => $householdId];
        $types = [];

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
            $params['reviewed'] = hb_api_bool($reviewed);
            $types['reviewed'] = \Doctrine\DBAL\ParameterType::BOOLEAN;
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
            hb_api_dbal_assert_tag($db, $householdId, $tagId);
            $where[] = 'exists (select 1 from transaction_tags tt where tt.transaction_id = t.id and tt.tag_id = :tag_id)';
            $params['tag_id'] = $tagId;
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(lower(p.name) like lower(:q) or lower(t.counterparty_name) like lower(:q) or lower(t.note) like lower(:q) or lower(t.external_id) like lower(:q))';
            $params['q'] = '%' . $q . '%';
        }

        $limit = hb_api_limit($_GET['limit'] ?? null);
        $offset = hb_api_offset($_GET['offset'] ?? null);
        $sqlWhere = implode(' and ', $where);
        $total = (int)$db->fetchOne(
            "select count(*) from transactions t left join payees p on p.id = t.payee_id where {$sqlWhere}",
            $params,
            $types
        );

        $rows = $db->fetchAllAssociative(
            "select t.id, t.type, t.booking_date, t.amount_cents, t.currency_code,
                    t.account_id, a.name as account_name,
                    t.category_id, c.name as category_name,
                    t.payee_id, p.name as payee_name,
                    t.counterparty_name, t.note, t.is_reviewed, t.external_id,
                    t.import_hash, t.planned_payment_id, t.receipt_id, t.split_group_id,
                    t.split_parent_id, t.split_note, t.created_at, t.updated_at
               from transactions t
          left join accounts a on a.id = t.account_id
          left join categories c on c.id = t.category_id
          left join payees p on p.id = t.payee_id
              where {$sqlWhere}
              order by t.booking_date desc, t.id desc
              limit :limit offset :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            array_merge($types, [
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
                'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
            ])
        );
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
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
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
        $receiptId = hb_api_int_or_null($data['receipt_id'] ?? null);
        $splitGroupId = hb_api_int_or_null($data['split_group_id'] ?? null);
        hb_api_dbal_assert_account($db, $householdId, $accountId);
        hb_api_dbal_assert_category($db, $householdId, $categoryId);
        hb_api_dbal_assert_receipt($db, $householdId, $receiptId);
        hb_api_dbal_assert_transaction_group($db, $householdId, $splitGroupId);
        $payeeId = hb_api_dbal_payee_id($db, $householdId, $data['payee_id'] ?? null, $data['payee'] ?? null);
        $counterparty = trim((string)($data['counterparty_name'] ?? $data['payee'] ?? ''));

        $id = hb_dbal_insert_and_get_id($db, 'transactions', [
            'household_id' => $householdId,
            'type' => $type,
            'booking_date' => $date,
            'amount_cents' => $amountCents,
            'currency_code' => 'EUR',
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'payee_id' => $payeeId,
            'counterparty_name' => $counterparty !== '' ? $counterparty : null,
            'note' => (string)($data['notes'] ?? ''),
            'is_reviewed' => array_key_exists('is_reviewed', $data) ? hb_api_bool($data['is_reviewed']) : true,
            'receipt_id' => $receiptId,
            'split_group_id' => $splitGroupId,
            'split_note' => trim((string)($data['split_note'] ?? '')) ?: null,
        ], 'id', ['is_reviewed' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

        if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
            hb_api_dbal_set_transaction_tags($db, $householdId, $id, $data['tag_ids']);
        }
        $responseData = ['transaction' => hb_api_transaction_row_dbal($db, $householdId, $id)];
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
        $currentTransaction = hb_api_transaction_row_dbal($db, $householdId, $id);
        $data = hb_api_read_json();
        $updates = [];
        $types = [];
        $normalizedSplits = null;

        if (array_key_exists('type', $data)) {
            $type = (string)$data['type'];
            if (!in_array($type, ['income', 'expense'], true)) {
                hb_api_json(['error' => 'type is invalid'], 400);
            }
            $updates['type'] = $type;
        }
        if (array_key_exists('date', $data)) {
            $updates['booking_date'] = hb_api_date((string)$data['date'], 'date', true);
        }
        if (array_key_exists('amount', $data)) {
            $updates['amount_cents'] = hb_api_amount_cents($data['amount']);
        }
        foreach (['account_id' => 'account_id', 'category_id' => 'category_id'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $value = hb_api_int_or_null($data[$input]);
                if ($column === 'account_id') {
                    hb_api_dbal_assert_account($db, $householdId, $value);
                } else {
                    hb_api_dbal_assert_category($db, $householdId, $value);
                }
                $updates[$column] = $value;
            }
        }
        if (array_key_exists('receipt_id', $data)) {
            $receiptId = hb_api_int_or_null($data['receipt_id']);
            hb_api_dbal_assert_receipt($db, $householdId, $receiptId);
            $updates['receipt_id'] = $receiptId;
        }
        if (array_key_exists('split_group_id', $data)) {
            $splitGroupId = hb_api_int_or_null($data['split_group_id']);
            hb_api_dbal_assert_transaction_group($db, $householdId, $splitGroupId);
            $updates['split_group_id'] = $splitGroupId;
        }
        if (array_key_exists('split_note', $data)) {
            $updates['split_note'] = trim((string)$data['split_note']) ?: null;
        }
        if (array_key_exists('payee_id', $data) || array_key_exists('payee', $data)) {
            $updates['payee_id'] = hb_api_dbal_payee_id($db, $householdId, $data['payee_id'] ?? null, $data['payee'] ?? null);
        }
        if (array_key_exists('counterparty_name', $data)) {
            $updates['counterparty_name'] = trim((string)$data['counterparty_name']) ?: null;
        }
        if (array_key_exists('notes', $data)) {
            $updates['note'] = (string)$data['notes'];
        }
        if (array_key_exists('is_reviewed', $data)) {
            $updates['is_reviewed'] = hb_api_bool($data['is_reviewed']);
            $types['is_reviewed'] = \Doctrine\DBAL\ParameterType::BOOLEAN;
        }

        if (array_key_exists('splits', $data)) {
            if (!is_array($data['splits']) || !$data['splits']) {
                hb_api_json(['error' => 'splits must be a non-empty array'], 400);
            }
            $targetAmountCents = (int)($updates['amount_cents'] ?? $currentTransaction['amount_cents']);
            $splitSum = 0;
            $normalizedSplits = [];
            foreach (array_values($data['splits']) as $index => $split) {
                if (!is_array($split)) {
                    hb_api_json(['error' => 'splits must contain objects'], 400);
                }
                $splitAmountCents = hb_api_amount_cents($split['amount'] ?? null, 'splits.amount');
                if ($splitAmountCents === null || $splitAmountCents <= 0) {
                    hb_api_json(['error' => 'splits.amount must be greater than zero'], 400);
                }
                $splitCategoryId = hb_api_int_or_null($split['category_id'] ?? null);
                if ($splitCategoryId === null) {
                    hb_api_json(['error' => 'splits.category_id is required'], 400);
                }
                hb_api_dbal_assert_category($db, $householdId, $splitCategoryId);
                $normalizedSplits[] = [
                    'amount_cents' => $splitAmountCents,
                    'category_id' => $splitCategoryId,
                    'note' => trim((string)($split['note'] ?? '')) ?: null,
                    'sort_order' => array_key_exists('sort_order', $split) ? max(0, (int)$split['sort_order']) : $index,
                ];
                $splitSum += $splitAmountCents;
            }
            if ($splitSum !== $targetAmountCents) {
                hb_api_error('split_total_mismatch', 'Sum of splits does not match transaction amount', 400);
            }
            // Keep categorization at split level only.
            $updates['category_id'] = null;
        }

        if ($updates) {
            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $db->update('transactions', $updates, ['household_id' => $householdId, 'id' => $id], $types);
        }
        if (is_array($normalizedSplits)) {
            $db->delete('transaction_splits', ['transaction_id' => $id]);
            foreach ($normalizedSplits as $split) {
                $db->insert('transaction_splits', [
                    'transaction_id' => $id,
                    'category_id' => $split['category_id'],
                    'amount_cents' => $split['amount_cents'],
                    'note' => $split['note'],
                    'sort_order' => $split['sort_order'],
                ]);
            }
        }
        if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
            hb_api_dbal_set_transaction_tags($db, $householdId, $id, $data['tag_ids']);
        }
        $responseData = ['transaction' => hb_api_transaction_row_dbal($db, $householdId, $id)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    if ($method === 'DELETE') {
        hb_api_require_scope($auth, 'transactions:delete');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);

        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        $responseData = ['deleted' => $db->delete('transactions', ['household_id' => $householdId, 'id' => $id]) > 0];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    hb_api_json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    error_log('API Error (transactions.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
}
