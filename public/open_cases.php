<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

$pageTitle = 'Offene Posten';
$activeNav = 'open_cases';
$breadcrumbs = [
    ['label' => 'Offene Posten', 'href' => '/open_cases.php'],
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
    'open' => 'Offen',
    'clarifying' => 'In Klärung',
    'agreed' => 'Vereinbart',
    'done' => 'Erledigt',
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

    if ($title === '') {
        $error = 'Titel ist erforderlich.';
    } elseif (!isset($statusOptions[$status])) {
        $error = 'Ungültiger Status.';
    }

    if ($error === null && $paymentKind !== 'none') {
        if ($status !== 'agreed') {
            $error = 'Zahlung kann nur bei Status "Vereinbart" erstellt werden.';
        } elseif ($paymentName === '') {
            $error = 'Zahlungsname ist erforderlich.';
        } elseif (!in_array($paymentDirection, ['income', 'expense'], true)) {
            $error = 'Ungültige Richtung für Zahlung.';
        } elseif ($paymentAmount === null || $paymentAmount <= 0) {
            $error = 'Betrag der Zahlung ist ungültig.';
        } elseif ($paymentKind === 'one_time' && $paymentDate === '') {
            $error = 'Datum der Einmalzahlung ist erforderlich.';
        } elseif ($paymentKind === 'recurring' && $paymentStartDate === '') {
            $error = 'Startdatum ist erforderlich.';
        }
    }

    if ($error === null) {
        if ($action === 'store') {
            $stmt = $pdo->prepare(
                'insert into open_cases
                    (household_id, title, status, reference, contact_name, contact_details, notes)
                 values
                    (:hid, :title, :status, :reference, :contact_name, :contact_details, :notes)'
            );
            $stmt->execute([
                'hid' => $household['id'],
                'title' => $title,
                'status' => $status,
                'reference' => $reference !== '' ? $reference : null,
                'contact_name' => $contactName !== '' ? $contactName : null,
                'contact_details' => $contactDetails !== '' ? $contactDetails : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);
            header('Location: /open_cases.php?msg=saved');
            exit;
        }

        $stmt = $pdo->prepare('select * from open_cases where id = :id and household_id = :hid');
        $stmt->execute(['id' => $id, 'hid' => $household['id']]);
        $current = $stmt->fetch();
        if (!$current) {
            $error = 'Offener Posten nicht gefunden.';
        } elseif ($paymentKind !== 'none' && (!empty($current['planned_payment_id']) || !empty($current['recurring_payment_id']))) {
            $error = 'Es ist bereits eine Zahlung verknüpft.';
        }
    }

    if ($error === null) {
        $plannedId = $current['planned_payment_id'] ?? null;
        $recurringId = $current['recurring_payment_id'] ?? null;
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
            'id' => $id,
            'hid' => $household['id'],
            'row_version' => $rowVersion,
        ]);

        if ($update->rowCount() === 0) {
            $fresh = $pdo->prepare('select * from open_cases where id = :id and household_id = :hid');
            $fresh->execute(['id' => $id, 'hid' => $household['id']]);
            $current = $fresh->fetch() ?: [];
            $conflictRows = hb_build_conflict_rows(
                [
                    'title' => 'Titel',
                    'status' => 'Status',
                    'reference' => 'Aktenzeichen',
                    'contact_name' => 'Ansprechpartner',
                    'contact_details' => 'Kontaktdaten',
                    'notes' => 'Notizen',
                ],
                $current,
                [
                    'title' => $title,
                    'status' => $status,
                    'reference' => $reference,
                    'contact_name' => $contactName,
                    'contact_details' => $contactDetails,
                    'notes' => $notes,
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
            header('Location: /open_cases.php?msg=saved');
            exit;
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
        $error = 'Offener Posten nicht gefunden.';
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
      <h1 class="h4 mb-0">Offene Posten</h1>
      <div class="text-muted small">Forderungen, Klärungen und Sonderfälle</div>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success">Eintrag gespeichert.</div>
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
                  <th>Titel</th>
                  <th>Status</th>
                  <th>Referenz</th>
                  <th>Zahlung</th>
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
                        Einmalzahlung
                      <?php elseif (!empty($case['recurring_payment_id'])): ?>
                        Wiederkehrend
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="/open_cases.php?action=edit&id=<?= (int)$case['id'] ?>">Bearbeiten</a></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$cases): ?>
                  <tr><td colspan="5" class="text-muted">Keine offenen Posten vorhanden.</td></tr>
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
          <h2 class="h6 mb-3"><?= $isEdit ? 'Posten bearbeiten' : 'Neuer Posten' ?></h2>
          <form method="post" action="/open_cases.php">
            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'store' ?>">
            <?php if ($isEdit): ?>
              <input type="hidden" name="id" value="<?= (int)$editCase['id'] ?>">
              <input type="hidden" name="row_version" value="<?= (int)($editCase['row_version'] ?? 0) ?>">
            <?php endif; ?>
            <div class="mb-3">
              <label class="form-label">Titel</label>
              <input type="text" class="form-control" name="title" required value="<?= htmlspecialchars($editCase['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                  <?php foreach ($statusOptions as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($editCase['status'] ?? 'open') === $key ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Aktenzeichen</label>
                <input type="text" class="form-control" name="reference" value="<?= htmlspecialchars($editCase['reference'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
            </div>
            <div class="mt-3">
              <label class="form-label">Ansprechpartner</label>
              <input type="text" class="form-control" name="contact_name" value="<?= htmlspecialchars($editCase['contact_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="mt-3">
              <label class="form-label">Kontaktdaten</label>
              <textarea class="form-control" name="contact_details" rows="2"><?= htmlspecialchars($editCase['contact_details'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </div>
            <div class="mt-3">
              <label class="form-label">Notizen</label>
              <textarea class="form-control" name="notes" rows="2"><?= htmlspecialchars($editCase['notes'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </div>

            <div class="border rounded-3 p-3 mt-3 bg-light-subtle">
              <div class="fw-semibold mb-2">Zahlung anlegen (optional)</div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Art</label>
                  <select class="form-select" name="payment_kind">
                    <option value="none">Keine</option>
                    <option value="one_time">Einmalzahlung</option>
                    <option value="recurring">Wiederkehrend</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Name</label>
                  <input type="text" class="form-control" name="payment_name" value="<?= htmlspecialchars($editCase['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label">Richtung</label>
                  <select class="form-select" name="payment_direction">
                    <option value="expense">Ausgabe</option>
                    <option value="income">Einnahme</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Betrag</label>
                  <input type="text" class="form-control" name="payment_amount" placeholder="0,00">
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label">Einmal-Datum</label>
                  <input type="date" class="form-control" name="payment_date">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Startdatum (Recurring)</label>
                  <input type="date" class="form-control" name="payment_start_date">
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label">Intervall</label>
                  <select class="form-select" name="payment_interval_unit">
                    <?php foreach (['day' => 'Tage', 'week' => 'Wochen', 'month' => 'Monate', 'year' => 'Jahre'] as $key => $label): ?>
                      <option value="<?= $key ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Intervallwert</label>
                  <input type="number" class="form-control" name="payment_interval_value" min="1" value="1">
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label">Konto</label>
                  <select class="form-select" name="payment_account_id">
                    <option value="">--</option>
                    <?php foreach ($accounts as $acc): ?>
                      <option value="<?= (int)$acc['id'] ?>"><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Kategorie</label>
                  <select class="form-select" name="payment_category_id">
                    <option value="">--</option>
                    <?php foreach ($categories as $cat): ?>
                      <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label">Empfänger</label>
                  <select class="form-select" name="payment_payee_id">
                    <option value="">--</option>
                    <?php foreach ($payees as $payee): ?>
                      <option value="<?= (int)$payee['id'] ?>"><?= htmlspecialchars($payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Priorität</label>
                  <input type="number" class="form-control" name="payment_priority" min="1" max="5" value="3">
                </div>
              </div>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="payment_optional" id="payment-optional">
                <label class="form-check-label" for="payment-optional">Optional</label>
              </div>
              <div class="mt-2">
                <label class="form-label">Notiz</label>
                <textarea class="form-control" name="payment_note" rows="2"></textarea>
              </div>
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
