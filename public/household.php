<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/api.php';

$layoutCompact = false;
hb_require_login();
$pdo = hb_get_pdo();
$userId = hb_current_user_id();
$currentUser = hb_current_user($pdo);
$pageTitle = 'Household';
$activeNav = 'household';
$breadcrumbs = [
    ['label' => 'Household', 'href' => '/household.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'select';
$error = null;
$conflict = null;
$msg = $_GET['msg'] ?? null;

$households = hb_user_households($pdo, $userId);

$currentHousehold = hb_current_household($pdo);
$canManageMembers = $currentHousehold ? hb_is_household_creator($currentHousehold, $userId) : false;

function hb_household_row_exists(PDO $pdo, string $table, int $id, int $householdId): bool
{
    if (!in_array($table, ['accounts', 'categories', 'payees'], true)) {
        return false;
    }
    $stmt = $pdo->prepare("select 1 from {$table} where id = :id and household_id = :hid limit 1");
    $stmt->execute(['id' => $id, 'hid' => $householdId]);
    return (bool)$stmt->fetchColumn();
}

if ($action === 'settings' && !$currentHousehold) {
    header('Location: /household.php');
    exit;
}

if ($action === 'set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $householdId = (int)($_POST['household_id'] ?? 0);
    if ($householdId > 0) {
        $membership = $pdo->prepare(
            'select 1 from household_members where household_id = :hid and user_id = :uid and is_active = true'
        );
        $membership->execute(['hid' => $householdId, 'uid' => $userId]);
        if ($membership->fetch()) {
            hb_set_current_household($householdId);
            header('Location: /accounts.php');
            exit;
        } else {
            $error = hb_t('You are not active in this household.');
        }
    } else {
        $error = hb_t('Invalid selection.');
    }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $currency = strtoupper(trim((string)($_POST['currency_code'] ?? 'EUR')));
    $mode = (string)($_POST['month_close_mode'] ?? 'first_of_month');
    $salaryDay = $_POST['salary_day'] !== '' ? (int)$_POST['salary_day'] : null;

    if ($name === '') {
        $error = hb_t('Please provide a household name.');
    } elseif (!in_array($mode, hb_allowed_month_close_modes(), true)) {
        $error = hb_t('Invalid mode.');
    } elseif ($mode === 'salary_day' && ($salaryDay === null || $salaryDay < 1 || $salaryDay > 31)) {
        $error = hb_t('Valid salary day (1-31) required.');
    }

    if ($error === null) {
        $householdId = hb_create_household($pdo, $userId, $name, $currency, $mode, $salaryDay);
        hb_set_current_household($householdId);
        header('Location: /accounts.php');
        exit;
    }
}

if ($action === 'update_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$currentHousehold || !hb_is_household_admin($currentHousehold)) {
        $error = hb_t('Only household admins can change settings.');
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $currency = strtoupper(trim((string)($_POST['currency_code'] ?? $currentHousehold['currency_code'])));
        $mode = (string)($_POST['month_close_mode'] ?? $currentHousehold['month_close_mode']);
        $salaryDayRaw = (string)($_POST['salary_day'] ?? '');
        $anchorAccountRaw = (string)($_POST['salary_anchor_account_id'] ?? '');
        $anchorCategoryRaw = (string)($_POST['salary_anchor_category_id'] ?? '');
        $anchorPayeeRaw = (string)($_POST['salary_anchor_payee_id'] ?? '');
        $salaryDay = $salaryDayRaw !== '' ? (int)$salaryDayRaw : null;
        $anchorAccountId = $anchorAccountRaw !== '' ? (int)$anchorAccountRaw : null;
        $anchorCategoryId = $anchorCategoryRaw !== '' ? (int)$anchorCategoryRaw : null;
        $anchorPayeeId = $anchorPayeeRaw !== '' ? (int)$anchorPayeeRaw : null;
        $rowVersion = (int)($_POST['row_version'] ?? 0);
        if ($name === '') {
            $error = hb_t('Name is required.');
        } elseif (!in_array($mode, hb_allowed_month_close_modes(), true)) {
            $error = hb_t('Invalid mode.');
        } elseif ($mode === 'salary_day' && ($salaryDay === null || $salaryDay < 1 || $salaryDay > 31)) {
            $error = hb_t('Valid salary day (1-31) required.');
        } elseif ($salaryDay !== null && ($salaryDay < 1 || $salaryDay > 31)) {
            $error = hb_t('Valid salary day (1-31) required.');
        } elseif ($anchorAccountId !== null && !hb_household_row_exists($pdo, 'accounts', $anchorAccountId, (int)$currentHousehold['id'])) {
            $error = hb_t('Account does not belong to the household.');
        } elseif ($anchorCategoryId !== null && !hb_household_row_exists($pdo, 'categories', $anchorCategoryId, (int)$currentHousehold['id'])) {
            $error = hb_t('Category does not belong to the household.');
        } elseif ($anchorPayeeId !== null && !hb_household_row_exists($pdo, 'payees', $anchorPayeeId, (int)$currentHousehold['id'])) {
            $error = hb_t('Payee does not belong to the household.');
        } else {
            $stmt = $pdo->prepare(
                'update households
                    set name = :name,
                        currency_code = :currency,
                        month_close_mode = :mode,
                        salary_day = :salary,
                        salary_anchor_account_id = :anchor_account,
                        salary_anchor_category_id = :anchor_category,
                        salary_anchor_payee_id = :anchor_payee,
                        updated_at = now()
                  where id = :id and row_version = :row_version'
            );
            $stmt->execute([
                'name' => $name,
                'currency' => $currency,
                'mode' => $mode,
                'salary' => $salaryDay,
                'anchor_account' => $anchorAccountId,
                'anchor_category' => $anchorCategoryId,
                'anchor_payee' => $anchorPayeeId,
                'id' => $currentHousehold['id'],
                'row_version' => $rowVersion,
            ]);
            if ($stmt->rowCount() === 0) {
                $currentHousehold = hb_current_household($pdo);
                $conflictRows = hb_build_conflict_rows(
                    [
                        'name' => hb_t('Name'),
                        'currency_code' => hb_t('Currency'),
                        'month_close_mode' => hb_t('Period calculation'),
                        'salary_day' => hb_t('Salary day'),
                        'salary_anchor_account_id' => hb_t('Salary account'),
                        'salary_anchor_category_id' => hb_t('Salary category'),
                        'salary_anchor_payee_id' => hb_t('Salary payee'),
                    ],
                    $currentHousehold ?? [],
                    [
                        'name' => $name,
                        'currency_code' => $currency,
                        'month_close_mode' => $mode,
                        'salary_day' => $salaryDay !== null ? (string)$salaryDay : '',
                        'salary_anchor_account_id' => $anchorAccountId !== null ? (string)$anchorAccountId : '',
                        'salary_anchor_category_id' => $anchorCategoryId !== null ? (string)$anchorCategoryId : '',
                        'salary_anchor_payee_id' => $anchorPayeeId !== null ? (string)$anchorPayeeId : '',
                    ]
                );
                $conflict = hb_render_conflict_table($conflictRows);
                $currentHousehold = array_merge($currentHousehold ?? [], [
                    'name' => $name,
                    'currency_code' => $currency,
                    'month_close_mode' => $mode,
                    'salary_day' => $salaryDay,
                    'salary_anchor_account_id' => $anchorAccountId,
                    'salary_anchor_category_id' => $anchorCategoryId,
                    'salary_anchor_payee_id' => $anchorPayeeId,
                ]);
            } else {
                header('Location: /household.php?action=settings&msg=saved');
                exit;
            }
        }
    }
}

if ($action === 'add_member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$currentHousehold) {
        $error = hb_t('No household selected.');
    } elseif (!$canManageMembers) {
        $error = hb_t('Only the household creator can add members.');
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        if ($identifier === '') {
            $error = hb_t('Please enter a username or email.');
        } else {
            $userStmt = $pdo->prepare(
                'select id, username, email, is_active
                   from users
                  where lower(username) = lower(:ident)
                     or lower(email) = lower(:ident)
                  limit 1'
            );
            $userStmt->execute(['ident' => $identifier]);
            $user = $userStmt->fetch();
            if (!$user) {
                $error = hb_t('User not found.');
            } elseif (!$user['is_active']) {
                $error = hb_t('User is not active yet.');
            } else {
                $existsStmt = $pdo->prepare(
                    'select is_active from household_members where household_id = :hid and user_id = :uid'
                );
                $existsStmt->execute(['hid' => $currentHousehold['id'], 'uid' => $user['id']]);
                $existing = $existsStmt->fetch();
                if ($existing) {
                    if (!$existing['is_active']) {
                        $activate = $pdo->prepare(
                            'update household_members set is_active = true where household_id = :hid and user_id = :uid'
                        );
                        $activate->execute(['hid' => $currentHousehold['id'], 'uid' => $user['id']]);
                        $msg = hb_t('Member reactivated.');
                    } else {
                        $error = hb_t('User already belongs to this household.');
                    }
                } else {
                    $addStmt = $pdo->prepare(
                        'insert into household_members (household_id, user_id, role, is_active)
                         values (:hid, :uid, :role, true)'
                    );
                    $addStmt->execute([
                        'hid' => $currentHousehold['id'],
                        'uid' => $user['id'],
                        'role' => 'editor',
                    ]);
                    $msg = hb_t('Member added.');
                }
            }
        }
    }
}

