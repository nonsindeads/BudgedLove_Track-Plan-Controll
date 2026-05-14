<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/api.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hb_api_json(['error' => 'Method not allowed'], 405);
}

$pdo = hb_get_pdo();
$auth = hb_api_require_token($pdo);
$householdId = (int)($auth['household_id'] ?? 0);
if ($householdId < 1) {
    hb_api_json(['error' => 'No household linked to token user'], 400);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    hb_api_json(['error' => 'Invalid JSON'], 400);
}

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

$accountId = null;
if (array_key_exists('account_id', $data) && $data['account_id'] !== null && $data['account_id'] !== '') {
    $accountId = (int)$data['account_id'];
    if ($accountId < 1) {
        hb_api_json(['error' => 'account_id is invalid'], 400);
    }
    $accStmt = $pdo->prepare('select id from accounts where id = :id and household_id = :hid');
    $accStmt->execute(['id' => $accountId, 'hid' => $householdId]);
    if (!$accStmt->fetch()) {
        hb_api_json(['error' => 'account_id not found'], 400);
    }
}

$categoryId = null;
if (array_key_exists('category_id', $data) && $data['category_id'] !== null && $data['category_id'] !== '') {
    $categoryId = (int)$data['category_id'];
    if ($categoryId < 1) {
        hb_api_json(['error' => 'category_id is invalid'], 400);
    }
    $catStmt = $pdo->prepare('select id from categories where id = :id and household_id = :hid and is_active = true');
    $catStmt->execute(['id' => $categoryId, 'hid' => $householdId]);
    if (!$catStmt->fetch()) {
        hb_api_json(['error' => 'category_id not found'], 400);
    }
}

$payee = trim((string)($data['payee'] ?? ''));
$payeeId = null;
if ($payee !== '') {
    $p = $pdo->prepare('select id from payees where household_id = :hid and lower(name) = lower(:name) limit 1');
    $p->execute(['hid' => $householdId, 'name' => $payee]);
    $payeeId = (int)($p->fetchColumn() ?: 0);
}

$note = trim((string)($data['notes'] ?? ''));
$draftNote = '[receipt-draft]';
if ($note !== '') {
    $draftNote .= ' ' . $note;
}

$amountCents = (int)round($amount * 100);
$ins = $pdo->prepare(
    "insert into transactions
        (household_id, type, booking_date, amount_cents, currency_code, account_id, category_id, payee_id, note, is_reviewed, counterparty_name)
     values
        (:hid, :type, :d, :amount, 'EUR', :acc, :cat, :payee_id, :note, false, :counterparty)
     returning id"
);
$ins->execute([
    'hid' => $householdId,
    'type' => $type,
    'd' => $date,
    'amount' => $amountCents,
    'acc' => $accountId,
    'cat' => $categoryId,
    'payee_id' => $payeeId > 0 ? $payeeId : null,
    'note' => $draftNote,
    'counterparty' => $payee !== '' ? $payee : null,
]);
$id = (int)$ins->fetchColumn();

$tagIds = hb_normalize_id_list(is_array($data['tag_ids'] ?? null) ? $data['tag_ids'] : []);
if ($tagIds) {
    $tagCheck = $pdo->prepare('select id from tags where id = :id and household_id = :hid and is_active = true');
    $tagInsert = $pdo->prepare('insert into transaction_tags (transaction_id, tag_id) values (:tid, :tag) on conflict do nothing');
    foreach ($tagIds as $tagId) {
        $tagCheck->execute(['id' => $tagId, 'hid' => $householdId]);
        if ($tagCheck->fetch()) {
            $tagInsert->execute(['tid' => $id, 'tag' => $tagId]);
        }
    }
}

hb_api_json([
    'id' => $id,
    'amount_cents' => $amountCents,
    'date' => $date,
    'is_reviewed' => false,
    'status' => 'draft',
], 201);
