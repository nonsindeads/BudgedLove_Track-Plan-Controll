<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$db = hb_dbal_household();
$householdId = hb_api_household_id($auth);
$method = $_SERVER['REQUEST_METHOD'];

function hb_receipt_store_attachment(\Doctrine\DBAL\Connection $db, int $householdId, string $binary, string $originalName): array
{
    if ($binary === '') {
        hb_api_json(['error' => 'Empty receipt payload'], 400);
    }
    if (strlen($binary) > 15 * 1024 * 1024) {
        hb_api_json(['error' => 'Receipt file too large (max 15MB)'], 400);
    }

    $storedMeta = hb_attachment_store_binary(hb_get_pdo(), $householdId, $binary, $originalName);

    $attachmentId = hb_dbal_insert_and_get_id($db, 'attachments', [
        'household_id' => $householdId,
        'transaction_id' => null,
        'original_filename' => $originalName !== '' ? $originalName : 'receipt-upload',
        'stored_filename' => $storedMeta['stored_filename'],
        'mime_type' => $storedMeta['mime_type'],
        'size_bytes' => $storedMeta['size_bytes'],
        'storage_path' => $storedMeta['storage_path'],
    ]);

    return [
        'attachment_id' => $attachmentId,
        'attachment_path' => $storedMeta['storage_path'],
        'file_hash' => hash('sha256', $binary),
        'mime_type' => $storedMeta['mime_type'],
        'size_bytes' => $storedMeta['size_bytes'],
    ];
}

