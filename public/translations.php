<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = hb_get_pdo();
$lang = hb_normalize_locale($_GET['lang'] ?? hb_get_locale());
$query = trim((string)($_GET['q'] ?? ''));
$action = (string)($_GET['action'] ?? '');

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['action'] ?? '');
    $key = trim((string)($_POST['translation_key'] ?? ''));
    $value = trim((string)($_POST['value'] ?? ''));
    $postLang = hb_normalize_locale($_POST['lang'] ?? $lang);

    if ($postAction === 'save') {
        if ($key === '' || $value === '') {
            $error = hb_t('Please provide key and value.');
        } else {
            $stmt = $pdo->prepare(
                'insert into translations (translation_key, lang, value, updated_by)
                 values (:key, :lang, :value, :user)
                 on conflict (translation_key, lang)
                 do update set value = excluded.value, updated_at = :updated_at, updated_by = excluded.updated_by'
            );
            $stmt->execute([
                'key' => $key,
                'lang' => $postLang,
                'value' => $value,
                'user' => $_SESSION['user_id'] ?? null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $notice = hb_t('Translation saved.');
            $action = '';
        }
    }

    if ($postAction === 'delete') {
        if ($key !== '') {
            $stmt = $pdo->prepare('delete from translations where translation_key = :key and lang = :lang');
            $stmt->execute(['key' => $key, 'lang' => $postLang]);
            $notice = hb_t('Translation deleted.');
        }
    }
}

$fileTranslations = hb_load_translation_file($lang);
$dbStmt = $pdo->prepare('select translation_key, value from translations where lang = :lang');
$dbStmt->execute(['lang' => $lang]);
$dbTranslations = [];
foreach ($dbStmt->fetchAll() as $row) {
    $dbTranslations[(string)$row['translation_key']] = (string)$row['value'];
}

$keys = array_unique(array_merge(array_keys($fileTranslations), array_keys($dbTranslations)));
sort($keys);

$lower = function (string $value): string {
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value);
    }
    return strtolower($value);
};

$rows = [];
foreach ($keys as $key) {
    $value = $dbTranslations[$key] ?? $fileTranslations[$key] ?? '';
    $source = isset($dbTranslations[$key]) ? 'db' : 'file';
    if ($query !== '') {
        $needle = $lower($query);
        $hayKey = $lower($key);
        $hayValue = $lower($value);
        if (!str_contains($hayKey, $needle) && !str_contains($hayValue, $needle)) {
            continue;
        }
    }
    $rows[] = [
        'key' => $key,
        'value' => $value,
        'source' => $source,
    ];
}

$modalOpen = false;
$modalKey = '';
$modalValue = '';
if ($action === 'edit') {
    $modalOpen = true;
    $modalKey = (string)($_GET['key'] ?? '');
    $modalValue = $dbTranslations[$modalKey] ?? $fileTranslations[$modalKey] ?? '';
}
if ($action === 'create') {
    $modalOpen = true;
    $modalKey = '';
    $modalValue = '';
}

$pageTitle = 'Translations';
$activeNav = 'translations';
$breadcrumbs = [
    ['label' => 'Translations', 'href' => '/translations.php'],
];

ob_start();
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h5 mb-1"><?= htmlspecialchars(hb_t('Translations'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <div class="text-muted small"><?= htmlspecialchars(hb_t('Manage UI and user-facing messages.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  </div>
  <a href="/translations.php?action=create&amp;lang=<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="btn btn-primary">
    <?= htmlspecialchars(hb_t('New translation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
  </a>
</div>

<?php if ($notice): ?>
  <div class="alert alert-success"><?= htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
<?php endif; ?>

<form class="row g-2 align-items-end mb-3" method="get" action="/translations.php">
  <div class="col-md-4">
    <label class="form-label"><?= htmlspecialchars(hb_t('Search'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
    <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(hb_t('Key or text'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label"><?= htmlspecialchars(hb_t('Language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
    <select class="form-select" name="lang">
      <?php foreach (hb_available_locales() as $code => $label): ?>
        <option value="<?= htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $lang === $code ? 'selected' : '' ?>>
          <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <button type="submit" class="btn btn-outline-secondary w-100"><?= htmlspecialchars(hb_t('Filter'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead>
      <tr>
        <th><?= htmlspecialchars(hb_t('Key'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
        <th><?= htmlspecialchars(hb_t('Value'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
        <th><?= htmlspecialchars(hb_t('Source'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
        <th class="text-end"><?= htmlspecialchars(hb_t('Actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="4" class="text-muted"><?= htmlspecialchars(hb_t('No translations found.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
        </tr>
      <?php endif; ?>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td class="text-break"><?= htmlspecialchars($row['key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          <td class="text-break"><?= htmlspecialchars($row['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
          <td>
            <span class="badge <?= $row['source'] === 'db' ? 'text-bg-primary' : 'text-bg-light' ?>">
              <?= htmlspecialchars($row['source'] === 'db' ? hb_t('Database') : hb_t('File'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </span>
          </td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-secondary" href="/translations.php?action=edit&amp;lang=<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>&amp;key=<?= urlencode($row['key']) ?>">
              <?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </a>
            <?php if ($row['source'] === 'db'): ?>
              <form method="post" action="/translations.php?lang=<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="d-inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="translation_key" value="<?= htmlspecialchars($row['key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="lang" value="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="<?= htmlspecialchars(hb_t('Delete this translation?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($modalOpen): ?>
  <div class="modal fade" id="translation-modal" tabindex="-1" aria-labelledby="translation-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <form method="post" action="/translations.php?lang=<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <div class="modal-header">
            <h5 class="modal-title" id="translation-modal-label"><?= htmlspecialchars(hb_t($action === 'create' ? 'New translation' : 'Edit translation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('Key'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" name="translation_key" value="<?= htmlspecialchars($modalKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $action === 'edit' ? 'readonly' : '' ?> required>
            </div>
            <div class="mb-3">
              <label class="form-label"><?= htmlspecialchars(hb_t('Value'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <textarea class="form-control" name="value" rows="4" required><?= htmlspecialchars($modalValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </div>
            <input type="hidden" name="lang" value="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save">
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            <button type="submit" class="btn btn-primary"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<script>
  const modalEl = document.getElementById('translation-modal');
  if (modalEl) {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
