<?php declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

$layoutCompact = true;
$pageTitle = 'Login';
$breadcrumbs = [['label' => 'Login', 'href' => '/login']];
$languageOptions = hb_available_locales();
$languageValue = hb_get_locale();

ob_start();
?>
<div class="hb-auth-shell">
  <div class="hb-auth-orb hb-auth-orb-primary" aria-hidden="true"></div>
  <div class="hb-auth-orb hb-auth-orb-secondary" aria-hidden="true"></div>
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <div class="hb-auth-brand mb-3">
          <img class="hb-auth-logo" src="/assets/logo.svg" alt="BudgetLove logo">
          <div>
            <div class="fw-semibold">BudgetLove</div>
            <div class="text-muted small">Track. Plan. Control.</div>
          </div>
        </div>
        <h1 class="display-6 fw-semibold mb-3"><?= htmlspecialchars(hb_t('Welcome back'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="text-muted mb-4"><?= htmlspecialchars(hb_t('Sign in to your household workspace and keep the month on track.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-outline-secondary" href="mailto:hello@budgetlove.de"><?= htmlspecialchars(hb_t('Request access'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          <a class="btn btn-outline-primary" href="https://budgetlove.de"><?= htmlspecialchars(hb_t('Back to landing'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        </div>
      </div>
      <div class="col-lg-5 offset-lg-1">
        <div class="card hb-auth-card border-0">
          <div class="card-body p-4">
            <h2 class="h5 mb-3"><?= htmlspecialchars(hb_t('Sign in'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <form hx-post="/auth.php?action=login"
                  hx-target="#feedback"
                  hx-swap="innerHTML"
                  hx-indicator="#login-spinner">
              <?= hb_csrf_field() ?>
              <div class="mb-3">
                <label for="login-identifier" class="form-label"><?= htmlspecialchars(hb_t('Username or email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" id="login-identifier" name="login" autocomplete="username email" required>
              </div>
              <div class="mb-3">
                <label for="login-password" class="form-label"><?= htmlspecialchars(hb_t('Password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="password" class="form-control" id="login-password" name="password" autocomplete="current-password" required>
              </div>
              <div class="mb-3">
                <label for="login-language" class="form-label"><?= htmlspecialchars(hb_t('Language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select" id="login-language" name="lang">
                  <?php foreach ($languageOptions as $lang => $label): ?>
                    <option value="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $languageValue === $lang ? 'selected' : '' ?>>
                      <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-primary w-100">
                <span class="me-2"><?= htmlspecialchars(hb_t('Log in'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span id="login-spinner" class="spinner-border spinner-border-sm text-light d-none" role="status"></span>
              </button>
            </form>
            <div id="feedback" class="pt-3"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
