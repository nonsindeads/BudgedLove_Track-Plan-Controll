<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$db = hb_dbal_household();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST' && ($_GET['action'] ?? '') === 'bulk_reassign') {
        hb_api_require_scope($auth, 'categories:write');
        hb_api_require_scope($auth, 'transactions:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);

        $data = hb_api_read_json();
        $fromCategoryId = hb_api_int_or_null($data['from_category_id'] ?? null);
        $toCategoryId = hb_api_int_or_null($data['to_category_id'] ?? null);
        if ($fromCategoryId === null) {
            hb_api_json(['error' => 'from_category_id is required'], 400);
        }
        if ($toCategoryId === null) {
            hb_api_json(['error' => 'to_category_id is required'], 400);
        }
        if (!$db->fetchOne('select id from categories where id = :id and household_id = :hid', ['id' => $fromCategoryId, 'hid' => $householdId])) {
            hb_api_json(['error' => 'from_category_id not found'], 404);
        }
        if (!$db->fetchOne('select id from categories where id = :id and household_id = :hid', ['id' => $toCategoryId, 'hid' => $householdId])) {
            hb_api_json(['error' => 'to_category_id not found'], 404);
        }

        $affectedCount = $db->update(
            'transactions',
            ['category_id' => $toCategoryId],
            ['category_id' => $fromCategoryId, 'household_id' => $householdId]
        );
        $responseData = ['affected_count' => $affectedCount];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    if ($method === 'GET') {
        hb_api_require_scope($auth, 'categories:read');
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id !== null) {
            $row = $db->fetchAssociative(
                'select id, name, parent_id, is_active, created_at, updated_at
                   from categories
                  where household_id = :hid and id = :id',
                ['hid' => $householdId, 'id' => $id]
            );
            if (!$row) {
                hb_api_json(['error' => 'Category not found'], 404);
            }
            hb_api_json(['category' => hb_api_format_category($row)]);
        }

        $where = ['household_id = :hid', 'is_active = true'];
        $params = ['hid' => $householdId];
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'lower(name) like lower(:q)';
            $params['q'] = '%' . $q . '%';
        }

        $limit = hb_api_limit($_GET['limit'] ?? null);
        $offset = hb_api_offset($_GET['offset'] ?? null);
        $sqlWhere = implode(' and ', $where);
        $total = (int)$db->fetchOne("select count(*) from categories where {$sqlWhere}", $params);
        $rows = $db->fetchAllAssociative(
            "select id, name, parent_id, is_active, created_at, updated_at
               from categories
              where {$sqlWhere}
              order by name asc
              limit :limit offset :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]
        );

        hb_api_json([
            'categories' => array_map('hb_api_format_category', $rows),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
        ]);
    }

    if ($method === 'POST') {
        hb_api_require_scope($auth, 'categories:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);

        $data = hb_api_read_json();
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            hb_api_json(['error' => 'name is required'], 400);
        }
        $parentId = hb_api_int_or_null($data['parent_id'] ?? null);
        if ($parentId !== null && !$db->fetchOne('select id from categories where id = :id and household_id = :hid', ['id' => $parentId, 'hid' => $householdId])) {
            hb_api_json(['error' => 'parent_id not found'], 404);
        }

        $id = hb_dbal_insert_and_get_id($db, 'categories', [
            'household_id' => $householdId,
            'name' => $name,
            'parent_id' => $parentId,
            'type' => 'custom',
            'is_active' => true,
        ]);
        $row = hb_api_category_row($db, $householdId, $id);
        $responseData = ['category' => hb_api_format_category($row)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData, 201);
        hb_api_json($responseData, 201);
    }

    if ($method === 'PATCH') {
        hb_api_require_scope($auth, 'categories:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);

        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        if (!$db->fetchOne('select id from categories where id = :id and household_id = :hid', ['id' => $id, 'hid' => $householdId])) {
            hb_api_json(['error' => 'Category not found'], 404);
        }

        $data = hb_api_read_json();
        $updates = [];
        if (array_key_exists('name', $data)) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                hb_api_json(['error' => 'name cannot be empty'], 400);
            }
            $updates['name'] = $name;
        }
        if (array_key_exists('parent_id', $data)) {
            $parentId = hb_api_int_or_null($data['parent_id']);
            if ($parentId !== null && !$db->fetchOne('select id from categories where id = :id and household_id = :hid', ['id' => $parentId, 'hid' => $householdId])) {
                hb_api_json(['error' => 'parent_id not found'], 404);
            }
            $updates['parent_id'] = $parentId;
        }

        if ($updates) {
            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            $db->update('categories', $updates, ['household_id' => $householdId, 'id' => $id]);
        }
        $row = hb_api_category_row($db, $householdId, $id);
        $responseData = ['category' => hb_api_format_category($row)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    if ($method === 'DELETE') {
        hb_api_require_scope($auth, 'categories:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);

        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        if (!$db->fetchOne('select id from categories where id = :id and household_id = :hid', ['id' => $id, 'hid' => $householdId])) {
            hb_api_json(['error' => 'Category not found'], 404);
        }

        $deleted = $db->update(
            'categories',
            ['is_active' => false, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['household_id' => $householdId, 'id' => $id],
            ['is_active' => \Doctrine\DBAL\ParameterType::BOOLEAN]
        ) > 0;
        $responseData = ['deleted' => $deleted];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    hb_api_json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    error_log('API Error (categories.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
}

function hb_api_category_row(\Doctrine\DBAL\Connection $db, int $householdId, int $id): array
{
    $row = $db->fetchAssociative(
        'select id, name, parent_id, is_active, created_at, updated_at
           from categories
          where household_id = :hid and id = :id',
        ['hid' => $householdId, 'id' => $id]
    );
    if (!$row) {
        hb_api_json(['error' => 'Category not found'], 404);
    }
    return $row;
}

function hb_api_format_category(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
        'is_active' => (bool)$row['is_active'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}
