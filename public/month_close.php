<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$serverPdo = hb_get_pdo();
$household = hb_require_household($serverPdo);
$pdo = hb_household_pdo($serverPdo, (int)$household['id']);
$currentHousehold = $household;
$currentUser = hb_current_user($serverPdo);

$pageTitle = 'Period close';
$activeNav = 'month_close';
$breadcrumbs = [
    ['label' => 'Period close', 'href' => '/month_close.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;

$today = new DateTimeImmutable('today');
$rangePreset = (string)($_GET['range'] ?? $_POST['range'] ?? '');
$resolvedRange = hb_resolve_period_range($pdo, $household, $rangePreset, $today);
$periodStart = $resolvedRange['start'];
$periodEnd = $resolvedRange['end'];
$periodLabel = $resolvedRange['label'];
$rangePreset = $resolvedRange['preset'] === 'current_period' ? '' : $resolvedRange['preset'];

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
        $error = hb_t('This period is already closed.');
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
        header('Location: /month_close.php?msg=closed' . ($rangePreset !== '' ? '&range=' . urlencode($rangePreset) : ''));
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
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Period close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Period:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($periodLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <form method="get" action="/month_close.php" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center">
      <select class="form-select form-select-sm" name="range">
        <option value="" <?= $rangePreset === '' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Current period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
        <option value="previous_period" <?= $rangePreset === 'previous_period' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Previous period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
      </select>
      <button type="submit" class="btn btn-sm btn-outline-secondary"><?= htmlspecialchars(hb_t('Change'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
    </form>
  </div>

  <?php if ($msg === 'closed'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Period closed.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Run close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <form method="post" action="/month_close.php">
            <input type="hidden" name="action" value="close">
            <input type="hidden" name="range" value="<?= htmlspecialchars($rangePreset, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div class="mb-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('Note (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <textarea class="form-control" name="note" rows="2"></textarea>
            </div>
            <button type="submit" class="btn btn-success"><?= htmlspecialchars(hb_t('Close period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Closures'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(hb_t('Period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('By'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($closures as $close): ?>
                  <tr>
                    <td><?= htmlspecialchars($close['period_start'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> – <?= htmlspecialchars($close['period_end'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($close['closed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($close['username'] ?? hb_t('System'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($close['note'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$closures): ?>
                  <tr><td colspan="4" class="text-muted"><?= htmlspecialchars(hb_t('No closures yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
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
