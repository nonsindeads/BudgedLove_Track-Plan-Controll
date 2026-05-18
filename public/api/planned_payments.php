<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$db = hb_dbal_household();
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

function hb_api_planned_payment_status(?string $status, string $field = 'status'): string
{
    $value = trim((string)$status);
    $allowed = ['open', 'done', 'resolved', 'skipped', 'overdue', 'suggested', 'cancelled'];
    if (!in_array($value, $allowed, true)) {
        hb_api_json(['error' => $field . ' is invalid'], 400);
    }
    return $value === 'done' ? 'resolved' : $value;
}

function hb_api_planned_payment_priority(mixed $value): int
{
    if (is_string($value)) {
        $map = ['low' => 1, 'normal' => 3, 'high' => 5];
        $normalized = strtolower(trim($value));
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }
    }
    $priority = hb_api_int_or_null($value);
    if ($priority === null || $priority < 1 || $priority > 99) {
        hb_api_json(['error' => 'priority is invalid'], 400);
    }
    return $priority;
}

try {
    if ($method === 'GET') {
        hb_api_require_scope($auth, 'planned:read');
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id !== null) {
            hb_api_json(['planned_payment' => hb_api_planned_payment_row_dbal($db, $householdId, $id)]);
        }

        $where = ['household_id = :hid'];
        $params = ['hid' => $householdId];
        $status = trim((string)($_GET['status'] ?? ''));
        if ($status !== '') {
            $normalizedStatus = hb_api_planned_payment_status($status);
            if ($normalizedStatus === 'resolved') {
                $where[] = 'status in (:status_resolved, :status_done)';
                $params['status_resolved'] = 'resolved';
                $params['status_done'] = 'done';
            } else {
                $where[] = 'status = :status';
                $params['status'] = $normalizedStatus;
            }
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
        $total = (int)$db->fetchOne("select count(*) from planned_payments where {$sqlWhere}", $params);
        $rows = $db->fetchAllAssociative(
            "select id, name, direction, amount_cents, planned_date, status, priority, is_optional,
                    account_id, category_id, payee_id, note, resolved_at, created_at, updated_at
               from planned_payments
              where {$sqlWhere}
              order by planned_date asc, id asc
              limit :limit offset :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]
        );

        hb_api_json([
            'planned_payments' => array_map('hb_api_format_planned_payment', $rows),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
        ]);
    }

    if ($method === 'POST') {
        hb_api_require_scope($auth, 'planned:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);

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
        $priority = hb_api_planned_payment_priority($data['priority'] ?? 3);
        $accountId = hb_api_int_or_null($data['account_id'] ?? null);
        if ($accountId === null) {
            hb_api_json(['error' => 'account_id is required'], 400);
        }
        hb_api_dbal_assert_account($db, $householdId, $accountId);
        $categoryId = hb_api_int_or_null($data['category_id'] ?? null);
        hb_api_dbal_assert_category($db, $householdId, $categoryId);
        $payeeId = hb_api_dbal_payee_id($db, $householdId, $data['payee_id'] ?? null, $data['payee'] ?? null);
        $note = trim((string)($data['note'] ?? $data['notes'] ?? ''));
        $isOptional = array_key_exists('is_optional', $data) ? hb_api_bool($data['is_optional']) : false;
        $resolvedAt = $status === 'resolved' ? gmdate('Y-m-d H:i:s') : null;

        $id = hb_dbal_insert_and_get_id($db, 'planned_payments', [
            'household_id' => $householdId,
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
            'is_optional' => $isOptional,
            'resolved_at' => $resolvedAt,
        ], 'id', ['is_optional' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

        $responseData = ['planned_payment' => hb_api_planned_payment_row_dbal($db, $householdId, $id)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData, 201);
        hb_api_json($responseData, 201);
    }

    if ($method === 'PATCH') {
        hb_api_require_scope($auth, 'planned:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);

        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        hb_api_planned_payment_row_dbal($db, $householdId, $id);
        $data = hb_api_read_json();
        $updates = [];
        $types = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                hb_api_json(['error' => 'name is required'], 400);
            }
            $updates['name'] = $name;
        }
        if (array_key_exists('direction', $data)) {
            $direction = (string)$data['direction'];
            if (!in_array($direction, ['income', 'expense'], true)) {
                hb_api_json(['error' => 'direction is invalid'], 400);
            }
            $updates['direction'] = $direction;
        }
        if (array_key_exists('planned_date', $data) || array_key_exists('date', $data)) {
            $updates['planned_date'] = hb_api_date((string)($data['planned_date'] ?? $data['date']), 'planned_date', true);
        }
        if (array_key_exists('amount', $data)) {
            $updates['amount_cents'] = hb_api_amount_cents($data['amount']);
        }
        if (array_key_exists('status', $data)) {
            $status = hb_api_planned_payment_status((string)$data['status']);
            $updates['status'] = $status;
            $updates['resolved_at'] = $status === 'resolved' ? gmdate('Y-m-d H:i:s') : null;
        }
        if (array_key_exists('priority', $data)) {
            $updates['priority'] = hb_api_planned_payment_priority($data['priority']);
        }
        if (array_key_exists('is_optional', $data)) {
            $updates['is_optional'] = hb_api_bool($data['is_optional']);
            $types['is_optional'] = \Doctrine\DBAL\ParameterType::BOOLEAN;
        }
        if (array_key_exists('account_id', $data)) {
            $accountId = hb_api_int_or_null($data['account_id']);
            if ($accountId === null) {
                hb_api_json(['error' => 'account_id is required'], 400);
            }
            hb_api_dbal_assert_account($db, $householdId, $accountId);
            $updates['account_id'] = $accountId;
        }
        if (array_key_exists('category_id', $data)) {
            $categoryId = hb_api_int_or_null($data['category_id']);
            hb_api_dbal_assert_category($db, $householdId, $categoryId);
            $updates['category_id'] = $categoryId;
        }
        if (array_key_exists('payee_id', $data) || array_key_exists('payee', $data)) {
            $updates['payee_id'] = hb_api_dbal_payee_id($db, $householdId, $data['payee_id'] ?? null, $data['payee'] ?? null);
        }
        if (array_key_exists('note', $data) || array_key_exists('notes', $data)) {
            $note = trim((string)($data['note'] ?? $data['notes'] ?? ''));
            $updates['note'] = $note !== '' ? $note : null;
        }

        if ($updates) {
            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $db->update('planned_payments', $updates, ['household_id' => $householdId, 'id' => $id], $types);
        }
        $responseData = ['planned_payment' => hb_api_planned_payment_row_dbal($db, $householdId, $id)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    if ($method === 'DELETE') {
        hb_api_require_scope($auth, 'planned:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);

        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        $deleted = $db->delete('planned_payments', ['household_id' => $householdId, 'id' => $id]) > 0;
        if (!$deleted) {
            hb_api_json(['error' => 'Planned payment not found'], 404);
        }
        $responseData = ['deleted' => true];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    hb_api_json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    error_log('API Error (planned_payments.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
}

function hb_api_planned_payment_row_dbal(\Doctrine\DBAL\Connection $db, int $householdId, int $id): array
{
    $row = $db->fetchAssociative(
        'select id, name, direction, amount_cents, planned_date, status, priority, is_optional,
                account_id, category_id, payee_id, note, resolved_at, created_at, updated_at
           from planned_payments
          where household_id = :hid and id = :id',
        ['hid' => $householdId, 'id' => $id]
    );
    if (!$row) {
        hb_api_json(['error' => 'Planned payment not found'], 404);
    }
    return hb_api_format_planned_payment($row);
}
