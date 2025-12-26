<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

$pageTitle = 'Monatsabschluss';
$activeNav = 'month_close';
$breadcrumbs = [
    ['label' => 'Monatsabschluss', 'href' => '/month_close.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;

$today = new DateTimeImmutable('today');
[$periodStart, $periodEnd] = hb_household_period_bounds($household, $today);

if ($action === 'close' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $note = trim((string)($_POST['note'] ?? ''));

    $existing = $pdo->prepare(
        'select id from month_closures where household_id = :hid and period_start = :start'
    );
    $existing->execute([
        'hid' => $household['id'],
        'start' => $periodStart->format('Y-m-d'),
    ]);
    if ($existing->fetch()) {
        $error = 'Dieser Zeitraum ist bereits abgeschlossen.';
    } else {
        $insert = $pdo->prepare(
            'insert into month_closures (household_id, period_start, period_end, closed_by, note)
             values (:hid, :start, :end, :user_id, :note)'
        );
        $insert->execute([
            'hid' => $household['id'],
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
            'user_id' => $currentUser['id'] ?? null,
            'note' => $note !== '' ? $note : null,
        ]);
        header('Location: /month_close.php?msg=closed');
        exit;
    }
}

$closuresStmt = $pdo->prepare(
    'select mc.*, u.username
       from month_closures mc
       left join users u on u.id = mc.closed_by
      where mc.household_id = :hid
      order by mc.period_start desc'
);
$closuresStmt->execute(['hid' => $household['id']]);
$closures = $closuresStmt->fetchAll();

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0">Monatsabschluss</h1>
      <div class="text-muted small">Zeitraum: <?= htmlspecialchars($periodStart->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> – <?= htmlspecialchars($periodEnd->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
  </div>

  <?php if ($msg === 'closed'): ?>
    <div class="alert alert-success">Monat abgeschlossen.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3">Abschluss durchführen</h2>
          <form method="post" action="/month_close.php">
            <input type="hidden" name="action" value="close">
            <div class="mb-3">
              <label class="form-label">Notiz (optional)</label>
              <textarea class="form-control" name="note" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-success">Monat abschließen</button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3">Abschlüsse</h2>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Zeitraum</th>
                  <th>Datum</th>
                  <th>Von</th>
                  <th>Notiz</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($closures as $close): ?>
                  <tr>
                    <td><?= htmlspecialchars($close['period_start'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> – <?= htmlspecialchars($close['period_end'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($close['closed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($close['username'] ?? 'System', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($close['note'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$closures): ?>
                  <tr><td colspan="4" class="text-muted">Noch keine Abschlüsse.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
