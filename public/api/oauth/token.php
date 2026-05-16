<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../app/api.php';
require_once __DIR__ . '/../../../app/oauth.php';

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
    // application/x-www-form-urlencoded
    $data = $_POST;
}

$grantType = trim((string)($data['grant_type'] ?? ''));
$clientId = trim((string)($data['client_id'] ?? ''));

if (!$grantType || !$clientId) {
    hb_api_error('invalid_request', 'grant_type and client_id required', 400);
}

try {
    if ($grantType === 'authorization_code') {
        handle_authorization_code_grant($pdo, $data);
    } elseif ($grantType === 'refresh_token') {
        handle_refresh_token_grant($pdo, $data);
    } else {
        hb_api_error('unsupported_grant_type', "Grant type '$grantType' not supported", 400);
    }
} catch (Exception $e) {
    // Log but don't expose details
    error_log('OAuth token error: ' . $e->getMessage());
    hb_api_error('invalid_grant', 'Invalid grant or parameters', 400);
}

// === Grant Handlers ===

function handle_authorization_code_grant(PDO $pdo, array $data): void
{
    $code = trim((string)($data['code'] ?? ''));
    $clientId = trim((string)($data['client_id'] ?? ''));
    $redirectUri = trim((string)($data['redirect_uri'] ?? ''));
    $codeVerifier = trim((string)($data['code_verifier'] ?? ''));

    if (!$code || !$clientId || !$redirectUri || !$codeVerifier) {
        hb_api_error('invalid_request', 'Missing required parameters', 400);
    }

    // Validate client
    $client = hb_oauth_client($pdo, $clientId);
    if (!$client) {
        hb_api_error('invalid_client', 'Client not found', 400);
    }

    // Exchange code
    try {
        $codeData = hb_oauth_exchange_code($pdo, $code, $clientId, $redirectUri, $codeVerifier);
    } catch (Exception $e) {
        throw new Exception($e->getMessage(), 400);
    }

    // Issue tokens
    $tokens = hb_oauth_issue_access_token(
        $pdo,
        $codeData['user_id'],
        $clientId,
        $codeData['household_id'],
        $codeData['scopes']
    );

    // Return token response
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    http_response_code(200);
    echo json_encode([
        'access_token' => $tokens['access_token'],
        'token_type' => 'Bearer',
        'expires_in' => $tokens['expires_in'],
        'refresh_token' => $tokens['refresh_token'],
        'scope' => $codeData['scopes'],
        'request_id' => $GLOBALS['hb_request_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function handle_refresh_token_grant(PDO $pdo, array $data): void
{
    $refreshToken = trim((string)($data['refresh_token'] ?? ''));
    $clientId = trim((string)($data['client_id'] ?? ''));

    if (!$refreshToken || !$clientId) {
        hb_api_error('invalid_request', 'refresh_token and client_id required', 400);
    }

    // Validate client
    $client = hb_oauth_client($pdo, $clientId);
    if (!$client) {
        hb_api_error('invalid_client', 'Client not found', 400);
    }

    // Refresh
    try {
        $tokens = hb_oauth_refresh($pdo, $refreshToken, $clientId);
    } catch (Exception $e) {
        // Might be reuse detection
        if (str_contains($e->getMessage(), 'reuse')) {
            hb_api_error('invalid_grant', 'Refresh token reuse detected – session revoked', 400);
        }
        throw $e;
    }

    // Return new tokens
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    http_response_code(200);
    echo json_encode([
        'access_token' => $tokens['access_token'],
        'token_type' => 'Bearer',
        'expires_in' => $tokens['expires_in'],
        'refresh_token' => $tokens['refresh_token'],
        'request_id' => $GLOBALS['hb_request_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
