<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$db = hb_dbal_household();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        hb_api_require_scope($auth, 'tags:read');
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id !== null) {
            $row = $db->fetchAssociative(
                'select id, name, color from tags where household_id = :hid and id = :id',
                ['hid' => $householdId, 'id' => $id]
            );
            if (!$row) {
                hb_api_json(['error' => 'Tag not found'], 404);
            }
            hb_api_json(['tag' => hb_api_format_tag($row)]);
        }

        $q = trim((string)($_GET['q'] ?? ''));
        $where = ['household_id = :hid', 'is_active = true'];
        $params = ['hid' => $householdId];
        if ($q !== '') {
            $where[] = 'lower(name) like lower(:q)';
            $params['q'] = '%' . $q . '%';
        }

        $limit = hb_api_limit($_GET['limit'] ?? null);
        $offset = hb_api_offset($_GET['offset'] ?? null);
        $sqlWhere = implode(' and ', $where);
        $total = (int)$db->fetchOne("select count(*) from tags where {$sqlWhere}", $params);
        $rows = $db->fetchAllAssociative(
            "select id, name, color from tags where {$sqlWhere} order by name asc limit :limit offset :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]
        );

        hb_api_json([
            'tags' => array_map('hb_api_format_tag', $rows),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
        ]);
    }

    if ($method === 'POST') {
        hb_api_require_scope($auth, 'tags:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $data = hb_api_read_json();
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            hb_api_json(['error' => 'name is required'], 400);
        }
        $color = $data['color'] ?? null;
        if ($color !== null && !is_string($color)) {
            hb_api_json(['error' => 'color must be a string or null'], 400);
        }

        try {
            $id = hb_dbal_insert_and_get_id($db, 'tags', [
                'household_id' => $householdId,
                'name' => $name,
                'color' => $color,
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            hb_api_json(['error' => 'Tag already exists'], 409);
        }

        $responseData = ['tag' => ['id' => $id, 'name' => $name, 'color' => $color]];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData, 201);
        hb_api_json($responseData, 201);
    }

    if ($method === 'PATCH') {
        hb_api_require_scope($auth, 'tags:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        if (!$db->fetchOne('select id from tags where household_id = :hid and id = :id', ['hid' => $householdId, 'id' => $id])) {
            hb_api_json(['error' => 'Tag not found'], 404);
        }

        $data = hb_api_read_json();
        if (empty($data)) {
            hb_api_json(['error' => 'No fields to update'], 400);
        }

        $updates = [];
        if (array_key_exists('name', $data)) {
            $name = trim((string)$data['name']);
            if ($name === '') {
                hb_api_json(['error' => 'name cannot be empty'], 400);
            }
            $updates['name'] = $name;
        }
        if (array_key_exists('color', $data)) {
            $color = $data['color'];
            if ($color !== null && !is_string($color)) {
                hb_api_json(['error' => 'color must be a string or null'], 400);
            }
            $updates['color'] = $color;
        }

        if ($updates) {
            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            try {
                $db->update('tags', $updates, ['household_id' => $householdId, 'id' => $id]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                hb_api_json(['error' => 'Tag name already exists'], 409);
            }
        }

        $row = $db->fetchAssociative(
            'select id, name, color from tags where household_id = :hid and id = :id',
            ['hid' => $householdId, 'id' => $id]
        );
        $responseData = ['tag' => hb_api_format_tag($row ?: [])];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    if ($method === 'DELETE') {
        hb_api_require_scope($auth, 'tags:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }

        $responseData = [
            'deleted' => $db->update(
                'tags',
                ['is_active' => false, 'updated_at' => gmdate('Y-m-d H:i:s')],
                ['household_id' => $householdId, 'id' => $id],
                ['is_active' => \Doctrine\DBAL\ParameterType::BOOLEAN]
            ) > 0,
        ];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    hb_api_json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    error_log('API Error (tags.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
}

function hb_api_format_tag(array $row): array
{
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => (string)($row['name'] ?? ''),
        'color' => $row['color'] ?? null,
    ];
}
