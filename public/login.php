<?php declare(strict_types=1);
session_start();
require_once __DIR__ . '/../app/domain.php';

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
<div class="container" style="max-width: 520px;">
  <div class="card shadow-sm mt-5">
    <div class="card-body">
      <h1 class="h5 mb-3"><?= htmlspecialchars(hb_t('Sign in'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <form hx-post="/auth.php?action=login"
            hx-target="#feedback"
            hx-swap="innerHTML"
            hx-indicator="#login-spinner">
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
        <p class="text-muted small mt-2 mb-0"><?= htmlspecialchars(hb_t('Admin demo: admin / admin'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </form>
      <div id="feedback" class="pt-3"></div>
      <div class="mt-3 text-center">
        <a href="/register"><?= htmlspecialchars(hb_t('No account yet? Register'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
