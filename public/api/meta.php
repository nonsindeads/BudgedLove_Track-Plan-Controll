<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$db = hb_dbal_household();
$auth = hb_api_require_token($pdo);
hb_api_require_scope($auth, 'categories:read');
$householdId = hb_api_household_id($auth);

try {
    $categories = $db->fetchAllAssociative(
        'select id, name from categories where household_id = :hid and is_active = :active order by name asc',
        ['hid' => $householdId, 'active' => true],
        ['active' => \Doctrine\DBAL\ParameterType::BOOLEAN]
    );
    $accounts = $db->fetchAllAssociative(
        'select id, name, opening_balance_cents from accounts where household_id = :hid and is_archived = :archived order by name asc',
        ['hid' => $householdId, 'archived' => false],
        ['archived' => \Doctrine\DBAL\ParameterType::BOOLEAN]
    );
    $payees = $db->fetchAllAssociative(
        'select id, name from payees where household_id = :hid order by name asc',
        ['hid' => $householdId]
    );
    $tags = $db->fetchAllAssociative(
        'select id, name, color from tags where household_id = :hid and is_active = :active order by name asc',
        ['hid' => $householdId, 'active' => true],
        ['active' => \Doctrine\DBAL\ParameterType::BOOLEAN]
    );

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
        'payees' => array_map(static fn($p) => ['id' => (int)$p['id'], 'name' => (string)$p['name']], $payees),
        'tags' => array_map(static fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['name'], 'color' => $t['color']], $tags),
    ]);
} catch (Throwable $e) {
    error_log('API Error (meta.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
}
