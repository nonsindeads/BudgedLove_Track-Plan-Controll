<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);
$pageTitle = 'Tags';
$activeNav = 'tags';
$breadcrumbs = [
    ['label' => 'Tags', 'href' => '/tags.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$conflict = null;

if (in_array($action, ['store', 'update'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $color = trim((string)($_POST['color'] ?? ''));
    $isActive = isset($_POST['is_active']);
    $id = (int)($_POST['id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    if ($name === '') {
        $error = 'Name ist erforderlich.';
    }

    if ($error === null) {
        if ($action === 'store') {
            $stmt = $pdo->prepare(
                'insert into tags (household_id, name, color, is_active)
                 values (:hid, :name, :color, :active)'
            );
            $stmt->execute([
                'hid' => $household['id'],
                'name' => $name,
                'color' => $color !== '' ? $color : null,
                'active' => $isActive,
            ]);
        } else {
            $own = $pdo->prepare('select id from tags where id = :id and household_id = :hid');
            $own->execute(['id' => $id, 'hid' => $household['id']]);
            if (!$own->fetch()) {
                $error = 'Tag nicht gefunden.';
            } else {
                $stmt = $pdo->prepare(
                    'update tags
                        set name = :name,
                            color = :color,
                            is_active = :active,
                            updated_at = now()
                      where id = :id and household_id = :hid and row_version = :row_version'
                );
                $stmt->execute([
                    'name' => $name,
                    'color' => $color !== '' ? $color : null,
                    'active' => $isActive,
                    'id' => $id,
                    'hid' => $household['id'],
                    'row_version' => $rowVersion,
                ]);
                if ($stmt->rowCount() === 0) {
                    $fresh = $pdo->prepare('select * from tags where id = :id and household_id = :hid');
                    $fresh->execute(['id' => $id, 'hid' => $household['id']]);
                    $current = $fresh->fetch() ?: [];
                    $conflictRows = hb_build_conflict_rows(
                        [
                            'name' => 'Name',
                            'color' => 'Farbe',
                            'is_active' => 'Aktiv',
                        ],
                        $current,
                        [
                            'name' => $name,
                            'color' => $color,
                            'is_active' => $isActive ? '1' : '0',
                        ]
                    );
                    $conflict = hb_render_conflict_table($conflictRows);
                    $editTag = array_merge($current, [
                        'name' => $name,
                        'color' => $color,
                        'is_active' => $isActive ? 1 : 0,
                        'row_version' => $current['row_version'] ?? 0,
                    ]);
                    $action = 'edit';
                }
            }
        }
        if ($error === null) {
            if (empty($conflict)) {
                header('Location: /tags.php?msg=saved');
                exit;
            }
        }
    }
}

$editTag = $editTag ?? null;
if ($action === 'edit' && $editTag === null) {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('select * from tags where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $household['id']]);
    $editTag = $stmt->fetch();
    if (!$editTag) {
        $error = 'Tag nicht gefunden.';
        $action = 'list';
    }
}

$tagsStmt = $pdo->prepare('select * from tags where household_id = :hid order by name asc');
$tagsStmt->execute(['hid' => $household['id']]);
$tags = $tagsStmt->fetchAll();

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0">Tags</h1>
      <div class="text-muted small">Haushalt: <?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/categories.php">Kategorien</a>
      <a class="btn btn-sm btn-outline-primary" href="/transactions.php">Transaktionen</a>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success">Tag gespeichert.</div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h6 mb-3">Liste</h2>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Farbe</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tags as $tag): ?>
                  <tr>
                    <td><?= htmlspecialchars($tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($tag['color'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= $tag['is_active'] ? 'Aktiv' : 'Inaktiv' ?></td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="/tags.php?action=edit&id=<?= (int)$tag['id'] ?>">Bearbeiten</a></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$tags): ?>
                  <tr><td colspan="4" class="text-muted">Keine Tags vorhanden.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <?php if (!empty($conflict)): ?>
            <?= $conflict ?>
          <?php endif; ?>
          <?php $isEdit = $action === 'edit' && $editTag; ?>
          <h2 class="h6 mb-3"><?= $isEdit ? 'Tag bearbeiten' : 'Neuer Tag' ?></h2>
          <form method="post" action="/tags.php">
            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'store' ?>">
            <?php if ($isEdit): ?>
              <input type="hidden" name="id" value="<?= (int)$editTag['id'] ?>">
              <input type="hidden" name="row_version" value="<?= (int)($editTag['row_version'] ?? 0) ?>">
            <?php endif; ?>
            <div class="mb-3">
              <label for="name" class="form-label">Name</label>
              <input type="text" class="form-control" id="name" name="name" required value="<?= htmlspecialchars($editTag['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="mb-3">
              <label for="color" class="form-label">
                Farbe (optional)
                <span class="text-muted" data-bs-toggle="tooltip" title="Freies Feld, z.B. #ff9900 oder CSS-Farbnamen.">ℹ️</span>
              </label>
              <input type="text" class="form-control" id="color" name="color" value="<?= htmlspecialchars($editTag['color'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="#hex oder Name">
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="active" name="is_active" <?= !empty($editTag['is_active']) || $editTag === null ? 'checked' : '' ?>>
              <label class="form-check-label" for="active">Aktiv</label>
            </div>
            <button type="submit" class="btn btn-success">Speichern</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
