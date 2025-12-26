<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();

$accountIdRaw = (string)($_POST['account_id'] ?? '');
$accountId = $accountIdRaw === '' || $accountIdRaw === 'all' ? null : (int)$accountIdRaw;
hb_set_selected_account_id($accountId);

$redirect = $_SERVER['HTTP_REFERER'] ?? '/';
if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
    header('HX-Redirect: ' . $redirect);
    exit;
}
header('Location: ' . $redirect);
