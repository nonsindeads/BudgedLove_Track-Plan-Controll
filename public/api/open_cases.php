<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];
try {

if ($method === 'GET') {
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id !== null) {
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
        hb_api_json(['open_case' => hb_api_format_open_case($row)]);
    }

    $where = ['household_id = :hid'];
    $params = ['hid' => $householdId];

    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '') {
        if (!in_array($status, ['open', 'resolved', 'cancelled'], true)) {
            hb_api_json(['error' => 'status is invalid'], 400);
        }
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

hb_api_json(['error' => 'Method not allowed'], 405);
} catch (PDOException $e) {
    error_log('API Error (open_cases.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
} catch (Exception $e) {
    error_log('API Error (open_cases.php): ' . $e->getMessage());
    hb_api_json(['error' => 'An error occurred. Please try again.'], 500);
}
