<?php declare(strict_types=1);
session_start();
require_once __DIR__ . '/../app/domain.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

$layoutCompact = true;
$pageTitle = 'Registrierung';
$breadcrumbs = [['label' => 'Registrieren', 'href' => '/register']];
$accountTypes = hb_allowed_account_types();

ob_start();
?>
<div class="container" style="max-width: 720px;">
  <div class="card shadow-sm mt-4">
    <div class="card-body">
      <h1 class="h5 mb-3">Registrieren</h1>
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
          <label class="form-label">Adresse</label>
          <div class="row g-3">
            <div class="col-md-8">
              <label for="register-street" class="form-label">Straße</label>
              <input type="text" class="form-control" id="register-street" name="address_street" required>
            </div>
            <div class="col-md-4">
              <label for="register-house-number" class="form-label">Hausnummer</label>
              <input type="text" class="form-control" id="register-house-number" name="address_house_number" required>
            </div>
          </div>
          <div class="row g-3 mt-1">
            <div class="col-md-4">
              <label for="register-postal" class="form-label">PLZ</label>
              <input type="text" class="form-control" id="register-postal" name="address_postal_code" required>
            </div>
            <div class="col-md-8">
              <label for="register-city" class="form-label">Ort</label>
              <input type="text" class="form-control" id="register-city" name="address_city" required>
            </div>
          </div>
          <div class="row g-3 mt-1">
            <div class="col-md-6">
              <label for="register-state" class="form-label">Bundesland (optional)</label>
              <input type="text" class="form-control" id="register-state" name="address_state">
            </div>
            <div class="col-md-6">
              <label for="register-extra" class="form-label">Weitere Angaben</label>
              <input type="text" class="form-control" id="register-extra" name="address_extra">
            </div>
          </div>
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
        <div class="border rounded-3 p-3 mt-3 bg-light-subtle">
          <div class="fw-semibold mb-2">Optional: Haushalt & Primäres Konto</div>
          <div class="mb-3">
            <label for="household-name" class="form-label">Haushaltsname</label>
            <input type="text" class="form-control" id="household-name" name="household_name" placeholder="z.B. Haushalt Muster">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label for="primary-account-name" class="form-label">Primäres Konto</label>
              <input type="text" class="form-control" id="primary-account-name" name="primary_account_name" placeholder="z.B. Girokonto">
            </div>
            <div class="col-md-6">
              <label for="primary-account-type" class="form-label">Kontotyp</label>
              <select class="form-select" id="primary-account-type" name="primary_account_type">
                <?php foreach ($accountTypes as $type): ?>
                  <option value="<?= htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?= htmlspecialchars(hb_account_type_label($type), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mt-3">
            <label for="primary-account-opening" class="form-label">Startsaldo (optional)</label>
            <input type="text" class="form-control" id="primary-account-opening" name="primary_account_opening_balance" placeholder="0,00">
          </div>
          <div class="form-text text-muted mt-2">Leer lassen, wenn du den Haushalt später einrichten willst.</div>
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
      <div class="mt-3 text-center">
        <a href="/login">Schon einen Account? Zum Login</a>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
