<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hb_api_json(['error' => 'Method not allowed'], 405);
}

$pdo = hb_get_pdo();
$db = hb_dbal_household();
$auth = hb_api_require_token($pdo);
hb_api_require_scope($auth, 'transactions:write');
$householdId = hb_api_household_id($auth);

[$idempotencyKey, $requestHash] = hb_api_idempotency_prepare($pdo, $auth);
$data = hb_api_read_json();

$amount = isset($data['amount']) ? (float)$data['amount'] : 0.0;
$date = (string)($data['date'] ?? '');
$type = (string)($data['type'] ?? 'expense');
if (!in_array($type, ['income', 'expense'], true)) {
    hb_api_json(['error' => 'type is invalid'], 400);
}
if ($amount <= 0 || $date === '') {
    hb_api_json(['error' => 'amount and date are required'], 400);
}
$dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
    hb_api_json(['error' => 'date is invalid'], 400);
}

$accountId = hb_api_int_or_null($data['account_id'] ?? null);
hb_api_dbal_assert_account($db, $householdId, $accountId);

$categoryId = hb_api_int_or_null($data['category_id'] ?? null);
hb_api_dbal_assert_category($db, $householdId, $categoryId);

$receiptId = hb_api_int_or_null($data['receipt_id'] ?? null);
hb_api_dbal_assert_receipt($db, $householdId, $receiptId);

$payee = trim((string)($data['payee'] ?? ''));
$payeeId = hb_api_dbal_payee_id($db, $householdId, null, $payee, false);

$note = trim((string)($data['notes'] ?? ''));
$draftNote = '[receipt-draft]';
if ($note !== '') {
    $draftNote .= ' ' . $note;
}

$amountCents = (int)round($amount * 100);
$id = hb_dbal_insert_and_get_id($db, 'transactions', [
    'household_id' => $householdId,
    'type' => $type,
    'booking_date' => $date,
    'amount_cents' => $amountCents,
    'currency_code' => 'EUR',
    'account_id' => $accountId,
    'category_id' => $categoryId,
    'payee_id' => $payeeId,
    'note' => $draftNote,
    'is_reviewed' => false,
    'counterparty_name' => $payee !== '' ? $payee : null,
    'receipt_id' => $receiptId,
], 'id', ['is_reviewed' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

$attachmentId = hb_api_int_or_null($data['attachment_id'] ?? null);
if ($attachmentId !== null) {
    $exists = (int)($db->fetchOne(
        'select id
           from attachments
          where id = :id
            and household_id = :hid
            and transaction_id is null
          limit 1',
        ['id' => $attachmentId, 'hid' => $householdId]
    ) ?: 0);
    if ($exists < 1) {
        hb_api_json(['error' => 'attachment_id not found or already linked'], 400);
    }
    $db->executeStatement(
        'update attachments
            set transaction_id = :tx,
                receipt_id = coalesce(receipt_id, :receipt_id)
          where id = :id
            and household_id = :hid',
        ['tx' => $id, 'receipt_id' => $receiptId, 'id' => $attachmentId, 'hid' => $householdId]
    );
}

$tagIds = hb_normalize_id_list(is_array($data['tag_ids'] ?? null) ? $data['tag_ids'] : []);
if ($tagIds) {
    foreach ($tagIds as $tagId) {
        if ($db->fetchOne('select id from tags where id = :id and household_id = :hid and is_active = true', ['id' => $tagId, 'hid' => $householdId])) {
            $db->insert('transaction_tags', ['transaction_id' => $id, 'tag_id' => $tagId]);
        }
    }
}

$responseData = [
    'id' => $id,
    'amount_cents' => $amountCents,
    'date' => $date,
    'is_reviewed' => false,
    'status' => 'draft',
    'receipt_id' => $receiptId,
    'attachment_id' => $attachmentId,
];
hb_api_idempotency_store_if_needed($pdo, $auth, $idempotencyKey, $requestHash, $responseData, 201);
hb_api_json($responseData, 201);
