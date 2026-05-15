<?php
declare(strict_types=1);

// CORS handling with Origin allowlist

function hb_cors_send_headers(): void
{
    $allowed = [
        'https://chat.openai.com',
        'https://chatgpt.com',
    ];

    // Optional extra origin for local development
    $extra = getenv('HB_CORS_EXTRA_ORIGIN');
    if ($extra) {
        $allowed[] = $extra;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 86400');

    // Handle preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
