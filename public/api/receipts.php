<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

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
        'file_hash' => hash('sha256', $binary),
        'mime_type' => $mime,
        'size_bytes' => strlen($binary),
    ];
}

if ($method === 'GET') {
    hb_api_require_scope($auth, 'receipts:read');
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id !== null) {
        hb_api_json(['receipt' => hb_api_receipt_row($pdo, $householdId, $id)]);
    }

    $where = ['household_id = :hid'];
    $params = ['hid' => $householdId];
    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '') {
        if (!in_array($status, ['draft', 'matched', 'archived'], true)) {
            hb_api_json(['error' => 'status is invalid'], 400);
        }
        $where[] = 'status = :status';
        $params['status'] = $status;
    }
    $from = hb_api_date($_GET['date_from'] ?? null, 'date_from');
    $to = hb_api_date($_GET['date_to'] ?? null, 'date_to');
    if ($from !== null) {
        $where[] = 'receipt_date >= :date_from';
        $params['date_from'] = $from;
    }
    if ($to !== null) {
        $where[] = 'receipt_date <= :date_to';
        $params['date_to'] = $to;
    }
    $merchant = trim((string)($_GET['merchant'] ?? ''));
    if ($merchant !== '') {
        $where[] = 'merchant ilike :merchant';
        $params['merchant'] = '%' . $merchant . '%';
    }
    $limit = hb_api_limit($_GET['limit'] ?? null);
    $offset = hb_api_offset($_GET['offset'] ?? null);
    $sqlWhere = implode(' and ', $where);
    $countStmt = $pdo->prepare("select count(*) from receipts where $sqlWhere");
    $countStmt->execute($params);
    $stmt = $pdo->prepare(
        "select id, merchant, receipt_date, total_amount_cents, currency_code, file_path,
                storage_key, file_hash, mime_type, ocr_json, status, created_at, updated_at
           from receipts
          where $sqlWhere
          order by coalesce(receipt_date, created_at::date) desc, id desc
          limit :limit offset :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    hb_api_json([
        'receipts' => array_map('hb_api_format_receipt', $stmt->fetchAll()),
        'limit' => $limit,
        'offset' => $offset,
        'total' => (int)$countStmt->fetchColumn(),
    ]);
}

if ($method === 'POST') {
    hb_api_require_scope($auth, 'receipts:write');

    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $requestBody = file_get_contents('php://input') ?: '';
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
        $decoded = json_decode($requestBody, true);
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
    }

    $merchant = trim((string)($jsonData['merchant'] ?? $jsonData['payee'] ?? $_POST['merchant'] ?? ''));
    $receiptDate = hb_api_date($jsonData['receipt_date'] ?? $jsonData['date'] ?? $_POST['receipt_date'] ?? null, 'receipt_date');
    $totalAmountCents = hb_api_amount_cents($jsonData['total_amount'] ?? $jsonData['amount'] ?? $_POST['total_amount'] ?? null, 'total_amount', false);
    $currencyCode = strtoupper(trim((string)($jsonData['currency_code'] ?? $_POST['currency_code'] ?? 'EUR')));
    if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
        hb_api_json(['error' => 'currency_code is invalid'], 400);
    }
    $status = trim((string)($jsonData['status'] ?? $_POST['status'] ?? 'draft'));
    if (!in_array($status, ['draft', 'matched', 'archived'], true)) {
        hb_api_json(['error' => 'status is invalid'], 400);
    }
    $ocrJson = null;
    if (isset($jsonData['ocr_json'])) {
        $ocrJson = json_encode($jsonData['ocr_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $ins = $pdo->prepare(
        'insert into receipts
            (household_id, merchant, receipt_date, total_amount_cents, currency_code, file_path, storage_key, file_hash, mime_type, ocr_json, status)
         values
            (:hid, :merchant, :receipt_date, :total_amount, :currency, :file_path, :storage_key, :file_hash, :mime, cast(:ocr_json as jsonb), :status)
         returning id'
    );
    $ins->execute([
        'hid' => $householdId,
        'merchant' => $merchant !== '' ? $merchant : null,
        'receipt_date' => $receiptDate,
        'total_amount' => $totalAmountCents,
        'currency' => $currencyCode,
        'file_path' => $attachmentMeta['attachment_path'] ?? null,
        'storage_key' => $attachmentMeta['attachment_path'] ?? null,
        'file_hash' => $attachmentMeta['file_hash'] ?? null,
        'mime' => $attachmentMeta['mime_type'] ?? null,
        'ocr_json' => $ocrJson,
        'status' => $status,
    ]);
    $receiptId = (int)$ins->fetchColumn();
    if ($attachmentMeta !== null) {
        $updAttachment = $pdo->prepare('update attachments set receipt_id = :receipt_id where household_id = :hid and id = :id');
        $updAttachment->execute([
            'receipt_id' => $receiptId,
            'hid' => $householdId,
            'id' => (int)$attachmentMeta['attachment_id'],
        ]);
    }

    $responseData = [
        'amount' => $totalAmountCents !== null ? $totalAmountCents / 100 : null,
        'date' => $receiptDate,
        'payee' => $merchant !== '' ? $merchant : null,
        'suggested_category' => null,
        'raw_text' => $rawText,
        'receipt' => hb_api_receipt_row($pdo, $householdId, $receiptId, false),
        'receipt_id' => $receiptId,
        'attachment_id' => $attachmentMeta !== null ? (int)$attachmentMeta['attachment_id'] : null,
        'attachment_path' => $attachmentMeta !== null ? (string)$attachmentMeta['attachment_path'] : null,
        'mime_type' => $attachmentMeta !== null ? (string)$attachmentMeta['mime_type'] : null,
        'size_bytes' => $attachmentMeta !== null ? (int)$attachmentMeta['size_bytes'] : null,
    ];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 201);
    }
    hb_api_json($responseData, 201);
}

