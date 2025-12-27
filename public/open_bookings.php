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

    $txCheck = $pdo->prepare('select id from transactions where id = :id and household_id = :hid and is_reviewed = false');
    $txCheck->execute(['id' => $txId, 'hid' => $household['id']]);
    if (!$txCheck->fetch()) {
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

    if ($error === null) {
        $stmt = $pdo->prepare(
            'update transactions
                set category_id = :category_id,
                    payee_id = :payee_id,
                    planned_payment_id = :planned_payment_id,
                    note = :note,
                    is_reviewed = true,
                    suggested_payee_id = null,
                    suggested_match_rule_id = null,
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

if ($action === 'add_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pattern = trim((string)($_POST['pattern'] ?? ''));
    $payeeId = (int)($_POST['rule_payee_id'] ?? 0);
    $priority = (int)($_POST['priority'] ?? 10);
    if ($pattern === '') {
        $error = 'Match-Text ist erforderlich.';
    } else {
        $payeeCheck = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
        $payeeCheck->execute(['id' => $payeeId, 'hid' => $household['id']]);
        if (!$payeeCheck->fetch()) {
            $error = 'Payee gehört nicht zum Haushalt.';
        }
    }
    if ($error === null) {
        $stmt = $pdo->prepare(
            'insert into payee_match_rules (household_id, pattern, match_type, payee_id, priority, is_active)
             values (:hid, :pattern, :match_type, :payee_id, :priority, true)'
        );
        $stmt->execute([
            'hid' => $household['id'],
            'pattern' => $pattern,
            'match_type' => 'contains',
            'payee_id' => $payeeId,
            'priority' => $priority,
        ]);
        header('Location: /open_bookings.php?msg=rule_saved');
        exit;
    }
}

if ($action === 'toggle_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    $isActive = !empty($_POST['is_active']);
    $stmt = $pdo->prepare(
        'update payee_match_rules
            set is_active = :active,
                updated_at = now()
          where id = :id and household_id = :hid'
    );
    $stmt->execute(['active' => $isActive, 'id' => $ruleId, 'hid' => $household['id']]);
    header('Location: /open_bookings.php?msg=rule_updated');
    exit;
}

if ($action === 'delete_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    $stmt = $pdo->prepare('delete from payee_match_rules where id = :id and household_id = :hid');
    $stmt->execute(['id' => $ruleId, 'hid' => $household['id']]);
    header('Location: /open_bookings.php?msg=rule_deleted');
    exit;
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

$ruleStmt = $pdo->prepare(
    'select r.*, p.name as payee_name
       from payee_match_rules r
       join payees p on p.id = r.payee_id
      where r.household_id = :hid
      order by r.priority asc, r.id asc'
);
$ruleStmt->execute(['hid' => $household['id']]);
$matchRules = $ruleStmt->fetchAll();

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
  <?php elseif ($msg === 'rule_saved'): ?>
    <div class="alert alert-success">Matching-Regel gespeichert.</div>
  <?php elseif ($msg === 'rule_updated'): ?>
    <div class="alert alert-success">Matching-Regel aktualisiert.</div>
  <?php elseif ($msg === 'rule_deleted'): ?>
    <div class="alert alert-success">Matching-Regel gelöscht.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?= $conflict ?>

  <div class="row g-4">
    <div class="col-xl-8">
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
                <label class="form-label small">Kategorie</label>
                <select class="form-select form-select-sm" name="category_id">
                  <option value="">Nicht gesetzt</option>
                  <?php foreach ($categories as $cat): ?>
                    <?php $selected = (int)$cat['id'] === (int)$tx['category_id']; ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                      <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small">Payee</label>
                <select class="form-select form-select-sm" name="payee_id">
                  <option value="">Nicht gesetzt</option>
                  <?php foreach ($payees as $payee): ?>
                    <?php $selected = (int)$payee['id'] === (int)$selectedPayee; ?>
                    <option value="<?= (int)$payee['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                      <?= htmlspecialchars($payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
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
                <select class="form-select form-select-sm" name="tag_ids[]" multiple size="4">
                  <?php foreach ($tags as $tag): ?>
                    <?php $selected = in_array((int)$tag['id'], $selectedTags, true); ?>
                    <option value="<?= (int)$tag['id'] ?>" <?= $selected ? 'selected' : '' ?>>
                      <?= htmlspecialchars($tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
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
    <div class="col-xl-4">
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h2 class="h6 mb-3">Matching-Regeln</h2>
          <form method="post" action="/open_bookings.php" class="row g-2">
            <input type="hidden" name="action" value="add_rule">
            <div class="col-12">
              <label class="form-label small">Match-Text (Teilstring)</label>
              <input class="form-control form-control-sm" type="text" name="pattern" placeholder="z. B. PayPal, AVIA">
            </div>
            <div class="col-12">
              <label class="form-label small">Payee</label>
              <select class="form-select form-select-sm" name="rule_payee_id" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($payees as $payee): ?>
                  <option value="<?= (int)$payee['id'] ?>"><?= htmlspecialchars($payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small">Priorität</label>
              <input class="form-control form-control-sm" type="number" name="priority" value="10" min="1" max="99">
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-primary btn-sm">Regel hinzufügen</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm">
        <div class="card-body">
          <h3 class="h6 mb-3">Regelübersicht</h3>
          <?php if (!$matchRules): ?>
            <div class="text-muted small">Keine Regeln hinterlegt.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th>Match</th>
                    <th>Payee</th>
                    <th>Prio</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($matchRules as $rule): ?>
                    <tr>
                      <td><?= htmlspecialchars($rule['pattern'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars($rule['payee_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= (int)$rule['priority'] ?></td>
                      <td class="text-end">
                        <form method="post" action="/open_bookings.php" class="d-inline">
                          <input type="hidden" name="action" value="toggle_rule">
                          <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                          <input type="hidden" name="is_active" value="<?= $rule['is_active'] ? '0' : '1' ?>">
                          <button type="submit" class="btn btn-sm <?= $rule['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                            <?= $rule['is_active'] ? 'Deaktivieren' : 'Aktivieren' ?>
                          </button>
                        </form>
                        <form method="post" action="/open_bookings.php" class="d-inline">
                          <input type="hidden" name="action" value="delete_rule">
                          <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger">Löschen</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
