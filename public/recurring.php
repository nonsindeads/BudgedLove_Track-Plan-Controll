<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$serverPdo = hb_get_pdo();
$household = hb_require_household($serverPdo);
$pdo = hb_household_pdo($serverPdo, (int)$household['id']);
$currentHousehold = $household;
$currentUser = hb_current_user($serverPdo);

$pageTitle = 'Recurring payments';
$activeNav = 'recurring';
$breadcrumbs = [
    ['label' => 'Recurring payments', 'href' => '/recurring.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$conflict = null;

$accounts = $pdo->prepare('select * from accounts where household_id = :hid and is_archived = false order by name asc');
$accounts->execute(['hid' => $household['id']]);
$accounts = $accounts->fetchAll();

$catStmt = $pdo->prepare('select * from categories where household_id = :hid and is_active = true order by type asc, name asc');
$catStmt->execute(['hid' => $household['id']]);
$categories = $catStmt->fetchAll();

$payeeStmt = $pdo->prepare('select * from payees where household_id = :hid order by name asc');
$payeeStmt->execute(['hid' => $household['id']]);
$payees = $payeeStmt->fetchAll();

if (in_array($action, ['store', 'update'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $direction = (string)($_POST['direction'] ?? 'expense');
    $amount = hb_parse_cents((string)($_POST['amount'] ?? ''));
    $intervalUnit = (string)($_POST['interval_unit'] ?? 'month');
    $intervalValue = (int)($_POST['interval_value'] ?? 1);
    $startDate = (string)($_POST['start_date'] ?? '');
    $endDate = (string)($_POST['end_date'] ?? '');
    $priority = (int)($_POST['priority'] ?? 3);
    $isOptional = isset($_POST['is_optional']);
    $amountMode = (string)($_POST['amount_mode'] ?? 'fixed');
    $toleranceAmount = hb_parse_cents((string)($_POST['tolerance_amount'] ?? ''));
    $tolerancePctRaw = trim((string)($_POST['tolerance_pct'] ?? ''));
    $tolerancePct = $tolerancePctRaw !== '' ? (float)str_replace(',', '.', $tolerancePctRaw) : null;
    $minAmount = hb_parse_cents((string)($_POST['min_amount'] ?? ''));
    $maxAmount = hb_parse_cents((string)($_POST['max_amount'] ?? ''));
    $accountId = $_POST['account_id'] !== '' ? (int)$_POST['account_id'] : null;
    $categoryId = $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
    $payeeId = $_POST['payee_id'] !== '' ? (int)$_POST['payee_id'] : null;
    $note = trim((string)($_POST['note'] ?? ''));
    $isActive = isset($_POST['is_active']);
    $id = (int)($_POST['id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    if ($name === '') {
        $error = hb_t('Name is required.');
    } elseif (!in_array($direction, ['income', 'expense'], true)) {
        $error = hb_t('Invalid direction.');
    } elseif ($amount === null || $amount <= 0) {
        $error = hb_t('Amount is invalid.');
    } elseif (!in_array($amountMode, ['fixed', 'tolerance', 'range'], true)) {
        $error = hb_t('Invalid amount logic.');
    } elseif ($amountMode === 'tolerance' && $toleranceAmount === null && $tolerancePct === null) {
        $error = hb_t('Tolerance is required.');
    } elseif ($amountMode === 'range' && ($minAmount === null || $maxAmount === null)) {
        $error = hb_t('Min and max amount are required.');
    } elseif (!in_array($intervalUnit, ['day', 'week', 'month', 'year'], true)) {
        $error = hb_t('Invalid interval.');
    } elseif ($intervalValue < 1) {
        $error = hb_t('Interval value must be positive.');
    } elseif ($startDate === '') {
        $error = hb_t('Start date is required.');
    }

    if ($error === null) {
        $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if ($startDateObj && hb_is_period_closed($pdo, $household['id'], $startDateObj)) {
            $error = hb_t('The month is already closed. Changes are locked.');
        }
        if ($endDate !== '') {
            $endDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
            if (!$endDateObj) {
                $error = hb_t('End date is invalid.');
            } elseif ($startDateObj && $endDateObj < $startDateObj) {
                $error = hb_t('End date must be after start date.');
            }
        }
    }

    if ($error === null) {
        if ($action === 'store') {
            $stmt = $pdo->prepare(
                'insert into recurring_payments
                    (household_id, name, direction, amount_cents, interval_unit, interval_value, start_date, end_date,
                     priority, is_optional, account_id, category_id, payee_id, note, is_active,
                     amount_mode, tolerance_cents, tolerance_pct, min_amount_cents, max_amount_cents)
                 values
                    (:hid, :name, :direction, :amount, :unit, :ival, :start_date, :end_date,
                     :priority, :is_optional, :account_id, :category_id, :payee_id, :note, :is_active,
                     :amount_mode, :tolerance_cents, :tolerance_pct, :min_amount_cents, :max_amount_cents)'
            );
            $stmt->execute([
                'hid' => $household['id'],
                'name' => $name,
                'direction' => $direction,
                'amount' => $amount,
                'unit' => $intervalUnit,
                'ival' => $intervalValue,
                'start_date' => $startDate,
                'end_date' => $endDate !== '' ? $endDate : null,
                'priority' => $priority,
                'is_optional' => $isOptional ? 1 : 0,
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'payee_id' => $payeeId,
                'note' => $note !== '' ? $note : null,
                'is_active' => $isActive ? 1 : 0,
                'amount_mode' => $amountMode,
                'tolerance_cents' => $amountMode === 'tolerance' ? $toleranceAmount : null,
                'tolerance_pct' => $amountMode === 'tolerance' ? $tolerancePct : null,
                'min_amount_cents' => $amountMode === 'range' ? $minAmount : null,
                'max_amount_cents' => $amountMode === 'range' ? $maxAmount : null,
            ]);
            header('Location: /recurring.php?msg=saved');
            exit;
        }

        $stmt = $pdo->prepare(
            'update recurring_payments
                set name = :name,
                    direction = :direction,
                    amount_cents = :amount,
                    interval_unit = :unit,
                    interval_value = :ival,
                    start_date = :start_date,
                    end_date = :end_date,
                    priority = :priority,
                    is_optional = :is_optional,
                    account_id = :account_id,
                    category_id = :category_id,
                    payee_id = :payee_id,
                    note = :note,
                    is_active = :is_active,
                    amount_mode = :amount_mode,
                    tolerance_cents = :tolerance_cents,
                    tolerance_pct = :tolerance_pct,
                    min_amount_cents = :min_amount_cents,
                    max_amount_cents = :max_amount_cents,
                    updated_at = :updated_at
              where id = :id and household_id = :hid and row_version = :row_version'
        );
        $stmt->execute([
            'name' => $name,
            'direction' => $direction,
            'amount' => $amount,
            'unit' => $intervalUnit,
            'ival' => $intervalValue,
            'start_date' => $startDate,
            'end_date' => $endDate !== '' ? $endDate : null,
            'priority' => $priority,
            'is_optional' => $isOptional ? 1 : 0,
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'payee_id' => $payeeId,
            'note' => $note !== '' ? $note : null,
            'is_active' => $isActive ? 1 : 0,
            'amount_mode' => $amountMode,
            'tolerance_cents' => $amountMode === 'tolerance' ? $toleranceAmount : null,
            'tolerance_pct' => $amountMode === 'tolerance' ? $tolerancePct : null,
            'min_amount_cents' => $amountMode === 'range' ? $minAmount : null,
            'max_amount_cents' => $amountMode === 'range' ? $maxAmount : null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $id,
            'hid' => $household['id'],
            'row_version' => $rowVersion,
        ]);

        if ($stmt->rowCount() === 0) {
            $fresh = $pdo->prepare('select * from recurring_payments where id = :id and household_id = :hid');
            $fresh->execute(['id' => $id, 'hid' => $household['id']]);
            $current = $fresh->fetch() ?: [];
            $conflictRows = hb_build_conflict_rows(
                [
                    'name' => hb_t('Name'),
                    'direction' => hb_t('Direction'),
                    'amount_cents' => hb_t('Amount'),
                    'interval_unit' => hb_t('Interval'),
                    'interval_value' => hb_t('Interval value'),
                    'start_date' => hb_t('Start date'),
                    'end_date' => hb_t('End date'),
                    'priority' => hb_t('Priority'),
                    'is_optional' => hb_t('Optional'),
                    'amount_mode' => hb_t('Amount logic'),
                    'tolerance_cents' => hb_t('Tolerance (amount)'),
                    'tolerance_pct' => hb_t('Tolerance (%)'),
                    'min_amount_cents' => hb_t('Min amount'),
                    'max_amount_cents' => hb_t('Max amount'),
                    'account_id' => hb_t('Account'),
                    'category_id' => hb_t('Category'),
                    'payee_id' => hb_t('Payee'),
                    'note' => hb_t('Note'),
                    'is_active' => hb_t('Active'),
                ],
                $current,
                [
                    'name' => $name,
                    'direction' => $direction,
                    'amount_cents' => (string)$amount,
                    'interval_unit' => $intervalUnit,
                    'interval_value' => (string)$intervalValue,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'priority' => (string)$priority,
                    'is_optional' => $isOptional ? '1' : '0',
                    'amount_mode' => $amountMode,
                    'tolerance_cents' => (string)($toleranceAmount ?? ''),
                    'tolerance_pct' => $tolerancePct !== null ? (string)$tolerancePct : '',
                    'min_amount_cents' => (string)($minAmount ?? ''),
                    'max_amount_cents' => (string)($maxAmount ?? ''),
                    'account_id' => (string)($accountId ?? ''),
                    'category_id' => (string)($categoryId ?? ''),
                    'payee_id' => (string)($payeeId ?? ''),
                    'note' => $note,
                    'is_active' => $isActive ? '1' : '0',
                ]
            );
            $conflict = hb_render_conflict_table($conflictRows);
            $editRecurring = array_merge($current, [
                'name' => $name,
                'direction' => $direction,
                'amount_cents' => $amount,
                'interval_unit' => $intervalUnit,
                'interval_value' => $intervalValue,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'priority' => $priority,
                'is_optional' => $isOptional ? 1 : 0,
                'amount_mode' => $amountMode,
                'tolerance_cents' => $toleranceAmount,
                'tolerance_pct' => $tolerancePct,
                'min_amount_cents' => $minAmount,
                'max_amount_cents' => $maxAmount,
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'payee_id' => $payeeId,
                'note' => $note,
                'is_active' => $isActive ? 1 : 0,
                'row_version' => $current['row_version'] ?? 0,
            ]);
            $action = 'edit';
        } else {
            header('Location: /recurring.php?msg=saved');
            exit;
        }
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $recId = (int)($_POST['id'] ?? 0);
    $own = $pdo->prepare('select id from recurring_payments where id = :id and household_id = :hid');
    $own->execute(['id' => $recId, 'hid' => $household['id']]);
    if (!$own->fetch()) {
        $error = hb_t('Recurring entry not found.');
    } else {
        $del = $pdo->prepare('delete from recurring_payments where id = :id and household_id = :hid');
        $del->execute(['id' => $recId, 'hid' => $household['id']]);
        header('Location: /recurring.php?msg=deleted');
        exit;
    }
}

$editRecurring = $editRecurring ?? null;
if ($action === 'edit' && $editRecurring === null) {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('select * from recurring_payments where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $editRecurring = $stmt->fetch();
    if (!$editRecurring) {
        $error = hb_t('Recurring entry not found.');
        $action = 'list';
    }
}

$recurringsStmt = $pdo->prepare('select * from recurring_payments where household_id = :hid order by is_active desc, name asc');
$recurringsStmt->execute(['hid' => $household['id']]);
$recurrings = $recurringsStmt->fetchAll();

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Recurring payments'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Plan baseline for the period forecast'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="d-flex gap-2 w-100 w-md-auto">
      <a class="btn btn-sm btn-outline-secondary" href="/plan.php"><?= htmlspecialchars(hb_t('Period plan'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Entry saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Entry deleted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('List'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <div class="table-responsive d-none d-md-block">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Direction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Logic'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Interval'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('End date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recurrings as $rec): ?>
                  <?php
                  $directionLabel = $rec['direction'] === 'income'
                      ? hb_t('Income')
                      : ($rec['direction'] === 'expense' ? hb_t('Expense') : (string)$rec['direction']);
                  $intervalLabelMap = [
                      'day' => hb_t('Days'),
                      'week' => hb_t('Weeks'),
                      'month' => hb_t('Months'),
                      'year' => hb_t('Years'),
                  ];
                  $intervalLabel = $intervalLabelMap[$rec['interval_unit']] ?? $rec['interval_unit'];
                  ?>
                  <tr>
                    <td><?= htmlspecialchars($rec['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($directionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= number_format($rec['amount_cents'] / 100, 2, ',', '.') ?> €</td>
                    <td class="small text-muted">
                      <?php
                      $mode = $rec['amount_mode'] ?? 'fixed';
                      if ($mode === 'tolerance') {
                          $tolParts = [];
                          if (!empty($rec['tolerance_cents'])) {
                              $tolParts[] = number_format($rec['tolerance_cents'] / 100, 2, ',', '.') . ' €';
                          }
                          if (!empty($rec['tolerance_pct'])) {
                              $tolParts[] = rtrim(rtrim(number_format((float)$rec['tolerance_pct'], 2, ',', '.'), '0'), ',') . ' %';
                          }
                          echo htmlspecialchars(hb_t('Tolerance'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' ' . htmlspecialchars(implode(' / ', $tolParts), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                      } elseif ($mode === 'range') {
                          $min = isset($rec['min_amount_cents']) ? number_format($rec['min_amount_cents'] / 100, 2, ',', '.') . ' €' : '-';
                          $max = isset($rec['max_amount_cents']) ? number_format($rec['max_amount_cents'] / 100, 2, ',', '.') . ' €' : '-';
                          echo htmlspecialchars(hb_t('Range'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' ' . htmlspecialchars($min . '–' . $max, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                      } else {
                          echo htmlspecialchars(hb_t('Fixed'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                      }
                      ?>
                    </td>
                    <td><?= (int)$rec['interval_value'] ?> <?= htmlspecialchars($intervalLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($rec['end_date'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= $rec['is_active'] ? htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : htmlspecialchars(hb_t('Inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="text-end">
                      <div class="d-flex justify-content-end gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="/recurring.php?action=edit&id=<?= (int)$rec['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                        <form method="post" action="/recurring.php" data-confirm="<?= htmlspecialchars(hb_t('Delete recurring payment?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= (int)$rec['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$recurrings): ?>
                  <tr><td colspan="8" class="text-muted"><?= htmlspecialchars(hb_t('No entries available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="d-md-none">
            <?php foreach ($recurrings as $rec): ?>
              <?php
              $directionLabel = $rec['direction'] === 'income'
                  ? hb_t('Income')
                  : ($rec['direction'] === 'expense' ? hb_t('Expense') : (string)$rec['direction']);
              $intervalLabelMap = [
                  'day' => hb_t('Days'),
                  'week' => hb_t('Weeks'),
                  'month' => hb_t('Months'),
                  'year' => hb_t('Years'),
              ];
              $intervalLabel = $intervalLabelMap[$rec['interval_unit']] ?? $rec['interval_unit'];
              $mode = $rec['amount_mode'] ?? 'fixed';
              if ($mode === 'tolerance') {
                  $tolParts = [];
                  if (!empty($rec['tolerance_cents'])) {
                      $tolParts[] = number_format($rec['tolerance_cents'] / 100, 2, ',', '.') . ' €';
                  }
                  if (!empty($rec['tolerance_pct'])) {
                      $tolParts[] = rtrim(rtrim(number_format((float)$rec['tolerance_pct'], 2, ',', '.'), '0'), ',') . ' %';
                  }
                  $logicLabel = hb_t('Tolerance') . ' ' . implode(' / ', $tolParts);
              } elseif ($mode === 'range') {
                  $min = isset($rec['min_amount_cents']) ? number_format($rec['min_amount_cents'] / 100, 2, ',', '.') . ' €' : '-';
                  $max = isset($rec['max_amount_cents']) ? number_format($rec['max_amount_cents'] / 100, 2, ',', '.') . ' €' : '-';
                  $logicLabel = hb_t('Range') . ' ' . $min . ' - ' . $max;
              } else {
                  $logicLabel = hb_t('Fixed');
              }
              ?>
              <div class="hb-mobile-card p-3">
                <div class="hb-mobile-card-row mb-2">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($rec['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="text-muted small"><?= htmlspecialchars($directionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </div>
                  <div class="fw-semibold"><?= number_format($rec['amount_cents'] / 100, 2, ',', '.') ?> €</div>
                </div>
                <div class="hb-mobile-meta">
                  <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Logic'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= htmlspecialchars($logicLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Interval'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= (int)$rec['interval_value'] ?> <?= htmlspecialchars($intervalLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('End date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= htmlspecialchars($rec['end_date'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= $rec['is_active'] ? htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : htmlspecialchars(hb_t('Inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
                <div class="hb-mobile-actions mt-3">
                  <a class="btn btn-sm btn-outline-secondary" href="/recurring.php?action=edit&id=<?= (int)$rec['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                  <form method="post" action="/recurring.php" data-confirm="<?= htmlspecialchars(hb_t('Delete recurring payment?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$rec['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
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
          <?php $isEdit = $action === 'edit' && $editRecurring; ?>
          <h2 class="h6 mb-3"><?= htmlspecialchars($isEdit ? hb_t('Edit payment') : hb_t('New payment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <form method="post" action="/recurring.php">
            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'store' ?>">
            <?php if ($isEdit): ?>
              <input type="hidden" name="id" value="<?= (int)$editRecurring['id'] ?>">
              <input type="hidden" name="row_version" value="<?= (int)($editRecurring['row_version'] ?? 0) ?>">
            <?php endif; ?>
            <div class="mb-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" name="name" required value="<?= htmlspecialchars($editRecurring['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Direction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select" name="direction">
                  <?php foreach (['income' => hb_t('Income'), 'expense' => hb_t('Expense')] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($editRecurring['direction'] ?? 'expense') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" name="amount" required value="<?= isset($editRecurring['amount_cents']) ? number_format($editRecurring['amount_cents'] / 100, 2, ',', '.') : '' ?>">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Amount logic'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select" name="amount_mode">
                  <?php foreach (['fixed' => hb_t('Fixed'), 'tolerance' => hb_t('Tolerance'), 'range' => hb_t('Range')] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($editRecurring['amount_mode'] ?? 'fixed') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6" data-recurring-group="tolerance">
                <label class="form-label"><?= htmlspecialchars(hb_t('Tolerance (amount)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" name="tolerance_amount" value="<?= isset($editRecurring['tolerance_cents']) ? number_format($editRecurring['tolerance_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="<?= htmlspecialchars(hb_t('e.g. 5.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6" data-recurring-group="tolerance">
                <label class="form-label"><?= htmlspecialchars(hb_t('Tolerance (%)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" name="tolerance_pct" value="<?= htmlspecialchars((string)($editRecurring['tolerance_pct'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(hb_t('e.g. 5'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <div class="col-md-6" data-recurring-group="range">
                <label class="form-label"><?= htmlspecialchars(hb_t('Min/Max (range)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <div class="input-group">
                  <input type="text" class="form-control" name="min_amount" value="<?= isset($editRecurring['min_amount_cents']) ? number_format($editRecurring['min_amount_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="<?= htmlspecialchars(hb_t('Min'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <span class="input-group-text">–</span>
                  <input type="text" class="form-control" name="max_amount" value="<?= isset($editRecurring['max_amount_cents']) ? number_format($editRecurring['max_amount_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="<?= htmlspecialchars(hb_t('Max'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Interval'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select" name="interval_unit">
                  <?php foreach (['day' => hb_t('Days'), 'week' => hb_t('Weeks'), 'month' => hb_t('Months'), 'year' => hb_t('Years')] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($editRecurring['interval_unit'] ?? 'month') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Interval value'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="number" class="form-control" name="interval_value" min="1" value="<?= (int)($editRecurring['interval_value'] ?? 1) ?>">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Start date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="date" class="form-control" name="start_date" required value="<?= htmlspecialchars($editRecurring['start_date'] ?? date('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('End date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($editRecurring['end_date'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Priority'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="number" class="form-control" name="priority" min="1" max="5" value="<?= (int)($editRecurring['priority'] ?? 3) ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select" name="account_id">
                  <option value=""><?= htmlspecialchars(hb_t('None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php foreach ($accounts as $acc): ?>
                    <option value="<?= (int)$acc['id'] ?>" <?= ($editRecurring['account_id'] ?? null) == $acc['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select" name="category_id">
                  <option value=""><?= htmlspecialchars(hb_t('None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= ($editRecurring['category_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select" name="payee_id">
                  <option value=""><?= htmlspecialchars(hb_t('None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php foreach ($payees as $payee): ?>
                    <option value="<?= (int)$payee['id'] ?>" <?= ($editRecurring['payee_id'] ?? null) == $payee['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6 d-flex align-items-center">
                <div class="form-check mt-4">
                  <input class="form-check-input" type="checkbox" name="is_optional" id="is-optional" <?= !empty($editRecurring['is_optional']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="is-optional"><?= htmlspecialchars(hb_t('Optional'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                </div>
              </div>
            </div>
            <div class="mt-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <textarea class="form-control" name="note" rows="2"><?= htmlspecialchars($editRecurring['note'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </div>
            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" name="is_active" id="is-active" <?= !empty($editRecurring['is_active']) || $editRecurring === null ? 'checked' : '' ?>>
              <label class="form-check-label" for="is-active"><?= htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
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
$extraScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', () => {
  const toggleRecurringFields = () => {
    document.querySelectorAll('form[action="/recurring.php"]').forEach((form) => {
      const mode = form.querySelector('select[name="amount_mode"]')?.value || 'fixed';
      const showTolerance = mode === 'tolerance';
      const showRange = mode === 'range';
      form.querySelectorAll('[data-recurring-group="tolerance"]').forEach((el) => {
        el.classList.toggle('d-none', !showTolerance);
      });
      form.querySelectorAll('[data-recurring-group="range"]').forEach((el) => {
        el.classList.toggle('d-none', !showRange);
      });
    });
  };
  toggleRecurringFields();
  document.querySelectorAll('select[name="amount_mode"]').forEach((select) => {
    select.addEventListener('change', toggleRecurringFields);
  });
});
</script>
HTML;
require __DIR__ . '/../templates/layout.php';