if ($action === 'create_api_token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim((string)($_POST['token_label'] ?? ''));
    if ($label === '') {
        $label = 'API Token';
    }
    $plain = hb_api_token_plain();
    $hash = hb_api_token_hash($plain);
    $ins = $pdo->prepare('insert into api_tokens (user_id, token_hash, label) values (:uid, :hash, :label)');
    $ins->execute(['uid' => $userId, 'hash' => $hash, 'label' => $label]);
    $_SESSION['hb_new_api_token'] = $plain;
    header('Location: /household.php?action=settings&msg=token_created');
    exit;
}

if ($action === 'delete_api_token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenId = (int)($_POST['token_id'] ?? 0);
    if ($tokenId > 0) {
        $deleteStmt = $pdo->prepare('update api_tokens set revoked_at = now() where id = :id and user_id = :uid');
        $deleteStmt->execute(['id' => $tokenId, 'uid' => $userId]);
        if ($deleteStmt->rowCount() > 0) {
            header('Location: /household.php?action=settings&msg=token_deleted');
            exit;
        } else {
            $error = hb_t('Token not found.');
        }
    }
}

if ($action === 'revoke_oauth_client' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    if ($clientId > 0) {
        try {
            // Revoke all access tokens for this client+user
            $revokeAccess = $pdo->prepare(
                'update oauth_access_tokens set revoked = true
                 where client_id = :client_id and user_id = :uid'
            );
            $revokeAccess->execute(['client_id' => $clientId, 'uid' => $userId]);

            // Revoke all refresh tokens for this client+user
            $revokeRefresh = $pdo->prepare(
                'update oauth_refresh_tokens set revoked = true
                 where client_id = :client_id and user_id = :uid'
            );
            $revokeRefresh->execute(['client_id' => $clientId, 'uid' => $userId]);

            header('Location: /household.php?action=settings&msg=oauth_revoked');
            exit;
        } catch (Exception $e) {
            $error = hb_t('Failed to revoke OAuth client.');
        }
    }
}

