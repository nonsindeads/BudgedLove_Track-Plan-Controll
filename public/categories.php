<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);
$pageTitle = 'Categories';
$activeNav = 'categories';
$breadcrumbs = [
    ['label' => 'Categories', 'href' => '/categories.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$conflict = null;

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $catId = (int)($_POST['id'] ?? 0);
    $own = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
    $own->execute(['id' => $catId, 'hid' => $household['id']]);
    if (!$own->fetch()) {
        $error = hb_t('Category not found.');
    } else {
        $usageStmt = $pdo->prepare(
            'select
                (select count(*) from transactions where household_id = :hid and category_id = :id) as tx_count,
                (select count(*) from transaction_splits ts join transactions t on t.id = ts.transaction_id where t.household_id = :hid and ts.category_id = :id) as split_count,
                (select count(*) from planned_payments where household_id = :hid and category_id = :id) as planned_count,
                (select count(*) from recurring_payments where household_id = :hid and category_id = :id) as recurring_count'
        );
        $usageStmt->execute(['id' => $catId, 'hid' => $household['id']]);
        $usage = $usageStmt->fetch() ?: [];
        $usageTotal = (int)($usage['tx_count'] ?? 0)
            + (int)($usage['split_count'] ?? 0)
            + (int)($usage['planned_count'] ?? 0)
            + (int)($usage['recurring_count'] ?? 0);
        if ($usageTotal > 0) {
            $error = hb_t('Category cannot be deleted because it is in use.');
        } else {
            $del = $pdo->prepare('delete from categories where id = :id and household_id = :hid');
            $del->execute(['id' => $catId, 'hid' => $household['id']]);
            header('Location: /categories.php?msg=deleted');
            exit;
        }
    }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $type = (string)($_POST['type'] ?? 'expense');
    if ($name === '' || !in_array($type, ['income', 'expense'], true)) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => hb_t('Name and type are required.')]);
        exit;
    }
    $exists = $pdo->prepare('select id from categories where household_id = :hid and lower(name) = lower(:name) and type = :type');
    $exists->execute(['hid' => $household['id'], 'name' => $name, 'type' => $type]);
    if ($exists->fetch()) {
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode(['error' => hb_t('Category already exists.')]);
        exit;
    }
    $insert = $pdo->prepare(
        'insert into categories (household_id, name, type, parent_id, sort_order, is_active)
         values (:hid, :name, :type, null, 0, true)
         returning id, name, type'
    );
    $insert->execute([
        'hid' => $household['id'],
        'name' => $name,
        'type' => $type,
    ]);
    $row = $insert->fetch() ?: [];
    header('Content-Type: application/json');
    echo json_encode([
        'id' => (int)($row['id'] ?? 0),
        'name' => (string)($row['name'] ?? $name),
        'type' => (string)($row['type'] ?? $type),
    ]);
    exit;
}

