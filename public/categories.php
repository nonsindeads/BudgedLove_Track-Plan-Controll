<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);
$pageTitle = 'Kategorien';
$activeNav = 'categories';
$breadcrumbs = [
    ['label' => 'Kategorien', 'href' => '/categories.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;

if (in_array($action, ['store', 'update'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $type = (string)($_POST['type'] ?? 'expense');
    $parentId = $_POST['parent_id'] !== '' ? (int)($_POST['parent_id'] ?? 0) : null;
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $isActive = isset($_POST['is_active']);
    $id = (int)($_POST['id'] ?? 0);

    if ($name === '') {
        $error = 'Name ist erforderlich.';
    } elseif (!in_array($type, ['income', 'expense'], true)) {
        $error = 'Ungültiger Typ.';
    }

    if ($parentId !== null) {
        $parent = $pdo->prepare('select id from categories where id = :id and household_id = :hid');
        $parent->execute(['id' => $parentId, 'hid' => $household['id']]);
        if (!$parent->fetch()) {
            $error = 'Parent muss im gleichen Haushalt sein.';
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
                $error = 'Kategorie nicht gefunden.';
            } else {
                $stmt = $pdo->prepare(
                    'update categories
                        set name = :name,
                            type = :type,
                            parent_id = :parent_id,
                            sort_order = :sort_order,
                            is_active = :active,
                            updated_at = now()
                      where id = :id and household_id = :hid'
                );
                $stmt->execute([
                    'name' => $name,
                    'type' => $type,
                    'parent_id' => $parentId,
                    'sort_order' => $sortOrder,
                    'active' => $isActive,
                    'id' => $id,
                    'hid' => $household['id'],
                ]);
            }
        }
        if ($error === null) {
            header('Location: /categories.php?msg=saved');
            exit;
        }
    }
}

$editCategory = null;
if ($action === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('select * from categories where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $editCategory = $stmt->fetch();
    if (!$editCategory) {
        $error = 'Kategorie nicht gefunden.';
        $action = 'list';
    }
}

$catStmt = $pdo->prepare('select * from categories where household_id = :hid order by sort_order asc, name asc');
$catStmt->execute(['hid' => $household['id']]);
$categories = $catStmt->fetchAll();

function hb_render_category_tree(array $categories, ?int $parentId = null, int $level = 0): string
{
    $html = '';
    foreach ($categories as $cat) {
        if ((int)$cat['parent_id'] === (int)$parentId) {
            $indent = str_repeat('&nbsp;&nbsp;', $level);
            $name = htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $type = htmlspecialchars($cat['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $status = $cat['is_active'] ? '' : ' <span class="badge bg-secondary">inaktiv</span>';
            $html .= "<li>{$indent}{$name} ({$type}){$status} <a class=\"small\" href=\"/categories.php?action=edit&id={$cat['id']}\">Bearbeiten</a></li>";
            $html .= hb_render_category_tree($categories, (int)$cat['id'], $level + 1);
        }
    }
    return $html ? '<ul class="list-unstyled mb-0">' . $html . '</ul>' : '';
}

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0">Kategorien</h1>
      <div class="text-muted small">Haushalt: <?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/accounts.php">Konten</a>
      <a class="btn btn-sm btn-outline-primary" href="/transactions.php">Transaktionen</a>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success">Kategorie gespeichert.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6">Struktur</h2>
          <?= hb_render_category_tree($categories) ?: '<p class="text-muted mb-0">Noch keine Kategorien.</p>' ?>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <?php $isEdit = $action === 'edit' && $editCategory; ?>
          <h2 class="h6 mb-3"><?= $isEdit ? 'Kategorie bearbeiten' : 'Neue Kategorie' ?></h2>
          <form method="post" action="/categories.php">
            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'store' ?>">
            <?php if ($isEdit): ?>
              <input type="hidden" name="id" value="<?= (int)$editCategory['id'] ?>">
            <?php endif; ?>
            <div class="mb-3">
              <label for="name" class="form-label">Name</label>
              <input type="text" class="form-control" id="name" name="name" required value="<?= htmlspecialchars($editCategory['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label for="type" class="form-label">
                  Typ
                  <span class="text-muted" data-bs-toggle="tooltip" title="Einnahmen oder Ausgaben bestimmen spätere Auswertungen.">ℹ️</span>
                </label>
                <select class="form-select" id="type" name="type">
                  <?php foreach (['income', 'expense'] as $t): ?>
                    <option value="<?= $t ?>" <?= ($editCategory['type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label for="sort" class="form-label">Sortierung</label>
                <input type="number" class="form-control" id="sort" name="sort_order" value="<?= (int)($editCategory['sort_order'] ?? 0) ?>">
              </div>
            </div>
            <div class="mt-3">
              <label for="parent" class="form-label">
                Parent (optional)
                <span class="text-muted" data-bs-toggle="tooltip" title="Unterkategorien bleiben innerhalb desselben Haushalts. Leer lassen für Top-Level.">ℹ️</span>
              </label>
              <select class="form-select" id="parent" name="parent_id">
                <option value="">Keiner</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat['id'] ?>" <?= ($editCategory['parent_id'] ?? null) == $cat['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= htmlspecialchars($cat['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-check mt-3">
              <input class="form-check-input" type="checkbox" id="active" name="is_active" <?= !empty($editCategory['is_active']) || $editCategory === null ? 'checked' : '' ?>>
              <label class="form-check-label" for="active">Aktiv</label>
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
