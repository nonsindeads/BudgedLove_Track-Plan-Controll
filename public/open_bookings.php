<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

$pageTitle = 'Open bookings';
$activeNav = 'open_bookings';
$breadcrumbs = [
    ['label' => 'Open bookings', 'href' => '/open_bookings.php'],
];

$action = $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$conflict = null;

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txId = (int)($_POST['transaction_id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);
    $categoryId = $_POST['category_id'] !== '' ? (int)($_POST['category_id'] ?? 0) : null;
    $payeeId = $_POST['payee_id'] !== '' ? (int)($_POST['payee_id'] ?? 0) : null;
    $plannedPaymentId = $_POST['planned_payment_id'] !== '' ? (int)($_POST['planned_payment_id'] ?? 0) : null;
    $note = trim((string)($_POST['note'] ?? ''));
    $tagIds = array_map('intval', $_POST['tag_ids'] ?? []);
    $splitCats = $_POST['split_category_id'] ?? [];
    $splitAmounts = $_POST['split_amount'] ?? [];

    $txCheck = $pdo->prepare('select id, amount_cents from transactions where id = :id and household_id = :hid and is_reviewed = false');
    $txCheck->execute(['id' => $txId, 'hid' => $household['id']]);
    $txRow = $txCheck->fetch();
    if (!$txRow) {
        $error = hb_t('Booking not found or already reviewed.');
    }

    if ($error === null && $categoryId !== null) {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        $catCheck->execute(['id' => $categoryId, 'hid' => $household['id']]);
        if (!$catCheck->fetch()) {
            $error = hb_t('Category does not belong to the household.');
        }
    }
    if ($error === null && $payeeId !== null) {
        $payeeCheck = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
        $payeeCheck->execute(['id' => $payeeId, 'hid' => $household['id']]);
        if (!$payeeCheck->fetch()) {
            $error = hb_t('Payee does not belong to the household.');
        }
    }
    if ($error === null && $plannedPaymentId !== null) {
        $planCheck = $pdo->prepare('select id from planned_payments where id = :id and household_id = :hid');
        $planCheck->execute(['id' => $plannedPaymentId, 'hid' => $household['id']]);
        if (!$planCheck->fetch()) {
            $error = hb_t('Planned payment does not belong to the household.');
        }
    }
    if ($error === null && $tagIds) {
        $tagCheck = $pdo->prepare('select id from tags where id = :id and household_id = :hid');
        foreach ($tagIds as $tagId) {
            $tagCheck->execute(['id' => $tagId, 'hid' => $household['id']]);
            if (!$tagCheck->fetch()) {
                $error = hb_t('Tag does not belong to the household.');
                break;
            }
        }
    }

    $splits = [];
    $splitSum = 0;
    if ($error === null) {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        foreach ($splitCats as $idx => $catIdRaw) {
            $catId = (int)$catIdRaw;
            $cents = hb_parse_cents((string)($splitAmounts[$idx] ?? ''));
            if ($catId && $cents !== null && $cents > 0) {
                $catCheck->execute(['id' => $catId, 'hid' => $household['id']]);
                if (!$catCheck->fetch()) {
                    $error = hb_t('Split category does not belong to the household.');
                    break;
                }
                $splits[] = ['category_id' => $catId, 'amount_cents' => $cents];
                $splitSum += $cents;
            }
        }
        if ($error === null && $splits && $splitSum !== (int)$txRow['amount_cents']) {
            $error = hb_t('Split total must match the amount.');
        }
    }

    if ($error === null && !$splits && $categoryId === null) {
        $error = hb_t('Category is required.');
    }

    if ($error === null) {
        $stmt = $pdo->prepare(
            'update transactions
                set category_id = :category_id,
                    payee_id = :payee_id,
                    planned_payment_id = :planned_payment_id,
                    note = :note,
                    is_reviewed = true,
                    suggested_payee_id = null,
                    suggested_planned_payment_id = null,
                    updated_at = now()
              where id = :id and household_id = :hid and row_version = :row_version and is_reviewed = false'
        );
        $stmt->execute([
            'category_id' => $categoryId,
            'payee_id' => $payeeId,
            'planned_payment_id' => $plannedPaymentId,
            'note' => $note !== '' ? $note : null,
            'id' => $txId,
            'hid' => $household['id'],
            'row_version' => $rowVersion,
        ]);
        if ($stmt->rowCount() === 0) {
            $fresh = $pdo->prepare('select * from transactions where id = :id and household_id = :hid');
            $fresh->execute(['id' => $txId, 'hid' => $household['id']]);
            $current = $fresh->fetch() ?: [];
            $conflictRows = hb_build_conflict_rows(
                [
                    'category_id' => hb_t('Category'),
                    'payee_id' => hb_t('Payee'),
                    'planned_payment_id' => hb_t('Planned payment'),
                    'note' => hb_t('Note'),
                ],
                $current,
                [
                    'category_id' => (string)($categoryId ?? ''),
                    'payee_id' => (string)($payeeId ?? ''),
                    'planned_payment_id' => (string)($plannedPaymentId ?? ''),
                    'note' => $note,
                ]
            );
            $conflict = hb_render_conflict_table($conflictRows);
        } else {
            $pdo->prepare('delete from transaction_splits where transaction_id = :id')->execute(['id' => $txId]);
            foreach ($splits as $split) {
                $ins = $pdo->prepare(
                    'insert into transaction_splits (transaction_id, category_id, amount_cents, note)
                     values (:tid, :cid, :amount, null)'
                );
                $ins->execute([
                    'tid' => $txId,
                    'cid' => $split['category_id'],
                    'amount' => $split['amount_cents'],
                ]);
            }
            $pdo->prepare('delete from transaction_tags where transaction_id = :id')->execute(['id' => $txId]);
            foreach ($tagIds as $tagId) {
                $pdo->prepare('insert into transaction_tags (transaction_id, tag_id) values (:tid, :tag)')
                    ->execute(['tid' => $txId, 'tag' => $tagId]);
            }
            if ($plannedPaymentId !== null) {
                $planUpdate = $pdo->prepare(
                    "update planned_payments
                        set status = 'done',
                            resolved_at = now(),
                            resolved_transaction_id = :tx_id,
                            updated_at = now()
                      where id = :id and household_id = :hid"
                );
                $planUpdate->execute(['tx_id' => $txId, 'id' => $plannedPaymentId, 'hid' => $household['id']]);
            }
            header('Location: /open_bookings.php?msg=saved');
            exit;
        }
    }
}

if ($action === 'create_recurring' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $txId = (int)($_POST['transaction_id'] ?? 0);
    $name = trim((string)($_POST['recurring_name'] ?? ''));
    $intervalUnit = (string)($_POST['recurring_interval_unit'] ?? 'month');
    $intervalValue = (int)($_POST['recurring_interval_value'] ?? 1);
    $startDate = (string)($_POST['recurring_start_date'] ?? '');
    $endDate = (string)($_POST['recurring_end_date'] ?? '');
    $priority = (int)($_POST['recurring_priority'] ?? 3);
    $isOptional = isset($_POST['recurring_is_optional']);
    $amountMode = (string)($_POST['recurring_amount_mode'] ?? 'fixed');
    $toleranceAmount = hb_parse_cents((string)($_POST['recurring_tolerance_amount'] ?? ''));
    $tolerancePctRaw = trim((string)($_POST['recurring_tolerance_pct'] ?? ''));
    $tolerancePct = $tolerancePctRaw !== '' ? (float)str_replace(',', '.', $tolerancePctRaw) : null;
    $minAmount = hb_parse_cents((string)($_POST['recurring_min_amount'] ?? ''));
    $maxAmount = hb_parse_cents((string)($_POST['recurring_max_amount'] ?? ''));
    $categoryOverride = $_POST['recurring_category_id'] !== '' ? (int)($_POST['recurring_category_id'] ?? 0) : null;
    $payeeOverride = $_POST['recurring_payee_id'] !== '' ? (int)($_POST['recurring_payee_id'] ?? 0) : null;
    $noteOverride = trim((string)($_POST['recurring_note'] ?? ''));

    if ($name === '') {
        $error = hb_t('Recurring payment name is required.');
    } elseif (!in_array($intervalUnit, ['day', 'week', 'month', 'year'], true)) {
        $error = hb_t('Invalid interval.');
    } elseif ($intervalValue < 1) {
        $error = hb_t('Interval value must be positive.');
    } elseif ($startDate === '') {
        $error = hb_t('Start date is required.');
    } elseif (!in_array($amountMode, ['fixed', 'tolerance', 'range'], true)) {
        $error = hb_t('Invalid amount logic.');
    } elseif ($amountMode === 'tolerance' && $toleranceAmount === null && $tolerancePct === null) {
        $error = hb_t('Tolerance is required.');
    } elseif ($amountMode === 'range' && ($minAmount === null || $maxAmount === null)) {
        $error = hb_t('Min and max amount are required.');
    }

    $txStmt = $pdo->prepare('select * from transactions where id = :id and household_id = :hid');
    $txStmt->execute(['id' => $txId, 'hid' => $household['id']]);
    $txRow = $txStmt->fetch();
    if ($error === null && !$txRow) {
        $error = hb_t('Booking not found.');
    }

    if ($error === null) {
        $direction = (string)$txRow['type'];
        if (!in_array($direction, ['income', 'expense'], true)) {
            $error = hb_t('Recurring is only allowed for income/expense.');
        }
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

    $categoryId = $categoryOverride ?? $txRow['category_id'];
    $payeeId = $payeeOverride ?? $txRow['payee_id'];
    $note = $noteOverride !== '' ? $noteOverride : ($txRow['note'] ?? null);

    if ($categoryId) {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        $catCheck->execute(['id' => $categoryId, 'hid' => $household['id']]);
        if (!$catCheck->fetch()) {
            $error = hb_t('Category does not belong to the household.');
        }
    }
    if ($error === null && $payeeId) {
        $payeeCheck = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
        $payeeCheck->execute(['id' => $payeeId, 'hid' => $household['id']]);
        if (!$payeeCheck->fetch()) {
            $error = hb_t('Payee does not belong to the household.');
        }
    }

    if ($error === null) {
        $insert = $pdo->prepare(
            'insert into recurring_payments
                (household_id, name, direction, amount_cents, interval_unit, interval_value, start_date, end_date,
                 priority, is_optional, account_id, category_id, payee_id, note, is_active,
                 amount_mode, tolerance_cents, tolerance_pct, min_amount_cents, max_amount_cents)
             values
                (:hid, :name, :direction, :amount, :unit, :ival, :start_date, :end_date,
                 :priority, :is_optional, :account_id, :category_id, :payee_id, :note, true,
                 :amount_mode, :tolerance_cents, :tolerance_pct, :min_amount_cents, :max_amount_cents)'
        );
        $insert->execute([
            'hid' => $household['id'],
            'name' => $name,
            'direction' => $txRow['type'],
            'amount' => (int)$txRow['amount_cents'],
            'unit' => $intervalUnit,
            'ival' => $intervalValue,
            'start_date' => $startDate,
            'end_date' => $endDate !== '' ? $endDate : null,
            'priority' => $priority,
            'is_optional' => $isOptional ? 1 : 0,
            'account_id' => $txRow['account_id'],
            'category_id' => $categoryId,
            'payee_id' => $payeeId,
            'note' => $note !== '' ? $note : null,
            'amount_mode' => $amountMode,
            'tolerance_cents' => $amountMode === 'tolerance' ? $toleranceAmount : null,
            'tolerance_pct' => $amountMode === 'tolerance' ? $tolerancePct : null,
            'min_amount_cents' => $amountMode === 'range' ? $minAmount : null,
            'max_amount_cents' => $amountMode === 'range' ? $maxAmount : null,
        ]);

        $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if ($startDateObj) {
            [$periodStart, $periodEnd] = hb_household_period_bounds($household, $startDateObj);
            hb_ensure_month_plan($pdo, $household, $periodStart, $periodEnd);
        }

        header('Location: /open_bookings.php?msg=recurring_saved');
        exit;
    }
}

$categories = $pdo->prepare(
    'select id, name, type from categories where household_id = :hid and is_active = true order by name asc'
);
$categories->execute(['hid' => $household['id']]);
$categories = $categories->fetchAll();

$tagsStmt = $pdo->prepare('select id, name from tags where household_id = :hid and is_active = true order by name asc');
$tagsStmt->execute(['hid' => $household['id']]);
$tags = $tagsStmt->fetchAll();

$payeesStmt = $pdo->prepare('select id, name from payees where household_id = :hid order by name asc');
$payeesStmt->execute(['hid' => $household['id']]);
$payees = $payeesStmt->fetchAll();

$plansStmt = $pdo->prepare(
    "select *
       from planned_payments
      where household_id = :hid and status in ('open', 'overdue', 'suggested')
      order by planned_date asc, id asc"
);
$plansStmt->execute(['hid' => $household['id']]);
$plans = $plansStmt->fetchAll();
$planById = [];
$recurringById = [];
if ($plans) {
    foreach ($plans as $plan) {
        $planById[(int)$plan['id']] = $plan;
    }
    $recurringIds = array_values(array_unique(array_filter(array_map(
        fn($row) => (int)($row['recurring_payment_id'] ?? 0),
        $plans
    ))));
    if ($recurringIds) {
        $in = implode(',', array_fill(0, count($recurringIds), '?'));
        $recStmt = $pdo->prepare("select * from recurring_payments where id in ({$in})");
        $recStmt->execute($recurringIds);
        foreach ($recStmt->fetchAll() as $rec) {
            $recurringById[(int)$rec['id']] = $rec;
        }
    }
}

$txStmt = $pdo->prepare(
    'select t.*, a.name as account_name, p.name as payee_name, sp.name as suggested_payee_name
       from transactions t
       left join accounts a on a.id = t.account_id
       left join payees p on p.id = t.payee_id
       left join payees sp on sp.id = t.suggested_payee_id
      where t.household_id = :hid and t.is_reviewed = false
      order by t.booking_date desc, t.id desc'
);
$txStmt->execute(['hid' => $household['id']]);
$openBookings = $txStmt->fetchAll();

$suggestedPlans = [];
if ($openBookings && $plans) {
    foreach ($openBookings as $tx) {
        if (!empty($tx['planned_payment_id']) || !empty($tx['suggested_planned_payment_id'])) {
            continue;
        }
        $suggested = hb_suggest_planned_payment($plans, $recurringById, $tx);
        if ($suggested) {
            $suggestedPlans[(int)$tx['id']] = (int)$suggested['id'];
        }
    }
}

$txTags = [];
if ($openBookings) {
    $ids = array_map(fn($row) => (int)$row['id'], $openBookings);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $tagStmt = $pdo->prepare("select transaction_id, tag_id from transaction_tags where transaction_id in ({$in})");
    $tagStmt->execute($ids);
    foreach ($tagStmt->fetchAll() as $row) {
        $txTags[(int)$row['transaction_id']][] = (int)$row['tag_id'];
    }
}

$txSplits = [];
if ($openBookings) {
    $ids = array_map(fn($row) => (int)$row['id'], $openBookings);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $splitStmt = $pdo->prepare("select * from transaction_splits where transaction_id in ({$in}) order by id asc");
    $splitStmt->execute($ids);
    foreach ($splitStmt->fetchAll() as $row) {
        $txSplits[(int)$row['transaction_id']][] = $row;
    }
}

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Open bookings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Review, assign, and finalize new transactions.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="text-muted small"><?= htmlspecialchars(hb_t('Open:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= count($openBookings) ?></div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Booking finalized.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'recurring_saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Recurring payment created.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?= $conflict ?>

  <div class="row g-4">
    <div class="col-12">
      <?php if (!$openBookings): ?>
        <div class="card shadow-sm">
          <div class="card-body text-muted"><?= htmlspecialchars(hb_t('No open bookings available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
      <?php endif; ?>

      <?php foreach ($openBookings as $tx): ?>
        <?php
        $selectedPayee = $tx['payee_id'] ?? null;
        if (!$selectedPayee && !empty($tx['suggested_payee_id'])) {
            $selectedPayee = $tx['suggested_payee_id'];
        }
        $suggestedPlanId = $tx['suggested_planned_payment_id'] ?? ($suggestedPlans[(int)$tx['id']] ?? null);
        $selectedPlanId = $tx['planned_payment_id'] ?? $suggestedPlanId;
        $selectedTags = $txTags[(int)$tx['id']] ?? [];
        $splitRows = $txSplits[(int)$tx['id']] ?? [];
        $directionBadge = $tx['type'] === 'income' ? 'bg-success' : 'bg-danger';
        ?>
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start mb-2">
              <div>
                <div class="fw-semibold"><?= htmlspecialchars($tx['counterparty_name'] ?: hb_t('Unknown payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="text-muted small">
                  <?= htmlspecialchars($tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  · <?= htmlspecialchars($tx['account_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
              </div>
              <div class="text-end">
                <div class="fw-semibold"><?= number_format($tx['amount_cents'] / 100, 2, ',', '.') ?> €</div>
                <span class="badge <?= $directionBadge ?>">
                  <?= htmlspecialchars($tx['type'] === 'income' ? hb_t('Income') : ($tx['type'] === 'expense' ? hb_t('Expense') : (string)$tx['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </span>
              </div>
            </div>

            <?php if (!empty($tx['note'])): ?>
              <div class="text-muted small mb-3"><?= htmlspecialchars($tx['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (!empty($tx['suggested_payee_name'])): ?>
              <div class="badge bg-info-subtle text-info mb-2"><?= htmlspecialchars(hb_t('Suggestion:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($tx['suggested_payee_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($suggestedPlanId && empty($tx['planned_payment_id']) && isset($planById[(int)$suggestedPlanId])): ?>
              <div class="alert alert-warning py-1 px-2 small mb-2 d-flex align-items-center justify-content-between">
                <span><?= htmlspecialchars(hb_t('Suggestion:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($planById[(int)$suggestedPlanId]['planned_date'] . ' · ' . $planById[(int)$suggestedPlanId]['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <button type="button" class="btn btn-sm btn-outline-warning hb-apply-plan" data-plan-id="<?= (int)$suggestedPlanId ?>"><?= htmlspecialchars(hb_t('Apply'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </div>
            <?php endif; ?>

            <form method="post" action="/open_bookings.php" class="row g-2 align-items-end">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
              <input type="hidden" name="row_version" value="<?= (int)$tx['row_version'] ?>">
              <div class="col-md-4">
                <label class="form-label small d-flex justify-content-between align-items-center">
                  <span><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#split-<?= (int)$tx['id'] ?>"><?= htmlspecialchars(hb_t('Split'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </label>
                <?php
                $categorySelectorId = 'category-' . (int)$tx['id'];
                $categorySelectorName = 'category_id';
                $categorySelectorCategories = $categories;
                $categorySelectorSelected = $tx['category_id'] ?? null;
                $categorySelectorPlaceholder = hb_t('Search category...');
                $categoryModalTarget = '#categoryModal';
                require __DIR__ . '/../templates/partials/category_selector.php';
                ?>
                <?php $splitOpen = $splitRows ? 'show' : ''; ?>
                <div class="collapse <?= $splitOpen ?> mt-2" id="split-<?= (int)$tx['id'] ?>">
                  <div class="border rounded-3 p-2 bg-light-subtle">
                    <?php for ($i = 0; $i < 3; $i++): ?>
                      <?php $existing = $splitRows[$i] ?? null; ?>
                      <div class="row g-2 mb-2">
                        <div class="col-7">
                          <select class="form-select form-select-sm" name="split_category_id[]">
                            <option value=""><?= htmlspecialchars(hb_t('Select category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                            <?php foreach ($categories as $cat): ?>
                              <option value="<?= (int)$cat['id'] ?>" <?= ($existing['category_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-5">
                          <input type="text" class="form-control form-control-sm" name="split_amount[]" value="<?= $existing ? number_format($existing['amount_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="<?= htmlspecialchars(hb_t('0.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        </div>
                      </div>
                    <?php endfor; ?>
                    <div class="form-text"><?= htmlspecialchars(hb_t('Split total equals amount.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <?php
                $payeeSelectorId = 'payee-' . (int)$tx['id'];
                $payeeSelectorName = 'payee_id';
                $payeeSelectorPayees = $payees;
                $payeeSelectorSelected = $selectedPayee;
                $payeeSelectorPlaceholder = hb_t('Search payee...');
                $payeeModalTarget = '#payeeModal';
                require __DIR__ . '/../templates/partials/payee_selector.php';
                ?>
              </div>
              <div class="col-md-4">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Planned payment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select form-select-sm" name="planned_payment_id">
                  <option value=""><?= htmlspecialchars(hb_t('Not set'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php foreach ($plans as $plan): ?>
                    <?php $selected = (int)$plan['id'] === (int)($selectedPlanId ?? 0); ?>
                    <option value="<?= (int)$plan['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                      <?= htmlspecialchars($plan['planned_date'] . ' · ' . $plan['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <?php
                $tagSelectorId = 'tags-' . (int)$tx['id'];
                $tagSelectorName = 'tag_ids[]';
                $tagSelectorTags = $tags;
                $tagSelectorSelected = $selectedTags;
                $tagSelectorPlaceholder = hb_t('Search tag...');
                $tagModalTarget = '#tagModal';
                require __DIR__ . '/../templates/partials/tag_selector.php';
                ?>
              </div>
              <div class="col-md-6">
                <label class="form-label small"><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input class="form-control form-control-sm" type="text" name="note" value="<?= htmlspecialchars((string)($tx['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <div class="col-12 text-end">
                <button type="submit" class="btn btn-success btn-sm"><?= htmlspecialchars(hb_t('Finalize booking'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </div>
            </form>
            <?php if ($tx['type'] !== 'transfer'): ?>
              <?php
              $recurringName = $tx['payee_name'] ?? $tx['counterparty_name'] ?? $tx['note'] ?? hb_t('Recurring');
              $recurringId = (int)$tx['id'];
              ?>
              <div class="mt-3 border-top pt-2">
                <div class="d-flex justify-content-between align-items-center">
                  <h6 class="mb-0"><?= htmlspecialchars(hb_t('Save as recurring'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h6>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#recurring-<?= $recurringId ?>" aria-expanded="false"><?= htmlspecialchars(hb_t('Details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </div>
                <div class="collapse mt-2" id="recurring-<?= $recurringId ?>">
                  <form method="post" action="/open_bookings.php" class="hb-recurring-form" data-transaction-id="<?= $recurringId ?>">
                    <input type="hidden" name="action" value="create_recurring">
                    <input type="hidden" name="transaction_id" value="<?= $recurringId ?>">
                    <input type="hidden" name="recurring_category_id" value="">
                    <input type="hidden" name="recurring_payee_id" value="">
                    <input type="hidden" name="recurring_note" value="">
                    <div class="row g-2 align-items-end">
                      <div class="col-md-6">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_name" value="<?= htmlspecialchars($recurringName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                      </div>
                      <div class="col-6 col-md-2">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Interval'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <select class="form-select form-select-sm" name="recurring_interval_unit">
                          <option value="day"><?= htmlspecialchars(hb_t('Day'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="week"><?= htmlspecialchars(hb_t('Week'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="month" selected><?= htmlspecialchars(hb_t('Month'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="year"><?= htmlspecialchars(hb_t('Year'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        </select>
                      </div>
                      <div class="col-6 col-md-2">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Every'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="number" class="form-control form-control-sm" name="recurring_interval_value" value="1" min="1">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Start'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="date" class="form-control form-control-sm" name="recurring_start_date" value="<?= htmlspecialchars($tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                    </div>
                    <div class="row g-2 align-items-end mt-2">
                      <div class="col-md-4">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Amount logic'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <select class="form-select form-select-sm" name="recurring_amount_mode">
                          <option value="fixed" selected><?= htmlspecialchars(hb_t('Fixed'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="tolerance"><?= htmlspecialchars(hb_t('Tolerance'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <option value="range"><?= htmlspecialchars(hb_t('Range'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        </select>
                      </div>
                      <div class="col-md-4" data-recurring-group="tolerance">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Tolerance (amount)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_tolerance_amount" placeholder="<?= htmlspecialchars(hb_t('e.g. 5.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                      <div class="col-md-4" data-recurring-group="tolerance">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Tolerance (%)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_tolerance_pct" placeholder="<?= htmlspecialchars(hb_t('e.g. 5'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                    </div>
                    <div class="row g-2 align-items-end mt-2">
                      <div class="col-md-4" data-recurring-group="range">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Min amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_min_amount" placeholder="<?= htmlspecialchars(hb_t('e.g. 40.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                      <div class="col-md-4" data-recurring-group="range">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Max amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="text" class="form-control form-control-sm" name="recurring_max_amount" placeholder="<?= htmlspecialchars(hb_t('e.g. 60.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('End'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <input type="date" class="form-control form-control-sm" name="recurring_end_date">
                      </div>
                    </div>
                    <div class="row g-2 align-items-center mt-2">
                      <div class="col-6 col-md-3">
                        <label class="form-label small"><?= htmlspecialchars(hb_t('Priority'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        <select class="form-select form-select-sm" name="recurring_priority">
                          <?php for ($p = 1; $p <= 5; $p++): ?>
                            <option value="<?= $p ?>" <?= $p === 3 ? 'selected' : '' ?>><?= $p ?></option>
                          <?php endfor; ?>
                        </select>
                      </div>
                      <div class="col-6 col-md-3">
                        <div class="form-check mt-4">
                          <input class="form-check-input" type="checkbox" name="recurring_is_optional" id="recurring-opt-<?= $recurringId ?>">
                          <label class="form-check-label small" for="recurring-opt-<?= $recurringId ?>"><?= htmlspecialchars(hb_t('Optional'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                        </div>
                      </div>
                      <div class="col-md-6 text-end">
                        <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars(hb_t('Save recurring'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                      </div>
                    </div>
                  </form>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
  $tagModalId = 'tagModal';
  require __DIR__ . '/../templates/partials/tag_modal.php';
  $categoryModalId = 'categoryModal';
  require __DIR__ . '/../templates/partials/category_modal.php';
  $payeeModalId = 'payeeModal';
  require __DIR__ . '/../templates/partials/payee_modal.php';
  ?>
</div>
<?php
$content = ob_get_clean();
$extraScripts = '<script src="/js/chip-selector.js"></script>';
$extraScripts .= <<<HTML
<script>
document.addEventListener('click', (event) => {
  const btn = event.target.closest('.hb-apply-plan');
  if (!btn) return;
  const card = btn.closest('.card');
  if (!card) return;
  const select = card.querySelector('select[name="planned_payment_id"]');
  if (select) {
    select.value = btn.dataset.planId || '';
  }
});
const toggleRecurringFields = (form) => {
  const mode = form.querySelector('select[name="recurring_amount_mode"]')?.value || 'fixed';
  const showTolerance = mode === 'tolerance';
  const showRange = mode === 'range';
  form.querySelectorAll('[data-recurring-group="tolerance"]').forEach((el) => {
    el.classList.toggle('d-none', !showTolerance);
  });
  form.querySelectorAll('[data-recurring-group="range"]').forEach((el) => {
    el.classList.toggle('d-none', !showRange);
  });
};
document.querySelectorAll('.hb-recurring-form').forEach((form) => {
  toggleRecurringFields(form);
  form.querySelector('select[name="recurring_amount_mode"]')?.addEventListener('change', () => toggleRecurringFields(form));
});
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  if (!form.classList.contains('hb-recurring-form')) return;
  const card = form.closest('.card');
  if (!card) return;
  const reviewForm = card.querySelector('form[action="/open_bookings.php"]');
  if (!reviewForm) return;
  const actionInput = reviewForm.querySelector('input[name="action"][value="save"]');
  if (!actionInput) return;
  const getValue = (selector) => reviewForm.querySelector(selector)?.value || '';
  form.querySelector('input[name="recurring_category_id"]').value = getValue('input[name="category_id"]');
  form.querySelector('input[name="recurring_payee_id"]').value = getValue('input[name="payee_id"]');
  form.querySelector('input[name="recurring_note"]').value = getValue('input[name="note"]');
});
</script>
HTML;
require __DIR__ . '/../templates/layout.php';
