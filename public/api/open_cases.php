<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

function hb_api_open_case_row(PDO $pdo, int $householdId, int $id): array
{
    $stmt = $pdo->prepare(
        "select id, title, status, reference, contact_name, contact_details, notes, created_at, updated_at
           from open_cases
          where household_id = :hid and id = :id"
    );
    $stmt->execute(['hid' => $householdId, 'id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        hb_api_json(['error' => 'Open case not found'], 404);
    }
    return hb_api_format_open_case($row);
}

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
        hb_api_json(['open_case' => hb_api_open_case_row($pdo, $householdId, $id)]);
    }

    $where = ['household_id = :hid'];
    $params = ['hid' => $householdId];

    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '') {
        $status = hb_api_open_case_status($status);
        $where[] = 'status = :status';
        $params['status'] = $status;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(title ilike :q or reference ilike :q or contact_name ilike :q or notes ilike :q)';
        $params['q'] = '%' . $q . '%';
    }

    $limit = hb_api_limit($_GET['limit'] ?? null);
    $offset = hb_api_offset($_GET['offset'] ?? null);
    $sqlWhere = implode(' and ', $where);

    $countStmt = $pdo->prepare("select count(*) from open_cases where $sqlWhere");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "select id, title, status, reference, contact_name, contact_details, notes, created_at, updated_at
           from open_cases
          where $sqlWhere
          order by created_at desc, id desc
          limit :limit offset :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $cases = array_map(static fn($c) => hb_api_format_open_case($c), $rows);

    hb_api_json([
        'open_cases' => $cases,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
    ]);
}

if ($method === 'POST') {
    hb_api_require_scope($auth, 'cases:write');

    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $requestBody = file_get_contents('php://input');
    if ($idempotencyKey) {
        $requestHash = hash('sha256', $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $requestBody);
        $cached = hb_api_idempotency_check($pdo, $auth, $idempotencyKey, $requestHash);
        if ($cached) {
            hb_api_send_cached_idempotent($cached);
        }
    }

    $data = hb_api_read_json();
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        hb_api_json(['error' => 'title is required'], 400);
    }
    $status = hb_api_open_case_status((string)($data['status'] ?? 'open'));
    $reference = trim((string)($data['reference'] ?? ''));
    $contactName = trim((string)($data['contact_name'] ?? ''));
    $contactDetails = trim((string)($data['contact_details'] ?? ''));
    $notes = trim((string)($data['notes'] ?? ''));

    $ins = $pdo->prepare(
        "insert into open_cases
            (household_id, title, status, reference, contact_name, contact_details, notes)
         values
            (:hid, :title, :status, :reference, :contact_name, :contact_details, :notes)
         returning id"
    );
    $ins->execute([
        'hid' => $householdId,
        'title' => $title,
        'status' => $status,
        'reference' => $reference !== '' ? $reference : null,
        'contact_name' => $contactName !== '' ? $contactName : null,
        'contact_details' => $contactDetails !== '' ? $contactDetails : null,
        'notes' => $notes !== '' ? $notes : null,
    ]);
    $id = (int)$ins->fetchColumn();

    $responseData = ['open_case' => hb_api_open_case_row($pdo, $householdId, $id)];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 201);
    }
    hb_api_json($responseData, 201);
}

if ($method === 'PATCH') {
    hb_api_require_scope($auth, 'cases:write');

    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $requestBody = file_get_contents('php://input');
    if ($idempotencyKey) {
        $requestHash = hash('sha256', $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $requestBody);
        $cached = hb_api_idempotency_check($pdo, $auth, $idempotencyKey, $requestHash);
        if ($cached) {
            hb_api_send_cached_idempotent($cached);
        }
    }

    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }
    hb_api_open_case_row($pdo, $householdId, $id);
    $data = hb_api_read_json();
    $sets = [];
    $params = ['hid' => $householdId, 'id' => $id];

    if (array_key_exists('title', $data)) {
        $title = trim((string)$data['title']);
        if ($title === '') {
            hb_api_json(['error' => 'title is required'], 400);
        }
        $sets[] = 'title = :title';
        $params['title'] = $title;
    }
    if (array_key_exists('status', $data)) {
        $sets[] = 'status = :status';
        $params['status'] = hb_api_open_case_status((string)$data['status']);
    }
    if (array_key_exists('reference', $data)) {
        $sets[] = 'reference = :reference';
        $value = trim((string)$data['reference']);
        $params['reference'] = $value !== '' ? $value : null;
    }
    if (array_key_exists('contact_name', $data)) {
        $sets[] = 'contact_name = :contact_name';
        $value = trim((string)$data['contact_name']);
        $params['contact_name'] = $value !== '' ? $value : null;
    }
    if (array_key_exists('contact_details', $data)) {
        $sets[] = 'contact_details = :contact_details';
        $value = trim((string)$data['contact_details']);
        $params['contact_details'] = $value !== '' ? $value : null;
    }
    if (array_key_exists('notes', $data)) {
        $sets[] = 'notes = :notes';
        $value = trim((string)$data['notes']);
        $params['notes'] = $value !== '' ? $value : null;
    }
    if (!$sets) {
        hb_api_json(['error' => 'No fields to update'], 400);
    }

    $sql = 'update open_cases set ' . implode(', ', $sets) . ', updated_at = now() where household_id = :hid and id = :id';
    $upd = $pdo->prepare($sql);
    $upd->execute($params);

    $responseData = ['open_case' => hb_api_open_case_row($pdo, $householdId, $id)];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 200);
    }
    hb_api_json($responseData);
}

if ($method === 'DELETE') {
    hb_api_require_scope($auth, 'cases:write');

    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $requestBody = file_get_contents('php://input');
    if ($idempotencyKey) {
        $requestHash = hash('sha256', $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $requestBody);
        $cached = hb_api_idempotency_check($pdo, $auth, $idempotencyKey, $requestHash);
        if ($cached) {
            hb_api_send_cached_idempotent($cached);
        }
    }

    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }
    $stmt = $pdo->prepare('delete from open_cases where household_id = :hid and id = :id');
    $stmt->execute(['hid' => $householdId, 'id' => $id]);

    $responseData = ['deleted' => $stmt->rowCount() > 0];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 200);
    }
    hb_api_json($responseData);
}

hb_api_json(['error' => 'Method not allowed'], 405);
} catch (PDOException $e) {
    error_log('API Error (open_cases.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
} catch (Exception $e) {
    error_log('API Error (open_cases.php): ' . $e->getMessage());
    hb_api_json(['error' => 'An error occurred. Please try again.'], 500);
}
