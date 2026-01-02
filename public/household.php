<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

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
        $salaryDay = $_POST['salary_day'] !== '' ? (int)$_POST['salary_day'] : null;
        $rowVersion = (int)($_POST['row_version'] ?? 0);
        if ($name === '') {
            $error = hb_t('Name is required.');
        } elseif (!in_array($mode, hb_allowed_month_close_modes(), true)) {
            $error = hb_t('Invalid mode.');
        } elseif ($mode === 'salary_day' && ($salaryDay === null || $salaryDay < 1 || $salaryDay > 31)) {
            $error = hb_t('Valid salary day (1-31) required.');
        } else {
            $stmt = $pdo->prepare(
                'update households
                    set name = :name,
                        currency_code = :currency,
                        month_close_mode = :mode,
                        salary_day = :salary,
                        updated_at = now()
                  where id = :id and row_version = :row_version'
            );
            $stmt->execute([
                'name' => $name,
                'currency' => $currency,
                'mode' => $mode,
                'salary' => $salaryDay,
                'id' => $currentHousehold['id'],
                'row_version' => $rowVersion,
            ]);
            if ($stmt->rowCount() === 0) {
                $currentHousehold = hb_current_household($pdo);
                $conflictRows = hb_build_conflict_rows(
                    [
                        'name' => hb_t('Name'),
                        'currency_code' => hb_t('Currency'),
                        'month_close_mode' => hb_t('Month close'),
                        'salary_day' => hb_t('Salary day'),
                    ],
                    $currentHousehold ?? [],
                    [
                        'name' => $name,
                        'currency_code' => $currency,
                        'month_close_mode' => $mode,
                        'salary_day' => $salaryDay !== null ? (string)$salaryDay : '',
                    ]
                );
                $conflict = hb_render_conflict_table($conflictRows);
                $currentHousehold = array_merge($currentHousehold ?? [], [
                    'name' => $name,
                    'currency_code' => $currency,
                    'month_close_mode' => $mode,
                    'salary_day' => $salaryDay,
                ]);
            } else {
                header('Location: /household.php?action=settings&msg=saved');
                exit;
            }
        }
    }
}

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
                    <?= htmlspecialchars(hb_t('Month close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Defines when the billing month ends (start of month or custom salary day).'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <select class="form-select" id="mode" name="month_close_mode">
                    <?php foreach (hb_allowed_month_close_modes() as $mode): ?>
                      <option value="<?= $mode ?>" <?= $currentHousehold['month_close_mode'] === $mode ? 'selected' : '' ?>>
                        <?= htmlspecialchars(hb_t($mode === 'salary_day' ? 'Salary day' : 'Start of month'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
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
              <button class="btn btn-success mt-3" type="submit" <?= hb_is_household_admin($currentHousehold) ? '' : 'disabled' ?>><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              <?php if (!hb_is_household_admin($currentHousehold)): ?>
                <p class="text-muted small mb-0 mt-2"><?= htmlspecialchars(hb_t('Only household admins can save.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </form>
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
                    <?= htmlspecialchars(hb_t('Month close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Defines when the billing month ends (start of month or custom salary day).'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <select class="form-select" id="mode" name="month_close_mode">
                    <option value="first_of_month"><?= htmlspecialchars(hb_t('Start of month'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <option value="salary_day"><?= htmlspecialchars(hb_t('Salary day'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
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
