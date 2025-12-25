<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/db.php';

header('Content-Type: text/html; charset=utf-8');

if (!hb_is_admin()) {
    http_response_code(403);
    echo render_alert('Nur Admins dürfen diese Aktion ausführen.');
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'activate':
        handle_activate();
        break;
    case 'list':
    default:
        render_pending();
}

function handle_activate(): void
{
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId < 1) {
        http_response_code(400);
        echo render_alert('Ungültige Benutzer-ID.');
        return;
    }

    $pdo = hb_get_pdo();
    $update = $pdo->prepare('update users set is_active = true where id = :id');
    $update->execute(['id' => $userId]);

    render_pending();
}

function render_pending(): void
{
    $pdo = hb_get_pdo();
    $stmt = $pdo->query(
        'select id, username, email, first_name, last_name, address,
                address_street, address_house_number, address_postal_code,
                address_city, address_state, address_extra,
                consent_contact, created_at
           from users
          where is_active = false and is_admin = false
          order by created_at asc'
    );
    $users = $stmt->fetchAll();

    if (!$users) {
        echo '<div class="alert alert-info mb-0">Keine offenen Freischaltungen.</div>';
        return;
    }

    echo '<div class="table-responsive">';
    echo '<table class="table align-middle mb-0">';
    echo '<thead><tr><th>Benutzer</th><th>Name</th><th>E-Mail</th><th>Adresse</th><th>Zustimmung</th><th>Aktion</th></tr></thead>';
    echo '<tbody>';

    foreach ($users as $user) {
        $consent = $user['consent_contact'] ? 'Ja' : 'Nein';
        $fullName = htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $username = htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $email = htmlspecialchars($user['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $address = htmlspecialchars(hb_format_address_admin($user), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo '<tr>';
        echo '<td>' . $username . '</td>';
        echo '<td>' . $fullName . '</td>';
        echo '<td>' . $email . '</td>';
        echo '<td class="small">' . $address . '</td>';
        echo '<td>' . $consent . '</td>';
        echo '<td>';
        echo '<form hx-post="/admin.php?action=activate" hx-target="#pending-list" hx-swap="innerHTML">';
        echo '<input type="hidden" name="user_id" value="' . (int)$user['id'] . '">';
        echo '<button type="submit" class="btn btn-sm btn-success">Freischalten</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

function hb_is_admin(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function hb_format_address_admin(array $user): string
{
    $street = trim((string)($user['address_street'] ?? ''));
    $houseNumber = trim((string)($user['address_house_number'] ?? ''));
    $postalCode = trim((string)($user['address_postal_code'] ?? ''));
    $city = trim((string)($user['address_city'] ?? ''));
    $state = trim((string)($user['address_state'] ?? ''));
    $extra = trim((string)($user['address_extra'] ?? ''));
    if ($street !== '' || $houseNumber !== '' || $postalCode !== '' || $city !== '' || $state !== '' || $extra !== '') {
        $line1 = trim($street . ' ' . $houseNumber);
        $line2 = trim($postalCode . ' ' . $city);
        $parts = array_filter([$line1, $line2, $state !== '' ? $state : null, $extra !== '' ? $extra : null]);
        return implode(', ', $parts);
    }
    return (string)($user['address'] ?? '');
}

function render_alert(string $message, string $type = 'danger'): string
{
    $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<div class="alert alert-' . $type . ' mb-0" role="alert">' . $escaped . '</div>';
}
