<?php declare(strict_types=1);
session_start();
require_once __DIR__ . '/../app/domain.php';
$isLoggedIn = isset($_SESSION['user_id']);
$username = $_SESSION['username'] ?? '';
$isAdmin = $_SESSION['is_admin'] ?? false;
$currentHousehold = null;
if ($isLoggedIn) {
    $pdo = hb_get_pdo();
    $currentHousehold = hb_current_household($pdo);
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Haushaltsbuch</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://unpkg.com/htmx.org@1.9.12"></script>
</head>
<body class="bg-light">
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
      <div>
        <h1 class="h4 mb-1">Haushaltsbuch</h1>
        <p class="text-muted mb-0">Self-Service Registrierung mit Admin-Freischaltung</p>
      </div>
      <?php if ($isLoggedIn): ?>
        <span class="badge bg-success">Eingeloggt</span>
      <?php else: ?>
        <div class="btn-group">
          <a class="btn btn-outline-primary" href="/login">Login</a>
          <a class="btn btn-primary" href="/register">Registrieren</a>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($isLoggedIn): ?>
      <div class="row g-3">
        <div class="col-lg-8">
          <div class="card shadow-sm">
            <div class="card-body">
              <p class="lead mb-3">Willkommen, <strong><?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
              <p class="text-muted mb-4">Accounts müssen von einem Admin freigeschaltet werden. Admin-Login: <code>admin/admin</code>.</p>
              <?php if ($currentHousehold): ?>
                <div class="mb-3">
                  <span class="badge bg-primary me-2">Haushalt: <?= htmlspecialchars($currentHousehold['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <a href="/household.php" class="btn btn-sm btn-outline-secondary">Haushalt wechseln</a>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-3">
                  <a class="btn btn-outline-primary" href="/accounts.php">Konten</a>
                  <a class="btn btn-outline-primary" href="/transactions.php">Transaktionen</a>
                  <a class="btn btn-outline-primary" href="/categories.php">Kategorien</a>
                  <a class="btn btn-outline-primary" href="/tags.php">Tags</a>
                  <a class="btn btn-outline-primary" href="/payees.php">Empfänger</a>
                </div>
              <?php else: ?>
                <div class="alert alert-warning">Du hast noch keinen Haushalt ausgewählt. <a href="/household.php">Jetzt auswählen</a></div>
              <?php endif; ?>
              <div class="d-flex gap-2 align-items-center">
                <button class="btn btn-outline-danger"
                        hx-post="/auth.php?action=logout"
                        hx-target="#feedback"
                        hx-swap="outerHTML">
                  Abmelden
                </button>
                <button class="btn btn-outline-secondary"
                        hx-get="/ping.php"
                        hx-target="#ping-out"
                        hx-indicator="#ping-spinner">
                  Ping (HTMX)
                </button>
                <div id="ping-spinner" class="spinner-border spinner-border-sm text-secondary d-none" role="status"></div>
              </div>
              <div id="ping-out" class="mt-3 small text-muted"></div>
              <div id="feedback"></div>
            </div>
          </div>
        </div>
        <?php if ($isAdmin): ?>
          <div class="col-lg-4">
            <div class="card shadow-sm h-100">
              <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Offene Registrierungen</span>
                <button class="btn btn-sm btn-outline-primary"
                        hx-get="/admin.php?action=list"
                        hx-target="#pending-list"
                        hx-swap="innerHTML"
                        hx-indicator="#pending-spinner">
                  Aktualisieren
                </button>
                <div id="pending-spinner" class="spinner-border spinner-border-sm text-secondary d-none" role="status"></div>
              </div>
              <div class="card-body" id="pending-card">
                <div id="pending-list"
                     hx-get="/admin.php?action=list"
                     hx-trigger="load"
                     hx-target="this"
                     hx-swap="innerHTML">
                  <div class="spinner-border spinner-border-sm text-secondary" role="status"></div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="row g-4">
        <div class="col-lg-7">
          <div class="card shadow-sm border-0">
            <div class="card-body">
              <h2 class="h5">Dein Einstieg</h2>
              <p class="text-muted">Registriere dich mit E-Mail, vollständiger Adresse und Zustimmung zur Kontaktaufnahme. Ein Admin schaltet dich frei.</p>
              <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="/register">Jetzt registrieren</a>
                <a class="btn btn-outline-primary" href="/login">Zum Login</a>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="card bg-white border-0 shadow-sm h-100">
            <div class="card-body">
              <h2 class="h6">Ablauf</h2>
              <ul class="mb-3">
                <li>Registrierung mit E-Mail, Username, Name, Adresse und Zustimmung.</li>
                <li>Passwortpolicy: 12+ Zeichen, Mix aus Groß/klein, Zahl, Sonderzeichen.</li>
                <li>Login mit Username oder E-Mail, sobald Admin freigeschaltet hat.</li>
                <li>Interaktionen laufen per HTMX ohne unnötige Reloads.</li>
              </ul>
              <p class="small text-muted mb-0">Demo-Admin: <code>admin/admin</code></p>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
