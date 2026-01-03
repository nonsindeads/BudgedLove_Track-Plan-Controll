<?php
declare(strict_types=1);

$crumbs = $breadcrumbs ?? [];
if (!$crumbs && isset($pageTitle)) {
    $crumbs = [['label' => $pageTitle, 'href' => null]];
}

$headerAccounts = [];
$selectedAccountId = null;
$currentLang = hb_get_locale();
$languageOptions = hb_available_locales();
if (!empty($currentHousehold)) {
    $pdo = hb_get_pdo();
    $stmt = $pdo->prepare('select id, name from accounts where household_id = :hid and is_archived = false order by name asc');
    $stmt->execute(['hid' => $currentHousehold['id']]);
    $headerAccounts = $stmt->fetchAll();
    $selectedAccountId = hb_selected_account_id();
}
?>
<header class="hb-header d-flex align-items-center justify-content-between px-4 py-3 border-bottom bg-white">
  <div class="hb-header-left d-flex align-items-center gap-3">
    <button class="btn btn-outline-secondary btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#hbSidebar">
      <i class="bi bi-list"></i>
    </button>
    <nav aria-label="<?= htmlspecialchars(hb_t('Breadcrumb'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/"><?= htmlspecialchars(hb_t('Home'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></li>
        <?php foreach ($crumbs as $idx => $crumb): ?>
          <?php if ($idx === count($crumbs) - 1 || empty($crumb['href'])): ?>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars(hb_t($crumb['label']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php else: ?>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($crumb['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(hb_t($crumb['label']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ol>
    </nav>
  </div>
  <div class="hb-header-right d-flex align-items-center gap-3">
    <form class="d-flex align-items-center" method="post" action="/language.php">
      <?= hb_csrf_field() ?>
      <select class="form-select form-select-sm"
              name="lang"
              aria-label="<?= htmlspecialchars(hb_t('Language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              hx-post="/language.php"
              hx-trigger="change"
              hx-swap="none">
        <?php foreach ($languageOptions as $code => $label): ?>
          <option value="<?= htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $currentLang === $code ? 'selected' : '' ?>>
            <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php if (!empty($currentHousehold)): ?>
      <form class="d-flex align-items-center" method="post" action="/account_select.php">
        <?= hb_csrf_field() ?>
        <select class="form-select form-select-sm"
                name="account_id"
                aria-label="<?= htmlspecialchars(hb_t('Account selection'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                hx-post="/account_select.php"
                hx-trigger="change"
                hx-swap="none">
          <option value="all" <?= $selectedAccountId === null ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('All accounts'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
          <?php foreach ($headerAccounts as $acc): ?>
            <option value="<?= (int)$acc['id'] ?>" <?= $selectedAccountId === (int)$acc['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <?php if (!empty($currentHousehold)): ?>
      <span class="badge bg-primary-subtle text-primary d-flex align-items-center gap-2">
        <i class="bi bi-house-door"></i>
        <?= htmlspecialchars($currentHousehold['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </span>
    <?php endif; ?>
    <?php if (!empty($currentUser) && !empty($currentHousehold)): ?>
      <button class="btn btn-outline-secondary btn-sm" type="button" data-hb-live-toggle>
        <i class="bi bi-broadcast"></i>
      </button>
    <?php endif; ?>
  </div>
</header>
