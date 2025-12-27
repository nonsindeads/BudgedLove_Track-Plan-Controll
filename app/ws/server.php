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
            'message' => $message,
            'timestamp' => $createdAt ?: gmdate('c'),
        ]);
    }
};

$worker->onClose = function ($connection) use (&$clients): void {
    unset($clients[$connection->id]);
};

Timer::add(1, function () use (&$clients, $pgListen): void {
    if (!pg_consume_input($pgListen)) {
        return;
    }
    while ($notify = pg_get_notify($pgListen, PGSQL_ASSOC)) {
        $payload = json_decode($notify['payload'] ?? '', true);
        if (!is_array($payload)) {
            continue;
        }
        $householdId = (int)($payload['household_id'] ?? 0);
        $message = hb_ws_format_audit_message($payload);
        hb_ws_broadcast($clients, $householdId, [
            'type' => 'audit',
            'username' => $payload['username'] ?? null,
            'action' => $payload['action'] ?? '',
            'table' => $payload['table'] ?? '',
            'entity_id' => $payload['entity_id'] ?? '',
            'timestamp' => $payload['timestamp'] ?? gmdate('c'),
            'message' => $message,
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

function hb_ws_format_audit_message(array $payload): string
{
    if (!empty($payload['message'])) {
        return (string)$payload['message'];
    }
    $action = $payload['action'] ?? '';
    $table = $payload['table'] ?? '';
    $entity = $payload['entity_id'] ?? '';
    $label = trim($table . ' ' . $entity);
    return trim($action . ' ' . $label);
}
