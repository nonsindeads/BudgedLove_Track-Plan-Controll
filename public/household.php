<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$userId = hb_current_user_id();

$action = $_GET['action'] ?? $_POST['action'] ?? 'select';
$error = null;

if ($action === 'set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $householdId = (int)($_POST['household_id'] ?? 0);
    if ($householdId > 0) {
        $membership = $pdo->prepare(
            'select 1 from household_members where household_id = :hid and user_id = :uid and is_active = true'
        );
        $membership->execute(['hid' => $householdId, 'uid' => $userId]);
        if ($membership->fetch()) {
            hb_set_current_household($householdId);
            header('Location: /accounts.php');
            exit;
        } else {
            $error = 'Du bist in diesem Haushalt nicht aktiv.';
        }
    } else {
        $error = 'Ungültige Auswahl.';
    }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $currency = strtoupper(trim((string)($_POST['currency_code'] ?? 'EUR')));
    $mode = (string)($_POST['month_close_mode'] ?? 'first_of_month');
    $salaryDay = $_POST['salary_day'] !== '' ? (int)$_POST['salary_day'] : null;

    if ($name === '') {
        $error = 'Bitte einen Haushaltsnamen eingeben.';
    } elseif (!in_array($mode, hb_allowed_month_close_modes(), true)) {
        $error = 'Ungültiger Modus.';
    } elseif ($mode === 'salary_day' && ($salaryDay === null || $salaryDay < 1 || $salaryDay > 31)) {
        $error = 'Gültiger Gehaltstag (1-31) nötig.';
    }

    if ($error === null) {
        $householdId = hb_create_household($pdo, $userId, $name, $currency, $mode, $salaryDay);
        hb_set_current_household($householdId);
        header('Location: /accounts.php');
        exit;
    }
}

$households = hb_user_households($pdo, $userId);

$currentHousehold = hb_current_household($pdo);

if ($action === 'settings' && !$currentHousehold) {
    header('Location: /household.php');
    exit;
}

