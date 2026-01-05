<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

$pageTitle = 'Open cases';
$activeNav = 'open_cases';
$breadcrumbs = [
    ['label' => 'Open cases', 'href' => '/open_cases.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$conflict = null;

$accountsStmt = $pdo->prepare('select * from accounts where household_id = :hid and is_archived = false order by name asc');
$accountsStmt->execute(['hid' => $household['id']]);
$accounts = $accountsStmt->fetchAll();

$catStmt = $pdo->prepare('select * from categories where household_id = :hid and is_active = true order by type asc, name asc');
$catStmt->execute(['hid' => $household['id']]);
$categories = $catStmt->fetchAll();

$payeeStmt = $pdo->prepare('select * from payees where household_id = :hid order by name asc');
$payeeStmt->execute(['hid' => $household['id']]);
$payees = $payeeStmt->fetchAll();

$statusOptions = [
    'open' => hb_t('Open'),
    'clarifying' => hb_t('Clarifying'),
    'agreed' => hb_t('Agreed'),
    'done' => hb_t('Done'),
];

if (in_array($action, ['store', 'update'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    $status = (string)($_POST['status'] ?? 'open');
    $reference = trim((string)($_POST['reference'] ?? ''));
    $contactName = trim((string)($_POST['contact_name'] ?? ''));
    $contactDetails = trim((string)($_POST['contact_details'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    $paymentKind = (string)($_POST['payment_kind'] ?? 'none');
    $paymentName = trim((string)($_POST['payment_name'] ?? $title));
    $paymentDirection = (string)($_POST['payment_direction'] ?? 'expense');
    $paymentAmount = hb_parse_cents((string)($_POST['payment_amount'] ?? ''));
    $paymentDate = (string)($_POST['payment_date'] ?? '');
    $paymentAccountId = $_POST['payment_account_id'] !== '' ? (int)$_POST['payment_account_id'] : null;
    $paymentCategoryId = $_POST['payment_category_id'] !== '' ? (int)$_POST['payment_category_id'] : null;
    $paymentPayeeId = $_POST['payment_payee_id'] !== '' ? (int)$_POST['payment_payee_id'] : null;
    $paymentNote = trim((string)($_POST['payment_note'] ?? ''));
    $paymentOptional = isset($_POST['payment_optional']);
    $intervalUnit = (string)($_POST['payment_interval_unit'] ?? 'month');
    $intervalValue = (int)($_POST['payment_interval_value'] ?? 1);
    $paymentStartDate = (string)($_POST['payment_start_date'] ?? '');
    $paymentPriority = (int)($_POST['payment_priority'] ?? 3);
    $totalAmount = hb_parse_cents((string)($_POST['total_amount'] ?? ''));
    $settledAmount = hb_parse_cents((string)($_POST['settled_amount'] ?? ''));
    if ($settledAmount === null) {
        $settledAmount = 0;
    }

    if ($title === '') {
        $error = hb_t('Title is required.');
    } elseif (!isset($statusOptions[$status])) {
        $error = hb_t('Invalid status.');
    }

    if ($error === null && $paymentKind !== 'none') {
        if ($status !== 'agreed') {
            $error = hb_t('Payment can only be created when status is "Agreed".');
        } elseif ($paymentName === '') {
            $error = hb_t('Payment name is required.');
        } elseif (!in_array($paymentDirection, ['income', 'expense'], true)) {
            $error = hb_t('Invalid direction for payment.');
        } elseif ($paymentAmount === null || $paymentAmount <= 0) {
            $error = hb_t('Payment amount is invalid.');
        } elseif ($paymentKind === 'one_time' && $paymentDate === '') {
            $error = hb_t('One-time payment date is required.');
        } elseif ($paymentKind === 'recurring' && $paymentStartDate === '') {
            $error = hb_t('Start date is required.');
        } elseif ($paymentKind === 'one_time') {
            $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $paymentDate);
            if ($dateObj && hb_is_period_closed($pdo, $household['id'], $dateObj)) {
                $error = hb_t('The month is already closed. Changes are locked.');
            }
        } elseif ($paymentKind === 'recurring') {
            $dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $paymentStartDate);
            if ($dateObj && hb_is_period_closed($pdo, $household['id'], $dateObj)) {
                $error = hb_t('The month is already closed. Changes are locked.');
            }
        }
    }

    if ($error === null) {
        if ($totalAmount !== null && $totalAmount < 0) {
            $error = hb_t('Total amount is invalid.');
        } elseif ($settledAmount !== null && $settledAmount < 0) {
            $error = hb_t('Settled amount is invalid.');
        } elseif ($totalAmount !== null && $settledAmount !== null && $settledAmount > $totalAmount) {
            $error = hb_t('Settled amount cannot exceed total amount.');
        }
    }

    if ($error === null) {
        $current = null;
        if ($action === 'update') {
            $stmt = $pdo->prepare('select * from open_cases where id = :id and household_id = :hid');
            $stmt->execute(['id' => $id, 'hid' => $household['id']]);
            $current = $stmt->fetch();
            if (!$current) {
                $error = hb_t('Open case not found.');
            } elseif ($paymentKind !== 'none' && (!empty($current['planned_payment_id']) || !empty($current['recurring_payment_id']))) {
                $error = hb_t('A payment is already linked.');
            }
        }
    }

    if ($error === null) {
        $pdo->beginTransaction();
        try {
            $plannedId = $current['planned_payment_id'] ?? null;
            $recurringId = $current['recurring_payment_id'] ?? null;
            if ($totalAmount === null && $paymentAmount !== null) {
                $totalAmount = $paymentAmount;
            }
            if ($paymentKind === 'one_time') {
                $insertPlan = $pdo->prepare(
                    'insert into planned_payments
                        (household_id, name, direction, amount_cents, planned_date, status, priority, is_optional,
                         account_id, category_id, payee_id, note)
                     values
                        (:hid, :name, :direction, :amount, :planned_date, :status, :priority, :is_optional,
                         :account_id, :category_id, :payee_id, :note)
                     returning id'
                );
                $insertPlan->execute([
                    'hid' => $household['id'],
                    'name' => $paymentName,
                    'direction' => $paymentDirection,
                    'amount' => $paymentAmount,
                    'planned_date' => $paymentDate,
                    'status' => 'open',
                    'priority' => $paymentPriority,
                    'is_optional' => $paymentOptional ? 1 : 0,
                    'account_id' => $paymentAccountId,
                    'category_id' => $paymentCategoryId,
                    'payee_id' => $paymentPayeeId,
                    'note' => $paymentNote !== '' ? $paymentNote : null,
                ]);
                $plannedId = (int)$insertPlan->fetchColumn();
            } elseif ($paymentKind === 'recurring') {
                $insertRecurring = $pdo->prepare(
                    'insert into recurring_payments
                        (household_id, name, direction, amount_cents, interval_unit, interval_value, start_date,
                         priority, is_optional, account_id, category_id, payee_id, note, is_active)
                     values
                        (:hid, :name, :direction, :amount, :unit, :ival, :start_date,
                         :priority, :is_optional, :account_id, :category_id, :payee_id, :note, true)
                     returning id'
                );
                $insertRecurring->execute([
                    'hid' => $household['id'],
                    'name' => $paymentName,
                    'direction' => $paymentDirection,
                    'amount' => $paymentAmount,
                    'unit' => $intervalUnit,
                    'ival' => $intervalValue,
                    'start_date' => $paymentStartDate,
                    'priority' => $paymentPriority,
                    'is_optional' => $paymentOptional ? 1 : 0,
                    'account_id' => $paymentAccountId,
                    'category_id' => $paymentCategoryId,
                    'payee_id' => $paymentPayeeId,
                    'note' => $paymentNote !== '' ? $paymentNote : null,
                ]);
                $recurringId = (int)$insertRecurring->fetchColumn();
            }

            if ($action === 'store') {
                $stmt = $pdo->prepare(
                    'insert into open_cases
                        (household_id, title, status, reference, contact_name, contact_details, notes, planned_payment_id, recurring_payment_id, total_amount_cents, settled_amount_cents)
                     values
                        (:hid, :title, :status, :reference, :contact_name, :contact_details, :notes, :planned_id, :recurring_id, :total_amount, :settled_amount)'
                );
                $stmt->execute([
                    'hid' => $household['id'],
                    'title' => $title,
                    'status' => $status,
                    'reference' => $reference !== '' ? $reference : null,
                    'contact_name' => $contactName !== '' ? $contactName : null,
                    'contact_details' => $contactDetails !== '' ? $contactDetails : null,
                    'notes' => $notes !== '' ? $notes : null,
                    'planned_id' => $plannedId,
                    'recurring_id' => $recurringId,
                    'total_amount' => $totalAmount,
                    'settled_amount' => $settledAmount,
                ]);
                $pdo->commit();
                header('Location: /open_cases.php?msg=saved');
                exit;
            }

            $update = $pdo->prepare(
                'update open_cases
                    set title = :title,
                        status = :status,
                        reference = :reference,
                        contact_name = :contact_name,
                        contact_details = :contact_details,
                        notes = :notes,
                        planned_payment_id = :planned_id,
                        recurring_payment_id = :recurring_id,
                        total_amount_cents = :total_amount,
                        settled_amount_cents = :settled_amount,
                        updated_at = now()
                  where id = :id and household_id = :hid and row_version = :row_version'
            );
            $update->execute([
                'title' => $title,
                'status' => $status,
                'reference' => $reference !== '' ? $reference : null,
                'contact_name' => $contactName !== '' ? $contactName : null,
                'contact_details' => $contactDetails !== '' ? $contactDetails : null,
                'notes' => $notes !== '' ? $notes : null,
                'planned_id' => $plannedId,
                'recurring_id' => $recurringId,
                'total_amount' => $totalAmount,
                'settled_amount' => $settledAmount,
                'id' => $id,
                'hid' => $household['id'],
                'row_version' => $rowVersion,
            ]);

            if ($update->rowCount() === 0) {
                $pdo->rollBack();
                $fresh = $pdo->prepare('select * from open_cases where id = :id and household_id = :hid');
                $fresh->execute(['id' => $id, 'hid' => $household['id']]);
                $current = $fresh->fetch() ?: [];
                $conflictRows = hb_build_conflict_rows(
                    [
                        'title' => hb_t('Title'),
                        'status' => hb_t('Status'),
                        'reference' => hb_t('Reference'),
                        'contact_name' => hb_t('Contact person'),
                        'contact_details' => hb_t('Contact details'),
                        'notes' => hb_t('Notes'),
                        'total_amount_cents' => hb_t('Total amount'),
                        'settled_amount_cents' => hb_t('Settled amount'),
                    ],
                    $current,
                    [
                        'title' => $title,
                        'status' => $status,
                        'reference' => $reference,
                        'contact_name' => $contactName,
                        'contact_details' => $contactDetails,
                        'notes' => $notes,
                        'total_amount_cents' => $totalAmount !== null ? (string)$totalAmount : '',
                        'settled_amount_cents' => $settledAmount !== null ? (string)$settledAmount : '',
                    ]
                );
                $conflict = hb_render_conflict_table($conflictRows);
                $editCase = array_merge($current, [
                    'title' => $title,
                    'status' => $status,
                    'reference' => $reference,
                    'contact_name' => $contactName,
                    'contact_details' => $contactDetails,
                    'notes' => $notes,
                ]);
                $action = 'edit';
            } else {
                $pdo->commit();
                header('Location: /open_cases.php?msg=saved');
                exit;
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

$editCase = $editCase ?? null;
if ($action === 'edit' && $editCase === null) {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('select * from open_cases where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $editCase = $stmt->fetch();
    if (!$editCase) {
        $error = hb_t('Open case not found.');
        $action = 'list';
    }
}

$casesStmt = $pdo->prepare('select * from open_cases where household_id = :hid order by created_at desc');
$casesStmt->execute(['hid' => $household['id']]);
$cases = $casesStmt->fetchAll();

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Open cases'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Claims, clarifications, and special cases'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Entry saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('List'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(hb_t('Title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Reference'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Payment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cases as $case): ?>
                  <tr>
                    <td><?= htmlspecialchars($case['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($statusOptions[$case['status']] ?? $case['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($case['reference'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="small">
                      <?php if (!empty($case['planned_payment_id'])): ?>
                        <?= htmlspecialchars(hb_t('One-time payment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <?php elseif (!empty($case['recurring_payment_id'])): ?>
                        <?= htmlspecialchars(hb_t('Recurring'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                      <?php
                      $totalCents = $case['total_amount_cents'] ?? null;
                      $settledCents = $case['settled_amount_cents'] ?? null;
                      if ($totalCents !== null) {
                        $totalCents = (int)$totalCents;
                        $settledCents = $settledCents !== null ? (int)$settledCents : 0;
                        $openCents = max(0, $totalCents - $settledCents);
                      }
                      ?>
                      <?php if (isset($openCents)): ?>
                        <div class="text-muted small">
                          <?= htmlspecialchars(hb_t('Open amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:
                          <?= hb_budget_amount($openCents) ?> ·
                          <?= htmlspecialchars(hb_t('Total'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:
                          <?= hb_budget_amount($totalCents) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="/open_cases.php?action=edit&id=<?= (int)$case['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$cases): ?>
                  <tr><td colspan="5" class="text-muted"><?= htmlspecialchars(hb_t('No open cases available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
                <?php endif; ?>
              </tbody>
            </table>
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
          <?php $isEdit = $action === 'edit' && $editCase; ?>
          <?php $paymentLocked = $isEdit && (!empty($editCase['planned_payment_id']) || !empty($editCase['recurring_payment_id'])); ?>
          <h2 class="h6 mb-3"><?= htmlspecialchars($isEdit ? hb_t('Edit case') : hb_t('New case'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <form method="post" action="/open_cases.php">
            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'store' ?>">
            <?php if ($isEdit): ?>
              <input type="hidden" name="id" value="<?= (int)$editCase['id'] ?>">
              <input type="hidden" name="row_version" value="<?= (int)($editCase['row_version'] ?? 0) ?>">
            <?php endif; ?>
            <div class="mb-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('Title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" name="title" required value="<?= htmlspecialchars($editCase['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select" name="status">
                  <?php foreach ($statusOptions as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($editCase['status'] ?? 'open') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label"><?= htmlspecialchars(hb_t('Reference'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" name="reference" value="<?= htmlspecialchars($editCase['reference'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
            </div>
            <div class="mt-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('Contact person'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" name="contact_name" value="<?= htmlspecialchars($editCase['contact_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="mt-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('Contact details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <textarea class="form-control" name="contact_details" rows="2"><?= htmlspecialchars($editCase['contact_details'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </div>
            <div class="mt-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('Notes'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <textarea class="form-control" name="notes" rows="2"><?= htmlspecialchars($editCase['notes'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </div>

            <div class="border rounded-3 p-3 mt-3 bg-light-subtle">
              <div class="fw-semibold mb-2"><?= htmlspecialchars(hb_t('Create payment (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <?php if ($paymentLocked): ?>
                <div class="alert alert-info py-2 mb-2"><?= htmlspecialchars(hb_t('This case is already linked to a payment.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <input type="hidden" name="payment_kind" value="none">
              <?php endif; ?>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" name="payment_kind" <?= $paymentLocked ? 'disabled' : '' ?>>
                    <option value="none"><?= htmlspecialchars(hb_t('None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <option value="one_time"><?= htmlspecialchars(hb_t('One-time payment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <option value="recurring"><?= htmlspecialchars(hb_t('Recurring'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="text" class="form-control" name="payment_name" value="<?= htmlspecialchars($editCase['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $paymentLocked ? 'disabled' : '' ?>>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Direction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" name="payment_direction" <?= $paymentLocked ? 'disabled' : '' ?>>
                    <option value="expense"><?= htmlspecialchars(hb_t('Expense'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <option value="income"><?= htmlspecialchars(hb_t('Income'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="text" class="form-control" name="payment_amount" placeholder="<?= htmlspecialchars(hb_t('0.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $paymentLocked ? 'disabled' : '' ?>>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('One-time date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="date" class="form-control" name="payment_date" <?= $paymentLocked ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Start date (recurring)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="date" class="form-control" name="payment_start_date" <?= $paymentLocked ? 'disabled' : '' ?>>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Interval'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" name="payment_interval_unit" <?= $paymentLocked ? 'disabled' : '' ?>>
                    <?php foreach (['day' => hb_t('Days'), 'week' => hb_t('Weeks'), 'month' => hb_t('Months'), 'year' => hb_t('Years')] as $key => $label): ?>
                      <option value="<?= $key ?>"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Interval value'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="number" class="form-control" name="payment_interval_value" min="1" value="1" <?= $paymentLocked ? 'disabled' : '' ?>>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Total amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="text" class="form-control" name="total_amount" placeholder="<?= htmlspecialchars(hb_t('0.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= isset($editCase['total_amount_cents']) ? number_format(((int)$editCase['total_amount_cents']) / 100, 2, ',', '.') : '' ?>" <?= $paymentLocked ? 'disabled' : '' ?>>
                </div>
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Settled amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="text" class="form-control" name="settled_amount" placeholder="<?= htmlspecialchars(hb_t('0.00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= isset($editCase['settled_amount_cents']) ? number_format(((int)$editCase['settled_amount_cents']) / 100, 2, ',', '.') : '' ?>" <?= $paymentLocked ? 'disabled' : '' ?>>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" name="payment_account_id" <?= $paymentLocked ? 'disabled' : '' ?>>
                    <option value=""><?= htmlspecialchars(hb_t('None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php foreach ($accounts as $acc): ?>
                      <option value="<?= (int)$acc['id'] ?>"><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" name="payment_category_id" <?= $paymentLocked ? 'disabled' : '' ?>>
                    <option value=""><?= htmlspecialchars(hb_t('None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php foreach ($categories as $cat): ?>
                      <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" name="payment_payee_id" <?= $paymentLocked ? 'disabled' : '' ?>>
                    <option value=""><?= htmlspecialchars(hb_t('None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php foreach ($payees as $payee): ?>
                      <option value="<?= (int)$payee['id'] ?>"><?= htmlspecialchars($payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label"><?= htmlspecialchars(hb_t('Priority'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="number" class="form-control" name="payment_priority" min="1" max="5" value="3" <?= $paymentLocked ? 'disabled' : '' ?>>
                </div>
              </div>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="payment_optional" id="payment-optional" <?= $paymentLocked ? 'disabled' : '' ?>>
                <label class="form-check-label" for="payment-optional"><?= htmlspecialchars(hb_t('Optional'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              </div>
              <div class="mt-2">
                <label class="form-label"><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <textarea class="form-control" name="payment_note" rows="2" <?= $paymentLocked ? 'disabled' : '' ?>></textarea>
              </div>
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
