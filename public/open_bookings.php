<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

$pageTitle = 'Offene Buchungen';
$activeNav = 'open_bookings';
$breadcrumbs = [
    ['label' => 'Offene Buchungen', 'href' => '/open_bookings.php'],
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
        $error = 'Buchung nicht gefunden oder bereits geprüft.';
    }

    if ($error === null && $categoryId !== null) {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        $catCheck->execute(['id' => $categoryId, 'hid' => $household['id']]);
        if (!$catCheck->fetch()) {
            $error = 'Kategorie gehört nicht zum Haushalt.';
        }
    }
    if ($error === null && $payeeId !== null) {
        $payeeCheck = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
        $payeeCheck->execute(['id' => $payeeId, 'hid' => $household['id']]);
        if (!$payeeCheck->fetch()) {
            $error = 'Payee gehört nicht zum Haushalt.';
        }
    }
    if ($error === null && $plannedPaymentId !== null) {
        $planCheck = $pdo->prepare('select id from planned_payments where id = :id and household_id = :hid');
        $planCheck->execute(['id' => $plannedPaymentId, 'hid' => $household['id']]);
        if (!$planCheck->fetch()) {
            $error = 'Zahlungsplan gehört nicht zum Haushalt.';
        }
    }
    if ($error === null && $tagIds) {
        $tagCheck = $pdo->prepare('select id from tags where id = :id and household_id = :hid');
        foreach ($tagIds as $tagId) {
            $tagCheck->execute(['id' => $tagId, 'hid' => $household['id']]);
            if (!$tagCheck->fetch()) {
                $error = 'Tag gehört nicht zum Haushalt.';
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
                    $error = 'Split-Kategorie gehört nicht zum Haushalt.';
                    break;
                }
                $splits[] = ['category_id' => $catId, 'amount_cents' => $cents];
                $splitSum += $cents;
            }
        }
        if ($error === null && $splits && $splitSum !== (int)$txRow['amount_cents']) {
            $error = 'Split-Summe muss dem Betrag entsprechen.';
        }
    }

    if ($error === null && !$splits && $categoryId === null) {
        $error = 'Kategorie ist erforderlich.';
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
                    'category_id' => 'Kategorie',
                    'payee_id' => 'Payee',
                    'planned_payment_id' => 'Zahlungsplan',
                    'note' => 'Notiz',
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
            header('Location: /open_bookings.php?msg=saved');
            exit;
        }
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
    "select id, name, planned_date
       from planned_payments
      where household_id = :hid and status in ('open', 'overdue', 'suggested')
      order by planned_date asc, id asc"
);
$plansStmt->execute(['hid' => $household['id']]);
$plans = $plansStmt->fetchAll();

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
      <h1 class="h4 mb-0">Offene Buchungen</h1>
      <div class="text-muted small">Neue Umsätze prüfen, zuordnen und final buchen.</div>
    </div>
    <div class="text-muted small">Offen: <?= count($openBookings) ?></div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success">Buchung final gespeichert.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?= $conflict ?>

  <div class="row g-4">
    <div class="col-12">
      <?php if (!$openBookings): ?>
        <div class="card shadow-sm">
          <div class="card-body text-muted">Keine offenen Buchungen vorhanden.</div>
        </div>
      <?php endif; ?>

      <?php foreach ($openBookings as $tx): ?>
        <?php
        $selectedPayee = $tx['payee_id'] ?? null;
        if (!$selectedPayee && !empty($tx['suggested_payee_id'])) {
            $selectedPayee = $tx['suggested_payee_id'];
        }
        $selectedTags = $txTags[(int)$tx['id']] ?? [];
        $splitRows = $txSplits[(int)$tx['id']] ?? [];
        $directionBadge = $tx['type'] === 'income' ? 'bg-success' : 'bg-danger';
        ?>
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start mb-2">
              <div>
                <div class="fw-semibold"><?= htmlspecialchars($tx['counterparty_name'] ?: 'Unbekannter Empfänger', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="text-muted small">
                  <?= htmlspecialchars($tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  · <?= htmlspecialchars($tx['account_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
              </div>
              <div class="text-end">
                <div class="fw-semibold"><?= number_format($tx['amount_cents'] / 100, 2, ',', '.') ?> €</div>
                <span class="badge <?= $directionBadge ?>"><?= htmlspecialchars($tx['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </div>
            </div>

            <?php if (!empty($tx['note'])): ?>
              <div class="text-muted small mb-3"><?= htmlspecialchars($tx['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if (!empty($tx['suggested_payee_name'])): ?>
              <div class="badge bg-info-subtle text-info mb-2">Vorschlag: <?= htmlspecialchars($tx['suggested_payee_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="/open_bookings.php" class="row g-2 align-items-end">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="transaction_id" value="<?= (int)$tx['id'] ?>">
              <input type="hidden" name="row_version" value="<?= (int)$tx['row_version'] ?>">
              <div class="col-md-4">
                <label class="form-label small d-flex justify-content-between align-items-center">
                  <span>Kategorie</span>
                  <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#split-<?= (int)$tx['id'] ?>">Split</button>
                </label>
                <?php
                $categorySelectorId = 'category-' . (int)$tx['id'];
                $categorySelectorName = 'category_id';
                $categorySelectorCategories = $categories;
                $categorySelectorSelected = $tx['category_id'] ?? null;
                $categorySelectorPlaceholder = 'Kategorie suchen...';
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
                            <option value="">Kategorie wählen</option>
                            <?php foreach ($categories as $cat): ?>
                              <option value="<?= (int)$cat['id'] ?>" <?= ($existing['category_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-5">
                          <input type="text" class="form-control form-control-sm" name="split_amount[]" value="<?= $existing ? number_format($existing['amount_cents'] / 100, 2, ',', '.') : '' ?>" placeholder="0,00">
                        </div>
                      </div>
                    <?php endfor; ?>
                    <div class="form-text">Summe der Splits = Betrag.</div>
                  </div>
                </div>
              </div>
              <div class="col-md-4">
                <label class="form-label small">Payee</label>
                <?php
                $payeeSelectorId = 'payee-' . (int)$tx['id'];
                $payeeSelectorName = 'payee_id';
                $payeeSelectorPayees = $payees;
                $payeeSelectorSelected = $selectedPayee;
                $payeeSelectorPlaceholder = 'Payee suchen...';
                $payeeModalTarget = '#payeeModal';
                require __DIR__ . '/../templates/partials/payee_selector.php';
                ?>
              </div>
              <div class="col-md-4">
                <label class="form-label small">Zahlungsplan</label>
                <select class="form-select form-select-sm" name="planned_payment_id">
                  <option value="">Nicht gesetzt</option>
                  <?php foreach ($plans as $plan): ?>
                    <?php $selected = (int)$plan['id'] === (int)($tx['planned_payment_id'] ?? 0); ?>
                    <option value="<?= (int)$plan['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                      <?= htmlspecialchars($plan['planned_date'] . ' · ' . $plan['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label small">Tags</label>
                <?php
                $tagSelectorId = 'tags-' . (int)$tx['id'];
                $tagSelectorName = 'tag_ids[]';
                $tagSelectorTags = $tags;
                $tagSelectorSelected = $selectedTags;
                $tagSelectorPlaceholder = 'Tag suchen...';
                $tagModalTarget = '#tagModal';
                require __DIR__ . '/../templates/partials/tag_selector.php';
                ?>
              </div>
              <div class="col-md-6">
                <label class="form-label small">Notiz</label>
                <input class="form-control form-control-sm" type="text" name="note" value="<?= htmlspecialchars((string)($tx['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <div class="col-12 text-end">
                <button type="submit" class="btn btn-success btn-sm">Final buchen</button>
              </div>
            </form>
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
require __DIR__ . '/../templates/layout.php';
