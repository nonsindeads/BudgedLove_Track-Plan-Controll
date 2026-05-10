<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = (int)($auth['household_id'] ?? 0);
if ($householdId < 1) {
    hb_api_json(['error' => 'No household linked to token user'], 400);
}

$catStmt = $pdo->prepare('select id, name from categories where household_id = :hid and is_active = true order by name asc');
$catStmt->execute(['hid' => $householdId]);
$categories = $catStmt->fetchAll();

$accStmt = $pdo->prepare('select id, name, opening_balance_cents from accounts where household_id = :hid and is_archived = false order by name asc');
$accStmt->execute(['hid' => $householdId]);
$accounts = $accStmt->fetchAll();

$today = new DateTimeImmutable('today');
hb_api_json([
    'month' => $today->format('m'),
    'year' => $today->format('Y'),
    'categories' => array_map(static fn($c) => ['id' => (int)$c['id'], 'name' => (string)$c['name']], $categories),
    'accounts' => array_map(static fn($a) => [
        'id' => (int)$a['id'],
        'name' => (string)$a['name'],
        'current_balance_cents' => (int)$a['opening_balance_cents'],
    ], $accounts),
]);
