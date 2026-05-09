<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

$pageTitle = 'Accounts';
$activeNav = 'accounts';
$breadcrumbs = [
    ['label' => 'Accounts', 'href' => '/accounts.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$conflict = null;

if ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $type = (string)($_POST['type'] ?? '');
    $currency = strtoupper(trim((string)($_POST['currency_code'] ?? $household['currency_code'] ?? 'EUR')));
    $opening = hb_parse_cents((string)($_POST['opening_balance'] ?? '0'));
    $openingDateRaw = trim((string)($_POST['opening_balance_date'] ?? ''));
    $openingDate = null;
    $isArchived = isset($_POST['is_archived']);

    if ($name === '') {
        $error = hb_t('Name is required.');
    } elseif (!in_array($type, hb_allowed_account_types(), true)) {
        $error = hb_t('Invalid account type.');
    } elseif ($opening === null) {
        $error = hb_t('Opening balance is invalid.');
    } elseif ($openingDateRaw !== '') {
        $openingDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $openingDateRaw);
        if (!$openingDateObj) {
            $error = hb_t('Opening balance date is invalid.');
        } else {
            $openingDate = $openingDateObj->format('Y-m-d');
        }
    }

    if ($error === null) {
        $stmt = $pdo->prepare(
            'insert into accounts (household_id, name, type, currency_code, opening_balance_cents, opening_balance_date, is_archived)
             values (:hid, :name, :type, :cur, :open, :open_date, :archived)'
        );
        $stmt->execute([
            'hid' => $household['id'],
            'name' => $name,
            'type' => $type,
            'cur' => $currency,
            'open' => $opening,
            'open_date' => $openingDate,
            'archived' => $isArchived ? 1 : 0,
        ]);
        header('Location: /accounts.php?msg=account_saved');
        exit;
    }
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $type = (string)($_POST['type'] ?? '');
    $currency = strtoupper(trim((string)($_POST['currency_code'] ?? $household['currency_code'] ?? 'EUR')));
    $opening = hb_parse_cents((string)($_POST['opening_balance'] ?? '0'));
    $openingDateRaw = trim((string)($_POST['opening_balance_date'] ?? ''));
    $openingDate = null;
    $isArchived = isset($_POST['is_archived']);
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    $own = $pdo->prepare('select id from accounts where id = :id and household_id = :hid');
    $own->execute(['id' => $id, 'hid' => $household['id']]);
    if (!$own->fetch()) {
        $error = hb_t('Account not found.');
    } elseif ($name === '') {
        $error = hb_t('Name is required.');
    } elseif (!in_array($type, hb_allowed_account_types(), true)) {
        $error = hb_t('Invalid account type.');
    } elseif ($opening === null) {
        $error = hb_t('Opening balance is invalid.');
    } elseif ($openingDateRaw !== '') {
        $openingDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $openingDateRaw);
        if (!$openingDateObj) {
            $error = hb_t('Opening balance date is invalid.');
        } else {
            $openingDate = $openingDateObj->format('Y-m-d');
        }
    }

    if ($error === null) {
        $stmt = $pdo->prepare(
            'update accounts
                set name = :name,
                    type = :type,
                    currency_code = :cur,
                    opening_balance_cents = :open,
                    opening_balance_date = :open_date,
                    is_archived = :archived,
                    updated_at = now()
              where id = :id and household_id = :hid and row_version = :row_version'
        );
        $stmt->execute([
            'name' => $name,
            'type' => $type,
            'cur' => $currency,
            'open' => $opening,
            'open_date' => $openingDate,
            'archived' => $isArchived ? 1 : 0,
            'id' => $id,
            'hid' => $household['id'],
            'row_version' => $rowVersion,
        ]);
        if ($stmt->rowCount() === 0) {
            $fresh = $pdo->prepare('select * from accounts where id = :id and household_id = :hid');
            $fresh->execute(['id' => $id, 'hid' => $household['id']]);
            $current = $fresh->fetch() ?: [];
            $conflictRows = hb_build_conflict_rows(
                [
                    'name' => hb_t('Name'),
                    'type' => hb_t('Type'),
                    'currency_code' => hb_t('Currency'),
                    'opening_balance_cents' => hb_t('Opening balance'),
                    'opening_balance_date' => hb_t('Opening balance date'),
                    'is_archived' => hb_t('Archived'),
                ],
                $current,
                [
                    'name' => $name,
                    'type' => $type,
                    'currency_code' => $currency,
                    'opening_balance_cents' => (string)$opening,
                    'opening_balance_date' => (string)$openingDate,
                    'is_archived' => $isArchived ? '1' : '0',
                ]
            );
            $conflict = hb_render_conflict_table($conflictRows);
            $editAccount = array_merge($current, [
                'name' => $name,
                'type' => $type,
                'currency_code' => $currency,
                'opening_balance_cents' => $opening,
                'opening_balance_date' => $openingDate,
                'is_archived' => $isArchived ? 1 : 0,
                'row_version' => $current['row_version'] ?? 0,
            ]);
            $action = 'edit';
        } else {
            header('Location: /accounts.php?msg=account_saved');
            exit;
        }
    }
}

