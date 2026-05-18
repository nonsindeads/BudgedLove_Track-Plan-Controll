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
$pageTitle = 'Tags';
$activeNav = 'tags';
$breadcrumbs = [
    ['label' => 'Tags', 'href' => '/tags.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$msg = $_GET['msg'] ?? null;
$error = null;
$conflict = null;

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tagId = (int)($_POST['id'] ?? 0);
    $own = $pdo->prepare('select id from tags where id = :id and household_id = :hid');
    $own->execute(['id' => $tagId, 'hid' => $household['id']]);
    if (!$own->fetch()) {
        $error = hb_t('Tag not found.');
    } else {
        $del = $pdo->prepare('delete from tags where id = :id and household_id = :hid');
        $del->execute(['id' => $tagId, 'hid' => $household['id']]);
        header('Location: /tags.php?msg=deleted');
        exit;
    }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $color = trim((string)($_POST['color'] ?? ''));
    if ($name === '') {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => hb_t('Name is required.')]);
        exit;
    }
    $exists = $pdo->prepare('select id from tags where household_id = :hid and lower(name) = lower(:name)');
    $exists->execute(['hid' => $household['id'], 'name' => $name]);
    if ($exists->fetch()) {
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode(['error' => hb_t('Tag already exists.')]);
        exit;
    }
    $newId = hb_dbal_insert_and_get_id($db, 'tags', [
        'household_id' => (int)$household['id'],
        'name' => $name,
        'color' => $color !== '' ? $color : null,
        'is_active' => true,
    ], 'id', ['is_active' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
    header('Content-Type: application/json');
    echo json_encode([
        'id' => $newId,
        'name' => $name,
        'color' => $color,
    ]);
    exit;
}

if (in_array($action, ['store', 'update'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $color = trim((string)($_POST['color'] ?? ''));
    $isActive = isset($_POST['is_active']);
    $id = (int)($_POST['id'] ?? 0);
    $rowVersion = (int)($_POST['row_version'] ?? 0);

    if ($name === '') {
        $error = hb_t('Name is required.');
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
                $error = hb_t('Tag not found.');
            } else {
                $stmt = $pdo->prepare(
                    'update tags
                        set name = :name,
                            color = :color,
                            is_active = :active,
                            updated_at = :updated_at
                      where id = :id and household_id = :hid and row_version = :row_version'
                );
                $stmt->execute([
                    'name' => $name,
                    'color' => $color !== '' ? $color : null,
                    'active' => $isActive,
                    'id' => $id,
                    'hid' => $household['id'],
                    'row_version' => $rowVersion,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                if ($stmt->rowCount() === 0) {
                    $fresh = $pdo->prepare('select * from tags where id = :id and household_id = :hid');
                    $fresh->execute(['id' => $id, 'hid' => $household['id']]);
                    $current = $fresh->fetch() ?: [];
                    $conflictRows = hb_build_conflict_rows(
                        [
                            'name' => hb_t('Name'),
                            'color' => hb_t('Color'),
                            'is_active' => hb_t('Active'),
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
        $error = hb_t('Tag not found.');
        $action = 'list';
    }
}

$tagsStmt = $pdo->prepare('select * from tags where household_id = :hid order by name asc');
$tagsStmt->execute(['hid' => $household['id']]);
$tags = $tagsStmt->fetchAll();

[$periodStart, $periodEnd] = hb_household_period_bounds($household, new DateTimeImmutable('today'), $pdo);
$periodLabel = $periodStart->format('d.m.Y') . ' - ' . $periodEnd->format('d.m.Y');
$tagTransactions = [];
$tagTxStmt = $pdo->prepare(
    "select tt.tag_id,
            t.id,
            t.booking_date,
            t.type,
            t.amount_cents,
            coalesce(p.name, t.counterparty_name, '') as payee_name,
            coalesce(a.name, '') as account_name,
            coalesce(t.note, '') as note
       from transaction_tags tt
       join transactions t on t.id = tt.transaction_id
  left join payees p on p.id = t.payee_id
  left join accounts a on a.id = t.account_id
      where t.household_id = :hid
        and t.booking_date between :start and :end
      order by tt.tag_id asc, t.booking_date desc, t.id desc"
);
$tagTxStmt->execute([
    'hid' => $household['id'],
    'start' => $periodStart->format('Y-m-d'),
    'end' => $periodEnd->format('Y-m-d'),
]);
foreach ($tagTxStmt->fetchAll() as $tx) {
    $tagTransactions[(int)$tx['tag_id']][] = $tx;
}

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Household:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-outline-secondary" href="/categories.php"><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      <a class="btn btn-sm btn-outline-primary" href="/transactions.php"><?= htmlspecialchars(hb_t('Transactions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Tag saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Tag deleted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
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
            <a class="btn btn-sm btn-primary" href="/tags.php?action=new"><?= htmlspecialchars(hb_t('New tag'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Color'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(hb_t('Status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th class="text-end"><?= htmlspecialchars(hb_t('Actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tags as $tag): ?>
                  <?php $tagTx = $tagTransactions[(int)$tag['id']] ?? []; ?>
                  <?php $tagDetailId = 'tag-transactions-' . (int)$tag['id']; ?>
                  <tr class="hb-drill-row" data-hb-drill-target="<?= htmlspecialchars($tagDetailId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <td>
                      <button type="button" class="btn btn-link btn-sm p-0 align-baseline hb-drill-toggle" aria-expanded="false" aria-controls="<?= htmlspecialchars($tagDetailId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= htmlspecialchars($tag['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </button>
                      <span class="badge text-bg-light ms-2"><?= count($tagTx) ?></span>
                    </td>
                    <td><?= htmlspecialchars($tag['color'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><?= $tag['is_active'] ? htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : htmlspecialchars(hb_t('Inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td class="text-end">
                      <div class="d-flex justify-content-end gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="/tags.php?action=edit&id=<?= (int)$tag['id'] ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                        <form method="post" action="/tags.php" data-confirm="<?= htmlspecialchars(hb_t('Delete tag?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?= (int)$tag['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                        </form>
                      </div>
                    </td>
                  </tr>
                  <tr id="<?= htmlspecialchars($tagDetailId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="hb-drill-detail d-none">
                    <td colspan="4" class="bg-light">
                      <div class="small text-muted mb-2"><?= htmlspecialchars(hb_t('Transactions in current period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= htmlspecialchars($periodLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <?php if ($tagTx): ?>
                        <div class="table-responsive">
                          <table class="table table-sm mb-0">
                            <tbody>
                              <?php foreach ($tagTx as $tx): ?>
                                <?php $amountPrefix = $tx['type'] === 'income' ? '+' : ($tx['type'] === 'expense' ? '-' : ''); ?>
                                <tr>
                                  <td class="text-nowrap"><?= htmlspecialchars((string)$tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                  <td>
                                    <a href="/transactions.php?action=edit&id=<?= (int)$tx['id'] ?>">
                                      <?= htmlspecialchars((string)($tx['payee_name'] !== '' ? $tx['payee_name'] : hb_t('Transaction')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </a>
                                    <?php if ((string)$tx['note'] !== ''): ?>
                                      <div class="text-muted small"><?= htmlspecialchars((string)$tx['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                  </td>
                                  <td class="text-muted"><?= htmlspecialchars((string)$tx['account_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                  <td class="text-end fw-semibold"><?= htmlspecialchars($amountPrefix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= number_format(((int)$tx['amount_cents']) / 100, 2, ',', '.') ?> €</td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      <?php else: ?>
                        <div class="text-muted"><?= htmlspecialchars(hb_t('No transactions in current period.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$tags): ?>
                  <tr><td colspan="4" class="text-muted"><?= htmlspecialchars(hb_t('No tags available.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
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
    <?php $isEdit = $action === 'edit' && $editTag; ?>
    <form method="post" action="/tags.php">
      <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'store' ?>">
      <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$editTag['id'] ?>">
        <input type="hidden" name="row_version" value="<?= (int)($editTag['row_version'] ?? 0) ?>">
      <?php endif; ?>
      <div class="mb-3">
        <label for="name" class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input type="text" class="form-control" id="name" name="name" required value="<?= htmlspecialchars($editTag['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      </div>
      <div class="mb-3">
        <label for="color" class="form-label">
          <?= htmlspecialchars(hb_t('Color (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Free field, e.g. #ff9900 or CSS color names.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
        </label>
        <input type="text" class="form-control" id="color" name="color" value="<?= htmlspecialchars($editTag['color'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(hb_t('#hex or name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      </div>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="active" name="is_active" <?= !empty($editTag['is_active']) || $editTag === null ? 'checked' : '' ?>>
        <label class="form-check-label" for="active"><?= htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
      </div>
      <button type="submit" class="btn btn-success"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      <a href="/tags.php" class="btn btn-outline-secondary"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </form>
    <?php
    $modalContent = ob_get_clean();
    $modalTitle = $isEdit ? hb_t('Edit tag') : hb_t('New tag');
    $modalTitleEsc = htmlspecialchars($modalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $closeLabel = htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $content .= <<<HTML
    <div class="modal fade" id="hb-tag-modal" tabindex="-1" aria-labelledby="hb-tag-modal-label" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="hb-tag-modal-label">{$modalTitleEsc}</h5>
            <a href="/tags.php" class="btn-close" aria-label="{$closeLabel}"></a>
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
  const modalEl = document.getElementById('hb-tag-modal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
  document.querySelectorAll('.hb-drill-toggle').forEach((button) => {
    button.addEventListener('click', () => {
      const targetId = button.getAttribute('aria-controls');
      const target = targetId ? document.getElementById(targetId) : null;
      if (!target) return;
      const shouldOpen = target.classList.contains('d-none');
      document.querySelectorAll('.hb-drill-detail').forEach((row) => row.classList.add('d-none'));
      document.querySelectorAll('.hb-drill-toggle[aria-expanded="true"]').forEach((openButton) => openButton.setAttribute('aria-expanded', 'false'));
      if (shouldOpen) {
        target.classList.remove('d-none');
        button.setAttribute('aria-expanded', 'true');
      }
    });
  });
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
