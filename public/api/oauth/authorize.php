<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../app/bootstrap.php';
require_once __DIR__ . '/../../../app/oauth.php';
require_once __DIR__ . '/../../../app/cors.php';

$pdo = hb_get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Authorization request from OAuth client (e.g., ChatGPT)
    hb_cors_send_headers();

    $clientId = trim((string)($_GET['client_id'] ?? ''));
    $redirectUri = trim((string)($_GET['redirect_uri'] ?? ''));
    $responseType = trim((string)($_GET['response_type'] ?? ''));
    $codeChallenge = trim((string)($_GET['code_challenge'] ?? ''));
    $codeChallengeMethod = trim((string)($_GET['code_challenge_method'] ?? 'plain'));
    $state = trim((string)($_GET['state'] ?? ''));
    $scope = trim((string)($_GET['scope'] ?? ''));

    // Validate request parameters
    if (!$clientId || !$redirectUri || !$responseType) {
        hb_api_error('invalid_request', 'Missing required OAuth parameters', 400);
    }

    if ($responseType !== 'code') {
        hb_api_error('unsupported_response_type', 'Only code flow supported', 400);
    }

    // PKCE: only S256 allowed
    if (!$codeChallenge || $codeChallengeMethod !== 'S256') {
        hb_api_error('invalid_request', 'PKCE S256 is required', 400);
    }

    // Validate client and redirect_uri
    $client = hb_oauth_client($pdo, $clientId);
    if (!$client || !hb_oauth_validate_redirect_uri($client, $redirectUri)) {
        hb_api_error('invalid_client', 'Invalid client or redirect URI', 400);
    }

    // Save state in session for CSRF protection
    hb_start_session();
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_client_id'] = $clientId;
    $_SESSION['oauth_redirect_uri'] = $redirectUri;
    $_SESSION['oauth_code_challenge'] = $codeChallenge;
    $_SESSION['oauth_scope'] = $scope;

    // Check if already logged in
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        // Show login form
        hb_show_oauth_login_form($clientId);
        exit;
    }

    // Get user's households
    $householdStmt = $pdo->prepare(
        'select h.id, h.name from households h
         join household_members hm on hm.household_id = h.id
         where hm.user_id = :uid and hm.is_active = true
         order by h.name asc'
    );
    $householdStmt->execute(['uid' => $userId]);
    $households = $householdStmt->fetchAll();

    if (count($households) === 1) {
        // Single household → show approval directly
        $_SESSION['oauth_household_id'] = (int)$households[0]['id'];
        hb_show_oauth_approval($client, $households[0], $scope);
    } else {
        // Multiple households → show selector
        hb_show_oauth_household_selector($client, $households, $scope);
    }
    exit;
}

if ($method === 'POST') {
    hb_cors_send_headers();
    hb_start_session();

    $action = trim((string)($_POST['action'] ?? ''));
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($action === 'login') {
        hb_handle_oauth_login($pdo);
        exit;
    }

    if ($action === 'select_household') {
        hb_handle_oauth_household_selection($pdo);
        exit;
    }

    if ($action === 'approve') {
        hb_handle_oauth_approval($pdo);
        exit;
    }

    hb_api_error('invalid_request', 'Invalid action', 400);
}

hb_api_error('method_not_allowed', 'Only GET and POST allowed', 405);

// === Helper Functions ===

