<?php
declare(strict_types=1);

function hb_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if ($proto === 'https') {
        return true;
    }
    $baseUrl = getenv('APP_BASE_URL') ?: '';
    return str_starts_with($baseUrl, 'https://');
}

function hb_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

function hb_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    $secure = hb_is_https();
    ini_set('session.cookie_secure', $secure ? '1' : '0');

    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function hb_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function hb_csrf_field(): string
{
    $token = hb_csrf_token();
    if ($token === '') {
        return '';
    }
    $escaped = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $escaped . '">';
}

function hb_require_csrf(): void
{
    if (php_sapi_name() === 'cli') {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        http_response_code(403);
        echo 'Invalid request.';
        exit;
    }
    $token = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        echo 'Invalid request.';
        exit;
    }
}

function hb_request_ip(): ?string
{
    $trustProxy = getenv('HB_TRUST_PROXY') === '1';
    if ($trustProxy) {
        $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            if (!empty($parts[0])) {
                return $parts[0];
            }
        }
        $realIp = (string)($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if ($realIp !== '') {
            return $realIp;
        }
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : null;
}

function hb_rate_limit_allow(PDO $pdo, string $action, int $limit, int $windowSeconds): bool
{
    $ip = hb_request_ip();
    if ($ip === null) {
        return true;
    }
    $cutoff = (new DateTimeImmutable())->modify('-' . $windowSeconds . ' seconds')->format('c');
    $cleanup = $pdo->prepare('delete from rate_limits where action = :action and created_at < :cutoff');
    $cleanup->execute(['action' => $action, 'cutoff' => $cutoff]);

    $stmt = $pdo->prepare(
        'select count(*) from rate_limits where action = :action and ip = :ip and created_at >= :cutoff'
    );
    $stmt->execute(['action' => $action, 'ip' => $ip, 'cutoff' => $cutoff]);
    $count = (int)$stmt->fetchColumn();
    return $count < $limit;
}

function hb_rate_limit_record(PDO $pdo, string $action): void
{
    $ip = hb_request_ip();
    if ($ip === null) {
        return;
    }
    $stmt = $pdo->prepare('insert into rate_limits (ip, action) values (:ip, :action)');
    $stmt->execute(['ip' => $ip, 'action' => $action]);
}
