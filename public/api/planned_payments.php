<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];
try {

if ($method === 'GET') {
    hb_api_require_scope($auth, 'planned:read');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id !== null) {
        $stmt = $pdo->prepare(
            "select id, name, direction, amount_cents, planned_date, status, priority, is_optional,
                    account_id, category_id, payee_id, note, resolved_at, created_at, updated_at
               from planned_payments
              where household_id = :hid and id = :id"
        );
        $stmt->execute(['hid' => $householdId, 'id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            hb_api_json(['error' => 'Planned payment not found'], 404);
        }
        hb_api_json(['planned_payment' => hb_api_format_planned_payment($row)]);
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

    $countStmt = $pdo->prepare("select count(*) from planned_payments where $sqlWhere");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "select id, name, direction, amount_cents, planned_date, status, priority, is_optional,
                account_id, category_id, payee_id, note, resolved_at, created_at, updated_at
           from planned_payments
          where $sqlWhere
          order by planned_date asc, id asc
          limit :limit offset :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $planned = array_map(static fn($p) => hb_api_format_planned_payment($p), $rows);

    hb_api_json([
        'planned_payments' => $planned,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
    ]);
}

hb_api_json(['error' => 'Method not allowed'], 405);
} catch (PDOException $e) {
    error_log('API Error (planned_payments.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
} catch (Exception $e) {
    error_log('API Error (planned_payments.php): ' . $e->getMessage());
    hb_api_json(['error' => 'An error occurred. Please try again.'], 500);
}
