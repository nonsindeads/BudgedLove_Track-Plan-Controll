<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hb_api_json(['error' => 'Method not allowed'], 405);
}
$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
hb_api_require_scope($auth, 'receipts:write');

$idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
$requestBody = file_get_contents('php://input');
if ($idempotencyKey) {
    $requestHash = hash('sha256', $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $requestBody);
    $cached = hb_api_idempotency_check($pdo, $auth, $idempotencyKey, $requestHash);
    if ($cached) {
        http_response_code($cached['status_code']);
        header('Content-Type: application/json; charset=utf-8');
        echo $cached['response_body'];
        exit;
    }
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$jsonData = [];
if (str_contains($contentType, 'application/json')) {
    $raw = $requestBody ?: '';
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        hb_api_json(['error' => 'Invalid JSON'], 400);
    }
    $jsonData = $decoded;
}

$rawText = '';
$imageBase64 = trim((string)($jsonData['image_base64'] ?? $_POST['image_base64'] ?? ''));
if ($imageBase64 !== '') {
    $decodedImage = base64_decode($imageBase64, true);
    if ($decodedImage === false) {
        hb_api_json(['error' => 'image_base64 is invalid'], 400);
    }
    $rawText = '';
}
if (!empty($_FILES['file']['tmp_name'])) {
    $rawText = '';
}

$responseData = [
    'amount' => null,
    'date' => null,
    'payee' => null,
    'suggested_category' => null,
    'raw_text' => $rawText,
];
if ($idempotencyKey) {
    hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 200);
}
hb_api_json($responseData);
