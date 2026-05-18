<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$serverPdo = hb_get_pdo();
$household = hb_require_household($serverPdo);
$pdo = hb_household_pdo($serverPdo, (int)$household['id']);
$currentHousehold = $household;
$currentUser = hb_current_user($serverPdo);
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
    $categoryId = $_POST['category_id'] !== '' ? (int)($_POST['category_id'] ?? 0) : null;
    $tagIdsRaw = $_POST['tag_ids'] ?? [];
    $tagIds = hb_normalize_id_list(is_array($tagIdsRaw) ? $tagIdsRaw : [$tagIdsRaw]);
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
    if ($error === null && $categoryId !== null) {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid and is_active = true');
        $catCheck->execute(['id' => $categoryId, 'hid' => $household['id']]);
        if (!$catCheck->fetch()) {
            $error = hb_t('Category does not belong to the household.');
        }
    }
    if ($error === null && $tagIds) {
        $tagCheck = $pdo->prepare('select id from tags where id = :id and household_id = :hid and is_active = true');
        foreach ($tagIds as $tagId) {
            $tagCheck->execute(['id' => $tagId, 'hid' => $household['id']]);
            if (!$tagCheck->fetch()) {
                $error = hb_t('Tag does not belong to the household.');
                break;
            }
        }
    }

    if ($error === null) {
        $stmt = $pdo->prepare(
            'update payee_mappings
                set payee_id = :payee_id,
                    category_id = :category_id,
                    tag_ids = :tag_ids,
                    updated_at = :updated_at
              where id = :id and household_id = :hid and row_version = :row_version'
        );
        $stmt->execute([
            'payee_id' => $payeeId,
            'category_id' => $categoryId,
            'tag_ids' => hb_php_int_array_to_pg($tagIds),
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'id' => $mappingId,
            'hid' => $household['id'],
            'row_version' => $rowVersion,
        ]);
        if ($stmt->rowCount() === 0) {
            $error = hb_t('The data changed in the meantime. Please reload and try again.');
        } else {
            header('Location: /payee_mapping.php?msg=saved');
            exit;
        }
    }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $counterpartyName = trim((string)($_POST['counterparty_name'] ?? ''));
    $payeeId = $_POST['payee_id'] !== '' ? (int)($_POST['payee_id'] ?? 0) : null;
    $categoryId = $_POST['category_id'] !== '' ? (int)($_POST['category_id'] ?? 0) : null;
    $tagIdsRaw = $_POST['tag_ids'] ?? [];
    $tagIds = hb_normalize_id_list(is_array($tagIdsRaw) ? $tagIdsRaw : [$tagIdsRaw]);

    if ($counterpartyName === '') {
        $error = hb_t('Auto name is required.');
    }
    if ($error === null && $payeeId !== null) {
        $payeeCheck = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
        $payeeCheck->execute(['id' => $payeeId, 'hid' => $household['id']]);
        if (!$payeeCheck->fetch()) {
            $error = hb_t('Payee does not belong to the household.');
        }
    }
    if ($error === null && $categoryId !== null) {
        $catCheck = $pdo->prepare('select id from categories where id = :id and household_id = :hid and is_active = true');
        $catCheck->execute(['id' => $categoryId, 'hid' => $household['id']]);
        if (!$catCheck->fetch()) {
            $error = hb_t('Category does not belong to the household.');
        }
    }
    if ($error === null && $tagIds) {
        $tagCheck = $pdo->prepare('select id from tags where id = :id and household_id = :hid and is_active = true');
        foreach ($tagIds as $tagId) {
            $tagCheck->execute(['id' => $tagId, 'hid' => $household['id']]);
            if (!$tagCheck->fetch()) {
                $error = hb_t('Tag does not belong to the household.');
                break;
            }
        }
    }
    if ($error === null) {
        $insert = $pdo->prepare(
            'insert into payee_mappings (household_id, counterparty_name, payee_id, category_id, tag_ids)
             values (:hid, :counterparty_name, :payee_id, :category_id, :tag_ids)'
        );
        try {
            $insert->execute([
                'hid' => $household['id'],
                'counterparty_name' => $counterpartyName,
                'payee_id' => $payeeId,
                'category_id' => $categoryId,
                'tag_ids' => hb_php_int_array_to_pg($tagIds),
            ]);
            header('Location: /payee_mapping.php?msg=created');
            exit;
        } catch (Throwable $e) {
            $error = hb_t('Mapping already exists or could not be created.');
        }
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
    'select pm.*, p.name as payee_name, c.name as category_name
       from payee_mappings pm
       left join payees p on p.id = pm.payee_id
       left join categories c on c.id = pm.category_id
      where pm.household_id = :hid
      order by pm.counterparty_name asc'
);
$mappingStmt->execute(['hid' => $household['id']]);
$mappings = $mappingStmt->fetchAll();

$payeesStmt = $pdo->prepare('select id, name from payees where household_id = :hid order by name asc');
$payeesStmt->execute(['hid' => $household['id']]);
$payees = $payeesStmt->fetchAll();

$catStmt = $pdo->prepare('select * from categories where household_id = :hid and is_active = true order by type asc, sort_order asc, name asc');
$catStmt->execute(['hid' => $household['id']]);
$categories = $catStmt->fetchAll();

