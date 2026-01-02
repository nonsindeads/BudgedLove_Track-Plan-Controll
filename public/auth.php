<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../app/domain.php';

header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        handle_login();
        break;

    case 'register':
        handle_register();
        break;

    case 'check_username':
        handle_check_username();
        break;

    case 'check_email':
        handle_check_email();
        break;

    case 'check_password':
        handle_check_password();
        break;

    case 'logout':
        handle_logout();
        break;

    default:
        http_response_code(400);
        echo render_alert('Unbekannte Aktion.');
}

function handle_login(): void
{
    $login = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!empty($_POST['lang'])) {
        hb_set_locale((string)$_POST['lang']);
    }

    if ($login === '' || $password === '') {
        http_response_code(400);
        echo render_alert(hb_t('Please enter username/email and password.'));
        return;
    }

    $pdo = hb_get_pdo();
    $stmt = $pdo->prepare(
        'select id, username, email, password_hash, is_active, is_admin, language
           from users
          where lower(username) = lower(:login) or lower(email) = lower(:login)
          limit 1'
    );
    $stmt->execute(['login' => $login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo render_alert(hb_t('Username/email or password is incorrect.'));
        return;
    }

    if (!(bool)$user['is_active']) {
        http_response_code(403);
        echo render_alert(hb_t('Account is not active yet. Please wait for admin approval.'));
        return;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['lang'] = hb_normalize_locale($user['language'] ?? 'de');
    $_SESSION['is_admin'] = (bool)$user['is_admin'];

    $households = hb_user_households($pdo, (int)$user['id']);
    if ($households) {
        hb_set_current_household((int)$households[0]['id']);
        header('HX-Redirect: /accounts.php');
    } else {
        unset($_SESSION['household_id']);
        header('HX-Redirect: /household.php');
    }
}

function handle_register(): void
{
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $street = trim((string)($_POST['address_street'] ?? ''));
    $houseNumber = trim((string)($_POST['address_house_number'] ?? ''));
    $postalCode = trim((string)($_POST['address_postal_code'] ?? ''));
    $city = trim((string)($_POST['address_city'] ?? ''));
    $state = trim((string)($_POST['address_state'] ?? ''));
    $extra = trim((string)($_POST['address_extra'] ?? ''));
    $householdName = trim((string)($_POST['household_name'] ?? ''));
    $primaryAccountName = trim((string)($_POST['primary_account_name'] ?? ''));
    $primaryAccountType = trim((string)($_POST['primary_account_type'] ?? 'checking'));
    $primaryAccountOpeningRaw = trim((string)($_POST['primary_account_opening_balance'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');
    $consentContact = isset($_POST['consent_contact']);
    $language = hb_normalize_locale($_POST['language'] ?? 'de');

    if ($username === '' || $email === '' || $firstName === '' || $lastName === '' || $password === '') {
        http_response_code(400);
        echo render_register_notice(hb_t('Please fill in all required fields.'));
        return;
    }

    if ($street === '' || $houseNumber === '' || $postalCode === '' || $city === '') {
        http_response_code(400);
        echo render_register_notice(hb_t('Street, house number, postal code, and city are required.'));
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo render_register_notice(hb_t('Please enter a valid email address.'));
        return;
    }

    if ($password !== $confirm) {
        http_response_code(400);
        echo render_register_notice(hb_t('Passwords do not match.'));
        return;
    }

    $passwordError = hb_validate_password($password);
    if ($passwordError !== null) {
        http_response_code(400);
        echo render_register_notice($passwordError);
        return;
    }

    if (!$consentContact) {
        http_response_code(400);
        echo render_register_notice(hb_t('Please agree to be contacted.'));
        return;
    }

    $pdo = hb_get_pdo();

    if ($primaryAccountName === '' && $primaryAccountOpeningRaw !== '') {
        http_response_code(400);
        echo render_register_notice(hb_t('Please provide a name for the primary account.'));
        return;
    }
    if ($primaryAccountName !== '' && !in_array($primaryAccountType, hb_allowed_account_types(), true)) {
        http_response_code(400);
        echo render_register_notice(hb_t('Invalid account type.'));
        return;
    }
    $primaryAccountOpening = null;
    if ($primaryAccountOpeningRaw !== '') {
        $primaryAccountOpening = hb_parse_cents($primaryAccountOpeningRaw);
        if ($primaryAccountOpening === null || $primaryAccountOpening < 0) {
            http_response_code(400);
            echo render_register_notice(hb_t('Opening balance is invalid.'));
            return;
        }
    }

    $existsUser = $pdo->prepare('select 1 from users where lower(username) = lower(:username)');
    $existsUser->execute(['username' => $username]);
    if ($existsUser->fetch()) {
        http_response_code(409);
        echo render_register_notice(hb_t('Username is already taken.'));
        return;
    }

    $existsEmail = $pdo->prepare('select 1 from users where lower(email) = lower(:email)');
    $existsEmail->execute(['email' => $email]);
    if ($existsEmail->fetch()) {
        http_response_code(409);
        echo render_register_notice(hb_t('Email is already registered.'));
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $address = hb_build_address_string($street, $houseNumber, $postalCode, $city, $state ?: null, $extra ?: null);
    $insert = $pdo->prepare(
        'insert into users (username, email, first_name, last_name, address,
                            address_street, address_house_number, address_postal_code,
                            address_city, address_state, address_extra, language,
                            consent_contact, password_hash, is_active, is_admin)
         values (:username, :email, :first_name, :last_name, :address,
                 :street, :house_number, :postal_code,
                 :city, :state, :extra, :language,
                 :consent_contact, :password_hash, false, false)
         returning id'
    );
    $insert->execute([
        'username' => $username,
        'email' => $email,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'address' => $address,
        'street' => $street,
        'house_number' => $houseNumber,
        'postal_code' => $postalCode,
        'city' => $city,
        'state' => $state !== '' ? $state : null,
        'extra' => $extra !== '' ? $extra : null,
        'language' => $language,
        'consent_contact' => true,
        'password_hash' => $hash,
    ]);
    $userId = (int)$insert->fetchColumn();

    if ($householdName !== '' || $primaryAccountName !== '') {
        $fallbackName = trim('Haushalt von ' . $firstName . ' ' . $lastName);
        $resolvedHouseholdName = $householdName !== '' ? $householdName : $fallbackName;
        $householdId = hb_create_household($pdo, $userId, $resolvedHouseholdName, 'EUR', 'first_of_month', null);
        if ($primaryAccountName !== '') {
            $accountInsert = $pdo->prepare(
                'insert into accounts (household_id, name, type, currency_code, opening_balance_cents)
                 values (:household_id, :name, :type, :currency_code, :opening_balance_cents)'
            );
            $accountInsert->execute([
                'household_id' => $householdId,
                'name' => $primaryAccountName,
                'type' => $primaryAccountType,
                'currency_code' => 'EUR',
                'opening_balance_cents' => $primaryAccountOpening ?? 0,
            ]);
        }
    }

    http_response_code(202);
    echo render_register_notice(
        'Registrierung eingereicht. Ein Admin muss dich freischalten, bevor du dich einloggen kannst.',
        'success',
        '/login',
        10000
    );
}

function handle_check_username(): void
{
    $username = trim((string)($_POST['username'] ?? ''));
    if ($username === '') {
        echo render_field_feedback('Bitte Benutzername eingeben.');
        return;
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,}$/', $username)) {
        echo render_field_feedback('Mindestens 3 Zeichen, nur Buchstaben/Ziffern/._- erlaubt.', 'text-danger');
        return;
    }

    $pdo = hb_get_pdo();
    $stmt = $pdo->prepare('select 1 from users where lower(username) = lower(:username)');
    $stmt->execute(['username' => $username]);
    $exists = (bool)$stmt->fetch();

    if ($exists) {
        echo render_field_feedback(hb_t('Username is already taken.'), 'text-danger');
    } else {
        echo render_field_feedback(hb_t('Username is available.'), 'text-success');
    }
}

function handle_check_email(): void
{
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '') {
        echo render_field_feedback(hb_t('Please enter an email.'));
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo render_field_feedback(hb_t('Invalid email address.'), 'text-danger');
        return;
    }

    $pdo = hb_get_pdo();
    $stmt = $pdo->prepare('select 1 from users where lower(email) = lower(:email)');
    $stmt->execute(['email' => $email]);
    $exists = (bool)$stmt->fetch();

    if ($exists) {
        echo render_field_feedback(hb_t('Email is already registered.'), 'text-danger');
    } else {
        echo render_field_feedback(hb_t('Email looks good.'), 'text-success');
    }
}

function handle_check_password(): void
{
    $password = (string)($_POST['password'] ?? '');
    if ($password === '') {
        echo render_field_feedback(hb_t('At least 12 characters, upper/lowercase, number & symbol.'));
        return;
    }

    $error = hb_validate_password($password);
    if ($error !== null) {
        echo render_field_feedback($error, 'text-danger');
    } else {
        echo render_field_feedback(hb_t('Password meets the policy.'), 'text-success');
    }
}

function render_register_notice(
    string $message,
    string $type = 'danger',
    ?string $redirectUrl = null,
    int $redirectDelayMs = 0
): string {
    $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $accent = $type === 'success' ? 'success' : 'danger';
    $redirectAttrs = '';
    if ($redirectUrl) {
        $redirectAttrs = ' data-redirect-url="' . htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' data-redirect-delay="' . (int)$redirectDelayMs . '"';
    }
    $redirectHint = '';
    if ($redirectUrl && $redirectDelayMs > 0) {
        $seconds = (int)ceil($redirectDelayMs / 1000);
        $redirectHint = '<p class="text-muted small mb-0 mt-2">'
            . htmlspecialchars(hb_t('Redirecting to login in {seconds} seconds.', null, ['seconds' => $seconds]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p>';
    }
    return '<div class="card border-0 shadow-sm"' . $redirectAttrs . '>'
        . '<div class="card-body">'
        . '<div class="d-flex align-items-center gap-2 mb-2">'
        . '<span class="badge bg-' . $accent . '-subtle text-' . $accent . '">'
        . htmlspecialchars($type === 'success' ? hb_t('Success') : hb_t('Notice'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</span>'
        . '</div>'
        . '<p class="mb-0">' . $escaped . '</p>'
        . $redirectHint
        . '</div>'
        . '</div>';
}

function handle_logout(): void
{
    session_unset();
    session_destroy();
    session_write_close();

    header('HX-Redirect: /');
}

function hb_validate_password(string $password): ?string
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

function render_alert(string $message, string $type = 'danger'): string
{
    $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<div class="alert alert-' . $type . ' mb-0" role="alert">' . $escaped . '</div>';
}

function render_field_feedback(string $message, string $class = 'text-muted'): string
{
    $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<span class="' . $class . '">' . $escaped . '</span>';
}
