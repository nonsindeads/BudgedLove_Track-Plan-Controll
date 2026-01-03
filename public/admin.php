<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

if (!hb_is_admin()) {
    http_response_code(403);
    echo render_alert(hb_t('Only admins can perform this action.'));
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$isHx = !empty($_SERVER['HTTP_HX_REQUEST']);

if ($isHx) {
    switch ($action) {
        case 'activate':
            handle_activate();
            break;
        case 'list':
        default:
            render_pending();
    }
}

function handle_activate(): void
{
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId < 1) {
        http_response_code(400);
        echo render_alert(hb_t('Invalid user id.'));
        return;
    }

    $pdo = hb_get_pdo();
    $update = $pdo->prepare('update users set is_active = true where id = :id');
    $update->execute(['id' => $userId]);

    render_pending();
}

function render_pending(bool $wrap = false): void
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

    if ($wrap) {
        echo '<div class="card shadow-sm">';
        echo '<div class="card-body">';
    }
    if (!$users) {
        echo '<div class="alert alert-info mb-0">' . htmlspecialchars(hb_t('No pending approvals.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        if ($wrap) {
            echo '</div></div>';
        }
        return;
    }

    echo '<div class="table-responsive">';
    echo '<table class="table align-middle mb-0">';
    echo '<thead><tr><th>' . htmlspecialchars(hb_t('User'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Consent'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th></tr></thead>';
    echo '<tbody>';

    foreach ($users as $user) {
        $consent = $user['consent_contact'] ? hb_t('Yes') : hb_t('No');
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
        echo '<button type="submit" class="btn btn-sm btn-success">' . htmlspecialchars(hb_t('Activate'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    if ($wrap) {
        echo '</div></div>';
    }
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

if (!$isHx) {
    if ($action === 'activate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $pdo = hb_get_pdo();
            $update = $pdo->prepare('update users set is_active = true where id = :id');
            $update->execute(['id' => $userId]);
        }
        header('Location: /admin.php');
        exit;
    }
    $pdo = hb_get_pdo();
    $currentHousehold = hb_current_household($pdo);
    $currentUser = hb_current_user($pdo);
    $pageTitle = 'Admin';
    $activeNav = 'admin';
    $breadcrumbs = [
        ['label' => 'Admin', 'href' => '/admin.php'],
    ];

    ob_start();
    ?>
    <div class="container-fluid">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Admin'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
          <div class="text-muted small"><?= htmlspecialchars(hb_t('Open registrations'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
      </div>
      <?php render_pending(true); ?>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../templates/layout.php';
    exit;
}