$editAccount = $editAccount ?? null;
if ($action === 'edit' && $editAccount === null) {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('select * from accounts where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $editAccount = $stmt->fetch();
    if (!$editAccount) {
        $error = hb_t('Account not found.');
        $action = 'list';
    }
}

$accounts = $pdo->prepare('select * from accounts where household_id = :hid order by created_at asc');
$accounts->execute(['hid' => $household['id']]);
$accountsList = $accounts->fetchAll();
$today = new DateTimeImmutable('today');
$balanceStmt = $pdo->prepare(
    'select a.id,
            coalesce(sum(case
                when t.type = \'income\' and t.account_id = a.id then t.amount_cents
                when t.type = \'expense\' and t.account_id = a.id then -t.amount_cents
                when t.type = \'transfer\' and t.transfer_to_account_id = a.id then t.amount_cents
                when t.type = \'transfer\' and t.transfer_from_account_id = a.id then -t.amount_cents
                else 0 end), 0) as net_cents
       from accounts a
       left join transactions t
         on t.household_id = a.household_id
        and t.booking_date <= :today
        and t.is_reviewed = true
        and (a.opening_balance_date is null or t.booking_date >= a.opening_balance_date)
      where a.household_id = :hid
      group by a.id'
);
$balanceStmt->execute([
    'hid' => $household['id'],
    'today' => $today->format('Y-m-d'),
]);
$currentBalances = [];
foreach ($balanceStmt->fetchAll() as $row) {
    $currentBalances[(int)$row['id']] = (int)$row['net_cents'];
}

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Accounts'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Household:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="d-flex gap-2 w-100 w-md-auto">
      <a href="/transactions.php" class="btn btn-sm btn-outline-primary"><?= htmlspecialchars(hb_t('Go to transactions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </div>
  </div>

  <?php if ($msg === 'account_saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Account saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 mb-0"><?= htmlspecialchars(hb_t('Overview'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <a class="btn btn-sm btn-primary" href="/accounts.php?action=new"><?= htmlspecialchars(hb_t('New account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          </div>
          <div class="table-responsive d-none d-md-block">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Currency'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Opening balance'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Current balance'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($accountsList as $acc): ?>
                  <?php
                  $accId = (int)$acc['id'];
                  $currentBalance = hb_effective_opening_balance($acc, $today) + (int)($currentBalances[$accId] ?? 0);
                  ?>
                  <tr>
                    <td><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(hb_account_type_label($acc['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($acc['currency_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= number_format(((int)$acc['opening_balance_cents']) / 100, 2, ',', '.') ?> €</td>
                    <td><?= number_format($currentBalance / 100, 2, ',', '.') ?> €</td>
                    <td>
                      <?php if ($acc['is_archived']): ?>
                        <span class="badge bg-secondary"
                              data-bs-toggle="tooltip"
                              title="<?= htmlspecialchars(hb_t('Archived accounts remain visible but cannot be used for new transactions.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                          <?= htmlspecialchars(hb_t('Archived'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </span>
                      <?php else: ?>
                        <span class="badge bg-success"><?= htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <?php endif; ?>
                    </td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="/accounts.php?action=edit&id=<?= (int)$acc['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$accountsList): ?>
                  <tr><td colspan="7" class="text-muted"><?= htmlspecialchars(hb_t('No accounts available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="d-md-none">
            <?php foreach ($accountsList as $acc): ?>
              <?php
              $accId = (int)$acc['id'];
              $currentBalance = hb_effective_opening_balance($acc, $today) + (int)($currentBalances[$accId] ?? 0);
              ?>
              <div class="hb-mobile-card p-3">
                <div class="hb-mobile-card-row mb-3">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="text-muted small"><?= htmlspecialchars(hb_account_type_label($acc['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars($acc['currency_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </div>
                  <div>
                    <?php if ($acc['is_archived']): ?>
                      <span class="badge bg-secondary"><?= htmlspecialchars(hb_t('Archived'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php else: ?>
                      <span class="badge bg-success"><?= htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="hb-mobile-meta">
                  <div>
                    <span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Opening balance'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <div><?= number_format(((int)$acc['opening_balance_cents']) / 100, 2, ',', '.') ?> €</div>
                  </div>
                  <div>
                    <span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Current balance'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <div class="fw-semibold"><?= number_format($currentBalance / 100, 2, ',', '.') ?> €</div>
                  </div>
                </div>
                <div class="hb-mobile-actions mt-3">
                  <a class="btn btn-outline-secondary btn-sm" href="/accounts.php?action=edit&id=<?= (int)$acc['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (!$accountsList): ?>
              <div class="text-muted"><?= htmlspecialchars(hb_t('No accounts available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <?php if (!empty($conflict)): ?>
            <?= $conflict ?>
          <?php endif; ?>
          <?php
          $isEdit = $action === 'edit' && $editAccount;
          $targetAction = $isEdit ? 'update' : 'store';
          ?>
          <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t($isEdit ? 'Edit account' : 'New account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <form method="post" action="/accounts.php">
            <input type="hidden" name="action" value="<?= $targetAction ?>">
            <?php if ($isEdit): ?>
              <input type="hidden" name="id" value="<?= (int)$editAccount['id'] ?>">
              <input type="hidden" name="row_version" value="<?= (int)($editAccount['row_version'] ?? 0) ?>">
            <?php endif; ?>
            <div class="mb-3">
              <label for="name" class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="name" name="name" required value="<?= htmlspecialchars($editAccount['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label for="type" class="form-label">
                  <?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Income or expense drives later reports.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                </label>
                <select class="form-select" id="type" name="type">
                  <?php foreach (hb_allowed_account_types() as $type): ?>
                    <option value="<?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                      <?= ($editAccount['type'] ?? '') === $type ? 'selected' : '' ?>>
                      <?= htmlspecialchars(hb_account_type_label($type), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label for="currency" class="form-label"><?= htmlspecialchars(hb_t('Currency'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" id="currency" name="currency_code" maxlength="3" value="<?= htmlspecialchars($editAccount['currency_code'] ?? ($household['currency_code'] ?? 'EUR'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
              </div>
            </div>
            <div class="mt-3">
              <label for="opening" class="form-label"><?= htmlspecialchars(hb_t('Opening balance'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="opening" name="opening_balance" value="<?= isset($editAccount) ? number_format(((int)$editAccount['opening_balance_cents']) / 100, 2, ',', '.') : '0,00' ?>">
            </div>
            <div class="mt-3">
              <label for="opening-date" class="form-label"><?= htmlspecialchars(hb_t('Opening balance date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="date" class="form-control" id="opening-date" name="opening_balance_date" value="<?= htmlspecialchars($editAccount['opening_balance_date'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" id="archived" name="is_archived" <?= !empty($editAccount['is_archived']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="archived">
                <?= htmlspecialchars(hb_t('Archived'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                <span class="ms-1 text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Archived accounts cannot be selected for new transactions, but remain visible in reports.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
              </label>
              <div class="form-text"><?= htmlspecialchars(hb_t('Use archive instead of delete to keep historical bookings.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
            <button type="submit" class="btn btn-success mt-3"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
