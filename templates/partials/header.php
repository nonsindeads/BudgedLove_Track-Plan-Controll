<?php
declare(strict_types=1);

$crumbs = $breadcrumbs ?? [];
if (!$crumbs && isset($pageTitle)) {
    $crumbs = [['label' => $pageTitle, 'href' => null]];
}
?>
<header class="hb-header d-flex align-items-center justify-content-between px-4 py-3 border-bottom bg-white">
  <div class="d-flex align-items-center gap-3">
    <button class="btn btn-outline-secondary btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#hbSidebar">
      <i class="bi bi-list"></i>
    </button>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/">Home</a></li>
        <?php foreach ($crumbs as $idx => $crumb): ?>
          <?php if ($idx === count($crumbs) - 1 || empty($crumb['href'])): ?>
            <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($crumb['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php else: ?>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($crumb['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($crumb['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ol>
    </nav>
  </div>
  <div class="d-flex align-items-center gap-3">
    <?php if (!empty($currentHousehold)): ?>
      <span class="badge bg-primary-subtle text-primary d-flex align-items-center gap-2">
        <i class="bi bi-house-door"></i>
        <?= htmlspecialchars($currentHousehold['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </span>
    <?php endif; ?>
  </div>
</header>
