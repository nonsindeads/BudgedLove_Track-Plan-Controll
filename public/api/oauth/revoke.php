<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';
require_once __DIR__ . '/../../app/oauth.php';

hb_cors_send_headers();

$pdo = hb_get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    hb_api_error('method_not_allowed', 'Only POST allowed', 405);
}

// Parse request body (form-encoded or JSON)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $data = hb_api_read_json();
} else {
    $data = $_POST;
}

$token = trim((string)($data['token'] ?? ''));
$clientId = trim((string)($data['client_id'] ?? ''));
$tokenTypeHint = trim((string)($data['token_type_hint'] ?? ''));

if (!$token || !$clientId) {
    // Per RFC 7009: still return 200 even on invalid requests
    http_response_code(200);
    exit;
}

// Validate client exists (optional per spec, but good practice)
$client = hb_oauth_client($pdo, $clientId);
if (!$client) {
    // Still 200 per RFC
    http_response_code(200);
    exit;
}

try {
    hb_oauth_revoke_token($pdo, $token, $clientId);
} catch (Exception $e) {
    error_log('Token revocation error: ' . $e->getMessage());
}

// Always return 200 per RFC 7009
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['request_id' => $GLOBALS['hb_request_id'] ?? null]);
