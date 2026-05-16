<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hb_api_json(['error' => 'Method not allowed'], 405);
}
$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
hb_api_require_scope($auth, 'receipts:write');
$householdId = hb_api_household_id($auth);

function hb_receipt_store_attachment(PDO $pdo, int $householdId, string $binary, string $originalName): array
{
    if ($binary === '') {
        hb_api_json(['error' => 'Empty receipt payload'], 400);
    }
    if (strlen($binary) > 15 * 1024 * 1024) {
        hb_api_json(['error' => 'Receipt file too large (max 15MB)'], 400);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($binary) ?: 'application/octet-stream';
    $extByMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'application/pdf' => 'pdf',
    ];
    $ext = $extByMime[$mime] ?? strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^A-Za-z0-9]/', '', (string)$ext);
    $stored = bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
    $dir = hb_ensure_upload_dir($householdId);
    $target = $dir . '/' . $stored;
    if (file_put_contents($target, $binary) === false) {
        throw new RuntimeException('Receipt file could not be saved.');
    }
    @chmod($target, 0640);
    $relPath = $householdId . '/' . $stored;

    $ins = $pdo->prepare(
        'insert into attachments
            (household_id, transaction_id, original_filename, stored_filename, mime_type, size_bytes, storage_path)
         values
            (:hid, null, :orig, :stored, :mime, :size, :path)
         returning id'
    );
    $ins->execute([
        'hid' => $householdId,
        'orig' => $originalName !== '' ? $originalName : 'receipt-upload.' . ($ext !== '' ? $ext : 'bin'),
        'stored' => $stored,
        'mime' => $mime,
        'size' => strlen($binary),
        'path' => $relPath,
    ]);
    $attachmentId = (int)$ins->fetchColumn();

    return [
        'attachment_id' => $attachmentId,
        'attachment_path' => $relPath,
        'mime_type' => $mime,
        'size_bytes' => strlen($binary),
    ];
}

$idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
$requestBody = file_get_contents('php://input');
if ($idempotencyKey) {
    $requestHash = hash('sha256', $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $requestBody);
    $cached = hb_api_idempotency_check($pdo, $auth, $idempotencyKey, $requestHash);
    if ($cached) {
        hb_api_send_cached_idempotent($cached);
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
$attachmentMeta = null;
$imageBase64 = trim((string)($jsonData['image_base64'] ?? $_POST['image_base64'] ?? ''));
if ($imageBase64 !== '') {
    $decodedImage = base64_decode($imageBase64, true);
    if ($decodedImage === false) {
        hb_api_json(['error' => 'image_base64 is invalid'], 400);
    }
    $attachmentMeta = hb_receipt_store_attachment($pdo, $householdId, $decodedImage, 'receipt-from-base64');
    $rawText = '';
}
if (!empty($_FILES['file']['tmp_name'])) {
    if ((int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        hb_api_json(['error' => 'File upload failed'], 400);
    }
    $uploaded = file_get_contents((string)$_FILES['file']['tmp_name']);
    if ($uploaded === false) {
        hb_api_json(['error' => 'Uploaded file could not be read'], 400);
    }
    $attachmentMeta = hb_receipt_store_attachment(
        $pdo,
        $householdId,
        $uploaded,
        (string)($_FILES['file']['name'] ?? 'receipt-upload')
    );
    $rawText = '';
}
if ($attachmentMeta === null) {
    hb_api_json(['error' => 'No receipt image provided'], 400);
}

$responseData = [
    'amount' => null,
    'date' => null,
    'payee' => null,
    'suggested_category' => null,
    'raw_text' => $rawText,
    'attachment_id' => (int)$attachmentMeta['attachment_id'],
    'attachment_path' => (string)$attachmentMeta['attachment_path'],
    'mime_type' => (string)$attachmentMeta['mime_type'],
    'size_bytes' => (int)$attachmentMeta['size_bytes'],
];
if ($idempotencyKey) {
    hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 200);
}
hb_api_json($responseData);
