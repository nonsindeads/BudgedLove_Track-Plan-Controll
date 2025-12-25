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
    .hb-offcanvas {
      width: 260px;
    }
    .hb-offcanvas .offcanvas-body {
      padding: 0;
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
<?php
$currentUser = $currentUser ?? null;
$currentHousehold = $currentHousehold ?? null;
$wsUrl = getenv('HB_WS_URL') ?: '';
$wsToken = hb_ws_token($currentUser, $currentHousehold);
?>
<body data-ws-url="<?= htmlspecialchars($wsUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
      data-ws-token="<?= htmlspecialchars($wsToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<?php if (empty($layoutCompact)): ?>
  <div class="hb-shell">
    <div class="offcanvas offcanvas-start hb-offcanvas d-lg-none" tabindex="-1" id="hbSidebar" aria-labelledby="hbSidebarLabel">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="hbSidebarLabel">Navigation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Schließen"></button>
      </div>
      <div class="offcanvas-body">
        <?php require __DIR__ . '/partials/sidebar.php'; ?>
      </div>
    </div>
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
  <?php if (!empty($currentUser) && !empty($currentHousehold)): ?>
    <div class="offcanvas offcanvas-end hb-offcanvas" tabindex="-1" id="hbLivePanel" aria-labelledby="hbLivePanelLabel">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="hbLivePanelLabel">Live</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Schließen"></button>
      </div>
      <div class="offcanvas-body d-flex flex-column gap-3">
        <div>
          <div class="fw-semibold mb-2">Aktivitäten</div>
          <div id="hb-live-log" class="border rounded-3 p-2 bg-light-subtle small" style="max-height: 260px; overflow-y: auto;">
            <div class="text-muted">Noch keine Live-Ereignisse.</div>
          </div>
        </div>
        <div class="mt-auto">
          <div class="fw-semibold mb-2">Chat</div>
          <div id="hb-live-chat" class="border rounded-3 p-2 bg-light-subtle small" style="max-height: 220px; overflow-y: auto;"></div>
          <form id="hb-chat-form" class="d-flex gap-2 mt-2">
            <input type="text" class="form-control form-control-sm" id="hb-chat-input" placeholder="Nachricht...">
            <button type="submit" class="btn btn-sm btn-primary">Senden</button>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>
  <main class="hb-content">
    <?= $content ?? '' ?>
  </main>
<?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(t => new bootstrap.Tooltip(t));
    const hbHandleRedirect = (root) => {
      const target = root.querySelector('[data-redirect-url]');
      if (!target) return;
      const url = target.getAttribute('data-redirect-url');
      const delay = parseInt(target.getAttribute('data-redirect-delay') || '0', 10);
      if (!url) return;
      window.setTimeout(() => {
        window.location.href = url;
      }, Number.isFinite(delay) ? delay : 0);
    };
    document.addEventListener('DOMContentLoaded', () => hbHandleRedirect(document));
    document.body.addEventListener('htmx:afterSwap', (event) => {
      if (!event || !event.target) return;
      hbHandleRedirect(event.target);
    });

    const hbWsUrl = document.body.dataset.wsUrl || '';
    const hbWsToken = document.body.dataset.wsToken || '';
    const hbLiveLog = document.getElementById('hb-live-log');
    const hbLiveChat = document.getElementById('hb-live-chat');
    const hbChatForm = document.getElementById('hb-chat-form');
    const hbChatInput = document.getElementById('hb-chat-input');
    let hbSocket = null;

    const hbAppendLine = (container, text) => {
      if (!container) return;
      const line = document.createElement('div');
      line.textContent = text;
      container.appendChild(line);
      container.scrollTop = container.scrollHeight;
    };

    const hbInitSocket = () => {
      if (!hbWsUrl || !hbWsToken) return;
      hbSocket = new WebSocket(hbWsUrl);
      hbSocket.addEventListener('open', () => {
        hbSocket.send(JSON.stringify({ type: 'hello', token: hbWsToken }));
      });
      hbSocket.addEventListener('message', (event) => {
        try {
          const payload = JSON.parse(event.data);
          if (payload.type === 'audit' && hbLiveLog) {
            const label = payload.username ? `${payload.username}` : 'System';
            hbAppendLine(hbLiveLog, `${label}: ${payload.message || payload.action}`);
          }
          if (payload.type === 'chat' && hbLiveChat) {
            const label = payload.username ? `${payload.username}` : 'System';
            hbAppendLine(hbLiveChat, `${label}: ${payload.message}`);
          }
        } catch (err) {
          // ignore malformed messages
        }
      });
      hbSocket.addEventListener('close', () => {
        setTimeout(hbInitSocket, 3000);
      });
    };

    hbInitSocket();

    if (hbChatForm && hbChatInput) {
      hbChatForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!hbSocket || hbSocket.readyState !== WebSocket.OPEN) return;
        const msg = hbChatInput.value.trim();
        if (!msg) return;
        hbSocket.send(JSON.stringify({ type: 'chat', message: msg }));
        hbChatInput.value = '';
      });
    }
  </script>
</body>
</html>
