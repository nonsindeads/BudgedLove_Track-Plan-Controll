<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];
try {

if ($method === 'GET') {
    hb_api_require_scope($auth, 'recurring:read');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id !== null) {
        $stmt = $pdo->prepare(
            "select id, name, kind, schedule_unit, schedule_interval, schedule_weekdays, schedule_monthday,
                    schedule_start_date, is_active, next_run_at, last_run_at, created_at, updated_at
               from recurring_rules
              where household_id = :hid and id = :id"
        );
        $stmt->execute(['hid' => $householdId, 'id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            hb_api_json(['error' => 'Recurring rule not found'], 404);
        }
        hb_api_json(['recurring_rule' => hb_api_format_recurring_rule($row)]);
    }

    $where = ['household_id = :hid'];
    $params = ['hid' => $householdId];

    $active = (string)($_GET['active'] ?? '');
    if ($active !== '') {
        $where[] = 'is_active = :active';
        $params['active'] = hb_api_bool($active) ? true : false;
    }

    $kind = trim((string)($_GET['kind'] ?? ''));
    if ($kind !== '') {
        if (!in_array($kind, ['transaction', 'payment'], true)) {
            hb_api_json(['error' => 'kind is invalid'], 400);
        }
        $where[] = 'kind = :kind';
        $params['kind'] = $kind;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = 'name ilike :q';
        $params['q'] = '%' . $q . '%';
    }

    $limit = hb_api_limit($_GET['limit'] ?? null);
    $offset = hb_api_offset($_GET['offset'] ?? null);
    $sqlWhere = implode(' and ', $where);

    $countStmt = $pdo->prepare("select count(*) from recurring_rules where $sqlWhere");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "select id, name, kind, schedule_unit, schedule_interval, schedule_weekdays, schedule_monthday,
                schedule_start_date, is_active, next_run_at, last_run_at, created_at, updated_at
           from recurring_rules
          where $sqlWhere
          order by schedule_start_date desc, id desc
          limit :limit offset :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $rules = array_map(static fn($r) => hb_api_format_recurring_rule($r), $rows);

    hb_api_json([
        'recurring_rules' => $rules,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
    ]);
}

hb_api_json(['error' => 'Method not allowed'], 405);
} catch (PDOException $e) {
    error_log('API Error (recurring_rules.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
} catch (Exception $e) {
    error_log('API Error (recurring_rules.php): ' . $e->getMessage());
    hb_api_json(['error' => 'An error occurred. Please try again.'], 500);
}
