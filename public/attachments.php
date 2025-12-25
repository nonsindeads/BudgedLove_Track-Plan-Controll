<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();

$action = $_GET['action'] ?? 'download';

if ($action === 'download') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare(
        'select a.*, m.user_id
           from attachments a
           join household_members m on m.household_id = a.household_id
          where a.id = :id and m.user_id = :uid and m.is_active = true'
    );
    $stmt->execute(['id' => $id, 'uid' => hb_current_user_id()]);
    $att = $stmt->fetch();
    if (!$att) {
        http_response_code(404);
        echo 'Anhang nicht gefunden.';
        exit;
    }
    $filePath = hb_upload_base_dir() . '/' . $att['storage_path'];
    if (!is_file($filePath)) {
        http_response_code(404);
        echo 'Datei fehlt.';
        exit;
    }
    header('Content-Type: ' . $att['mime_type']);
    header('Content-Length: ' . (int)$att['size_bytes']);
    header('Content-Disposition: inline; filename="' . basename($att['original_filename']) . '"');
    readfile($filePath);
    exit;
}

http_response_code(400);
echo 'Unbekannte Aktion.';
