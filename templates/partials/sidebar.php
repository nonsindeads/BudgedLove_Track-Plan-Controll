<?php
declare(strict_types=1);

$navItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/', 'icon' => 'speedometer2'],
    ['key' => 'accounts', 'label' => 'Accounts', 'href' => '/accounts.php', 'icon' => 'wallet2'],
    ['key' => 'transactions', 'label' => 'Transactions', 'href' => '/transactions.php', 'icon' => 'card-list'],
    ['key' => 'open_bookings', 'label' => 'Open bookings', 'href' => '/open_bookings.php', 'icon' => 'inbox'],
    ['key' => 'import', 'label' => 'Import', 'href' => '/import.php', 'icon' => 'upload'],
    ['key' => 'recurring', 'label' => 'Recurring', 'href' => '/recurring.php', 'icon' => 'repeat'],
    ['key' => 'plan', 'label' => 'Monthly plan', 'href' => '/plan.php', 'icon' => 'calendar2-week'],
    ['key' => 'budgets', 'label' => 'Budgets & Savings', 'href' => '/budgets.php', 'icon' => 'wallet'],
    ['key' => 'categories', 'label' => 'Categories', 'href' => '/categories.php', 'icon' => 'diagram-3'],
    ['key' => 'tags', 'label' => 'Tags', 'href' => '/tags.php', 'icon' => 'tags'],
    ['key' => 'payees', 'label' => 'Payees', 'href' => '/payees.php', 'icon' => 'people'],
    ['key' => 'payee_mapping', 'label' => 'Payee mapping', 'href' => '/payee_mapping.php', 'icon' => 'node-plus'],
    ['key' => 'open_cases', 'label' => 'Open cases', 'href' => '/open_cases.php', 'icon' => 'exclamation-octagon'],
    ['key' => 'month_close', 'label' => 'Month close', 'href' => '/month_close.php', 'icon' => 'calendar-check'],
    ['key' => 'history', 'label' => 'History', 'href' => '/history.php', 'icon' => 'clock-history'],
    ['key' => 'household', 'label' => 'Household', 'href' => '/household.php', 'icon' => 'gear'],
];
if (!empty($_SESSION['is_admin'])) {
    $navItems[] = ['key' => 'admin', 'label' => 'Admin', 'href' => '/admin.php', 'icon' => 'shield-lock'];
    $navItems[] = ['key' => 'translations', 'label' => 'Translations', 'href' => '/translations.php', 'icon' => 'translate'];
}
$profileActive = ($activeNav ?? '') === 'profile';

$hbSidebarLogoSvg = <<<SVG
<svg width="36" height="36" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <defs>
    <linearGradient id="hb-logo-g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#3B82F6"/>
      <stop offset="100%" stop-color="#06B6D4"/>
    </linearGradient>
  </defs>
  <rect x="6" y="6" width="88" height="88" rx="16" fill="url(#hb-logo-g)"/>
  <rect x="14" y="14" width="72" height="72" rx="12" fill="#020617"/>
  <text x="50" y="61"
        text-anchor="middle"
        font-family="Inter, Arial, sans-serif"
        font-size="34"
        font-weight="600"
        fill="#E0F2FE">BL</text>
</svg>
SVG;
?>
<aside class="hb-sidebar d-flex flex-column">
  <div class="d-flex align-items-center gap-2 px-3 py-3 border-bottom">
    <div class="hb-logo rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center">
      <?= $hbSidebarLogoSvg; ?>
    </div>
    <div>
      <div class="fw-semibold"><?= htmlspecialchars(hb_t('BudgetLove'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('Track. Plan. Controll.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
  </div>
  <nav class="flex-grow-1 py-3">
    <ul class="nav flex-column">
        <?php foreach ($navItems as $item): ?>
        <?php $active = ($activeNav ?? '') === $item['key']; ?>
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center <?= $active ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <i class="bi bi-<?= $item['icon'] ?> me-2"></i>
            <span><?= htmlspecialchars(hb_t($item['label']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <div class="border-top px-3 py-3">
    <?php if (!empty($currentUser)): ?>
      <div class="d-flex align-items-center mb-2">
        <div class="hb-avatar me-2 text-white">
          <?= strtoupper(substr(($currentUser['first_name'] ?? $currentUser['username'] ?? '?'), 0, 1)) ?>
        </div>
        <div class="flex-grow-1">
          <div class="fw-semibold small"><?= htmlspecialchars(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')) ?: $currentUser['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <div class="text-muted small"><?= htmlspecialchars($currentUser['email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
      </div>
      <div class="d-flex flex-column gap-2">
        <a class="btn btn-sm w-100 d-flex align-items-center justify-content-center <?= $profileActive ? 'btn-primary' : 'btn-outline-secondary' ?>" href="/profile.php">
          <i class="bi bi-person-gear me-1"></i> <?= htmlspecialchars(hb_t('Profile'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </a>
        <form method="post" action="/auth.php?action=logout" class="d-grid">
          <button type="submit" class="btn btn-outline-danger btn-sm w-100 d-flex align-items-center justify-content-center">
            <i class="bi bi-box-arrow-right me-1"></i> <?= htmlspecialchars(hb_t('Logout'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </button>
        </form>
      </div>
    <?php else: ?>
      <a class="btn btn-primary w-100 btn-sm" href="/login"><?= htmlspecialchars(hb_t('Login'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    <?php endif; ?>
  </div>
</aside>