$tagStmt = $pdo->prepare('select * from tags where household_id = :hid and is_active = true order by name asc');
$tagStmt->execute(['hid' => $household['id']]);
$tags = $tagStmt->fetchAll();

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
  <?php elseif ($msg === 'created'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Mapping created.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Mapping deleted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="hb-whitebox">
    <div class="hb-whitebox-body">
      <div class="border rounded-3 p-3 mb-3 bg-light-subtle">
        <form method="post" action="/payee_mapping.php" class="row g-3 align-items-end">
          <input type="hidden" name="action" value="create">
          <div class="col-lg-3">
            <label class="form-label small"><?= htmlspecialchars(hb_t('Auto name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control form-control-sm" name="counterparty_name" placeholder="EDEKA* / *LANDAU*" required>
            <div class="form-text"><?= htmlspecialchars(hb_t('Use * or % as wildcard for contains rules.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          </div>
          <div class="col-lg-3">
            <label class="form-label small"><?= htmlspecialchars(hb_t('Assigned payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <?php
            $payeeSelectorId = 'mapping-create-payee';
            $payeeSelectorName = 'payee_id';
            $payeeSelectorPayees = $payees;
            $payeeSelectorSelected = null;
            $payeeSelectorPlaceholder = hb_t('Search payee...');
            $payeeSelectorShowAdd = false;
            require __DIR__ . '/../templates/partials/payee_selector.php';
            ?>
          </div>
          <div class="col-lg-3">
            <label class="form-label small"><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <?php
            $categorySelectorId = 'mapping-create-category';
            $categorySelectorName = 'category_id';
            $categorySelectorCategories = $categories;
            $categorySelectorSelected = null;
            $categorySelectorPlaceholder = hb_t('Search category...');
            $categorySelectorShowAdd = false;
            require __DIR__ . '/../templates/partials/category_selector.php';
            ?>
          </div>
          <div class="col-lg-3">
            <label class="form-label small"><?= htmlspecialchars(hb_t('Tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <?php
            $tagSelectorId = 'mapping-create-tags';
            $tagSelectorName = 'tag_ids[]';
            $tagSelectorTags = $tags;
            $tagSelectorSelected = [];
            $tagSelectorPlaceholder = hb_t('Search tag...');
            $tagSelectorShowAdd = false;
            require __DIR__ . '/../templates/partials/tag_selector.php';
            ?>
          </div>
          <div class="col-12 text-end">
            <button type="submit" class="btn btn-sm btn-primary"><?= htmlspecialchars(hb_t('Create rule'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </div>
        </form>
      </div>

      <?php foreach ($mappings as $mapping): ?>
        <div class="border rounded-3 p-3 mb-3">
          <form method="post" action="/payee_mapping.php" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="mapping_id" value="<?= (int)$mapping['id'] ?>">
            <input type="hidden" name="row_version" value="<?= (int)$mapping['row_version'] ?>">
            <div class="col-lg-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Auto name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <div class="form-control form-control-sm bg-light-subtle text-truncate" title="<?= htmlspecialchars($mapping['counterparty_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?= htmlspecialchars($mapping['counterparty_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </div>
            </div>
            <div class="col-lg-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Assigned payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <?php
              $payeeSelectorId = 'mapping-payee-' . (int)$mapping['id'];
              $payeeSelectorName = 'payee_id';
              $payeeSelectorPayees = $payees;
              $payeeSelectorSelected = $mapping['payee_id'] ?? null;
              $payeeSelectorPlaceholder = hb_t('Search payee...');
              $payeeSelectorShowAdd = false;
              require __DIR__ . '/../templates/partials/payee_selector.php';
              ?>
            </div>
            <div class="col-lg-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <?php
              $categorySelectorId = 'mapping-category-' . (int)$mapping['id'];
              $categorySelectorName = 'category_id';
              $categorySelectorCategories = $categories;
              $categorySelectorSelected = $mapping['category_id'] ?? null;
              $categorySelectorPlaceholder = hb_t('Search category...');
              $categorySelectorShowAdd = false;
              require __DIR__ . '/../templates/partials/category_selector.php';
              ?>
            </div>
            <div class="col-lg-3">
              <label class="form-label small"><?= htmlspecialchars(hb_t('Tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <?php
              $tagSelectorId = 'mapping-tags-' . (int)$mapping['id'];
              $tagSelectorName = 'tag_ids[]';
              $tagSelectorTags = $tags;
              $tagSelectorSelected = hb_pg_int_array_to_php($mapping['tag_ids'] ?? '{}');
              $tagSelectorPlaceholder = hb_t('Search tag...');
              $tagSelectorShowAdd = false;
              require __DIR__ . '/../templates/partials/tag_selector.php';
              ?>
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-sm btn-success"><?= htmlspecialchars(hb_t('Save rule'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </div>
          </form>
          <form method="post" action="/payee_mapping.php" class="text-end mt-2" data-confirm="<?= htmlspecialchars(hb_t('Delete mapping?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="mapping_id" value="<?= (int)$mapping['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$mappings): ?>
        <div class="text-muted"><?= htmlspecialchars(hb_t('No payee mappings available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$extraScripts = '<script src="/js/chip-selector.js"></script>';
$extraScripts .= <<<HTML
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
