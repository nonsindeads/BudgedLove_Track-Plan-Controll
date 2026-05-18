<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$serverPdo = hb_get_pdo();
$household = hb_require_household($serverPdo);
$pdo = hb_household_pdo($serverPdo, (int)$household['id']);
$db = hb_dbal_household();
$currentHousehold = $household;
$currentUser = hb_current_user($serverPdo);
$pageTitle = 'Payees';
$activeNav = 'payees';
$breadcrumbs = [
    ['label' => 'Payees', 'href' => '/payees.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$conflict = null;

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payeeId = (int)($_POST['id'] ?? 0);
    $own = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
    $own->execute(['id' => $payeeId, 'hid' => $household['id']]);
    if (!$own->fetch()) {
        $error = hb_t('Payee not found.');
    } else {
        $del = $pdo->prepare('delete from payees where id = :id and household_id = :hid');
        $del->execute(['id' => $payeeId, 'hid' => $household['id']]);
        header('Location: /payees.php?msg=deleted');
        exit;
    }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $address = trim((string)($_POST['address_text'] ?? ''));
    $iban = trim((string)($_POST['iban'] ?? ''));
    $bic = trim((string)($_POST['bic'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => hb_t('Name is required.')]);
        exit;
    }
    $exists = $pdo->prepare('select id from payees where household_id = :hid and lower(name) = lower(:name)');
    $exists->execute(['hid' => $household['id'], 'name' => $name]);
    if ($exists->fetch()) {
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode(['error' => hb_t('Payee already exists.')]);
        exit;
    }
    $newId = hb_dbal_insert_and_get_id($db, 'payees', [
        'household_id' => (int)$household['id'],
        'name' => $name,
        'address_text' => $address !== '' ? $address : null,
        'iban' => $iban !== '' ? $iban : null,
        'bic' => $bic !== '' ? $bic : null,
        'notes' => $notes !== '' ? $notes : null,
    ]);
    header('Content-Type: application/json');
    echo json_encode([
        'id' => $newId,
        'name' => $name,
    ]);
    exit;
}

if (in_array($action, ['store', 'update'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $address = trim((string)($_POST['address_text'] ?? ''));
    $iban = trim((string)($_POST['iban'] ?? ''));
    $bic = trim((string)($_POST['bic'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    if ($name === '') {
        $error = hb_t('Name is required.');
    }

    if ($error === null) {
        if ($action === 'store') {
            $stmt = $pdo->prepare(
                'insert into payees (household_id, name, address_text, iban, bic, notes)
                 values (:hid, :name, :address, :iban, :bic, :notes)'
            );
            $stmt->execute([
                'hid' => $household['id'],
                'name' => $name,
                'address' => $address !== '' ? $address : null,
                'iban' => $iban !== '' ? $iban : null,
                'bic' => $bic !== '' ? $bic : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);
        } else {
            $own = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
            $own->execute(['id' => $id, 'hid' => $household['id']]);
            if (!$own->fetch()) {
                $error = hb_t('Payee not found.');
            } else {
                $stmt = $pdo->prepare(
                    'update payees
                        set name = :name,
                            address_text = :address,
                            iban = :iban,
                            bic = :bic,
                            notes = :notes,
                            updated_at = :updated_at
                      where id = :id and household_id = :hid and row_version = :row_version'
                );
                $stmt->execute([
                    'name' => $name,
                    'address' => $address !== '' ? $address : null,
                    'iban' => $iban !== '' ? $iban : null,
                    'bic' => $bic !== '' ? $bic : null,
                    'notes' => $notes !== '' ? $notes : null,
                    'id' => $id,
                    'hid' => $household['id'],
                    'row_version' => $rowVersion,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                if ($stmt->rowCount() === 0) {
                    $fresh = $pdo->prepare('select * from payees where id = :id and household_id = :hid');
                    $fresh->execute(['id' => $id, 'hid' => $household['id']]);
                    $current = $fresh->fetch() ?: [];
                    $conflictRows = hb_build_conflict_rows(
                        [
                            'name' => hb_t('Name'),
                            'address_text' => hb_t('Address'),
                            'iban' => 'IBAN',
                            'bic' => 'BIC',
                            'notes' => hb_t('Notes'),
                        ],
                        $current,
                        [
                            'name' => $name,
                            'address_text' => $address,
                            'iban' => $iban,
                            'bic' => $bic,
                            'notes' => $notes,
                        ]
                    );
                    $conflict = hb_render_conflict_table($conflictRows);
                    $editPayee = array_merge($current, [
                        'name' => $name,
                        'address_text' => $address,
                        'iban' => $iban,
                        'bic' => $bic,
                        'notes' => $notes,
                        'row_version' => $current['row_version'] ?? 0,
                    ]);
                    $action = 'edit';
                }
            }
        }
        if ($error === null) {
            if (empty($conflict)) {
                header('Location: /payees.php?msg=saved');
                exit;
            }
        }
    }
}

$editPayee = $editPayee ?? null;
if ($action === 'edit' && $editPayee === null) {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('select * from payees where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $editPayee = $stmt->fetch();
    if (!$editPayee) {
        $error = hb_t('Payee not found.');
        $action = 'list';
    }
}

$payeeStmt = $pdo->prepare('select * from payees where household_id = :hid order by name asc');
$payeeStmt->execute(['hid' => $household['id']]);
$payees = $payeeStmt->fetchAll();

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Payees'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Household:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/categories.php"><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      <a class="btn btn-sm btn-outline-primary" href="/transactions.php"><?= htmlspecialchars(hb_t('Transactions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Payee saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Payee deleted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="hb-whitebox">
        <div class="hb-whitebox-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h6 mb-0"><?= htmlspecialchars(hb_t('List'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <a class="btn btn-sm btn-primary" href="/payees.php?action=new"><?= htmlspecialchars(hb_t('New payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th>IBAN</th>
                  <th><?= htmlspecialchars(hb_t('Notes'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th class="text-end"><?= htmlspecialchars(hb_t('Actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($payees as $p): ?>
                  <tr>
                    <td><?= htmlspecialchars($p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($p['iban'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="small"><?= htmlspecialchars($p['notes'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="text-end">
                      <div class="d-flex justify-content-end gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="/payees.php?action=edit&id=<?= (int)$p['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                        <form method="post" action="/payees.php" data-confirm="<?= htmlspecialchars(hb_t('Delete payee?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$payees): ?>
                  <tr><td colspan="4" class="text-muted"><?= htmlspecialchars(hb_t('No payees available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
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
if (in_array($action, ['new', 'edit'], true)) {
    ob_start();
    ?>
    <?php if (!empty($conflict)): ?>
      <?= $conflict ?>
    <?php endif; ?>
    <?php $isEdit = $action === 'edit' && $editPayee; ?>
    <form method="post" action="/payees.php">
      <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'store' ?>">
      <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$editPayee['id'] ?>">
        <input type="hidden" name="row_version" value="<?= (int)($editPayee['row_version'] ?? 0) ?>">
      <?php endif; ?>
      <div class="mb-3">
        <label for="name" class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input type="text" class="form-control" id="name" name="name" required value="<?= htmlspecialchars($editPayee['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      </div>
      <div class="mb-3">
        <label for="address" class="form-label"><?= htmlspecialchars(hb_t('Address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <textarea class="form-control" id="address" name="address_text" rows="2"><?= htmlspecialchars($editPayee['address_text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <label for="iban" class="form-label">
            IBAN
            <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Optional; helps with later imports/matching.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
          </label>
          <input type="text" class="form-control" id="iban" name="iban" value="<?= htmlspecialchars($editPayee['iban'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="col-md-6">
          <label for="bic" class="form-label">
            BIC
            <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Optional; for bank transfers.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
          </label>
          <input type="text" class="form-control" id="bic" name="bic" value="<?= htmlspecialchars($editPayee['bic'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
      </div>
      <div class="mt-3">
        <label for="notes" class="form-label"><?= htmlspecialchars(hb_t('Notes'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <textarea class="form-control" id="notes" name="notes" rows="2"><?= htmlspecialchars($editPayee['notes'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
      </div>
      <button type="submit" class="btn btn-success mt-3"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      <a href="/payees.php" class="btn btn-outline-secondary mt-3"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </form>
    <?php
    $modalContent = ob_get_clean();
    $modalTitle = $isEdit ? hb_t('Edit payee') : hb_t('New payee');
    $modalTitleEsc = htmlspecialchars($modalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $closeLabel = htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $content .= <<<HTML
    <div class="modal fade" id="hb-payee-modal" tabindex="-1" aria-labelledby="hb-payee-modal-label" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="hb-payee-modal-label">{$modalTitleEsc}</h5>
            <a href="/payees.php" class="btn-close" aria-label="{$closeLabel}"></a>
          </div>
          <div class="modal-body">
            {$modalContent}
          </div>
        </div>
      </div>
    </div>
    HTML;
}
$extraScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('hb-payee-modal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
});
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
