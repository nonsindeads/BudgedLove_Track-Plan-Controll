<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$db = hb_dbal_household();
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

function hb_saving_goal_format(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'description' => $row['description'] !== null ? (string)$row['description'] : null,
        'target_amount_cents' => (int)$row['target_amount_cents'],
        'target_amount' => ((int)$row['target_amount_cents']) / 100,
        'current_amount_cents' => (int)$row['current_amount_cents'],
        'current_amount' => ((int)$row['current_amount_cents']) / 100,
        'target_date' => $row['target_date'] !== null ? (string)$row['target_date'] : null,
        'monthly_contribution_cents' => $row['monthly_contribution_cents'] !== null ? (int)$row['monthly_contribution_cents'] : null,
        'monthly_contribution' => $row['monthly_contribution_cents'] !== null ? ((int)$row['monthly_contribution_cents']) / 100 : null,
        'source_account' => $row['source_account_id'] !== null ? ['id' => (int)$row['source_account_id'], 'name' => (string)$row['source_account_name']] : null,
        'storage_type' => (string)$row['storage_type'],
        'storage_account' => $row['storage_account_id'] !== null ? ['id' => (int)$row['storage_account_id'], 'name' => (string)$row['storage_account_name']] : null,
        'cash_location' => $row['cash_location'] !== null ? (string)$row['cash_location'] : null,
        'category' => $row['category_id'] !== null ? ['id' => (int)$row['category_id'], 'name' => (string)$row['category_name']] : null,
        'priority' => (string)$row['priority'],
        'status' => (string)$row['status'],
        'is_optional' => (bool)$row['is_optional'],
        'completed_at' => $row['completed_at'] !== null ? (string)$row['completed_at'] : null,
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function hb_saving_goal_row(\Doctrine\DBAL\Connection $db, int $householdId, int $id): array
{
    $row = $db->fetchAssociative(
        "select sg.id, sg.name, sg.description, sg.target_amount_cents, sg.current_amount_cents,
                sg.target_date, sg.monthly_contribution_cents, sg.source_account_id, sa.name as source_account_name,
                sg.storage_type, sg.storage_account_id, sta.name as storage_account_name, sg.cash_location,
                sg.category_id, c.name as category_name, sg.priority, sg.status, sg.is_optional,
                sg.completed_at, sg.created_at, sg.updated_at
           from saving_goals sg
      left join accounts sa on sa.id = sg.source_account_id
      left join accounts sta on sta.id = sg.storage_account_id
      left join categories c on c.id = sg.category_id
          where sg.household_id = :hid and sg.id = :id",
        ['hid' => $householdId, 'id' => $id]
    );
    if (!$row) {
        hb_api_json(['error' => 'Saving goal not found'], 404);
    }
    return hb_saving_goal_format($row);
}

function hb_validate_saving_goal_storage(string $storageType, ?int $sourceAccountId, ?int $storageAccountId): void
{
    if ($storageType === 'virtual' && $sourceAccountId === null) {
        hb_api_json(['error' => 'source_account_id is required for virtual storage_type'], 400);
    }
    if ($storageType === 'external_account' && $storageAccountId === null) {
        hb_api_json(['error' => 'storage_account_id is required for external_account storage_type'], 400);
    }
}

try {
    if ($method === 'GET') {
        $resource = (string)($_GET['resource'] ?? 'goals');
        if ($resource === 'contributions') {
            hb_api_require_scope($auth, 'goals:read');
            $goalId = hb_api_int_or_null($_GET['saving_goal_id'] ?? null);
            if ($goalId === null) {
                hb_api_json(['error' => 'saving_goal_id is required'], 400);
            }
            hb_saving_goal_row($db, $householdId, $goalId);
            $rows = $db->fetchAllAssociative(
                'select id, saving_goal_id, transaction_id, planned_payment_id, amount_cents, contribution_date, note, created_at, updated_at
                   from saving_goal_contributions
                  where household_id = :hid and saving_goal_id = :goal_id
                  order by contribution_date desc, id desc',
                ['hid' => $householdId, 'goal_id' => $goalId]
            );
            $items = array_map(static fn(array $row) => [
                'id' => (int)$row['id'],
                'saving_goal_id' => (int)$row['saving_goal_id'],
                'transaction_id' => $row['transaction_id'] !== null ? (int)$row['transaction_id'] : null,
                'planned_payment_id' => $row['planned_payment_id'] !== null ? (int)$row['planned_payment_id'] : null,
                'amount_cents' => (int)$row['amount_cents'],
                'amount' => ((int)$row['amount_cents']) / 100,
                'contribution_date' => (string)$row['contribution_date'],
                'note' => $row['note'] !== null ? (string)$row['note'] : null,
                'created_at' => (string)$row['created_at'],
                'updated_at' => (string)$row['updated_at'],
            ], $rows);
            hb_api_json(['contributions' => $items]);
        }

        hb_api_require_scope($auth, 'goals:read');
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id !== null) {
            hb_api_json(['saving_goal' => hb_saving_goal_row($db, $householdId, $id)]);
        }

        $where = ['sg.household_id = :hid'];
        $params = ['hid' => $householdId];
        $status = trim((string)($_GET['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'sg.status = :status';
            $params['status'] = $status;
        }
        foreach (['category_id', 'source_account_id', 'storage_account_id'] as $field) {
            $value = hb_api_int_or_null($_GET[$field] ?? null);
            if ($value !== null) {
                $where[] = 'sg.' . $field . ' = :' . $field;
                $params[$field] = $value;
            }
        }
        $limit = hb_api_limit($_GET['limit'] ?? null);
        $offset = hb_api_offset($_GET['offset'] ?? null);
        $whereSql = implode(' and ', $where);
        $rows = $db->fetchAllAssociative(
            "select sg.id, sg.name, sg.description, sg.target_amount_cents, sg.current_amount_cents,
                    sg.target_date, sg.monthly_contribution_cents, sg.source_account_id, sa.name as source_account_name,
                    sg.storage_type, sg.storage_account_id, sta.name as storage_account_name, sg.cash_location,
                    sg.category_id, c.name as category_name, sg.priority, sg.status, sg.is_optional,
                    sg.completed_at, sg.created_at, sg.updated_at
               from saving_goals sg
          left join accounts sa on sa.id = sg.source_account_id
          left join accounts sta on sta.id = sg.storage_account_id
          left join categories c on c.id = sg.category_id
              where {$whereSql}
              order by sg.created_at desc, sg.id desc
              limit :limit offset :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
        $goals = array_map(static fn(array $row) => hb_saving_goal_format($row), $rows);
        $total = (int)$db->fetchOne("select count(*) from saving_goals sg where {$whereSql}", $params);
        hb_api_json(['saving_goals' => $goals, 'limit' => $limit, 'offset' => $offset, 'total' => $total]);
    }

    if ($method === 'POST') {
        hb_api_require_scope($auth, 'goals:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $resource = (string)($_GET['resource'] ?? 'goals');
        $data = hb_api_read_json();

        if ($resource === 'contributions') {
            $goalId = hb_api_int_or_null($data['saving_goal_id'] ?? null);
            if ($goalId === null) {
                hb_api_json(['error' => 'saving_goal_id is required'], 400);
            }
            hb_saving_goal_row($db, $householdId, $goalId);
            $amountCents = hb_api_amount_cents($data['amount'] ?? null, 'amount');
            if ($amountCents === null || $amountCents <= 0) {
                hb_api_json(['error' => 'amount must be greater than zero'], 400);
            }
            $contributionDate = hb_api_date((string)($data['contribution_date'] ?? ''), 'contribution_date', true);
            $transactionId = hb_api_int_or_null($data['transaction_id'] ?? null);
            $plannedPaymentId = hb_api_int_or_null($data['planned_payment_id'] ?? null);

            $db->beginTransaction();
            try {
                $contributionId = hb_dbal_insert_and_get_id($db, 'saving_goal_contributions', [
                    'household_id' => $householdId,
                    'saving_goal_id' => $goalId,
                    'transaction_id' => $transactionId,
                    'planned_payment_id' => $plannedPaymentId,
                    'amount_cents' => $amountCents,
                    'contribution_date' => $contributionDate,
                    'note' => trim((string)($data['note'] ?? '')) ?: null,
                ]);
                $db->executeStatement(
                    'update saving_goals
                        set current_amount_cents = current_amount_cents + :delta,
                            updated_at = :updated_at
                      where household_id = :hid and id = :id',
                    [
                        'delta' => $amountCents,
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                        'hid' => $householdId,
                        'id' => $goalId,
                    ]
                );
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }
            $responseData = ['saving_goal' => hb_saving_goal_row($db, $householdId, $goalId), 'contribution_id' => $contributionId];
            hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData, 201);
            hb_api_json($responseData, 201);
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            hb_api_json(['error' => 'name is required'], 400);
        }
        $targetAmountCents = hb_api_amount_cents($data['target_amount'] ?? null, 'target_amount');
        if ($targetAmountCents === null || $targetAmountCents <= 0) {
            hb_api_json(['error' => 'target_amount must be greater than zero'], 400);
        }
        $currentAmountCents = hb_api_amount_cents($data['current_amount'] ?? 0, 'current_amount');
        if ($currentAmountCents === null || $currentAmountCents < 0) {
            hb_api_json(['error' => 'current_amount must be zero or greater'], 400);
        }
        $monthlyContributionCents = null;
        if (array_key_exists('monthly_contribution', $data)) {
            $monthlyContributionCents = hb_api_amount_cents($data['monthly_contribution'], 'monthly_contribution');
            if ($monthlyContributionCents === null || $monthlyContributionCents < 0) {
                hb_api_json(['error' => 'monthly_contribution must be zero or greater'], 400);
            }
        }
        $sourceAccountId = hb_api_int_or_null($data['source_account_id'] ?? null);
        $storageAccountId = hb_api_int_or_null($data['storage_account_id'] ?? null);
        $categoryId = hb_api_int_or_null($data['category_id'] ?? null);
        hb_api_dbal_assert_account($db, $householdId, $sourceAccountId);
        hb_api_dbal_assert_account($db, $householdId, $storageAccountId);
        hb_api_dbal_assert_category($db, $householdId, $categoryId);
        $storageType = (string)($data['storage_type'] ?? 'virtual');
        if (!in_array($storageType, ['virtual', 'cash', 'external_account'], true)) {
            hb_api_json(['error' => 'storage_type is invalid'], 400);
        }
        hb_validate_saving_goal_storage($storageType, $sourceAccountId, $storageAccountId);
        $status = (string)($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'paused', 'completed', 'archived'], true)) {
            hb_api_json(['error' => 'status is invalid'], 400);
        }
        $priority = (string)($data['priority'] ?? 'normal');
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            hb_api_json(['error' => 'priority is invalid'], 400);
        }
        $targetDate = hb_api_date($data['target_date'] ?? null, 'target_date');

        $id = hb_dbal_insert_and_get_id($db, 'saving_goals', [
            'household_id' => $householdId,
            'name' => $name,
            'description' => trim((string)($data['description'] ?? '')) ?: null,
            'target_amount_cents' => $targetAmountCents,
            'current_amount_cents' => $currentAmountCents,
            'target_date' => $targetDate,
            'monthly_contribution_cents' => $monthlyContributionCents,
            'source_account_id' => $sourceAccountId,
            'category_id' => $categoryId,
            'priority' => $priority,
            'status' => $status,
            'is_optional' => array_key_exists('is_optional', $data) ? hb_api_bool($data['is_optional']) : true,
            'storage_type' => $storageType,
            'storage_account_id' => $storageAccountId,
            'cash_location' => trim((string)($data['cash_location'] ?? '')) ?: null,
            'completed_at' => $status === 'completed' ? gmdate('Y-m-d H:i:s') : null,
        ], 'id', ['is_optional' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

        $responseData = ['saving_goal' => hb_saving_goal_row($db, $householdId, $id)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData, 201);
        hb_api_json($responseData, 201);
    }

    if ($method === 'PATCH') {
        hb_api_require_scope($auth, 'goals:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        hb_saving_goal_row($db, $householdId, $id);
        $data = hb_api_read_json();
        $updates = [];
        $types = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                hb_api_json(['error' => 'name cannot be empty'], 400);
            }
            $updates['name'] = $name;
        }
        if (array_key_exists('description', $data)) {
            $updates['description'] = trim((string)$data['description']) ?: null;
        }
        if (array_key_exists('target_amount', $data)) {
            $targetAmountCents = hb_api_amount_cents($data['target_amount'], 'target_amount');
            if ($targetAmountCents === null || $targetAmountCents <= 0) {
                hb_api_json(['error' => 'target_amount must be greater than zero'], 400);
            }
            $updates['target_amount_cents'] = $targetAmountCents;
        }
        if (array_key_exists('current_amount', $data)) {
            $currentAmountCents = hb_api_amount_cents($data['current_amount'], 'current_amount');
            if ($currentAmountCents === null || $currentAmountCents < 0) {
                hb_api_json(['error' => 'current_amount must be zero or greater'], 400);
            }
            $updates['current_amount_cents'] = $currentAmountCents;
        }
        if (array_key_exists('monthly_contribution', $data)) {
            $monthlyContributionCents = hb_api_amount_cents($data['monthly_contribution'], 'monthly_contribution');
            if ($monthlyContributionCents === null || $monthlyContributionCents < 0) {
                hb_api_json(['error' => 'monthly_contribution must be zero or greater'], 400);
            }
            $updates['monthly_contribution_cents'] = $monthlyContributionCents;
        }
        foreach (['source_account_id', 'storage_account_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = hb_api_int_or_null($data[$field]);
                hb_api_dbal_assert_account($db, $householdId, $value);
                $updates[$field] = $value;
            }
        }
        if (array_key_exists('category_id', $data)) {
            $categoryId = hb_api_int_or_null($data['category_id']);
            hb_api_dbal_assert_category($db, $householdId, $categoryId);
            $updates['category_id'] = $categoryId;
        }
        if (array_key_exists('target_date', $data)) {
            $updates['target_date'] = hb_api_date($data['target_date'], 'target_date');
        }
        if (array_key_exists('storage_type', $data)) {
            $storageType = (string)$data['storage_type'];
            if (!in_array($storageType, ['virtual', 'cash', 'external_account'], true)) {
                hb_api_json(['error' => 'storage_type is invalid'], 400);
            }
            $updates['storage_type'] = $storageType;
        }
        if (array_key_exists('cash_location', $data)) {
            $updates['cash_location'] = trim((string)$data['cash_location']) ?: null;
        }
        if (array_key_exists('priority', $data)) {
            $priority = (string)$data['priority'];
            if (!in_array($priority, ['low', 'normal', 'high'], true)) {
                hb_api_json(['error' => 'priority is invalid'], 400);
            }
            $updates['priority'] = $priority;
        }
        if (array_key_exists('status', $data)) {
            $status = (string)$data['status'];
            if (!in_array($status, ['active', 'paused', 'completed', 'archived'], true)) {
                hb_api_json(['error' => 'status is invalid'], 400);
            }
            $updates['status'] = $status;
            $updates['completed_at'] = $status === 'completed' ? gmdate('Y-m-d H:i:s') : null;
        }
        if (array_key_exists('is_optional', $data)) {
            $updates['is_optional'] = hb_api_bool($data['is_optional']);
            $types['is_optional'] = \Doctrine\DBAL\ParameterType::BOOLEAN;
        }

        $existingStorage = $db->fetchAssociative(
            'select storage_type, source_account_id, storage_account_id
               from saving_goals
              where household_id = :hid and id = :id',
            ['hid' => $householdId, 'id' => $id]
        ) ?: [];
        $storageTypeCheck = (string)($updates['storage_type'] ?? $existingStorage['storage_type'] ?? '');
        $sourceAccountCheck = $updates['source_account_id'] ?? hb_api_int_or_null($existingStorage['source_account_id'] ?? null);
        $storageAccountCheck = $updates['storage_account_id'] ?? hb_api_int_or_null($existingStorage['storage_account_id'] ?? null);
        hb_validate_saving_goal_storage($storageTypeCheck, $sourceAccountCheck, $storageAccountCheck);

        if ($updates) {
            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $db->update('saving_goals', $updates, ['household_id' => $householdId, 'id' => $id], $types);
        }
        $responseData = ['saving_goal' => hb_saving_goal_row($db, $householdId, $id)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    if ($method === 'DELETE') {
        hb_api_require_scope($auth, 'goals:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $resource = (string)($_GET['resource'] ?? 'goals');
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        if ($resource === 'contributions') {
            $contribution = $db->fetchAssociative(
                'select id, saving_goal_id, amount_cents
                   from saving_goal_contributions
                  where household_id = :hid and id = :id',
                ['hid' => $householdId, 'id' => $id]
            );
            if (!$contribution) {
                hb_api_json(['error' => 'Saving goal contribution not found'], 404);
            }
            $goalId = (int)$contribution['saving_goal_id'];
            $amountCents = (int)$contribution['amount_cents'];
            $db->beginTransaction();
            try {
                $db->delete('saving_goal_contributions', [
                    'household_id' => $householdId,
                    'id' => $id,
                ]);
                $db->executeStatement(
                    'update saving_goals
                        set current_amount_cents = case
                                when current_amount_cents - :delta < 0 then 0
                                else current_amount_cents - :delta
                            end,
                            updated_at = :updated_at
                      where household_id = :hid and id = :id',
                    [
                        'delta' => $amountCents,
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                        'hid' => $householdId,
                        'id' => $goalId,
                    ]
                );
                $db->commit();
            } catch (Throwable $e) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                throw $e;
            }
            $responseData = [
                'deleted' => true,
                'saving_goal' => hb_saving_goal_row($db, $householdId, $goalId),
            ];
            hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
            hb_api_json($responseData);
        }
        if ($resource !== 'goals') {
            hb_api_json(['error' => 'resource is invalid'], 400);
        }
        $exists = (bool)$db->fetchOne('select 1 from saving_goals where household_id = :hid and id = :id', ['hid' => $householdId, 'id' => $id]);
        if (!$exists) {
            hb_api_json(['error' => 'Saving goal not found'], 404);
        }
        $db->update('saving_goals', [
            'status' => 'archived',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['household_id' => $householdId, 'id' => $id]);
        $responseData = ['archived' => true];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    hb_api_json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    error_log('API Error (saving-goals.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
}
