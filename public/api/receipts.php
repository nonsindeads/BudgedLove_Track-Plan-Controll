<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hb_api_json(['error' => 'Method not allowed'], 405);
}
$pdo = hb_get_pdo();
hb_api_require_token($pdo);

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$jsonData = [];
if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input') ?: '';
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

hb_api_json([
    'amount' => null,
    'date' => null,
    'payee' => null,
    'suggested_category' => null,
    'raw_text' => $rawText,
]);
