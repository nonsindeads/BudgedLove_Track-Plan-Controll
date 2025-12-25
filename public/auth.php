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

    if ($login === '' || $password === '') {
        http_response_code(400);
        echo render_alert('Bitte Benutzername/E-Mail und Passwort ausfüllen.');
        return;
    }

    $pdo = hb_get_pdo();
    $stmt = $pdo->prepare(
        'select id, username, email, password_hash, is_active, is_admin
           from users
          where lower(username) = lower(:login) or lower(email) = lower(:login)
          limit 1'
    );
    $stmt->execute(['login' => $login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo render_alert('Benutzername/E-Mail oder Passwort ist falsch.');
        return;
    }

    if (!(bool)$user['is_active']) {
        http_response_code(403);
        echo render_alert('Account ist noch nicht freigeschaltet. Bitte warte auf die Admin-Freigabe.');
        return;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
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
    $address = trim((string)($_POST['address'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');
    $consentContact = isset($_POST['consent_contact']);

    if ($username === '' || $email === '' || $firstName === '' || $lastName === '' || $address === '' || $password === '') {
        http_response_code(400);
        echo render_alert('Alle Felder sind Pflichtfelder.');
        return;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo render_alert('Bitte eine gültige E-Mail-Adresse eingeben.');
        return;
    }

    if ($password !== $confirm) {
        http_response_code(400);
        echo render_alert('Passwörter stimmen nicht überein.');
        return;
    }

    $passwordError = hb_validate_password($password);
    if ($passwordError !== null) {
        http_response_code(400);
        echo render_alert($passwordError);
        return;
    }

    if (!$consentContact) {
        http_response_code(400);
        echo render_alert('Bitte der Kontaktaufnahme zustimmen.');
        return;
    }

    $pdo = hb_get_pdo();

    $existsUser = $pdo->prepare('select 1 from users where lower(username) = lower(:username)');
    $existsUser->execute(['username' => $username]);
    if ($existsUser->fetch()) {
        http_response_code(409);
        echo render_alert('Benutzername bereits vergeben.');
        return;
    }

    $existsEmail = $pdo->prepare('select 1 from users where lower(email) = lower(:email)');
    $existsEmail->execute(['email' => $email]);
    if ($existsEmail->fetch()) {
        http_response_code(409);
        echo render_alert('E-Mail ist bereits registriert.');
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = $pdo->prepare(
        'insert into users (username, email, first_name, last_name, address, consent_contact, password_hash, is_active, is_admin)
         values (:username, :email, :first_name, :last_name, :address, :consent_contact, :password_hash, false, false)'
    );
    $insert->execute([
        'username' => $username,
        'email' => $email,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'address' => $address,
        'consent_contact' => true,
        'password_hash' => $hash,
    ]);

    http_response_code(202);
    echo render_alert('Registrierung eingereicht. Ein Admin muss dich freischalten, bevor du dich einloggen kannst.', 'success');
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
        echo render_field_feedback('Benutzername bereits vergeben.', 'text-danger');
    } else {
        echo render_field_feedback('Benutzername ist verfügbar.', 'text-success');
    }
}

function handle_check_email(): void
{
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '') {
        echo render_field_feedback('Bitte E-Mail eingeben.');
        return;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo render_field_feedback('Keine gültige E-Mail-Adresse.', 'text-danger');
        return;
    }

    $pdo = hb_get_pdo();
    $stmt = $pdo->prepare('select 1 from users where lower(email) = lower(:email)');
    $stmt->execute(['email' => $email]);
    $exists = (bool)$stmt->fetch();

    if ($exists) {
        echo render_field_feedback('E-Mail ist bereits registriert.', 'text-danger');
    } else {
        echo render_field_feedback('E-Mail sieht gut aus.', 'text-success');
    }
}

function handle_check_password(): void
{
    $password = (string)($_POST['password'] ?? '');
    if ($password === '') {
        echo render_field_feedback('Mindestens 12 Zeichen, Groß-/Kleinbuchstaben, Zahl & Sonderzeichen.');
        return;
    }

    $error = hb_validate_password($password);
    if ($error !== null) {
        echo render_field_feedback($error, 'text-danger');
    } else {
        echo render_field_feedback('Passwort erfüllt die Policy.', 'text-success');
    }
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
        return 'Passwort zu kurz (mindestens 12 Zeichen).';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Passwort benötigt mindestens einen Großbuchstaben.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Passwort benötigt mindestens einen Kleinbuchstaben.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Passwort benötigt mindestens eine Zahl.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Passwort benötigt mindestens ein Sonderzeichen.';
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
