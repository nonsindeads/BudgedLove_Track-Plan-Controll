<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();

$pdo = hb_get_pdo();
$household = hb_current_household($pdo);

$accountIdRaw = (string)($_POST['account_id'] ?? '');
$accountId = $accountIdRaw === '' || $accountIdRaw === 'all' ? null : (int)$accountIdRaw;
if (!$household) {
    hb_set_selected_account_id(null);
} elseif ($accountId !== null) {
    $stmt = $pdo->prepare('select 1 from accounts where id = :id and household_id = :hid');
    $stmt->execute(['id' => $accountId, 'hid' => $household['id']]);
    if (!$stmt->fetch()) {
        $accountId = null;
    }
    hb_set_selected_account_id($accountId);
} else {
    hb_set_selected_account_id(null);
}

$redirect = $_SERVER['HTTP_REFERER'] ?? '/';
if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
    header('HX-Redirect: ' . $redirect);
    exit;
}
header('Location: ' . $redirect);