function hb_show_oauth_login_form(string $clientId): void
{
    // Simple HTML form for OAuth login
    $clientName = htmlspecialchars($clientId, ENT_QUOTES, 'UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>BudgetLove – Anmelden</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f5f5f5; }
            .login-card { max-width: 400px; width: 100%; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
            .login-card h3 { margin-bottom: 1rem; font-size: 1.5rem; }
            .login-card p { color: #666; margin-bottom: 1.5rem; font-size: 0.95rem; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <h3>BudgetLove</h3>
            <p><?php echo $clientName; ?> möchte auf dein BudgetLove-Konto zugreifen.</p>
            <form method="post" action="/api/oauth/authorize.php">
                <input type="hidden" name="action" value="login">
                <div class="mb-3">
                    <label class="form-label" for="login">E-Mail oder Benutzername</label>
                    <input type="text" id="login" name="login" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Passwort</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Anmelden</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

function hb_show_oauth_household_selector(array $client, array $households, string $scope): void
{
    $clientName = htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Haushalt wählen</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f5f5f5; }
            .selector-card { max-width: 500px; width: 100%; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        </style>
    </head>
    <body>
        <div class="selector-card">
            <h3>Haushalt wählen</h3>
            <p><?php echo $clientName; ?> kann auf folgende Haushalte zugreifen:</p>
            <form method="post" action="/api/oauth/authorize.php">
                <input type="hidden" name="action" value="select_household">
                <div class="mb-3">
                    <select name="household_id" class="form-select" required>
                        <option value="">-- Bitte wählen --</option>
                        <?php foreach ($households as $h): ?>
                            <option value="<?php echo htmlspecialchars((string)$h['id'], ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($h['name'], ENT_QUOTES); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary w-100">Fortfahren</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

function hb_show_oauth_approval(array $client, array $household, string $scope): void
{
    $clientName = htmlspecialchars($client['name'], ENT_QUOTES, 'UTF-8');
    $householdName = htmlspecialchars($household['name'], ENT_QUOTES, 'UTF-8');
    $scopeList = $scope ? explode(' ', $scope) : [];
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Freigabe erteilen</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f5f5f5; }
            .approval-card { max-width: 500px; width: 100%; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
            .scope-list { list-style: none; padding-left: 0; }
            .scope-list li { padding: 0.5rem 0; color: #666; }
        </style>
    </head>
    <body>
        <div class="approval-card">
            <h3><?php echo $clientName; ?></h3>
            <p>möchte auf deinen Haushalt <strong><?php echo $householdName; ?></strong> zugreifen.</p>
            <p>Folgende Berechtigung wird angefordert:</p>
            <ul class="scope-list">
                <?php foreach ($scopeList as $s): ?>
                    <li>✓ <?php echo htmlspecialchars($s, ENT_QUOTES); ?></li>
                <?php endforeach; ?>
            </ul>
            <div class="d-flex gap-2 mt-4">
                <form method="post" action="/api/oauth/authorize.php" style="flex:1;">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn btn-success w-100">Zugang erteilen</button>
                </form>
                <form method="get" action="/api/oauth/authorize.php" style="flex:1;">
                    <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($_SESSION['oauth_client_id'] ?? '', ENT_QUOTES); ?>">
                    <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($_SESSION['oauth_redirect_uri'] ?? '', ENT_QUOTES); ?>">
                    <input type="hidden" name="response_type" value="code">
                    <input type="hidden" name="code_challenge" value="<?php echo htmlspecialchars($_SESSION['oauth_code_challenge'] ?? '', ENT_QUOTES); ?>">
                    <input type="hidden" name="code_challenge_method" value="S256">
                    <input type="hidden" name="state" value="<?php echo htmlspecialchars($_SESSION['oauth_state'] ?? '', ENT_QUOTES); ?>">
                    <button type="submit" class="btn btn-secondary w-100">Ablehnen</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function hb_handle_oauth_login(PDO $pdo): void
{
    hb_require_csrf();

    $login = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        hb_api_error('invalid_request', 'Username/email and password required', 400);
    }

    // Check rate limit
    if (!hb_rate_limit_allow($pdo, 'oauth_login', 10, 600)) {
        hb_api_error('too_many_requests', 'Too many login attempts', 429);
    }
    hb_rate_limit_record($pdo, 'oauth_login');

    // Authenticate user
    $stmt = $pdo->prepare(
        'select id, username, password_hash, is_active from users
         where (lower(username) = lower(:login) or lower(email) = lower(:login))
         limit 1'
    );
    $stmt->execute(['login' => $login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash']) || !$user['is_active']) {
        hb_api_error('invalid_credentials', 'Invalid username or password', 401);
    }

    // Set session
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];

    // Redirect back to authorize with same params
    $redirectUrl = '/api/oauth/authorize.php?' . http_build_query([
        'client_id' => $_SESSION['oauth_client_id'] ?? '',
        'redirect_uri' => $_SESSION['oauth_redirect_uri'] ?? '',
        'response_type' => 'code',
        'code_challenge' => $_SESSION['oauth_code_challenge'] ?? '',
        'code_challenge_method' => 'S256',
        'state' => $_SESSION['oauth_state'] ?? '',
        'scope' => $_SESSION['oauth_scope'] ?? '',
    ]);
    header('Location: ' . $redirectUrl);
    exit;
}

function hb_handle_oauth_household_selection(PDO $pdo): void
{
    hb_require_csrf();

    $householdId = hb_api_int_or_null($_POST['household_id'] ?? null);
    if (!$householdId) {
        hb_api_error('invalid_request', 'Household ID required', 400);
    }

    // Verify user owns this household
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $pdo->prepare(
        'select h.id from households h
         join household_members hm on hm.household_id = h.id
         where h.id = :hid and hm.user_id = :uid and hm.is_active = true'
    );
    $stmt->execute(['hid' => $householdId, 'uid' => $userId]);
    if (!$stmt->fetch()) {
        hb_api_error('forbidden', 'You do not have access to this household', 403);
    }

    $_SESSION['oauth_household_id'] = $householdId;

    // Redirect to approval page
    header('Location: /api/oauth/authorize.php?' . http_build_query([
        'client_id' => $_SESSION['oauth_client_id'] ?? '',
        'redirect_uri' => $_SESSION['oauth_redirect_uri'] ?? '',
        'response_type' => 'code',
        'code_challenge' => $_SESSION['oauth_code_challenge'] ?? '',
        'code_challenge_method' => 'S256',
        'state' => $_SESSION['oauth_state'] ?? '',
        'scope' => $_SESSION['oauth_scope'] ?? '',
    ]));
    exit;
}

function hb_handle_oauth_approval(PDO $pdo): void
{
    hb_require_csrf();

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $clientId = (string)($_SESSION['oauth_client_id'] ?? '');
    $redirectUri = (string)($_SESSION['oauth_redirect_uri'] ?? '');
    $codeChallenge = (string)($_SESSION['oauth_code_challenge'] ?? '');
    $state = (string)($_SESSION['oauth_state'] ?? '');
    $householdId = (int)($_SESSION['oauth_household_id'] ?? 0);
    $scope = (string)($_SESSION['oauth_scope'] ?? '');

    if (!$userId || !$clientId || !$householdId || !$codeChallenge) {
        hb_api_error('invalid_request', 'Missing OAuth session data', 400);
    }

    try {
        // Issue auth code
        require_once __DIR__ . '/../../app/oauth.php';
        $plainCode = hb_oauth_issue_auth_code($pdo, $userId, $clientId, $householdId, $redirectUri, $scope, $codeChallenge);

        // Cleanup session
        unset($_SESSION['oauth_state'], $_SESSION['oauth_client_id'], $_SESSION['oauth_redirect_uri'],
              $_SESSION['oauth_code_challenge'], $_SESSION['oauth_scope'], $_SESSION['oauth_household_id']);

        // Redirect with code
        $redirectUrl = $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query([
            'code' => $plainCode,
            'state' => $state,
        ]);
        header('Location: ' . $redirectUrl);
        exit;
    } catch (Exception $e) {
        error_log('OAuth approval error: ' . $e->getMessage());
        hb_api_error('server_error', 'Failed to issue authorization code', 500);
    }
}
