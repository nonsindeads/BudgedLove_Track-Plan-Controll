<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

$pageTitle = 'Monatsplan';
$activeNav = 'plan';
$breadcrumbs = [
    ['label' => 'Monatsplan', 'href' => '/plan.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$conflict = null;

$monthParam = trim((string)($_GET['month'] ?? ''));
$monthDate = $monthParam !== '' ? new DateTimeImmutable($monthParam . '-01') : new DateTimeImmutable('today');
[$periodStart, $periodEnd] = hb_household_period_bounds($household, $monthDate);

hb_ensure_month_plan($pdo, $household, $periodStart, $periodEnd);
hb_mark_overdue_plans($pdo, $household['id']);

if (in_array($action, ['mark_done', 'skip'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $planId = (int)($_POST['plan_id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);
    $redirect = trim((string)($_POST['redirect'] ?? ''));

    $planStmt = $pdo->prepare('select * from planned_payments where id = :id and household_id = :hid');
    $planStmt->execute(['id' => $planId, 'hid' => $household['id']]);
    $plan = $planStmt->fetch();
    if (!$plan) {
        $error = 'Plan nicht gefunden.';
    } elseif ($action === 'skip' && empty($plan['is_optional'])) {
        $error = 'Nur optionale Zahlungen können übersprungen werden.';
    } else {
        $planDate = DateTimeImmutable::createFromFormat('Y-m-d', $plan['planned_date']);
        if ($planDate && hb_is_period_closed($pdo, $household['id'], $planDate)) {
            $error = 'Der Monat ist bereits abgeschlossen. Änderungen sind gesperrt.';
        }
    }
    if ($error === null) {
        $newStatus = $action === 'mark_done' ? 'done' : 'skipped';
        $update = $pdo->prepare(
            'update planned_payments
                set status = :status,
                    resolved_at = now(),
                    updated_at = now()
              where id = :id and household_id = :hid and row_version = :row_version'
        );
        $update->execute([
            'status' => $newStatus,
            'id' => $planId,
            'hid' => $household['id'],
            'row_version' => $rowVersion,
        ]);
        if ($update->rowCount() === 0) {
            $current = $pdo->prepare('select * from planned_payments where id = :id and household_id = :hid');
            $current->execute(['id' => $planId, 'hid' => $household['id']]);
            $current = $current->fetch() ?: [];
            $conflictRows = hb_build_conflict_rows(
                [
                    'status' => 'Status',
                    'planned_date' => 'Datum',
                    'amount_cents' => 'Betrag',
                ],
                $current,
                [
                    'status' => $newStatus,
                    'planned_date' => (string)($plan['planned_date'] ?? ''),
                    'amount_cents' => (string)($plan['amount_cents'] ?? ''),
                ]
            );
            $conflict = hb_render_conflict_table($conflictRows);
        } else {
            if ($redirect !== '') {
                header('Location: ' . $redirect);
            } else {
                header('Location: /plan.php?month=' . $periodStart->format('Y-m'));
            }
            exit;
        }
    }
}

$planStmt = $pdo->prepare(
    'select p.*, a.name as account_name, c.name as category_name, pay.name as payee_name
       from planned_payments p
       left join accounts a on a.id = p.account_id
       left join categories c on c.id = p.category_id
       left join payees pay on pay.id = p.payee_id
      where p.household_id = :hid
        and p.planned_date between :start and :end
      order by p.planned_date asc, p.priority desc, p.name asc'
);
$planStmt->execute([
    'hid' => $household['id'],
    'start' => $periodStart->format('Y-m-d'),
    'end' => $periodEnd->format('Y-m-d'),
]);
$plans = $planStmt->fetchAll();

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0">Monatsplan</h1>
      <div class="text-muted small">
        Zeitraum: <?= htmlspecialchars($periodStart->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        – <?= htmlspecialchars($periodEnd->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <form method="get" action="/plan.php" class="d-flex gap-2 align-items-center">
        <input type="month" class="form-control form-control-sm" name="month" value="<?= htmlspecialchars($periodStart->format('Y-m'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary">Wechseln</button>
      </form>
      <a class="btn btn-sm btn-outline-primary" href="/recurring.php">Wiederkehrende Zahlungen</a>
    </div>
  </div>

  <?php if (!empty($conflict)): ?>
    <?= $conflict ?>
  <?php endif; ?>
  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success">Status gespeichert.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th>Datum</th>
              <th>Titel</th>
              <th>Richtung</th>
              <th>Betrag</th>
              <th>Status</th>
              <th>Konto</th>
              <th>Kategorie</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($plans as $plan): ?>
              <?php
              $status = $plan['status'];
              $badge = match ($status) {
                  'done' => 'bg-success',
                  'skipped' => 'bg-secondary',
                  'overdue' => 'bg-danger',
                  'suggested' => 'bg-warning',
                  default => 'bg-light text-dark',
              };
              ?>
              <tr>
                <td><?= htmlspecialchars($plan['planned_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td>
                  <div class="fw-semibold"><?= htmlspecialchars($plan['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <?php if (!empty($plan['note'])): ?>
                    <div class="text-muted small"><?= htmlspecialchars($plan['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($plan['direction'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= number_format($plan['amount_cents'] / 100, 2, ',', '.') ?> €</td>
                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(hb_plan_status_label($status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars($plan['account_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($plan['category_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td class="text-end">
                  <?php if (in_array($status, ['open', 'overdue', 'suggested'], true)): ?>
                    <div class="d-flex flex-column flex-sm-row gap-1 justify-content-end">
                      <form method="post" action="/plan.php?month=<?= htmlspecialchars($periodStart->format('Y-m'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="d-inline">
                        <input type="hidden" name="action" value="mark_done">
                        <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                        <input type="hidden" name="row_version" value="<?= (int)$plan['row_version'] ?>">
                        <button type="submit" class="btn btn-sm btn-success">Erledigt</button>
                      </form>
                      <?php if (!empty($plan['is_optional'])): ?>
                        <form method="post" action="/plan.php?month=<?= htmlspecialchars($periodStart->format('Y-m'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="d-inline">
                          <input type="hidden" name="action" value="skip">
                          <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                          <input type="hidden" name="row_version" value="<?= (int)$plan['row_version'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-secondary">Überspringen</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$plans): ?>
              <tr><td colspan="8" class="text-muted">Keine geplanten Zahlungen im Zeitraum.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
