<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

try {
if ($method === 'GET') {
    hb_api_require_scope($auth, 'tags:read');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id !== null) {
        $stmt = $pdo->prepare('select id, name, color from tags where household_id = :hid and id = :id');
        $stmt->execute(['hid' => $householdId, 'id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            hb_api_json(['error' => 'Tag not found'], 404);
        }
        hb_api_json(['tag' => ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'color' => $row['color']]]);
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $where = ['household_id = :hid', 'is_active = true'];
    $params = ['hid' => $householdId];

    if ($q !== '') {
        $where[] = 'name ilike :q';
        $params['q'] = '%' . $q . '%';
    }

    $limit = hb_api_limit($_GET['limit'] ?? null);
    $offset = hb_api_offset($_GET['offset'] ?? null);
    $sqlWhere = implode(' and ', $where);

    $countStmt = $pdo->prepare("select count(*) from tags where $sqlWhere");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "select id, name, color from tags where $sqlWhere order by name asc limit :limit offset :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $tags = array_map(static fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'color' => $t['color']], $rows);

    hb_api_json([
        'tags' => $tags,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
    ]);
}

if ($method === 'POST') {
    hb_api_require_scope($auth, 'tags:write');
    $data = hb_api_read_json();
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        hb_api_json(['error' => 'name is required'], 400);
    }
    $color = $data['color'] ?? null;
    if ($color !== null && !is_string($color)) {
        hb_api_json(['error' => 'color must be a string or null'], 400);
    }

    $ins = $pdo->prepare(
        "insert into tags (household_id, name, color) values (:hid, :name, :color) returning id"
    );
    try {
        $ins->execute(['hid' => $householdId, 'name' => $name, 'color' => $color]);
        $id = (int)$ins->fetchColumn();
        hb_api_json(['tag' => ['id' => $id, 'name' => $name, 'color' => $color]], 201);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'unique')) {
            hb_api_json(['error' => 'Tag already exists'], 409);
        }
        throw $e;
    }
}

if ($method === 'PATCH') {
    hb_api_require_scope($auth, 'tags:write');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }

    $stmt = $pdo->prepare('select id from tags where household_id = :hid and id = :id');
    $stmt->execute(['hid' => $householdId, 'id' => $id]);
    if (!$stmt->fetch()) {
        hb_api_json(['error' => 'Tag not found'], 404);
    }

    $data = hb_api_read_json();
    if (empty($data)) {
        hb_api_json(['error' => 'No fields to update'], 400);
    }

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

    if (array_key_exists('color', $data)) {
        $color = $data['color'];
        if ($color !== null && !is_string($color)) {
            hb_api_json(['error' => 'color must be a string or null'], 400);
        }
        $sets[] = 'color = :color';
        $params['color'] = $color;
    }

    if ($sets) {
        $sql = 'update tags set ' . implode(', ', $sets) . ', updated_at = now() where household_id = :hid and id = :id';
        try {
            $upd = $pdo->prepare($sql);
            $upd->execute($params);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'unique')) {
                hb_api_json(['error' => 'Tag name already exists'], 409);
            }
            throw $e;
        }
    }

    $stmt = $pdo->prepare('select id, name, color from tags where household_id = :hid and id = :id');
    $stmt->execute(['hid' => $householdId, 'id' => $id]);
    $row = $stmt->fetch();
    hb_api_json(['tag' => ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'color' => $row['color']]]);
}

if ($method === 'DELETE') {
    hb_api_require_scope($auth, 'tags:write');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }

    $stmt = $pdo->prepare('update tags set is_active = false, updated_at = now() where household_id = :hid and id = :id');
    $stmt->execute(['hid' => $householdId, 'id' => $id]);
    hb_api_json(['deleted' => $stmt->rowCount() > 0]);
}

hb_api_json(['error' => 'Method not allowed'], 405);
} catch (PDOException $e) {
    error_log('API Error (tags.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
} catch (Exception $e) {
    error_log('API Error (tags.php): ' . $e->getMessage());
    hb_api_json(['error' => 'An error occurred. Please try again.'], 500);
}
