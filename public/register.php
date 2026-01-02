<?php declare(strict_types=1);
session_start();
require_once __DIR__ . '/../app/domain.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

$layoutCompact = true;
$pageTitle = 'Register';
$breadcrumbs = [['label' => 'Register', 'href' => '/register']];
$accountTypes = hb_allowed_account_types();
$languageOptions = hb_available_locales();
$languageValue = hb_get_locale();

ob_start();
?>
<div class="container" style="max-width: 720px;">
  <div class="card shadow-sm mt-4">
    <div class="card-body">
      <h1 class="h5 mb-3"><?= htmlspecialchars(hb_t('Register'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <form id="register-form"
            hx-post="/auth.php?action=register"
            hx-target="#feedback"
            hx-swap="innerHTML"
            hx-indicator="#register-spinner">
        <div class="row g-3">
          <div class="col-md-6">
            <label for="first-name" class="form-label"><?= htmlspecialchars(hb_t('First name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control" id="first-name" name="first_name" required>
          </div>
          <div class="col-md-6">
            <label for="last-name" class="form-label"><?= htmlspecialchars(hb_t('Last name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control" id="last-name" name="last_name" required>
          </div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-6">
            <label for="register-username" class="form-label"><?= htmlspecialchars(hb_t('Username'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
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
            <label for="register-email" class="form-label"><?= htmlspecialchars(hb_t('Email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
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
          <label class="form-label"><?= htmlspecialchars(hb_t('Address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <div class="row g-3">
            <div class="col-md-8">
              <label for="register-street" class="form-label"><?= htmlspecialchars(hb_t('Street'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="register-street" name="address_street" required>
            </div>
            <div class="col-md-4">
              <label for="register-house-number" class="form-label"><?= htmlspecialchars(hb_t('House number'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="register-house-number" name="address_house_number" required>
            </div>
          </div>
          <div class="row g-3 mt-1">
            <div class="col-md-4">
              <label for="register-postal" class="form-label"><?= htmlspecialchars(hb_t('Postal code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="register-postal" name="address_postal_code" required>
            </div>
            <div class="col-md-8">
              <label for="register-city" class="form-label"><?= htmlspecialchars(hb_t('City'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="register-city" name="address_city" required>
            </div>
          </div>
          <div class="row g-3 mt-1">
            <div class="col-md-6">
              <label for="register-state" class="form-label"><?= htmlspecialchars(hb_t('State (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="register-state" name="address_state">
            </div>
            <div class="col-md-6">
              <label for="register-extra" class="form-label"><?= htmlspecialchars(hb_t('Additional details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="register-extra" name="address_extra">
            </div>
          </div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-6">
            <label for="register-password" class="form-label"><?= htmlspecialchars(hb_t('Password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
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
            <label for="register-password-confirm" class="form-label"><?= htmlspecialchars(hb_t('Confirm password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="password" class="form-control" id="register-password-confirm" name="password_confirm" autocomplete="new-password" required minlength="12">
          </div>
        </div>
        <div class="mt-3">
          <label for="register-language" class="form-label"><?= htmlspecialchars(hb_t('Language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select class="form-select" id="register-language" name="language">
            <?php foreach ($languageOptions as $lang => $label): ?>
              <option value="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $languageValue === $lang ? 'selected' : '' ?>>
                <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="d-flex align-items-center gap-2 mt-1">
          <div id="password-spinner" class="spinner-border spinner-border-sm text-secondary d-none" role="status"></div>
          <div id="password-feedback" class="form-text text-muted"><?= htmlspecialchars(hb_t('At least 12 characters, upper/lowercase, number & symbol.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
        <div class="border rounded-3 p-3 mt-3 bg-light-subtle">
          <div class="fw-semibold mb-2"><?= htmlspecialchars(hb_t('Optional: Household & primary account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <div class="mb-3">
            <label for="household-name" class="form-label"><?= htmlspecialchars(hb_t('Household name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control" id="household-name" name="household_name" placeholder="<?= htmlspecialchars(hb_t('e.g. Sample household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label for="primary-account-name" class="form-label"><?= htmlspecialchars(hb_t('Primary account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input type="text" class="form-control" id="primary-account-name" name="primary_account_name" placeholder="<?= htmlspecialchars(hb_t('e.g. Checking account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="col-md-6">
              <label for="primary-account-type" class="form-label"><?= htmlspecialchars(hb_t('Account type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
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
            <label for="primary-account-opening" class="form-label"><?= htmlspecialchars(hb_t('Opening balance (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" class="form-control" id="primary-account-opening" name="primary_account_opening_balance" placeholder="0,00">
          </div>
          <div class="form-text text-muted mt-2"><?= htmlspecialchars(hb_t('Leave empty if you want to set up the household later.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
        <div class="form-check my-3">
          <input class="form-check-input" type="checkbox" value="1" id="consent-contact" name="consent_contact" required>
          <label class="form-check-label" for="consent-contact">
            <?= htmlspecialchars(hb_t('I agree to be contacted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </label>
        </div>
        <button type="submit" class="btn btn-success w-100">
          <span class="me-2"><?= htmlspecialchars(hb_t('Create account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span id="register-spinner" class="spinner-border spinner-border-sm text-light d-none" role="status"></span>
        </button>
        <p class="text-muted small mt-2 mb-0"><?= htmlspecialchars(hb_t('After registration, an admin must activate the account.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </form>
      <div id="feedback" class="pt-3"></div>
      <div class="mt-3 text-center">
        <a href="/login"><?= htmlspecialchars(hb_t('Already have an account? Log in'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      </div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
