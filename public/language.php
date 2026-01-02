<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

$lang = hb_normalize_locale($_POST['lang'] ?? $_GET['lang'] ?? 'de');
hb_set_locale($lang);

if (!empty($_SESSION['user_id'])) {
    $pdo = hb_get_pdo();
    $stmt = $pdo->prepare('update users set language = :lang where id = :id');
    $stmt->execute([
        'lang' => $lang,
        'id' => (int)$_SESSION['user_id'],
    ]);
}

if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
    http_response_code(204);
    exit;
}

$redirect = $_SERVER['HTTP_REFERER'] ?? '/';
header('Location: ' . $redirect);
