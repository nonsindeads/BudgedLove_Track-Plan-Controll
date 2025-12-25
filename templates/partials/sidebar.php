<?php
declare(strict_types=1);

$navItems = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/', 'icon' => 'speedometer2'],
    ['key' => 'accounts', 'label' => 'Konten', 'href' => '/accounts.php', 'icon' => 'wallet2'],
    ['key' => 'transactions', 'label' => 'Transaktionen', 'href' => '/transactions.php', 'icon' => 'card-list'],
    ['key' => 'categories', 'label' => 'Kategorien', 'href' => '/categories.php', 'icon' => 'diagram-3'],
    ['key' => 'tags', 'label' => 'Tags', 'href' => '/tags.php', 'icon' => 'tags'],
    ['key' => 'payees', 'label' => 'Empfänger', 'href' => '/payees.php', 'icon' => 'people'],
    ['key' => 'history', 'label' => 'History', 'href' => '/history.php', 'icon' => 'clock-history'],
    ['key' => 'household', 'label' => 'Haushalt', 'href' => '/household.php', 'icon' => 'gear'],
];
if (!empty($_SESSION['is_admin'])) {
    $navItems[] = ['key' => 'admin', 'label' => 'Admin', 'href' => '/admin.php', 'icon' => 'shield-lock'];
}
$profileActive = ($activeNav ?? '') === 'profile';

$hbSidebarLogoSvg = <<<SVG
<svg width="36" height="36" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <rect x="4" y="4" width="56" height="56" rx="12" fill="#0d6efd" opacity="0.12"/>
  <path d="M18 38c0-6 4.5-11 10-11h8c5.5 0 10 5 10 11 0 6-4.5 11-10 11h-8c-5.5 0-10-5-10-11Z" fill="#0d6efd"/>
  <path d="M22 27c0-4.418 3.582-8 8-8h4c4.418 0 8 3.582 8 8" stroke="#0d6efd" stroke-width="3" stroke-linecap="round"/>
  <circle cx="32" cy="33" r="3.5" fill="#fff"/>
  <path d="M32 36.5v5" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
</svg>
SVG;
?>
<aside class="hb-sidebar d-flex flex-column">
  <div class="d-flex align-items-center gap-2 px-3 py-3 border-bottom">
    <div class="hb-logo rounded-circle bg-primary-subtle d-flex align-items-center justify-content-center">
      <?= $hbSidebarLogoSvg; ?>
    </div>
    <div>
      <div class="fw-semibold">Haushaltsbuch</div>
      <div class="text-muted small">Budget & Ausgaben</div>
    </div>
  </div>
  <nav class="flex-grow-1 py-3">
    <ul class="nav flex-column">
      <?php foreach ($navItems as $item): ?>
        <?php $active = ($activeNav ?? '') === $item['key']; ?>
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center <?= $active ? 'active' : '' ?>" href="<?= htmlspecialchars($item['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <i class="bi bi-<?= $item['icon'] ?> me-2"></i>
            <span><?= htmlspecialchars($item['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
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
          <i class="bi bi-person-gear me-1"></i> Profil
        </a>
        <form method="post" action="/auth.php?action=logout" class="d-grid">
          <button type="submit" class="btn btn-outline-danger btn-sm w-100 d-flex align-items-center justify-content-center">
            <i class="bi bi-box-arrow-right me-1"></i> Logout
          </button>
        </form>
      </div>
    <?php else: ?>
      <a class="btn btn-primary w-100 btn-sm" href="/login">Login</a>
    <?php endif; ?>
  </div>
</aside>
