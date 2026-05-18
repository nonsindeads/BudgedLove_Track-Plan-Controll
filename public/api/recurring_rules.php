<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$db = hb_dbal_household();
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        hb_api_require_scope($auth, 'recurring:read');
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id !== null) {
            hb_api_json(['recurring_rule' => hb_api_recurring_rule_row_dbal($db, $householdId, $id)]);
        }

        $where = ['household_id = :hid'];
        $params = ['hid' => $householdId];
        $types = [];

        $active = (string)($_GET['active'] ?? '');
        if ($active !== '') {
            $where[] = 'is_active = :active';
            $params['active'] = hb_api_bool($active);
            $types['active'] = \Doctrine\DBAL\ParameterType::BOOLEAN;
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
            $where[] = 'lower(name) like lower(:q)';
            $params['q'] = '%' . $q . '%';
        }

        $limit = hb_api_limit($_GET['limit'] ?? null);
        $offset = hb_api_offset($_GET['offset'] ?? null);
        $sqlWhere = implode(' and ', $where);

        $total = (int)$db->fetchOne("select count(*) from recurring_rules where {$sqlWhere}", $params, $types);
        $rows = $db->fetchAllAssociative(
            "select id, name, kind, schedule_unit, schedule_interval, schedule_weekdays, schedule_monthday,
                    schedule_start_date, is_active, next_run_at, last_run_at, created_at, updated_at
               from recurring_rules
              where {$sqlWhere}
              order by schedule_start_date desc, id desc
              limit :limit offset :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            array_merge($types, [
                'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
                'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
            ])
        );

        hb_api_json([
            'recurring_rules' => array_map('hb_api_format_recurring_rule', $rows),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
        ]);
    }

    hb_api_json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    error_log('API Error (recurring_rules.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
}

function hb_api_recurring_rule_row_dbal(\Doctrine\DBAL\Connection $db, int $householdId, int $id): array
{
    $row = $db->fetchAssociative(
        'select id, name, kind, schedule_unit, schedule_interval, schedule_weekdays, schedule_monthday,
                schedule_start_date, is_active, next_run_at, last_run_at, created_at, updated_at
           from recurring_rules
          where household_id = :hid and id = :id',
        ['hid' => $householdId, 'id' => $id]
    );
    if (!$row) {
        hb_api_json(['error' => 'Recurring rule not found'], 404);
    }
    return hb_api_format_recurring_rule($row);
}
