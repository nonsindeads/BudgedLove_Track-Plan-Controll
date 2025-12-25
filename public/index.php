<?php declare(strict_types=1);
session_start();
require_once __DIR__ . '/../app/domain.php';
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = $_SESSION['is_admin'] ?? false;
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$layoutCompact = !$isLoggedIn;

if ($isLoggedIn) {
    $pdo = hb_get_pdo();
    $currentHousehold = hb_current_household($pdo);
    $currentUser = hb_current_user($pdo);
}

ob_start();
?>
<div class="container-fluid">
  <?php if ($isLoggedIn): ?>
    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card shadow-sm">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-3">
              <div>
                <p class="text-muted small mb-1">Willkommen zurück</p>
                <h2 class="h5 mb-0"><?= htmlspecialchars($currentUser['first_name'] ?? $_SESSION['username'] ?? 'User', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
              </div>
              <span class="badge bg-success-subtle text-success">Angemeldet</span>
            </div>
            <p class="text-muted">Accounts müssen von einem Admin freigeschaltet werden. Admin-Login: <code>admin/admin</code>.</p>
            <?php if ($currentHousehold): ?>
              <div class="mb-3 d-flex align-items-center gap-2">
                <span class="badge bg-primary-subtle text-primary"><i class="bi bi-house-door me-1"></i> Haushalt: <?= htmlspecialchars($currentHousehold['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <a href="/household.php" class="btn btn-sm btn-outline-secondary">Haushalt wechseln</a>
              </div>
              <div class="row g-2 mb-3">
                <div class="col-md-4">
                  <a class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center" href="/accounts.php"><i class="bi bi-wallet2 me-2"></i>Konten</a>
                </div>
                <div class="col-md-4">
                  <a class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center" href="/transactions.php"><i class="bi bi-card-list me-2"></i>Transaktionen</a>
                </div>
                <div class="col-md-4">
                  <a class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-center" href="/categories.php"><i class="bi bi-diagram-3 me-2"></i>Kategorien</a>
                </div>
              </div>
            <?php else: ?>
              <div class="alert alert-warning">Du hast noch keinen Haushalt ausgewählt. <a href="/household.php">Jetzt auswählen</a></div>
            <?php endif; ?>
            <div class="d-flex gap-2 align-items-center">
              <button class="btn btn-outline-secondary"
                      hx-get="/ping.php"
                      hx-target="#ping-out"
                      hx-indicator="#ping-spinner">
                <i class="bi bi-wifi me-1"></i> Ping (HTMX)
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
      <div class="col-lg-6">
        <div class="card shadow-sm border-0">
          <div class="card-body">
            <p class="text-muted small mb-1">Haushaltsbuch</p>
            <h1 class="h4 mb-2">Behalte deine Finanzen im Blick</h1>
            <p class="text-muted">Registriere dich mit E-Mail, vollständiger Adresse und Zustimmung zur Kontaktaufnahme. Ein Admin schaltet dich frei.</p>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-primary" href="/register">Jetzt registrieren</a>
              <a class="btn btn-outline-primary" href="/login">Zum Login</a>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
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
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