if ($method === 'PATCH') {
    hb_api_require_scope($auth, 'receipts:write');
    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $requestBody = file_get_contents('php://input') ?: '';
    if ($idempotencyKey) {
        $requestHash = hash('sha256', $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $requestBody);
        $cached = hb_api_idempotency_check($pdo, $auth, $idempotencyKey, $requestHash);
        if ($cached) {
            hb_api_send_cached_idempotent($cached);
        }
    }
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }
    hb_api_receipt_row($pdo, $householdId, $id, false);
    $data = json_decode($requestBody, true);
    if (!is_array($data)) {
        hb_api_json(['error' => 'Invalid JSON'], 400);
    }
    $sets = [];
    $params = ['hid' => $householdId, 'id' => $id];
    if (array_key_exists('merchant', $data)) {
        $sets[] = 'merchant = :merchant';
        $params['merchant'] = trim((string)$data['merchant']) ?: null;
    }
    if (array_key_exists('receipt_date', $data) || array_key_exists('date', $data)) {
        $sets[] = 'receipt_date = :receipt_date';
        $params['receipt_date'] = hb_api_date($data['receipt_date'] ?? $data['date'] ?? null, 'receipt_date');
    }
    if (array_key_exists('total_amount', $data) || array_key_exists('amount', $data)) {
        $sets[] = 'total_amount_cents = :total_amount';
        $params['total_amount'] = hb_api_amount_cents($data['total_amount'] ?? $data['amount'] ?? null, 'total_amount', false);
    }
    if (array_key_exists('status', $data)) {
        $status = (string)$data['status'];
        if (!in_array($status, ['draft', 'matched', 'archived'], true)) {
            hb_api_json(['error' => 'status is invalid'], 400);
        }
        $sets[] = 'status = :status';
        $params['status'] = $status;
    }
    if (array_key_exists('ocr_json', $data)) {
        $sets[] = 'ocr_json = cast(:ocr_json as jsonb)';
        $params['ocr_json'] = json_encode($data['ocr_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (!$sets) {
        hb_api_json(['error' => 'No fields to update'], 400);
    }
    $sql = 'update receipts set ' . implode(', ', $sets) . ', updated_at = now() where household_id = :hid and id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $responseData = ['receipt' => hb_api_receipt_row($pdo, $householdId, $id, false)];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 200);
    }
    hb_api_json($responseData);
}

if ($method === 'DELETE') {
    hb_api_require_scope($auth, 'receipts:write');
    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $requestBody = file_get_contents('php://input') ?: '';
    if ($idempotencyKey) {
        $requestHash = hash('sha256', $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $requestBody);
        $cached = hb_api_idempotency_check($pdo, $auth, $idempotencyKey, $requestHash);
        if ($cached) {
            hb_api_send_cached_idempotent($cached);
        }
    }
    $id = hb_api_int_or_null($_GET['id'] ?? null);
    if ($id === null) {
        hb_api_json(['error' => 'id is required'], 400);
    }
    hb_api_receipt_row($pdo, $householdId, $id, false);
    $stmt = $pdo->prepare('update receipts set status = :archived, updated_at = now() where household_id = :hid and id = :id');
    $stmt->execute(['archived' => 'archived', 'hid' => $householdId, 'id' => $id]);
    $responseData = ['deleted' => true, 'receipt' => hb_api_receipt_row($pdo, $householdId, $id, false)];
    if ($idempotencyKey) {
        hb_api_idempotency_store($pdo, $auth, $idempotencyKey, $requestHash, json_encode($responseData), 200);
    }
    hb_api_json($responseData);
}

hb_api_json(['error' => 'Method not allowed'], 405);
