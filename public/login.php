<?php declare(strict_types=1);
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login | Haushaltsbuch</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://unpkg.com/htmx.org@1.9.12"></script>
</head>
<body class="bg-light">
  <div class="container py-4">
    <div class="mb-3 d-flex justify-content-between align-items-center">
      <h1 class="h4 mb-0">Anmelden</h1>
      <a href="/" class="btn btn-sm btn-outline-secondary">Zur Landingpage</a>
    </div>

    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-body">
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
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body">
            <h2 class="h6">Noch kein Account?</h2>
            <p class="text-muted">Registriere dich mit vollständigen Daten, ein Admin schaltet dich danach frei.</p>
            <a class="btn btn-outline-primary" href="/register">Zur Registrierung</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
