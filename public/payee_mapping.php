<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);
$pageTitle = 'Payee mapping';
$activeNav = 'payee_mapping';
$breadcrumbs = [
    ['label' => 'Payee mapping', 'href' => '/payee_mapping.php'],
];

$action = $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mappingId = (int)($_POST['mapping_id'] ?? 0);
    $payeeId = $_POST['payee_id'] !== '' ? (int)($_POST['payee_id'] ?? 0) : null;
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    $own = $pdo->prepare('select id from payee_mappings where id = :id and household_id = :hid');
    $own->execute(['id' => $mappingId, 'hid' => $household['id']]);
    if (!$own->fetch()) {
        $error = hb_t('Mapping not found.');
    } else {
        if ($payeeId !== null) {
            $payeeCheck = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
            $payeeCheck->execute(['id' => $payeeId, 'hid' => $household['id']]);
            if (!$payeeCheck->fetch()) {
                $error = hb_t('Payee does not belong to the household.');
            }
        }
    }

    if ($error === null) {
        $stmt = $pdo->prepare(
            'update payee_mappings
                set payee_id = :payee_id,
                    updated_at = now()
              where id = :id and household_id = :hid and row_version = :row_version'
        );
        $stmt->execute([
            'payee_id' => $payeeId,
            'id' => $mappingId,
            'hid' => $household['id'],
            'row_version' => $rowVersion,
        ]);
        header('Location: /payee_mapping.php?msg=saved');
        exit;
    }
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $mappingId = (int)($_POST['mapping_id'] ?? 0);
    $own = $pdo->prepare('select id from payee_mappings where id = :id and household_id = :hid');
    $own->execute(['id' => $mappingId, 'hid' => $household['id']]);
    if (!$own->fetch()) {
        $error = hb_t('Mapping not found.');
    } else {
        $del = $pdo->prepare('delete from payee_mappings where id = :id and household_id = :hid');
        $del->execute(['id' => $mappingId, 'hid' => $household['id']]);
        header('Location: /payee_mapping.php?msg=deleted');
        exit;
    }
}

$mappingStmt = $pdo->prepare(
    'select pm.*, p.name as payee_name
       from payee_mappings pm
       left join payees p on p.id = pm.payee_id
      where pm.household_id = :hid
      order by pm.counterparty_name asc'
);
$mappingStmt->execute(['hid' => $household['id']]);
$mappings = $mappingStmt->fetchAll();

$payeesStmt = $pdo->prepare('select id, name from payees where household_id = :hid order by name asc');
$payeesStmt->execute(['hid' => $household['id']]);
$payees = $payeesStmt->fetchAll();

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Payee mapping'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Assign auto-detected counterparties'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <a class="btn btn-sm btn-primary" href="/payees.php?action=new"><?= htmlspecialchars(hb_t('Create new payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Mapping saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Mapping deleted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="hb-whitebox">
    <div class="hb-whitebox-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th><?= htmlspecialchars(hb_t('Auto name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Assigned payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th class="text-end"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($mappings as $mapping): ?>
              <tr>
                <td><?= htmlspecialchars($mapping['counterparty_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td>
                  <form method="post" action="/payee_mapping.php" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="mapping_id" value="<?= (int)$mapping['id'] ?>">
                    <input type="hidden" name="row_version" value="<?= (int)$mapping['row_version'] ?>">
                    <select class="form-select form-select-sm" name="payee_id" onchange="this.form.submit()">
                      <option value=""><?= htmlspecialchars(hb_t('Unassigned'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php foreach ($payees as $payee): ?>
                        <option value="<?= (int)$payee['id'] ?>" <?= (int)($mapping['payee_id'] ?? 0) === (int)$payee['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars($payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                </td>
                <td class="text-end">
                  <form method="post" action="/payee_mapping.php" data-confirm="<?= htmlspecialchars(hb_t('Delete mapping?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="mapping_id" value="<?= (int)$mapping['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$mappings): ?>
              <tr><td colspan="3" class="text-muted"><?= htmlspecialchars(hb_t('No payee mappings available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$extraScripts = <<<HTML
<script>
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  const msg = form.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) {
    event.preventDefault();
  }
});
</script>
HTML;
require __DIR__ . '/../templates/layout.php';
