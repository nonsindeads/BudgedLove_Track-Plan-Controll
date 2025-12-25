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

ob_start();
?>
<div class="container" style="max-width: 520px;">
  <div class="card shadow-sm mt-5">
    <div class="card-body">
      <h1 class="h5 mb-3">Anmelden</h1>
      <form hx-post="/auth.php?action=login"
            hx-target="#feedback"
            hx-swap="innerHTML"
            hx-indicator="#login-spinner">
        <div class="mb-3">
          <label for="login-identifier" class="form-label">Benutzername oder E-Mail</label>
          <input type="text" class="form-control" id="login-identifier" name="login" autocomplete="username email" required>
        </div>
        <div class="mb-3">
          <label for="login-password" class="form-label">Passwort</label>
          <input type="password" class="form-control" id="login-password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">
          <span class="me-2">Einloggen</span>
          <span id="login-spinner" class="spinner-border spinner-border-sm text-light d-none" role="status"></span>
        </button>
        <p class="text-muted small mt-2 mb-0">Demo-Admin: admin / admin</p>
      </form>
      <div id="feedback" class="pt-3"></div>
      <div class="mt-3 text-center">
        <a href="/register">Noch keinen Account? Registrieren</a>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
