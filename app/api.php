<?php
declare(strict_types=1);

require_once __DIR__ . '/domain.php';

function hb_api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hb_api_token_plain(): string
{
    return bin2hex(random_bytes(32));
}

function hb_api_token_hash(string $plain): string
{
    return hash('sha256', $plain);
}

function hb_api_require_token(PDO $pdo): array
{
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        hb_api_json(['error' => 'Unauthorized'], 401);
    }
    $plain = trim((string)$m[1]);
    if ($plain === '') {
        hb_api_json(['error' => 'Unauthorized'], 401);
    }
    $hash = hb_api_token_hash($plain);
    $stmt = $pdo->prepare(
        'select t.id as token_id, t.user_id, u.username, hm.household_id
           from api_tokens t
           join users u on u.id = t.user_id
      left join household_members hm on hm.user_id = u.id and hm.is_active = true
          where t.token_hash = :hash
          order by hm.household_id asc nulls last
          limit 1'
    );
    $stmt->execute(['hash' => $hash]);
    $row = $stmt->fetch();
    if (!$row) {
        hb_api_json(['error' => 'Unauthorized'], 401);
    }
    $touch = $pdo->prepare('update api_tokens set last_used_at = now() where id = :id');
    $touch->execute(['id' => (int)$row['token_id']]);
    return $row;
}