if ($action === 'update_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$currentHousehold || !hb_is_household_admin($currentHousehold)) {
        $error = 'Nur Haushalts-Admins dürfen Einstellungen ändern.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $currency = strtoupper(trim((string)($_POST['currency_code'] ?? $currentHousehold['currency_code'])));
        $mode = (string)($_POST['month_close_mode'] ?? $currentHousehold['month_close_mode']);
        $salaryDay = $_POST['salary_day'] !== '' ? (int)$_POST['salary_day'] : null;
        if ($name === '') {
            $error = 'Name ist erforderlich.';
        } elseif (!in_array($mode, hb_allowed_month_close_modes(), true)) {
            $error = 'Ungültiger Modus.';
        } elseif ($mode === 'salary_day' && ($salaryDay === null || $salaryDay < 1 || $salaryDay > 31)) {
            $error = 'Gültiger Gehaltstag (1-31) nötig.';
        } else {
            $stmt = $pdo->prepare(
                'update households
                    set name = :name,
                        currency_code = :currency,
                        month_close_mode = :mode,
                        salary_day = :salary,
                        updated_at = now()
                  where id = :id'
            );
            $stmt->execute([
                'name' => $name,
                'currency' => $currency,
                'mode' => $mode,
                'salary' => $salaryDay,
                'id' => $currentHousehold['id'],
            ]);
            header('Location: /household.php?action=settings&msg=saved');
            exit;
        }
    }
}
$msg = $_GET['msg'] ?? null;
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Haushalt wählen | Haushaltsbuch</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h4 mb-0">Haushalt wählen/anlegen</h1>
      <a href="/" class="btn btn-sm btn-outline-secondary">Zur Landingpage</a>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($action === 'settings' && $currentHousehold): ?>
      <?php if ($msg === 'saved'): ?>
        <div class="alert alert-success">Einstellungen gespeichert.</div>
      <?php endif; ?>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="card shadow-sm">
            <div class="card-body">
              <h2 class="h6 mb-3">Haushalts-Einstellungen</h2>
              <form method="post" action="/household.php?action=update_settings">
                <input type="hidden" name="action" value="update_settings">
                <div class="mb-3">
                  <label class="form-label" for="name">Name</label>
                  <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($currentHousehold['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                </div>
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label" for="currency">Währung</label>
                    <input type="text" class="form-control" id="currency" name="currency_code" maxlength="3" value="<?= htmlspecialchars($currentHousehold['currency_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="mode">
                      Monatsschluss
                      <span class="text-muted" data-bs-toggle="tooltip" title="Steuert, wann der Abrechnungsmonat endet (Monatsanfang oder individueller Gehaltstag).">ℹ️</span>
                    </label>
                    <select class="form-select" id="mode" name="month_close_mode">
                      <?php foreach (hb_allowed_month_close_modes() as $mode): ?>
                        <option value="<?= $mode ?>" <?= $currentHousehold['month_close_mode'] === $mode ? 'selected' : '' ?>><?= $mode ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="salary-day">
                    Gehaltstag
                    <span class="text-muted" data-bs-toggle="tooltip" title="Nur relevant bei Modus 'salary_day'; Tag (1-31), an dem ein neuer Abrechnungsmonat startet.">ℹ️</span>
                  </label>
                  <input type="number" class="form-control" id="salary-day" name="salary_day" min="1" max="31" value="<?= htmlspecialchars((string)($currentHousehold['salary_day'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
                <button class="btn btn-success mt-3" type="submit" <?= hb_is_household_admin($currentHousehold) ? '' : 'disabled' ?>>Speichern</button>
                <?php if (!hb_is_household_admin($currentHousehold)): ?>
                  <p class="text-muted small mb-0 mt-2">Nur Haushalts-Admins dürfen speichern.</p>
                <?php endif; ?>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="row g-4">
        <?php if ($households): ?>
          <div class="col-lg-6">
            <div class="card shadow-sm">
              <div class="card-body">
                <h2 class="h6">Bestehende Haushalte</h2>
                <form method="post" action="/household.php?action=set">
                  <input type="hidden" name="action" value="set">
                  <div class="mb-3">
                    <label class="form-label" for="household-id">Haushalt auswählen</label>
                    <select class="form-select" id="household-id" name="household_id" required>
                      <?php foreach ($households as $h): ?>
                        <option value="<?= (int)$h['id'] ?>">
                          <?= htmlspecialchars($h['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= htmlspecialchars($h['member_role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <button type="submit" class="btn btn-primary">Haushalt betreten</button>
                  <?php if ($currentHousehold): ?>
                    <a class="btn btn-link btn-sm" href="/household.php?action=settings">Einstellungen</a>
                  <?php endif; ?>
                </form>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="col-lg-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <h2 class="h6">Neuen Haushalt anlegen</h2>
              <form method="post" action="/household.php?action=create">
                <input type="hidden" name="action" value="create">
                <div class="mb-3">
                  <label for="name" class="form-label">Name</label>
                  <input type="text" class="form-control" id="name" name="name" required>
                </div>
                <div class="row g-3">
                  <div class="col-md-6">
                    <label for="currency" class="form-label">
                      Währung
                      <span class="text-muted" data-bs-toggle="tooltip" title="3-stelliger ISO-Code, z.B. EUR.">ℹ️</span>
                    </label>
                    <input type="text" class="form-control" id="currency" name="currency_code" value="EUR" maxlength="3" required>
                  </div>
                  <div class="col-md-6">
                    <label for="mode" class="form-label">
                      Monatsschluss
                      <span class="text-muted" data-bs-toggle="tooltip" title="Steuert, wann der Abrechnungsmonat endet (Monatsanfang oder individueller Gehaltstag).">ℹ️</span>
                    </label>
                    <select class="form-select" id="mode" name="month_close_mode">
                      <option value="first_of_month">Monatsanfang</option>
                      <option value="salary_day">Gehaltstag</option>
                    </select>
                  </div>
                </div>
                <div class="mt-3">
                  <label for="salary-day" class="form-label">
                    Gehaltstag (optional)
                    <span class="text-muted" data-bs-toggle="tooltip" title="Nur bei Modus 'Gehaltstag' nötig.">ℹ️</span>
                  </label>
                  <input type="number" class="form-control" id="salary-day" name="salary_day" min="1" max="31" placeholder="1-31">
                  <div class="form-text">Nur nötig, wenn Modus "Gehaltstag" gewählt.</div>
                </div>
                <button type="submit" class="btn btn-success mt-3">Haushalt erstellen</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(t => new bootstrap.Tooltip(t));
  </script>
</body>
</html>
