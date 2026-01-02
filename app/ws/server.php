<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Workerman\Worker;
use Workerman\Lib\Timer;

$wsUrl = getenv('HB_WS_BIND') ?: 'websocket://0.0.0.0:8081';
$dbDsn = getenv('HB_DB_DSN') ?: '';
$dbUser = getenv('HB_DB_USER') ?: '';
$dbPass = getenv('HB_DB_PASS') ?: '';
$secret = getenv('HB_WS_SECRET') ?: '';

$pgListen = hb_ws_pg_connect($dbDsn, $dbUser, $dbPass);
$pgWrite = hb_ws_pg_connect($dbDsn, $dbUser, $dbPass);
if (!$pgListen || !$pgWrite) {
    fwrite(STDERR, "Postgres connection failed\n");
    exit(1);
}
pg_query($pgListen, 'listen hb_audit');

$worker = new Worker($wsUrl);
$worker->count = 1;
$clients = [];
$userCache = [];

$worker->onConnect = function ($connection): void {
    $connection->authed = false;
};

$worker->onMessage = function ($connection, string $payload) use (&$clients, $pgWrite, $secret): void {
    $data = json_decode($payload, true);
    if (!is_array($data) || empty($data['type'])) {
        return;
    }
    if ($data['type'] === 'hello') {
        $token = (string)($data['token'] ?? '');
        $claims = hb_ws_verify_token($token, $secret);
        if (!$claims) {
            $connection->close();
            return;
        }
        $connection->authed = true;
        $connection->user_id = (int)$claims['uid'];
        $connection->username = (string)$claims['uname'];
        $connection->household_id = (int)$claims['hid'];
        $connection->color_hex = null;
        $res = pg_query_params($pgWrite, 'select username, color_hex from users where id = $1', [$connection->user_id]);
        if ($res && ($row = pg_fetch_assoc($res))) {
            $connection->username = (string)($row['username'] ?? $connection->username);
            $connection->color_hex = $row['color_hex'] ?? null;
        }
        $clients[$connection->id] = $connection;
        return;
    }

    if ($data['type'] === 'chat' && $connection->authed) {
        $message = trim((string)($data['message'] ?? ''));
        if ($message === '') {
            return;
        }
        $result = pg_query_params(
            $pgWrite,
            'insert into chat_messages (household_id, user_id, message) values ($1, $2, $3) returning created_at',
            [$connection->household_id, $connection->user_id, $message]
        );
        $createdAt = null;
        if ($result) {
            $row = pg_fetch_assoc($result);
            $createdAt = $row['created_at'] ?? null;
        }
        hb_ws_broadcast($clients, $connection->household_id, [
            'type' => 'chat',
            'username' => $connection->username,
            'color' => $connection->color_hex,
            'message' => $message,
            'timestamp' => $createdAt ?: gmdate('c'),
        ]);
    }
};

$worker->onClose = function ($connection) use (&$clients): void {
    unset($clients[$connection->id]);
};

Timer::add(1, function () use (&$clients, $pgListen, $pgWrite, &$userCache): void {
    if (!pg_consume_input($pgListen)) {
        return;
    }
    while ($notify = pg_get_notify($pgListen, PGSQL_ASSOC)) {
        $payload = json_decode($notify['payload'] ?? '', true);
        if (!is_array($payload)) {
            continue;
        }
        $householdId = (int)($payload['household_id'] ?? 0);
        if (!empty($payload['user_id'])) {
            $uid = (int)$payload['user_id'];
            if (!isset($userCache[$uid])) {
                $res = pg_query_params($pgWrite, 'select username, color_hex from users where id = $1', [$uid]);
                if ($res && ($row = pg_fetch_assoc($res))) {
                    $userCache[$uid] = [
                        'username' => $row['username'] ?? 'System',
                        'color_hex' => $row['color_hex'] ?? null,
                    ];
                } else {
                    $userCache[$uid] = [
                        'username' => 'System',
                        'color_hex' => null,
                    ];
                }
            }
            if (empty($payload['username'])) {
                $payload['username'] = $userCache[$uid]['username'];
            }
            $payload['color'] = $userCache[$uid]['color_hex'];
        }
        $details = hb_ws_build_audit_details($pgWrite, $payload);
        hb_ws_broadcast($clients, $householdId, [
            'type' => 'audit',
            'username' => $payload['username'] ?? null,
            'color' => $payload['color'] ?? null,
            'action' => $payload['action'] ?? '',
            'table' => $payload['table'] ?? '',
            'entity_id' => $payload['entity_id'] ?? '',
            'timestamp' => $payload['timestamp'] ?? gmdate('c'),
            'label' => $details['label'] ?? '',
            'title' => $details['title'] ?? '',
            'action_label' => $details['action_label'] ?? '',
            'url' => $details['url'] ?? '',
            'important' => $details['important'] ?? false,
        ]);
    }
});

Worker::runAll();

function hb_ws_pg_connect(string $dsn, string $user, string $pass)
{
    $parts = hb_ws_parse_dsn($dsn);
    if (!$parts) {
        return null;
    }
    $connString = sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s',
        $parts['host'],
        $parts['port'],
        $parts['dbname'],
        $user,
        $pass
    );
    return pg_connect($connString);
}

