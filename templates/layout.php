<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? 'Haushaltsbuch', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <script src="https://unpkg.com/htmx.org@1.9.12"></script>
  <style>
    body {
      background-color: #f6f8fb;
      min-height: 100vh;
    }
    .hb-shell {
      display: grid;
      grid-template-columns: 260px 1fr;
      min-height: 100vh;
    }
    .hb-sidebar {
      background: linear-gradient(180deg, #0d6efd 8%, #0b5ed7 8%, #0f172a 8%);
      color: #f8f9fa;
      width: 260px;
      position: sticky;
      top: 0;
      height: 100vh;
      overflow-y: auto;
      border-right: 1px solid rgba(255,255,255,0.08);
    }
    .hb-sidebar .nav-link {
      color: #e9ecef;
      padding: 0.65rem 1.25rem;
      border-radius: 12px;
      margin: 0 0.75rem 0.35rem 0.75rem;
    }
    .hb-sidebar .nav-link:hover {
      background-color: rgba(255,255,255,0.08);
      color: #fff;
    }
    .hb-sidebar .nav-link.active {
      background-color: #fff;
      color: #0d6efd;
      box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .hb-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: #0d6efd;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 0.9rem;
    }
    .hb-header {
      position: sticky;
      top: 0;
      z-index: 1030;
    }
    .hb-content {
      padding: 1.5rem;
    }
    @media (max-width: 991.98px) {
      .hb-shell {
        grid-template-columns: 1fr;
      }
      .hb-sidebar {
        display: none;
      }
    }
  </style>
</head>
<body>
<?php if (empty($layoutCompact)): ?>
  <div class="hb-shell">
    <?php $currentUser = $currentUser ?? null; ?>
    <?php $currentHousehold = $currentHousehold ?? null; ?>
    <div class="d-none d-lg-block">
      <?php require __DIR__ . '/partials/sidebar.php'; ?>
    </div>
    <div class="d-flex flex-column">
      <?php require __DIR__ . '/partials/header.php'; ?>
      <main class="hb-content">
        <?= $content ?? '' ?>
      </main>
    </div>
  </div>
<?php else: ?>
  <main class="hb-content">
    <?= $content ?? '' ?>
  </main>
<?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(t => new bootstrap.Tooltip(t));
  </script>
</body>
</html>