if (in_array($action, ['store', 'update'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $type = (string)($_POST['type'] ?? 'expense');
    $parentId = $_POST['parent_id'] !== '' ? (int)($_POST['parent_id'] ?? 0) : null;
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $isActive = isset($_POST['is_active']);
    $id = (int)($_POST['id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    if ($name === '') {
        $error = hb_t('Name is required.');
    } elseif (!in_array($type, ['income', 'expense'], true)) {
        $error = hb_t('Invalid type.');
    }

    if ($parentId !== null) {
        $parent = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        $parent->execute(['id' => $parentId, 'hid' => $household['id']]);
        if (!$parent->fetch()) {
            $error = hb_t('Parent must belong to the same household.');
        }
    }

    if ($error === null) {
        if ($action === 'store') {
            $stmt = $pdo->prepare(
                'insert into categories (household_id, name, type, parent_id, sort_order, is_active)
                 values (:hid, :name, :type, :parent_id, :sort_order, :active)'
            );
            $stmt->execute([
                'hid' => $household['id'],
                'name' => $name,
                'type' => $type,
                'parent_id' => $parentId,
                'sort_order' => $sortOrder,
                'active' => $isActive,
            ]);
        } else {
            $own = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
            $own->execute(['id' => $id, 'hid' => $household['id']]);
            if (!$own->fetch()) {
                $error = hb_t('Category not found.');
            } else {
                $stmt = $pdo->prepare(
                    'update categories
                        set name = :name,
                            type = :type,
                            parent_id = :parent_id,
                            sort_order = :sort_order,
                            is_active = :active,
                            updated_at = now()
                      where id = :id and household_id = :hid and row_version = :row_version'
                );
                $stmt->execute([
                    'name' => $name,
                    'type' => $type,
                    'parent_id' => $parentId,
                    'sort_order' => $sortOrder,
                    'active' => $isActive,
                    'id' => $id,
                    'hid' => $household['id'],
                    'row_version' => $rowVersion,
                ]);
                if ($stmt->rowCount() === 0) {
                    $fresh = $pdo->prepare('select * from categories where id = :id and household_id = :hid');
                    $fresh->execute(['id' => $id, 'hid' => $household['id']]);
                    $current = $fresh->fetch() ?: [];
                    $conflictRows = hb_build_conflict_rows(
                        [
                            'name' => hb_t('Name'),
                            'type' => hb_t('Type'),
                            'parent_id' => hb_t('Parent'),
                            'sort_order' => hb_t('Sort order'),
                            'is_active' => hb_t('Active'),
                        ],
                        $current,
                        [
                            'name' => $name,
                            'type' => $type,
                            'parent_id' => (string)($parentId ?? ''),
                            'sort_order' => (string)$sortOrder,
                            'is_active' => $isActive ? '1' : '0',
                        ]
                    );
                    $conflict = hb_render_conflict_table($conflictRows);
                    $editCategory = array_merge($current, [
                        'name' => $name,
                        'type' => $type,
                        'parent_id' => $parentId,
                        'sort_order' => $sortOrder,
                        'is_active' => $isActive ? 1 : 0,
                        'row_version' => $current['row_version'] ?? 0,
                    ]);
                    $action = 'edit';
                }
            }
        }
        if ($error === null) {
            if (empty($conflict)) {
                header('Location: /categories.php?msg=saved');
                exit;
            }
        }
    }
}

$editCategory = $editCategory ?? null;
if ($action === 'edit' && $editCategory === null) {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('select * from categories where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $editCategory = $stmt->fetch();
    if (!$editCategory) {
        $error = hb_t('Category not found.');
        $action = 'list';
    }
}

$catStmt = $pdo->prepare('select * from categories where household_id = :hid order by sort_order asc, name asc');
$catStmt->execute(['hid' => $household['id']]);
$categories = $catStmt->fetchAll();

$categoryChildren = [];
$categoryRoots = [];
foreach ($categories as $cat) {
    $parentId = (int)($cat['parent_id'] ?? 0);
    if ($parentId > 0) {
        $categoryChildren[$parentId][] = $cat;
    } else {
        $categoryRoots[] = $cat;
    }
}

usort($categoryRoots, fn($a, $b) => strcmp($a['name'], $b['name']));
foreach ($categoryChildren as $pid => $items) {
    usort($items, fn($a, $b) => strcmp($a['name'], $b['name']));
    $categoryChildren[$pid] = $items;
}

$categoryList = [];
foreach ($categoryRoots as $root) {
    $categoryList[] = ['row' => $root, 'level' => 0];
    foreach ($categoryChildren[(int)$root['id']] ?? [] as $child) {
        $categoryList[] = ['row' => $child, 'level' => 1];
        foreach ($categoryChildren[(int)$child['id']] ?? [] as $grand) {
            $categoryList[] = ['row' => $grand, 'level' => 2];
        }
    }
}

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Household:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/accounts.php"><?= htmlspecialchars(hb_t('Accounts'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      <a class="btn btn-sm btn-outline-primary" href="/transactions.php"><?= htmlspecialchars(hb_t('Transactions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Category saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Category deleted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
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
            <a class="btn btn-sm btn-primary" href="/categories.php?action=new"><?= htmlspecialchars(hb_t('New category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th class="text-end"><?= htmlspecialchars(hb_t('Actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($categoryList as $entry): ?>
                  <?php $cat = $entry['row']; ?>
                  <?php $level = (int)$entry['level']; ?>
                  <tr>
                    <td>
                      <?php if ($level > 0): ?>
                        <?php
                        $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
                        ?>
                        <span class="text-muted me-1"><?= $indent ?>↳</span>
                        <span><?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <?php else: ?>
                        <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?= htmlspecialchars($cat['type'] === 'income' ? hb_t('Income') : ($cat['type'] === 'expense' ? hb_t('Expense') : $cat['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </td>
                    <td><?= $cat['is_active'] ? htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : htmlspecialchars(hb_t('Inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="text-end">
                      <div class="d-flex justify-content-end gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="/categories.php?action=edit&id=<?= (int)$cat['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                        <form method="post" action="/categories.php" data-confirm="<?= htmlspecialchars(hb_t('Delete category?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$categories): ?>
                  <tr><td colspan="4" class="text-muted"><?= htmlspecialchars(hb_t('No categories available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
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
    <?php $isEdit = $action === 'edit' && $editCategory; ?>
    <form method="post" action="/categories.php">
      <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'store' ?>">
      <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$editCategory['id'] ?>">
        <input type="hidden" name="row_version" value="<?= (int)($editCategory['row_version'] ?? 0) ?>">
      <?php endif; ?>
      <div class="mb-3">
        <label for="name" class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input type="text" class="form-control" id="name" name="name" required value="<?= htmlspecialchars($editCategory['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <label for="type" class="form-label">
            <?= htmlspecialchars(hb_t('Type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Income or expense drives later reports.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
          </label>
          <select class="form-select" id="type" name="type">
            <?php foreach (['income', 'expense'] as $t): ?>
              <option value="<?= $t ?>" <?= ($editCategory['type'] ?? '') === $t ? 'selected' : '' ?>>
                <?= htmlspecialchars($t === 'income' ? hb_t('Income') : hb_t('Expense'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label for="sort" class="form-label"><?= htmlspecialchars(hb_t('Sort order'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="number" class="form-control" id="sort" name="sort_order" value="<?= (int)($editCategory['sort_order'] ?? 0) ?>">
        </div>
      </div>
      <div class="mt-3">
        <label for="parent" class="form-label">
          <?= htmlspecialchars(hb_t('Parent category (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Subcategories stay within the same household. Leave empty for top-level.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
        </label>
        <select class="form-select" id="parent" name="parent_id">
          <option value=""><?= htmlspecialchars(hb_t('None'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= ($editCategory['parent_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              (<?= htmlspecialchars($cat['type'] === 'income' ? hb_t('Income') : ($cat['type'] === 'expense' ? hb_t('Expense') : $cat['type']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" id="active" name="is_active" <?= !empty($editCategory['is_active']) || $editCategory === null ? 'checked' : '' ?>>
        <label class="form-check-label" for="active"><?= htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
      </div>
      <button type="submit" class="btn btn-success mt-3"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      <a href="/categories.php" class="btn btn-outline-secondary mt-3"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </form>
    <?php
    $modalContent = ob_get_clean();
    $modalTitle = $isEdit ? hb_t('Edit category') : hb_t('New category');
    $modalTitleEsc = htmlspecialchars($modalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $closeLabel = htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $content .= <<<HTML
    <div class="modal fade" id="hb-category-modal" tabindex="-1" aria-labelledby="hb-category-modal-label" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="hb-category-modal-label">{$modalTitleEsc}</h5>
            <a href="/categories.php" class="btn-close" aria-label="{$closeLabel}"></a>
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
  const modalEl = document.getElementById('hb-category-modal');
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
