<?php
declare(strict_types=1);

$crumbs = $breadcrumbs ?? [];
if (!$crumbs && isset($pageTitle)) {
    $crumbs = [['label' => $pageTitle, 'href' => null]];
}
$headerTitle = $pageTitle ?? ($crumbs ? $crumbs[count($crumbs) - 1]['label'] : hb_t('Dashboard'));

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
<header class="hb-header bg-white border-bottom">
  <div class="hb-header-row hb-header-row-primary">
    <button class="btn btn-outline-secondary btn-sm hb-header-burger d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#hbSidebar" aria-label="<?= htmlspecialchars(hb_t('Open navigation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <i class="bi bi-list"></i>
    </button>
    <button class="btn btn-outline-secondary btn-sm hb-header-burger d-none d-lg-inline-flex" type="button" data-hb-sidebar-toggle aria-label="<?= htmlspecialchars(hb_t('Toggle sidebar'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <i class="bi bi-layout-sidebar-inset"></i>
    </button>
    <h1 class="hb-header-title"><?= htmlspecialchars(hb_t($headerTitle), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <?php if (!empty($currentHousehold)): ?>
      <form class="hb-header-account" method="post" action="/account_select.php">
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
    <?php if (!empty($currentUser) && !empty($currentHousehold)): ?>
      <button class="btn btn-outline-secondary btn-sm hb-header-icon" type="button" data-hb-live-toggle aria-label="<?= htmlspecialchars(hb_t('Activity & Chat'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <i class="bi bi-broadcast"></i>
      </button>
    <?php endif; ?>
  </div>
  <div class="hb-header-row hb-header-row-secondary">
    <nav class="hb-header-crumbs" aria-label="<?= htmlspecialchars(hb_t('Breadcrumb'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <ol class="breadcrumb mb-0 small">
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
    <?php if (!empty($currentHousehold)): ?>
      <span class="hb-header-household text-muted small d-none d-md-inline-flex">
        <i class="bi bi-house-door"></i>
        <?= htmlspecialchars($currentHousehold['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </span>
    <?php endif; ?>
    <div class="hb-header-lang dropdown">
      <button class="btn btn-link btn-sm p-1 text-muted" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= htmlspecialchars(hb_t('Language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <i class="bi bi-translate"></i>
        <span class="hb-header-lang-code text-uppercase ms-1"><?= htmlspecialchars($currentLang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </button>
      <form class="dropdown-menu dropdown-menu-end p-2" method="post" action="/language.php">
        <?= hb_csrf_field() ?>
        <?php foreach ($languageOptions as $code => $label): ?>
          <button type="submit" name="lang" value="<?= htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  class="dropdown-item <?= $currentLang === $code ? 'active' : '' ?>">
            <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </button>
        <?php endforeach; ?>
      </form>
    </div>
  </div>
</header>
