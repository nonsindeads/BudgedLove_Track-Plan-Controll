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
function hb_admin_validate_password(string $password): ?string
{
    if (strlen($password) < 12) {
        return hb_t('Password too short (minimum 12 characters).');
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return hb_t('Password needs at least one uppercase letter.');
    }
    if (!preg_match('/[a-z]/', $password)) {
        return hb_t('Password needs at least one lowercase letter.');
    }
    if (!preg_match('/\d/', $password)) {
        return hb_t('Password needs at least one number.');
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return hb_t('Password needs at least one symbol.');
    }
    return null;
}

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
    if ($action === 'create_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo = hb_get_pdo();
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        $language = hb_normalize_locale($_POST['language'] ?? 'de');
        $householdId = (int)($_POST['household_id'] ?? 0);
        $memberRole = $_POST['member_role'] === 'admin' ? 'admin' : 'editor';

        if ($username === '' || $email === '' || $firstName === '' || $lastName === '' || $password === '') {
            $msg = 'missing';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'invalid_email';
        } elseif ($password !== $confirm) {
            $msg = 'password_mismatch';
        } else {
            $passwordError = hb_admin_validate_password($password);
            if ($passwordError !== null) {
                $msg = 'password_policy';
            } else {
                $exists = $pdo->prepare('select 1 from users where lower(username) = lower(:username) or lower(email) = lower(:email)');
                $exists->execute(['username' => $username, 'email' => $email]);
                if ($exists->fetch()) {
                    $msg = 'exists';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $insert = $pdo->prepare(
                            'insert into users (username, email, first_name, last_name, password_hash, is_active, is_admin, language)
                             values (:username, :email, :first_name, :last_name, :hash, true, false, :language)
                             returning id'
                        );
                        $insert->execute([
                            'username' => $username,
                            'email' => $email,
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'hash' => $hash,
                            'language' => $language,
                        ]);
                        $newUserId = (int)$insert->fetchColumn();
                        if ($householdId > 0) {
                            $member = $pdo->prepare(
                                'insert into household_members (household_id, user_id, role, is_active)
                                 values (:household_id, :user_id, :role, true)
                                 on conflict (household_id, user_id)
                                 do update set role = excluded.role, is_active = true'
                            );
                            $member->execute([
                                'household_id' => $householdId,
                                'user_id' => $newUserId,
                                'role' => $memberRole,
                            ]);
                        }
                        $pdo->commit();
                        header('Location: /admin.php?msg=created');
                        exit;
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        $msg = 'error';
                    }
                }
            }
        }
        header('Location: /admin.php?msg=' . urlencode($msg ?? 'error'));
        exit;
    }

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
    $languageOptions = hb_available_locales();
    $householdOptions = $pdo->query('select id, name from households order by name asc')->fetchAll();
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
          <div class="text-muted small"><?= htmlspecialchars(hb_t('User management'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
      </div>
      <?php if (!empty($_GET['msg'])): ?>
        <?php
        $msgKey = $_GET['msg'];
        $msgMap = [
            'created' => ['type' => 'success', 'text' => hb_t('User created.')],
            'missing' => ['type' => 'danger', 'text' => hb_t('Please fill in all required fields.')],
            'invalid_email' => ['type' => 'danger', 'text' => hb_t('Invalid email address.')],
            'password_mismatch' => ['type' => 'danger', 'text' => hb_t('Passwords do not match.')],
            'password_policy' => ['type' => 'danger', 'text' => hb_t('Password does not meet the policy.')],
            'exists' => ['type' => 'danger', 'text' => hb_t('Username or email already exists.')],
            'error' => ['type' => 'danger', 'text' => hb_t('Could not create user.')],
        ];
        $alert = $msgMap[$msgKey] ?? null;
        ?>
        <?php if ($alert): ?>
          <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="row g-4">
        <div class="col-lg-5">
          <div class="card shadow-sm">
            <div class="card-body">
              <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Create user'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
              <form method="post" action="/admin.php?action=create_user">
                <input type="hidden" name="action" value="create_user">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label" for="admin-first-name"><?= htmlspecialchars(hb_t('First name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <input type="text" class="form-control" id="admin-first-name" name="first_name" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="admin-last-name"><?= htmlspecialchars(hb_t('Last name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <input type="text" class="form-control" id="admin-last-name" name="last_name" required>
                  </div>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-username"><?= htmlspecialchars(hb_t('Username'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="text" class="form-control" id="admin-username" name="username" required>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-email"><?= htmlspecialchars(hb_t('Email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="email" class="form-control" id="admin-email" name="email" required>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-password"><?= htmlspecialchars(hb_t('Password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="password" class="form-control" id="admin-password" name="password" autocomplete="new-password" required minlength="12">
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-password-confirm"><?= htmlspecialchars(hb_t('Confirm password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="password" class="form-control" id="admin-password-confirm" name="password_confirm" autocomplete="new-password" required minlength="12">
                  <div class="form-text text-muted"><?= htmlspecialchars(hb_t('At least 12 characters, upper/lowercase, number & symbol.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-language"><?= htmlspecialchars(hb_t('Language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" id="admin-language" name="language">
                    <?php foreach ($languageOptions as $lang => $label): ?>
                      <option value="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-household"><?= htmlspecialchars(hb_t('Assign household (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" id="admin-household" name="household_id">
                    <option value="0"><?= htmlspecialchars(hb_t('No household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php foreach ($householdOptions as $household): ?>
                      <option value="<?= (int)$household['id'] ?>"><?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-role"><?= htmlspecialchars(hb_t('Member role'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" id="admin-role" name="member_role">
                    <option value="editor"><?= htmlspecialchars(hb_t('Editor'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <option value="admin"><?= htmlspecialchars(hb_t('Admin'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  </select>
                </div>
                <button class="btn btn-primary mt-3" type="submit"><?= htmlspecialchars(hb_t('Create user'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </form>
            </div>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="mb-3">
            <div class="text-muted small"><?= htmlspecialchars(hb_t('Open registrations'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          </div>
          <?php render_pending(true); ?>
        </div>
      </div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../templates/layout.php';
    exit;
}