function hb_ws_parse_dsn(string $dsn): ?array
{
    if (!str_starts_with($dsn, 'pgsql:')) {
        return null;
    }
    $dsn = substr($dsn, 6);
    $parts = [];
    foreach (explode(';', $dsn) as $segment) {
        if ($segment === '') {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $segment, 2), 2, null);
        if ($key && $value) {
            $parts[$key] = $value;
        }
    }
    if (empty($parts['host']) || empty($parts['port']) || empty($parts['dbname'])) {
        return null;
    }
    return $parts;
}

function hb_ws_verify_token(string $token, string $secret): ?array
{
    if ($token === '' || $secret === '' || !str_contains($token, '.')) {
        return null;
    }
    [$payload, $sig] = explode('.', $token, 2);
    if (!hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) {
        return null;
    }
    $json = base64_decode(strtr($payload, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return null;
    }
    if (($data['exp'] ?? 0) < time()) {
        return null;
    }
    if (!isset($data['uid'], $data['uname'], $data['hid'])) {
        return null;
    }
    return $data;
}

function hb_ws_broadcast(array $clients, int $householdId, array $payload): void
{
    foreach ($clients as $client) {
        if (!$client->authed || (int)$client->household_id !== $householdId) {
            continue;
        }
        $client->send(json_encode($payload));
    }
}

function hb_ws_build_audit_details($pgWrite, array $payload): array
{
    $tableLabels = [
        'users' => 'Users',
        'households' => 'Households',
        'household_members' => 'Members',
        'accounts' => 'Accounts',
        'transactions' => 'Transactions',
        'transaction_splits' => 'Splits',
        'transaction_tags' => 'Transaction tags',
        'categories' => 'Categories',
        'tags' => 'Tags',
        'payees' => 'Payees',
        'payee_mappings' => 'Payee mapping',
        'recurring_payments' => 'Recurring payments',
        'planned_payments' => 'Monthly plan',
        'open_cases' => 'Open cases',
        'month_closures' => 'Month close',
        'attachments' => 'Attachments',
        'imports' => 'Imports',
    ];
    $actionLabels = [
        'insert' => 'Created',
        'update' => 'Updated',
        'delete' => 'Deleted',
    ];
    $tableRoutes = [
        'transactions' => '/transactions.php?action=show&id=',
        'planned_payments' => '/plan.php',
        'recurring_payments' => '/recurring.php',
        'open_bookings' => '/open_bookings.php',
        'open_cases' => '/open_cases.php',
        'categories' => '/categories.php',
        'tags' => '/tags.php',
        'payees' => '/payees.php',
        'payee_mappings' => '/payee_mapping.php',
        'accounts' => '/accounts.php',
        'imports' => '/import.php',
        'month_closures' => '/month_close.php',
    ];
    $importantTables = ['imports' => true, 'month_closures' => true];
    $table = (string)($payload['table'] ?? '');
    $action = (string)($payload['action'] ?? '');
    $entityId = trim((string)($payload['entity_id'] ?? ''));
    $title = '';
    if ($entityId !== '') {
        $title = hb_ws_lookup_title($pgWrite, $table, $entityId);
    }
    $label = $tableLabels[$table] ?? $table;
    $route = $tableRoutes[$table] ?? '';
    $url = '';
    if ($route !== '') {
        $url = $table === 'transactions' && $entityId !== '' ? $route . urlencode($entityId) : $route;
    }
    return [
        'label' => $label,
        'title' => hb_ws_truncate($title, 52),
        'action_label' => $actionLabels[$action] ?? $action,
        'url' => $url,
        'important' => isset($importantTables[$table]),
    ];
}

function hb_ws_truncate(string $value, int $max = 48): string
{
    $value = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($value === '' || $length <= $max) {
        return $value;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max - 1) . '…';
    }
    return substr($value, 0, $max - 1) . '…';
}

function hb_ws_lookup_title($pgWrite, string $table, string $entityId): string
{
    $queries = [
        'transactions' => 'select counterparty_name, note from transactions where id = $1',
        'planned_payments' => 'select name from planned_payments where id = $1',
        'recurring_payments' => 'select name from recurring_payments where id = $1',
        'open_cases' => 'select title from open_cases where id = $1',
        'open_bookings' => 'select counterparty_name, note from open_bookings where id = $1',
        'categories' => 'select name from categories where id = $1',
        'tags' => 'select name from tags where id = $1',
        'payees' => 'select name from payees where id = $1',
        'payee_mappings' => 'select counterparty_name from payee_mappings where id = $1',
        'accounts' => 'select name from accounts where id = $1',
    ];
    if (!isset($queries[$table])) {
        return '';
    }
    $res = pg_query_params($pgWrite, $queries[$table], [$entityId]);
    if (!$res || !($row = pg_fetch_assoc($res))) {
        return '';
    }
    foreach (['name', 'title', 'counterparty_name', 'note'] as $field) {
        if (!empty($row[$field])) {
            return (string)$row[$field];
        }
    }
    return '';
}
