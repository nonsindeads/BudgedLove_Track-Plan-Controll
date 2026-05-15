<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

// Handle bulk reassign action
if ($method === 'POST' && isset($_GET['action']) && $_GET['action'] === 'bulk_reassign') {
    hb_api_require_scope($auth, 'categories:write');
    hb_api_require_scope($auth, 'transactions:write');

    $data = hb_api_read_json();
    $fromCategoryId = hb_api_int_or_null($data['from_category_id'] ?? null);
    $toCategoryId = hb_api_int_or_null($data['to_category_id'] ?? null);

    if ($fromCategoryId === null) {
        hb_api_json(['error' => 'from_category_id is required'], 400);
    }
    if ($toCategoryId === null) {
        hb_api_json(['error' => 'to_category_id is required'], 400);
    }

    // Verify both categories exist and belong to this household
    $stmt = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
    $stmt->execute(['id' => $fromCategoryId, 'hid' => $householdId]);
    if (!$stmt->fetch()) {
        hb_api_json(['error' => 'from_category_id not found'], 404);
    }

    $stmt = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
    $stmt->execute(['id' => $toCategoryId, 'hid' => $householdId]);
    if (!$stmt->fetch()) {
        hb_api_json(['error' => 'to_category_id not found'], 404);
    }

    try {
        $upd = $pdo->prepare(
            'update transactions set category_id = :to_cat where category_id = :from_cat and household_id = :hid'
        );
        $upd->execute([
            'from_cat' => $fromCategoryId,
            'to_cat' => $toCategoryId,
            'hid' => $householdId,
        ]);
        $affectedCount = $upd->rowCount();

        hb_api_json(['affected_count' => $affectedCount]);
    } catch (PDOException $e) {
        error_log('Category bulk reassign error: ' . $e->getMessage());
        hb_api_error('database_error', 'Failed to reassign categories', 500);
    }
}

if ($method === 'GET') {
    hb_api_require_scope($auth, 'categories:read');

    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id !== null) {
        $stmt = $pdo->prepare(
            'select id, name, parent_id, is_active, created_at, updated_at
                from categories
               where household_id = :hid and id = :id'
        );
        $stmt->execute(['hid' => $householdId, 'id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            hb_api_json(['error' => 'Category not found'], 404);
        }
        hb_api_json(['category' => hb_api_format_category($row)]);
    }

    // List with pagination and optional search
    $where = ['household_id = :hid', 'is_active = true'];
    $params = ['hid' => $householdId];

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = 'name ilike :q';
        $params['q'] = '%' . $q . '%';
    }

    $limit = hb_api_limit($_GET['limit'] ?? null);
    $offset = hb_api_offset($_GET['offset'] ?? null);
    $sqlWhere = implode(' and ', $where);

    $countStmt = $pdo->prepare("select count(*) from categories where $sqlWhere");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "select id, name, parent_id, is_active, created_at, updated_at
            from categories
           where $sqlWhere
           order by name asc
           limit :limit offset :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $categories = array_map('hb_api_format_category', $rows);

    hb_api_json([
        'categories' => $categories,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
    ]);
}

if ($method === 'POST') {
    hb_api_require_scope($auth, 'categories:write');

    $data = hb_api_read_json();
    $name = trim((string)($data['name'] ?? ''));

    if ($name === '') {
        hb_api_json(['error' => 'name is required'], 400);
    }

    $parentId = hb_api_int_or_null($data['parent_id'] ?? null);
    if ($parentId !== null) {
        // Verify parent category exists and belongs to this household
        $stmt = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        $stmt->execute(['id' => $parentId, 'hid' => $householdId]);
        if (!$stmt->fetch()) {
            hb_api_json(['error' => 'parent_id not found'], 404);
        }
    }

    try {
        $ins = $pdo->prepare(
            'insert into categories (household_id, name, parent_id, type, is_active)
              values (:hid, :name, :parent, :type, true)
              returning id'
        );
        $ins->execute([
            'hid' => $householdId,
            'name' => $name,
            'parent' => $parentId,
            'type' => 'custom',
        ]);
        $id = (int)$ins->fetchColumn();

        $stmt = $pdo->prepare(
            'select id, name, parent_id, is_active, created_at, updated_at
                from categories
               where id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        hb_api_json(['category' => hb_api_format_category($row)], 201);
    } catch (PDOException $e) {
        error_log('Category creation error: ' . $e->getMessage());
        hb_api_error('database_error', 'Failed to create category', 500);
    }
}

if ($method === 'PATCH') {
    hb_api_require_scope($auth, 'categories:write');

    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }

    // Verify category exists
    $stmt = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $householdId]);
    if (!$stmt->fetch()) {
        hb_api_json(['error' => 'Category not found'], 404);
    }

    $data = hb_api_read_json();
    $sets = [];
    $params = ['hid' => $householdId, 'id' => $id];

    if (array_key_exists('name', $data)) {
        $name = trim((string)$data['name']);
        if ($name === '') {
            hb_api_json(['error' => 'name cannot be empty'], 400);
        }
        $sets[] = 'name = :name';
        $params['name'] = $name;
    }

    if (array_key_exists('parent_id', $data)) {
        $parentId = hb_api_int_or_null($data['parent_id']);
        if ($parentId !== null) {
            // Verify parent category exists and belongs to this household
            $stmt = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
            $stmt->execute(['id' => $parentId, 'hid' => $householdId]);
            if (!$stmt->fetch()) {
                hb_api_json(['error' => 'parent_id not found'], 404);
            }
        }
        $sets[] = 'parent_id = :parent';
        $params['parent'] = $parentId;
    }

    if (!$sets) {
        // No changes, just return current state
        $stmt = $pdo->prepare(
            'select id, name, parent_id, is_active, created_at, updated_at
                from categories
               where id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        hb_api_json(['category' => hb_api_format_category($row)]);
    }

    try {
        $sql = 'update categories set ' . implode(', ', $sets) . ', updated_at = now() where household_id = :hid and id = :id';
        $upd = $pdo->prepare($sql);
        $upd->execute($params);

        $stmt = $pdo->prepare(
            'select id, name, parent_id, is_active, created_at, updated_at
                from categories
               where id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        hb_api_json(['category' => hb_api_format_category($row)]);
    } catch (PDOException $e) {
        error_log('Category update error: ' . $e->getMessage());
        hb_api_error('database_error', 'Failed to update category', 500);
    }
}

if ($method === 'DELETE') {
    hb_api_require_scope($auth, 'categories:write');

    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }

    // Verify category exists
    $stmt = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $householdId]);
    if (!$stmt->fetch()) {
        hb_api_json(['error' => 'Category not found'], 404);
    }

    try {
        // Soft-delete: set is_active to false
        $upd = $pdo->prepare('update categories set is_active = false, updated_at = now() where id = :id and household_id = :hid');
        $upd->execute(['id' => $id, 'hid' => $householdId]);

        hb_api_json(['deleted' => $upd->rowCount() > 0]);
    } catch (PDOException $e) {
        error_log('Category deletion error: ' . $e->getMessage());
        hb_api_error('database_error', 'Failed to delete category', 500);
    }
}

hb_api_json(['error' => 'Method not allowed'], 405);

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
