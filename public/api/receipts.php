<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hb_api_json(['error' => 'Method not allowed'], 405);
}
$pdo = hb_get_pdo();
hb_api_require_token($pdo);

$rawText = '';
if (!empty($_POST['image_base64'])) {
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
