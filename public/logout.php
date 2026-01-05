<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$layoutCompact = true;
$pageTitle = 'Signed out';
$breadcrumbs = [['label' => 'Signed out', 'href' => '/logout.php']];
$redirectUrl = '/login';
$redirectDelayMs = 10000;
$redirectSeconds = (int)ceil($redirectDelayMs / 1000);

ob_start();
?>
<div class="hb-auth-shell">
  <div class="hb-auth-orb hb-auth-orb-primary" aria-hidden="true"></div>
  <div class="hb-auth-orb hb-auth-orb-secondary" aria-hidden="true"></div>
  <div class="container">
    <div class="row g-4 align-items-center justify-content-center">
      <div class="col-lg-6">
        <div class="card hb-auth-card border-0" data-redirect-url="<?= htmlspecialchars($redirectUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-redirect-delay="<?= (int)$redirectDelayMs ?>">
          <div class="card-body p-4">
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="badge bg-success-subtle text-success">
                <?= htmlspecialchars(hb_t('Signed out'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </span>
            </div>
            <h1 class="h4 mb-2"><?= htmlspecialchars(hb_t('See you soon'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="text-muted mb-3"><?= htmlspecialchars(hb_t('You have been signed out.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p class="text-muted small mb-0"><?= htmlspecialchars(hb_t('Redirecting to login in {seconds} seconds.', null, ['seconds' => $redirectSeconds]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <div class="mt-4 d-flex flex-wrap gap-2">
              <a class="btn btn-primary" href="/login"><?= htmlspecialchars(hb_t('Go to login'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
              <a class="btn btn-outline-secondary" href="https://budgetlove.de"><?= htmlspecialchars(hb_t('Back to landing'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