try {
    if ($method === 'GET') {
        hb_api_require_scope($auth, 'receipts:read');
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id !== null) {
            hb_api_json(['receipt' => hb_api_receipt_row_dbal($db, $householdId, $id)]);
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
            $where[] = 'lower(merchant) like lower(:merchant)';
            $params['merchant'] = '%' . $merchant . '%';
        }

        $limit = hb_api_limit($_GET['limit'] ?? null);
        $offset = hb_api_offset($_GET['offset'] ?? null);
        $sqlWhere = implode(' and ', $where);
        $total = (int)$db->fetchOne("select count(*) from receipts where {$sqlWhere}", $params);
        $rows = $db->fetchAllAssociative(
            "select id, merchant, receipt_date, total_amount_cents, currency_code, file_path,
                    storage_key, file_hash, mime_type, ocr_json, status, created_at, updated_at
               from receipts
              where {$sqlWhere}
              order by case when receipt_date is null then 1 else 0 end asc, receipt_date desc, created_at desc, id desc
              limit :limit offset :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
        hb_api_json([
            'receipts' => array_map('hb_api_format_receipt', $rows),
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
        ]);
    }

    if ($method === 'POST') {
        hb_api_require_scope($auth, 'receipts:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);

        $requestBody = file_get_contents('php://input') ?: '';
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
            $attachmentMeta = hb_receipt_store_attachment($db, $householdId, $decodedImage, 'receipt-from-base64');
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
                $db,
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

        $receiptId = hb_dbal_insert_and_get_id($db, 'receipts', [
            'household_id' => $householdId,
            'merchant' => $merchant !== '' ? $merchant : null,
            'receipt_date' => $receiptDate,
            'total_amount_cents' => $totalAmountCents,
            'currency_code' => $currencyCode,
            'file_path' => $attachmentMeta['attachment_path'] ?? null,
            'storage_key' => $attachmentMeta['attachment_path'] ?? null,
            'file_hash' => $attachmentMeta['file_hash'] ?? null,
            'mime_type' => $attachmentMeta['mime_type'] ?? null,
            'ocr_json' => $ocrJson,
            'status' => $status,
        ]);
        if ($attachmentMeta !== null) {
            $db->update(
                'attachments',
                ['receipt_id' => $receiptId],
                ['household_id' => $householdId, 'id' => (int)$attachmentMeta['attachment_id']]
            );
        }

        $responseData = [
            'amount' => $totalAmountCents !== null ? $totalAmountCents / 100 : null,
            'date' => $receiptDate,
            'payee' => $merchant !== '' ? $merchant : null,
            'suggested_category' => null,
            'raw_text' => $rawText,
            'receipt' => hb_api_receipt_row_dbal($db, $householdId, $receiptId, false),
            'receipt_id' => $receiptId,
            'attachment_id' => $attachmentMeta !== null ? (int)$attachmentMeta['attachment_id'] : null,
            'attachment_path' => $attachmentMeta !== null ? (string)$attachmentMeta['attachment_path'] : null,
            'mime_type' => $attachmentMeta !== null ? (string)$attachmentMeta['mime_type'] : null,
            'size_bytes' => $attachmentMeta !== null ? (int)$attachmentMeta['size_bytes'] : null,
        ];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData, 201);
        hb_api_json($responseData, 201);
    }

    if ($method === 'PATCH') {
        hb_api_require_scope($auth, 'receipts:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        hb_api_receipt_row_dbal($db, $householdId, $id, false);
        $data = hb_api_read_json();

        $updates = [];
        if (array_key_exists('merchant', $data)) {
            $updates['merchant'] = trim((string)$data['merchant']) ?: null;
        }
        if (array_key_exists('receipt_date', $data) || array_key_exists('date', $data)) {
            $updates['receipt_date'] = hb_api_date($data['receipt_date'] ?? $data['date'] ?? null, 'receipt_date');
        }
        if (array_key_exists('total_amount', $data) || array_key_exists('amount', $data)) {
            $updates['total_amount_cents'] = hb_api_amount_cents($data['total_amount'] ?? $data['amount'] ?? null, 'total_amount', false);
        }
        if (array_key_exists('status', $data)) {
            $status = (string)$data['status'];
            if (!in_array($status, ['draft', 'matched', 'archived'], true)) {
                hb_api_json(['error' => 'status is invalid'], 400);
            }
            $updates['status'] = $status;
        }
        if (array_key_exists('ocr_json', $data)) {
            $updates['ocr_json'] = json_encode($data['ocr_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (!$updates) {
            hb_api_json(['error' => 'No fields to update'], 400);
        }
        $updates['updated_at'] = gmdate('Y-m-d H:i:s');
        $db->update('receipts', $updates, ['household_id' => $householdId, 'id' => $id]);

        $responseData = ['receipt' => hb_api_receipt_row_dbal($db, $householdId, $id, false)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    if ($method === 'DELETE') {
        hb_api_require_scope($auth, 'receipts:write');
        [$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
        $id = hb_api_int_or_null($_GET['id'] ?? null);
        if ($id === null) {
            hb_api_json(['error' => 'id is required'], 400);
        }
        hb_api_receipt_row_dbal($db, $householdId, $id, false);
        $db->update(
            'receipts',
            ['status' => 'archived', 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['household_id' => $householdId, 'id' => $id]
        );
        $responseData = ['deleted' => true, 'receipt' => hb_api_receipt_row_dbal($db, $householdId, $id, false)];
        hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData);
        hb_api_json($responseData);
    }

    hb_api_json(['error' => 'Method not allowed'], 405);
} catch (Throwable $e) {
    error_log('API Error (receipts.php): ' . $e->getMessage());
    hb_api_json(['error' => 'Database query failed. Please try again.'], 500);
}

function hb_api_receipt_row_dbal(\Doctrine\DBAL\Connection $db, int $householdId, int $id, bool $includeGroups = true): array
{
    $row = $db->fetchAssociative(
        'select id, merchant, receipt_date, total_amount_cents, currency_code, file_path,
                storage_key, file_hash, mime_type, ocr_json, status, created_at, updated_at
           from receipts
          where household_id = :hid and id = :id',
        ['hid' => $householdId, 'id' => $id]
    );
    if (!$row) {
        hb_api_json(['error' => 'Receipt not found'], 404);
    }
    $receipt = hb_api_format_receipt($row);
    if ($includeGroups) {
        $groupIds = $db->fetchFirstColumn(
            'select id
               from transaction_groups
              where household_id = :hid and receipt_id = :receipt_id and status <> :archived
              order by booking_date desc, id desc',
            ['hid' => $householdId, 'receipt_id' => $id, 'archived' => 'archived']
        );
        $receipt['transaction_groups'] = array_map('intval', $groupIds);
    }
    return $receipt;
}
