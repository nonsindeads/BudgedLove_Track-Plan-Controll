<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$db = hb_dbal_household();
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

function hb_api_open_case_status(?string $value): string
{
    $status = trim((string)$value);
    if (!in_array($status, ['open', 'resolved', 'cancelled'], true)) {
        hb_api_json(['error' => 'status is invalid'], 400);
    }
    return $status;
}

try {
    if ($method === 'GET') {
        hb_api_require_scope($auth, 'cases:read');
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id !== null) {
            hb_api_json(['open_case' => hb_api_open_case_row_dbal($db, $householdId, $id)]);
        }

        $where = ['household_id = :hid'];
        $params = ['hid' => $householdId];
        $status = trim((string)($_GET['status'] ?? ''));
        if ($status !== '') {
            $params['status'] = hb_api_open_case_status($status);
            $where[] = 'status = :status';
        }
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(lower(title) like lower(:q) or lower(reference) like lower(:q) or lower(contact_name) like lower(:q) or lower(notes) like lower(:q))';
            $params['q'] = '%' . $q . '%';
        }

        $limit = hb_api_limit($_GET['limit'] ?? null);
        $offset = hb_api_offset($_GET['offset'] ?? null);
        $sqlWhere = implode(' and ', $where);
        $total = (int)$db->fetchOne("select count(*) from open_cases where {$sqlWhere}", $params);
        $rows = $db->fetchAllAssociative(
            "select id, title, status, reference, contact_name, contact_details, notes, created_at, updated_at
               from open_cases
              where {$sqlWhere}
              order by created_at desc, id desc
              limit :limit offset :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]
        );

        hb_api_json([
            'open_cases' => array_map('hb_api_format_open_case', $rows),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
        ]);
    }

    if ($method === 'POST') {
        hb_api_require_scope($auth, 'cases:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $data = hb_api_read_json();
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            hb_api_json(['error' => 'title is required'], 400);
        }

        $id = hb_dbal_insert_and_get_id($db, 'open_cases', [
            'household_id' => $householdId,
            'title' => $title,
            'status' => hb_api_open_case_status((string)($data['status'] ?? 'open')),
            'reference' => hb_api_nullable_trim($data['reference'] ?? null),
            'contact_name' => hb_api_nullable_trim($data['contact_name'] ?? ($data['contact'] ?? null)),
            'contact_details' => hb_api_nullable_trim($data['contact_details'] ?? null),
            'notes' => hb_api_nullable_trim($data['notes'] ?? null),
        ]);

        $responseData = ['open_case' => hb_api_open_case_row_dbal($db, $householdId, $id)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData, 201);
        hb_api_json($responseData, 201);
    }

    if ($method === 'PATCH') {
        hb_api_require_scope($auth, 'cases:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        hb_api_open_case_row_dbal($db, $householdId, $id);

        $data = hb_api_read_json();
        $updates = [];
        if (array_key_exists('title', $data)) {
            $title = trim((string)$data['title']);
            if ($title === '') {
                hb_api_json(['error' => 'title is required'], 400);
            }
            $updates['title'] = $title;
        }
        if (array_key_exists('status', $data)) {
            $updates['status'] = hb_api_open_case_status((string)$data['status']);
        }
        foreach (['reference', 'contact_name', 'contact_details', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = hb_api_nullable_trim($data[$field]);
            }
        }
        if (!$updates) {
            hb_api_json(['error' => 'No fields to update'], 400);
        }
        $updates['updated_at'] = gmdate('Y-m-d H:i:s');
        $db->update('open_cases', $updates, ['household_id' => $householdId, 'id' => $id]);

        $responseData = ['open_case' => hb_api_open_case_row_dbal($db, $householdId, $id)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    if ($method === 'DELETE') {
        hb_api_require_scope($auth, 'cases:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        $responseData = ['deleted' => $db->delete('open_cases', ['household_id' => $householdId, 'id' => $id]) > 0];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    hb_api_json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    error_log('API Error (open_cases.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
}

function hb_api_open_case_row_dbal(\Doctrine\DBAL\Connection $db, int $householdId, int $id): array
{
    $row = $db->fetchAssociative(
        'select id, title, status, reference, contact_name, contact_details, notes, created_at, updated_at
           from open_cases
          where household_id = :hid and id = :id',
        ['hid' => $householdId, 'id' => $id]
    );
    if (!$row) {
        hb_api_json(['error' => 'Open case not found'], 404);
    }
    return hb_api_format_open_case($row);
}

function hb_api_nullable_trim(mixed $value): ?string
{
    $trimmed = trim((string)$value);
    return $trimmed !== '' ? $trimmed : null;
}
