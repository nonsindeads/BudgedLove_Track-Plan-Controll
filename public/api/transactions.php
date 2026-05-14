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
$accountId = (int)($data['account_id'] ?? 0);
if ($amount <= 0 || $date === '') {
    hb_api_json(['error' => 'amount and date are required'], 400);
}
if ($accountId < 1) {
    hb_api_json(['error' => 'account_id is required'], 400);
}
$accStmt = $pdo->prepare('select id from accounts where id = :id and household_id = :hid');
$accStmt->execute(['id' => $accountId, 'hid' => $householdId]);
if (!$accStmt->fetch()) {
    hb_api_json(['error' => 'account_id not found'], 400);
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

$amountCents = (int)round($amount * 100);
$ins = $pdo->prepare(
    "insert into transactions (household_id, type, booking_date, amount_cents, currency_code, account_id, category_id, payee_id, note, is_reviewed)
     values (:hid, 'expense', :d, :amount, 'EUR', :acc, :cat, :payee, :note, true) returning id"
);
$ins->execute([
    'hid' => $householdId,
    'd' => $date,
    'amount' => $amountCents,
    'acc' => $accountId,
    'cat' => $categoryId,
    'payee' => $payeeId > 0 ? $payeeId : null,
    'note' => (string)($data['notes'] ?? ''),
]);
$id = (int)$ins->fetchColumn();

hb_api_json(['id' => $id, 'amount_cents' => $amountCents, 'date' => $date], 201);
