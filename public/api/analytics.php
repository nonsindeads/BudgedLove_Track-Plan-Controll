<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $endpoint = trim((string)($_GET['endpoint'] ?? ''));

    if ($endpoint === 'month_summary' || $endpoint === '') {
        $month = (int)($_GET['month'] ?? (int)(new DateTime())->format('m'));
        $year = (int)($_GET['year'] ?? (int)(new DateTime())->format('Y'));

        if ($month < 1 || $month > 12) {
            hb_api_json(['error' => 'month must be 1-12'], 400);
        }

        $stmt = $pdo->prepare(
            "select type, sum(amount_cents) as total_cents
               from transactions
              where household_id = :hid
                and extract(month from booking_date) = :month
                and extract(year from booking_date) = :year
              group by type"
        );
        $stmt->execute(['hid' => $householdId, 'month' => $month, 'year' => $year]);
        $summary = [];
        foreach ($stmt->fetchAll() as $row) {
            $summary[(string)$row['type']] = [
                'amount_cents' => (int)($row['total_cents'] ?? 0),
                'amount' => ((int)($row['total_cents'] ?? 0)) / 100,
            ];
        }

        $catStmt = $pdo->prepare(
            "select c.name, sum(t.amount_cents) as total_cents
               from transactions t
               join categories c on c.id = t.category_id
              where t.household_id = :hid
                and t.type = 'expense'
                and extract(month from t.booking_date) = :month
                and extract(year from t.booking_date) = :year
              group by c.name
              order by total_cents desc"
        );
        $catStmt->execute(['hid' => $householdId, 'month' => $month, 'year' => $year]);
        $byCategory = array_map(static fn($c) => [
            'category' => (string)$c['name'],
            'amount_cents' => (int)$c['total_cents'],
            'amount' => ((int)$c['total_cents']) / 100,
        ], $catStmt->fetchAll());

        hb_api_json([
            'period' => sprintf('%d-%02d', $year, $month),
            'summary' => $summary,
            'by_category' => $byCategory,
        ]);
    }

    if ($endpoint === 'open_planned') {
        $stmt = $pdo->prepare(
            "select direction, sum(amount_cents) as total_cents, count(*) as count
               from planned_payments
              where household_id = :hid and status = 'open'
              group by direction"
        );
        $stmt->execute(['hid' => $householdId]);
        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(string)$row['direction']] = [
                'amount_cents' => (int)$row['total_cents'],
                'amount' => ((int)$row['total_cents']) / 100,
                'count' => (int)$row['count'],
            ];
        }

        hb_api_json(['open_planned' => $totals]);
    }

    if ($endpoint === 'duplicate_candidates') {
        $days = (int)($_GET['days'] ?? 7);
        if ($days < 1 || $days > 90) {
            hb_api_json(['error' => 'days must be 1-90'], 400);
        }

        $stmt = $pdo->prepare(
            "select t1.id as id1, t2.id as id2, t1.amount_cents, t1.booking_date as date1, t2.booking_date as date2,
                    t1.payee_id, p.name as payee_name
               from transactions t1
               join transactions t2 on t2.household_id = t1.household_id
                and t1.id < t2.id
                and t1.amount_cents = t2.amount_cents
                and abs(extract(day from (t1.booking_date - t2.booking_date))) <= :days
              left join payees p on p.id = t1.payee_id
              where t1.household_id = :hid
              order by t1.booking_date desc, t1.id
              limit 50"
        );
        $stmt->execute(['hid' => $householdId, 'days' => $days]);
        $candidates = array_map(static fn($c) => [
            'id1' => (int)$c['id1'],
            'id2' => (int)$c['id2'],
            'amount_cents' => (int)$c['amount_cents'],
            'amount' => ((int)$c['amount_cents']) / 100,
            'date1' => (string)$c['date1'],
            'date2' => (string)$c['date2'],
            'payee' => $c['payee_name'] ?? 'Unknown',
        ], $stmt->fetchAll());

        hb_api_json(['duplicate_candidates' => $candidates]);
    }

    hb_api_json(['error' => 'endpoint is invalid or missing'], 400);
}

hb_api_json(['error' => 'Method not allowed'], 405);
