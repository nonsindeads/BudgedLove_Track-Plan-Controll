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
  <title>Registrierung | Haushaltsbuch</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://unpkg.com/htmx.org@1.9.12"></script>
</head>
<body class="bg-light">
  <div class="container py-4">
    <div class="mb-3 d-flex justify-content-between align-items-center">
      <h1 class="h4 mb-0">Registrieren</h1>
      <a href="/login" class="btn btn-sm btn-outline-primary">Schon einen Account? Zum Login</a>
    </div>

    <div class="row g-4">
      <div class="col-lg-8">
        <div class="card shadow-sm">
          <div class="card-body">
            <form id="register-form"
                  hx-post="/auth.php?action=register"
                  hx-target="#feedback"
                  hx-swap="innerHTML"
                  hx-indicator="#register-spinner">
              <div class="row g-3">
                <div class="col-md-6">
                  <label for="first-name" class="form-label">Vorname</label>
                  <input type="text" class="form-control" id="first-name" name="first_name" required>
                </div>
                <div class="col-md-6">
                  <label for="last-name" class="form-label">Nachname</label>
                  <input type="text" class="form-control" id="last-name" name="last_name" required>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label for="register-username" class="form-label">Benutzername</label>
                  <input type="text"
                         class="form-control"
                         id="register-username"
                         name="username"
                         autocomplete="username"
                         required
                         hx-post="/auth.php?action=check_username"
                         hx-trigger="keyup changed delay:400ms"
                         hx-target="#username-feedback"
                         hx-indicator="#username-spinner"
                         hx-include="#register-username">
                  <div class="d-flex align-items-center gap-2">
                    <div id="username-spinner" class="spinner-border spinner-border-sm text-secondary d-none" role="status"></div>
                    <div id="username-feedback" class="form-text text-muted"></div>
                  </div>
                </div>
                <div class="col-md-6">
                  <label for="register-email" class="form-label">E-Mail</label>
                  <input type="email"
                         class="form-control"
                         id="register-email"
                         name="email"
                         autocomplete="email"
                         required
                         hx-post="/auth.php?action=check_email"
                         hx-trigger="keyup changed delay:400ms"
                         hx-target="#email-feedback"
                         hx-indicator="#email-spinner"
                         hx-include="#register-email">
                  <div class="d-flex align-items-center gap-2">
                    <div id="email-spinner" class="spinner-border spinner-border-sm text-secondary d-none" role="status"></div>
                    <div id="email-feedback" class="form-text text-muted"></div>
                  </div>
                </div>
              </div>
              <div class="mt-3">
                <label for="register-address" class="form-label">Adresse (komplett)</label>
                <textarea class="form-control" id="register-address" name="address" rows="2" required></textarea>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label for="register-password" class="form-label">Passwort</label>
                  <input type="password"
                         class="form-control"
                         id="register-password"
                         name="password"
                         autocomplete="new-password"
                         required
                         minlength="12"
                         hx-post="/auth.php?action=check_password"
                         hx-trigger="keyup changed delay:400ms"
                         hx-target="#password-feedback"
                         hx-indicator="#password-spinner"
                         hx-include="#register-password">
                </div>
                <div class="col-md-6">
                  <label for="register-password-confirm" class="form-label">Passwort bestätigen</label>
                  <input type="password" class="form-control" id="register-password-confirm" name="password_confirm" autocomplete="new-password" required minlength="12">
                </div>
              </div>
              <div class="d-flex align-items-center gap-2 mt-1">
                <div id="password-spinner" class="spinner-border spinner-border-sm text-secondary d-none" role="status"></div>
                <div id="password-feedback" class="form-text text-muted">Mindestens 12 Zeichen, Groß-/Kleinbuchstaben, Zahl & Sonderzeichen.</div>
              </div>
              <div class="form-check my-3">
                <input class="form-check-input" type="checkbox" value="1" id="consent-contact" name="consent_contact" required>
                <label class="form-check-label" for="consent-contact">
                  Ich stimme der Kontaktaufnahme zu.
                </label>
              </div>
              <button type="submit" class="btn btn-success w-100">
                <span class="me-2">Account anlegen</span>
                <span id="register-spinner" class="spinner-border spinner-border-sm text-light d-none" role="status"></span>
              </button>
              <p class="text-muted small mt-2 mb-0">Nach der Registrierung muss ein Admin den Account freischalten.</p>
            </form>
            <div id="feedback" class="pt-3"></div>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body">
            <h2 class="h6">Hinweise</h2>
            <ul class="mb-2">
              <li>Login später mit Benutzername oder E-Mail möglich.</li>
              <li>Feldprüfungen laufen live per HTMX.</li>
              <li>Freigabe erfolgt durch einen Admin nach Registrierung.</li>
            </ul>
            <a class="btn btn-outline-secondary" href="/">Zur Landingpage</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