$members = [];
if ($action === 'settings' && $currentHousehold) {
    $membersStmt = $pdo->prepare(
        'select u.id, u.username, u.email, u.first_name, u.last_name, m.role, m.is_active, m.created_at
           from household_members m
           join users u on u.id = m.user_id
          where m.household_id = :hid
          order by m.created_at asc, u.username asc'
    );
    $membersStmt->execute(['hid' => $currentHousehold['id']]);
    $members = $membersStmt->fetchAll();
}
$anchorAccounts = [];
$anchorCategories = [];
$anchorPayees = [];
if ($action === 'settings' && $currentHousehold) {
    $accountsStmt = $pdo->prepare('select id, name from accounts where household_id = :hid and is_archived = false order by name asc');
    $accountsStmt->execute(['hid' => $currentHousehold['id']]);
    $anchorAccounts = $accountsStmt->fetchAll();

    $categoriesStmt = $pdo->prepare("select id, name from categories where household_id = :hid and is_active = true and type = 'income' order by name asc");
    $categoriesStmt->execute(['hid' => $currentHousehold['id']]);
    $anchorCategories = $categoriesStmt->fetchAll();

    $payeesStmt = $pdo->prepare('select id, name from payees where household_id = :hid order by name asc');
    $payeesStmt->execute(['hid' => $currentHousehold['id']]);
    $anchorPayees = $payeesStmt->fetchAll();
}
$apiTokens = [];
$oauthApps = [];
if ($action === 'settings') {
    $tokenStmt = $pdo->prepare('select id, label, created_at, last_used_at, revoked_at from api_tokens where user_id = :uid order by created_at desc');
    $tokenStmt->execute(['uid' => $userId]);
    $apiTokens = $tokenStmt->fetchAll();

    // Get active OAuth authorizations
    $oauthStmt = $pdo->prepare(
        'select distinct c.id, c.name, c.client_id,
                string_agg(distinct oat.scopes, \', \' order by oat.scopes) as scopes,
                max(oat.created_at) as created_at,
                max(oat.last_used_at) as last_used_at
         from oauth_access_tokens oat
         join oauth_clients c on c.id = oat.client_id
         where oat.user_id = :uid and oat.revoked = false
         group by c.id, c.name, c.client_id
         order by max(oat.created_at) desc'
    );
    $oauthStmt->execute(['uid' => $userId]);
    $oauthApps = $oauthStmt->fetchAll();
}
$newApiToken = (string)($_SESSION['hb_new_api_token'] ?? '');
unset($_SESSION['hb_new_api_token']);

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('User:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($currentUser['username'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <?php if ($currentHousehold): ?>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="/household.php?action=settings"><?= htmlspecialchars(hb_t('Settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        <a class="btn btn-sm btn-outline-secondary" href="/accounts.php"><?= htmlspecialchars(hb_t('Go to dashboard'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Settings saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($msg === 'token_created' && $newApiToken !== ''): ?>
    <div class="alert alert-warning">API token (nur jetzt sichtbar): <code><?= htmlspecialchars($newApiToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></div>
  <?php endif; ?>
  <?php if ($msg === 'token_deleted'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('API token deleted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($msg === 'oauth_revoked'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('OAuth authorization revoked.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($action === 'settings' && $currentHousehold): ?>
    <div class="row g-4">
      <div class="col-lg-8">
          <div class="card shadow-sm">
            <div class="card-body">
              <?php if (!empty($conflict)): ?>
                <?= $conflict ?>
              <?php endif; ?>
              <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Household settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <form method="post" action="/household.php?action=update_settings">
              <input type="hidden" name="action" value="update_settings">
              <input type="hidden" name="row_version" value="<?= (int)($currentHousehold['row_version'] ?? 0) ?>">
              <div class="mb-3">
                <label class="form-label" for="name"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($currentHousehold['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="currency">
                    <?= htmlspecialchars(hb_t('Currency'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('3-letter ISO code, e.g. EUR.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <input type="text" class="form-control" id="currency" name="currency_code" maxlength="3" value="<?= htmlspecialchars($currentHousehold['currency_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="mode">
                    <?= htmlspecialchars(hb_t('Period calculation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Defines how BudgetLove calculates budget, forecast, reports and close periods.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <select class="form-select" id="mode" name="month_close_mode">
                    <?php foreach (hb_allowed_month_close_modes() as $mode): ?>
                      <option value="<?= $mode ?>" <?= $currentHousehold['month_close_mode'] === $mode ? 'selected' : '' ?>>
                        <?= htmlspecialchars(hb_month_close_mode_label($mode), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label" for="salary-day">
                  <?= htmlspecialchars(hb_t('Salary day'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Only relevant for salary_day mode; day (1-31) when a new billing month starts.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                </label>
                <input type="number" class="form-control" id="salary-day" name="salary_day" min="1" max="31" value="<?= htmlspecialchars((string)($currentHousehold['salary_day'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <div class="border rounded-3 p-3 mt-3">
                <div class="fw-semibold mb-2"><?= htmlspecialchars(hb_t('Actual salary payment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="text-muted small mb-3"><?= htmlspecialchars(hb_t('BudgetLove uses matching income bookings as the start of a new budget period.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="alert alert-info small py-2">
                  <?= htmlspecialchars(hb_t('Setup: create/import the salary booking first, then select its account, income category and payee here. The mode can be changed later by a household admin.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label" for="salary-anchor-account"><?= htmlspecialchars(hb_t('Salary account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <select class="form-select" id="salary-anchor-account" name="salary_anchor_account_id">
                      <option value=""><?= htmlspecialchars(hb_t('Any account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php foreach ($anchorAccounts as $account): ?>
                        <option value="<?= (int)$account['id'] ?>" <?= (int)($currentHousehold['salary_anchor_account_id'] ?? 0) === (int)$account['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string)$account['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="salary-anchor-category"><?= htmlspecialchars(hb_t('Salary category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <select class="form-select" id="salary-anchor-category" name="salary_anchor_category_id">
                      <option value=""><?= htmlspecialchars(hb_t('Any income category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php foreach ($anchorCategories as $category): ?>
                        <option value="<?= (int)$category['id'] ?>" <?= (int)($currentHousehold['salary_anchor_category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string)$category['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="salary-anchor-payee"><?= htmlspecialchars(hb_t('Salary payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <select class="form-select" id="salary-anchor-payee" name="salary_anchor_payee_id">
                      <option value=""><?= htmlspecialchars(hb_t('Any payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php foreach ($anchorPayees as $payee): ?>
                        <option value="<?= (int)$payee['id'] ?>" <?= (int)($currentHousehold['salary_anchor_payee_id'] ?? 0) === (int)$payee['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string)$payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>
              <button class="btn btn-success mt-3" type="submit" <?= hb_is_household_admin($currentHousehold) ? '' : 'disabled' ?>><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              <?php if (!hb_is_household_admin($currentHousehold)): ?>
                <p class="text-muted small mb-0 mt-2"><?= htmlspecialchars(hb_t('Only household admins can save.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <h2 class="h6 mb-3">API Tokens</h2>
            <form method="post" action="/household.php?action=create_api_token" class="mb-3">
              <input type="hidden" name="action" value="create_api_token">
              <label class="form-label" for="token-label">Label</label>
              <input id="token-label" name="token_label" class="form-control mb-2" type="text" placeholder="Claude / ChatGPT / Script">
              <button class="btn btn-sm btn-primary" type="submit">Token erstellen</button>
            </form>
            <?php if ($apiTokens): ?>
              <ul class="list-group list-group-flush">
                <?php foreach ($apiTokens as $t): ?>
                  <li class="list-group-item px-0">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <div class="fw-semibold"><?= htmlspecialchars((string)$t['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <div class="small text-muted">Created: <?= htmlspecialchars((string)$t['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php if ($t['last_used_at']): ?>
                          <div class="small text-muted">Last used: <?= htmlspecialchars((string)$t['last_used_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($t['revoked_at']): ?>
                          <span class="badge bg-danger small">Revoked: <?= htmlspecialchars((string)$t['revoked_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if (!$t['revoked_at']): ?>
                        <form method="post" action="/household.php?action=delete_api_token" style="display: inline;">
                          <input type="hidden" name="action" value="delete_api_token">
                          <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Diesen Token wirklich löschen?');">Delete</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
        <div class="card shadow-sm">
          <div class="card-body">
            <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Household members'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <?php if ($members): ?>
              <ul class="list-group list-group-flush mb-3">
                <?php foreach ($members as $member): ?>
                  <?php
                  $fullName = trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? ''));
                  $creator = (int)($currentHousehold['created_by_user_id'] ?? 0) === (int)$member['id'];
                  ?>
                  <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($member['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <div class="text-muted small">
                        <?= htmlspecialchars($fullName !== '' ? $fullName : ($member['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </div>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-1">
                      <span class="badge bg-light text-dark border"><?= htmlspecialchars(hb_t($member['role'] === 'admin' ? 'Admin' : 'Editor'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <?php if (!$member['is_active']): ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars(hb_t('Inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <?php elseif ($creator): ?>
                        <span class="badge bg-primary"><?= htmlspecialchars(hb_t('Creator'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="text-muted small mb-3"><?= htmlspecialchars(hb_t('No members yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="/household.php?action=add_member">
              <input type="hidden" name="action" value="add_member">
              <div class="mb-2">
                <label class="form-label" for="member-identifier"><?= htmlspecialchars(hb_t('Username or email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" id="member-identifier" name="identifier" <?= $canManageMembers ? '' : 'disabled' ?> required>
              </div>
              <button class="btn btn-primary btn-sm" type="submit" <?= $canManageMembers ? '' : 'disabled' ?>>
                <?= htmlspecialchars(hb_t('Add member'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </button>
              <?php if (!$canManageMembers): ?>
                <p class="text-muted small mt-2 mb-0"><?= htmlspecialchars(hb_t('Only the household creator can add members.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </form>
          </div>
        </div>
        <div class="card shadow-sm">
          <div class="card-body">
            <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Connected Apps'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <?php if ($oauthApps): ?>
              <ul class="list-group list-group-flush">
                <?php foreach ($oauthApps as $app): ?>
                  <li class="list-group-item px-0">
                    <div class="d-flex justify-content-between align-items-start">
                      <div class="flex-grow-1">
                        <div class="fw-semibold"><?= htmlspecialchars((string)$app['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <div class="small text-muted">Authorized: <?= htmlspecialchars((string)$app['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php if ($app['last_used_at']): ?>
                          <div class="small text-muted">Last used: <?= htmlspecialchars((string)$app['last_used_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($app['scopes']): ?>
                          <div class="small text-muted">Scopes: <code><?= htmlspecialchars((string)$app['scopes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></div>
                        <?php endif; ?>
                      </div>
                      <form method="post" action="/household.php?action=revoke_oauth_client" style="display: inline;">
                        <input type="hidden" name="action" value="revoke_oauth_client">
                        <input type="hidden" name="client_id" value="<?= (int)$app['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Zugang wirklich widerrufen? Die App kann danach nicht mehr auf deine Daten zugreifen.');">Revoke</button>
                      </form>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="text-muted small"><?= htmlspecialchars(hb_t('No connected apps.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="row g-4">
      <?php if ($households): ?>
        <div class="col-lg-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <h2 class="h6">Bestehende Haushalte</h2>
              <form method="post" action="/household.php?action=set">
                <input type="hidden" name="action" value="set">
                <div class="mb-3">
                  <label class="form-label" for="household-id"><?= htmlspecialchars(hb_t('Select household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" id="household-id" name="household_id" required>
                    <?php foreach ($households as $h): ?>
                      <option value="<?= (int)$h['id'] ?>">
                        <?= htmlspecialchars($h['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= htmlspecialchars($h['member_role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary"><?= htmlspecialchars(hb_t('Enter household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                <?php if ($currentHousehold): ?>
                  <a class="btn btn-link btn-sm" href="/household.php?action=settings"><?= htmlspecialchars(hb_t('Settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                <?php endif; ?>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-body">
            <h2 class="h6"><?= htmlspecialchars(hb_t('Create new household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <form method="post" action="/household.php?action=create">
              <input type="hidden" name="action" value="create">
              <div class="mb-3">
                <label for="name" class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" id="name" name="name" required>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label for="currency" class="form-label">
                    <?= htmlspecialchars(hb_t('Currency'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('3-letter ISO code, e.g. EUR.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <input type="text" class="form-control" id="currency" name="currency_code" value="EUR" maxlength="3" required>
                </div>
                <div class="col-md-6">
                  <label for="mode" class="form-label">
                    <?= htmlspecialchars(hb_t('Period calculation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Defines how BudgetLove calculates budget, forecast, reports and close periods.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <select class="form-select" id="mode" name="month_close_mode">
                    <?php foreach (hb_allowed_month_close_modes() as $mode): ?>
                      <option value="<?= $mode ?>"><?= htmlspecialchars(hb_month_close_mode_label($mode), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="mt-3">
                <label for="salary-day" class="form-label">
                  <?= htmlspecialchars(hb_t('Salary day (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Only required for Salary day mode.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                </label>
                <input type="number" class="form-control" id="salary-day" name="salary_day" min="1" max="31" placeholder="<?= htmlspecialchars(hb_t('1-31'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <div class="form-text"><?= htmlspecialchars(hb_t('Only required when Salary day mode is selected.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </div>
              <button type="submit" class="btn btn-success mt-3"><?= htmlspecialchars(hb_t('Create household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </form>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
