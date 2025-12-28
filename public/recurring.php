<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

$pageTitle = 'Wiederkehrende Zahlungen';
$activeNav = 'recurring';
$breadcrumbs = [
    ['label' => 'Wiederkehrende Zahlungen', 'href' => '/recurring.php'],
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
        $error = 'Name ist erforderlich.';
    } elseif (!in_array($direction, ['income', 'expense'], true)) {
        $error = 'Ungültige Richtung.';
    } elseif ($amount === null || $amount <= 0) {
        $error = 'Betrag ungültig.';
    } elseif (!in_array($amountMode, ['fixed', 'tolerance', 'range'], true)) {
        $error = 'Ungültige Betragslogik.';
    } elseif ($amountMode === 'tolerance' && $toleranceAmount === null && $tolerancePct === null) {
        $error = 'Toleranz ist erforderlich.';
    } elseif ($amountMode === 'range' && ($minAmount === null || $maxAmount === null)) {
        $error = 'Min- und Maxbetrag sind erforderlich.';
    } elseif (!in_array($intervalUnit, ['day', 'week', 'month', 'year'], true)) {
        $error = 'Ungültiges Intervall.';
    } elseif ($intervalValue < 1) {
        $error = 'Intervallwert muss positiv sein.';
    } elseif ($startDate === '') {
        $error = 'Startdatum ist erforderlich.';
    }

    if ($error === null) {
        $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if ($startDateObj && hb_is_period_closed($pdo, $household['id'], $startDateObj)) {
            $error = 'Der Monat ist bereits abgeschlossen. Änderungen sind gesperrt.';
        }
        if ($endDate !== '') {
            $endDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
            if (!$endDateObj) {
                $error = 'Enddatum ist ungültig.';
            } elseif ($startDateObj && $endDateObj < $startDateObj) {
                $error = 'Enddatum muss nach dem Startdatum liegen.';
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
                    updated_at = now()
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
                    'name' => 'Name',
                    'direction' => 'Richtung',
                    'amount_cents' => 'Betrag',
                    'interval_unit' => 'Intervall',
                    'interval_value' => 'Intervallwert',
                    'start_date' => 'Startdatum',
                    'end_date' => 'Enddatum',
                    'priority' => 'Priorität',
                    'is_optional' => 'Optional',
                    'amount_mode' => 'Betragslogik',
                    'tolerance_cents' => 'Toleranz (Betrag)',
                    'tolerance_pct' => 'Toleranz (%)',
                    'min_amount_cents' => 'Minbetrag',
                    'max_amount_cents' => 'Maxbetrag',
                    'account_id' => 'Konto',
                    'category_id' => 'Kategorie',
                    'payee_id' => 'Empfänger',
                    'note' => 'Notiz',
                    'is_active' => 'Aktiv',
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
        $error = 'Eintrag nicht gefunden.';
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
        $error = 'Eintrag nicht gefunden.';
        $action = 'list';
    }
}

$recurringsStmt = $pdo->prepare('select * from recurring_payments where household_id = :hid order by is_active desc, name asc');
$recurringsStmt->execute(['hid' => $household['id']]);
$recurrings = $recurringsStmt->fetchAll();

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0">Wiederkehrende Zahlungen</h1>
      <div class="text-muted small">Planbasis für den Monatsforecast</div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/plan.php">Monatsplan</a>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success">Eintrag gespeichert.</div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-success">Eintrag gelöscht.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3">Liste</h2>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Richtung</th>
                  <th>Betrag</th>
                  <th>Logik</th>
                  <th>Intervall</th>
                  <th>Ende</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recurrings as $rec): ?>
                  <tr>
                    <td><?= htmlspecialchars($rec['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($rec['direction'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
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
                          echo 'Toleranz ' . htmlspecialchars(implode(' / ', $tolParts), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                      } elseif ($mode === 'range') {
                          $min = isset($rec['min_amount_cents']) ? number_format($rec['min_amount_cents'] / 100, 2, ',', '.') . ' €' : '-';
                          $max = isset($rec['max_amount_cents']) ? number_format($rec['max_amount_cents'] / 100, 2, ',', '.') . ' €' : '-';
                          echo 'Spanne ' . htmlspecialchars($min . '–' . $max, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                      } else {
                          echo 'Fix';
                      }
                      ?>
                    </td>
                    <td><?= (int)$rec['interval_value'] ?> <?= htmlspecialchars($rec['interval_unit'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($rec['end_date'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= $rec['is_active'] ? 'Aktiv' : 'Inaktiv' ?></td>
                    <td class="text-end">
                      <div class="d-flex justify-content-end gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="/recurring.php?action=edit&id=<?= (int)$rec['id'] ?>">Bearbeiten</a>
                        <form method="post" action="/recurring.php" data-confirm="Wiederkehrende Zahlung wirklich löschen?">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= (int)$rec['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$recurrings): ?>
                  <tr><td colspan="8" class="text-muted">Keine Einträge vorhanden.</td></tr>
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
          <?php $isEdit = $action === 'edit' && $editRecurring; ?>
          <h2 class="h6 mb-3"><?= $isEdit ? 'Zahlung bearbeiten' : 'Neue Zahlung' ?></h2>
          <form method="post" action="/recurring.php">
            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'store' ?>">
            <?php if ($isEdit): ?>
              <input type="hidden" name="id" value="<?= (int)$editRecurring['id'] ?>">
              <input type="hidden" name="row_version" value="<?= (int)($editRecurring['row_version'] ?? 0) ?>">
            <?php endif; ?>
            <div class="mb-3">
              <label class="form-label">Name</label>
              <input type="text" class="form-control" name="name" required value="<?= htmlspecialchars($editRecurring['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Richtung</label>
                <select class="form-select" name="direction">
                  <?php foreach (['income' => 'Einnahme', 'expense' => 'Ausgabe'] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($editRecurring['direction'] ?? 'expense') === $key ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Betrag</label>
                <input type="text" class="form-control" name="amount" required value="<?= isset($editRecurring['amount_cents']) ? number_format($editRecurring['amount_cents'] / 100, 2, ',', '.') : '' ?>">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label">Betragslogik</label>
                <select class="form-select" name="amount_mode">
                  <?php foreach (['fixed' => 'Fix', 'tolerance' => 'Toleranz', 'range' => 'Spanne'] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($editRecurring['amount_mode'] ?? 'fixed') === $key ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Toleranz (Betrag)</label>
                <input type="text" class="form-control" name="tolerance_amount" value="<?= isset($editRecurring['tolerance_cents']) ? number_format($editRecurring['tolerance_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="z. B. 5,00">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label">Toleranz (%)</label>
                <input type="text" class="form-control" name="tolerance_pct" value="<?= htmlspecialchars((string)($editRecurring['tolerance_pct'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="z. B. 5">
              </div>
              <div class="col-md-6">
                <label class="form-label">Min/Max (Spanne)</label>
                <div class="input-group">
                  <input type="text" class="form-control" name="min_amount" value="<?= isset($editRecurring['min_amount_cents']) ? number_format($editRecurring['min_amount_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="Min">
                  <span class="input-group-text">–</span>
                  <input type="text" class="form-control" name="max_amount" value="<?= isset($editRecurring['max_amount_cents']) ? number_format($editRecurring['max_amount_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="Max">
                </div>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label">Intervall</label>
                <select class="form-select" name="interval_unit">
                  <?php foreach (['day' => 'Tage', 'week' => 'Wochen', 'month' => 'Monate', 'year' => 'Jahre'] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($editRecurring['interval_unit'] ?? 'month') === $key ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Intervallwert</label>
                <input type="number" class="form-control" name="interval_value" min="1" value="<?= (int)($editRecurring['interval_value'] ?? 1) ?>">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label">Startdatum</label>
                <input type="date" class="form-control" name="start_date" required value="<?= htmlspecialchars($editRecurring['start_date'] ?? date('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Enddatum</label>
                <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($editRecurring['end_date'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label">Priorität</label>
                <input type="number" class="form-control" name="priority" min="1" max="5" value="<?= (int)($editRecurring['priority'] ?? 3) ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Konto</label>
                <select class="form-select" name="account_id">
                  <option value="">--</option>
                  <?php foreach ($accounts as $acc): ?>
                    <option value="<?= (int)$acc['id'] ?>" <?= ($editRecurring['account_id'] ?? null) == $acc['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Kategorie</label>
                <select class="form-select" name="category_id">
                  <option value="">--</option>
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
                <label class="form-label">Empfänger</label>
                <select class="form-select" name="payee_id">
                  <option value="">--</option>
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
                  <label class="form-check-label" for="is-optional">Optional</label>
                </div>
              </div>
            </div>
            <div class="mt-3">
              <label class="form-label">Notiz</label>
              <textarea class="form-control" name="note" rows="2"><?= htmlspecialchars($editRecurring['note'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </div>
            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" name="is_active" id="is-active" <?= !empty($editRecurring['is_active']) || $editRecurring === null ? 'checked' : '' ?>>
              <label class="form-check-label" for="is-active">Aktiv</label>
            </div>
            <button type="submit" class="btn btn-success mt-3">Speichern</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
