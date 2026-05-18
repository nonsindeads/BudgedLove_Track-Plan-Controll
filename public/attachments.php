<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$serverPdo = hb_get_pdo();
$household = hb_require_household($serverPdo);
$pdo = hb_household_pdo($serverPdo, (int)$household['id']);

$action = $_GET['action'] ?? 'download';

if ($action === 'download') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('select * from attachments where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $att = $stmt->fetch();
    if (!$att) {
        http_response_code(404);
        echo 'Anhang nicht gefunden.';
        exit;
    }
    try {
        $content = hb_attachment_read_binary($serverPdo, (int)$household['id'], (string)$att['storage_path']);
    } catch (Throwable) {
        http_response_code(404);
        echo 'Datei fehlt.';
        exit;
    }
    header('Content-Type: ' . $att['mime_type']);
    header('Content-Length: ' . strlen($content));
    header('Content-Disposition: inline; filename="' . basename($att['original_filename']) . '"');
    echo $content;
    exit;
}

http_response_code(400);
echo 'Unbekannte Aktion.';
