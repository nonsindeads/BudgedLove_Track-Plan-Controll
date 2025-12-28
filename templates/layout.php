<?php
declare(strict_types=1);

$liveLog = [];
$liveChat = [];
if (!empty($currentHousehold['id']) && function_exists('hb_get_pdo')) {
  try {
    $pdo = hb_get_pdo();
    $limit = 50;
    $tableLabels = [
      'users' => 'Benutzer',
      'households' => 'Haushalt',
      'household_members' => 'Mitglieder',
      'accounts' => 'Konten',
      'transactions' => 'Transaktionen',
      'transaction_splits' => 'Splits',
      'transaction_tags' => 'Transaktions-Tags',
      'categories' => 'Kategorien',
      'tags' => 'Tags',
      'payees' => 'Empfänger',
      'payee_mappings' => 'Empfänger-Mapping',
      'recurring_payments' => 'Wiederkehrend',
      'planned_payments' => 'Monatsplan',
      'open_cases' => 'Offene Posten',
      'month_closures' => 'Monatsabschluss',
      'attachments' => 'Anhänge',
      'chat_messages' => 'Chat',
      'imports' => 'Imports',
    ];
    $actionLabels = [
      'insert' => 'erstellt',
      'update' => 'aktualisiert',
      'delete' => 'gelöscht',
    ];
    $importantTables = ['imports' => true, 'month_closures' => true];
    $auditStmt = $pdo->prepare(
      'select e.event_at, e.username, e.user_id, e.action, e.table_name, e.entity_id, u.username as user_name
         from audit_events e
         left join users u on u.id = e.user_id
        where e.household_id = :hid
        order by e.event_at desc
        limit :limit'
    );
    $auditStmt->bindValue(':hid', (int)$currentHousehold['id'], PDO::PARAM_INT);
    $auditStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $auditStmt->execute();
    foreach ($auditStmt->fetchAll() as $row) {
      $tableName = (string)($row['table_name'] ?? '');
      $label = $tableLabels[$tableName] ?? $tableName;
      $actionKey = (string)($row['action'] ?? '');
      $action = $actionLabels[$actionKey] ?? $actionKey;
      $entity = $row['entity_id'] ? ' #' . $row['entity_id'] : '';
      $user = $row['username'] ?: ($row['user_name'] ?? '') ?: 'System';
      $liveLog[] = [
        'text' => sprintf('%s: %s %s%s', $user, $action, $label, $entity),
        'action' => $actionKey,
        'table' => $tableName,
        'important' => isset($importantTables[$tableName]),
      ];
    }
    $chatStmt = $pdo->prepare(
      'select c.message, u.username
         from chat_messages c
         join users u on u.id = c.user_id
        where c.household_id = :hid
        order by c.created_at desc
        limit :limit'
    );
    $chatStmt->bindValue(':hid', (int)$currentHousehold['id'], PDO::PARAM_INT);
    $chatStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $chatStmt->execute();
    foreach ($chatStmt->fetchAll() as $row) {
      $user = $row['username'] ?: 'System';
      $liveChat[] = sprintf('%s: %s', $user, $row['message']);
    }
  } catch (Throwable $e) {
    $liveLog = [];
    $liveChat = [];
  }
}
?>
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
      min-height: 100dvh;
    }
    .hb-shell {
      display: grid;
      grid-template-columns: 260px 1fr;
      min-height: 100dvh;
    }
    .hb-sidebar {
      background: linear-gradient(180deg, #0d6efd 8%, #0b5ed7 8%, #0f172a 8%);
      color: #f8f9fa;
      width: 260px;
      position: sticky;
      top: 0;
      height: 100dvh;
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
    .offcanvas {
      height: 100dvh;
    }
    .hb-header-left,
    .hb-header-right {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    .breadcrumb {
      flex-wrap: wrap;
    }
    .hb-offcanvas {
      width: 85vw;
      max-width: 320px;
    }
    .hb-offcanvas-nav .offcanvas-body {
      padding: 0;
      overflow-y: auto;
    }
    .hb-offcanvas-live {
      width: 90vw;
      max-width: 360px;
    }
    .hb-offcanvas-live .offcanvas-body {
      padding: 0;
      overflow: hidden;
    }
    .offcanvas .hb-sidebar {
      width: 100%;
      height: auto;
      position: static;
      border-right: none;
    }
    .hb-live-body {
      display: flex;
      flex-direction: column;
      min-height: 0;
      height: 100%;
      gap: 0.75rem;
      padding: 0.75rem 1rem 1rem;
    }
    .hb-live-log,
    .hb-live-chat {
      border: 1px solid rgba(0,0,0,0.08);
      border-radius: 12px;
      background: rgba(248,249,250,0.7);
    }
    .hb-live-log {
      flex: 1 1 auto;
      min-height: 140px;
      overflow-y: auto;
      padding: 0.5rem 0.75rem;
    }
    .hb-live-chat {
      max-height: 200px;
      overflow-y: auto;
      padding: 0.5rem 0.75rem;
    }
    .hb-live-panel {
      display: none;
      width: 320px;
      background: #fff;
      border-left: 1px solid rgba(0,0,0,0.08);
      height: 100dvh;
      position: sticky;
      top: 0;
      z-index: 1035;
    }
    .hb-live-panel .hb-live-body {
      padding: 0.75rem 1rem 1rem;
    }
    .hb-live-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0.75rem 1rem;
      border-bottom: 1px solid rgba(0,0,0,0.08);
    }
    .hb-live-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.2);
      z-index: 1030;
    }
    .hb-live-filters {
      display: none;
      gap: 0.5rem;
      flex-wrap: wrap;
    }
    .hb-live-filters.show {
      display: flex;
    }
    body.hb-live-open .hb-live-panel {
      display: block;
    }
    body.hb-live-open .hb-live-backdrop {
      display: none;
    }
    .hb-live-footer {
      margin-top: auto;
    }
    .offcanvas-header {
      padding: 0.75rem 1rem;
    }
    .hb-content {
      padding: 1.5rem;
    }
    .hb-forecast-chart {
      height: 240px;
    }
    .hb-tag-field {
      min-height: 38px;
      cursor: text;
    }
    .hb-tag-input {
      min-width: 120px;
      outline: none;
      background: transparent;
    }
    .hb-tag-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
    }
    .hb-tag-chip .btn-close {
      filter: brightness(1.4);
    }
    .hb-tag-dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #adb5bd;
      margin-right: 0.35rem;
      flex-shrink: 0;
    }
    .hb-tag-dropdown {
      max-height: 240px;
      overflow-y: auto;
      min-width: 220px;
    }
    .hb-whitebox {
      background: #fff;
      border: 1px solid rgba(15, 23, 42, 0.08);
      border-radius: 16px;
      box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06);
    }
    .hb-whitebox-header {
      padding: 1rem 1.25rem 0;
    }
    .hb-whitebox-body {
      padding: 1rem 1.25rem 1.25rem;
    }
    .hb-whitebox .hb-tag-selector {
      background: #fff;
    }
    @media (max-width: 991.98px) {
      .hb-shell {
        grid-template-columns: 1fr;
      }
      .hb-sidebar {
        display: none;
      }
      body.hb-live-open .hb-live-panel {
        position: fixed;
        right: 0;
        top: 0;
        width: 90vw;
        max-width: 360px;
        z-index: 1040;
        box-shadow: -8px 0 24px rgba(0,0,0,0.18);
      }
      body.hb-live-open .hb-live-backdrop {
        display: block;
      }
    }
    @media (min-width: 768px) {
      .hb-offcanvas {
        width: 320px;
        max-width: 360px;
      }
      .hb-offcanvas-live {
        width: 380px;
        max-width: 420px;
      }
    }
    @media (min-width: 992px) {
      .hb-forecast-chart {
        height: 280px;
      }
      body.hb-live-open .hb-shell {
        grid-template-columns: 260px 1fr 320px;
      }
    }
    @media (max-width: 575.98px) {
      .hb-content {
        padding: 1rem;
      }
      .hb-header {
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
      }
      .hb-header-right {
        justify-content: space-between;
      }
      .hb-header-right form {
        flex: 1;
      }
      .hb-header-right .form-select {
        width: 100%;
      }
      .hb-sidebar .border-bottom {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
      }
      .hb-sidebar .hb-logo svg {
        width: 28px;
        height: 28px;
      }
      .hb-sidebar .fw-semibold {
        font-size: 0.95rem;
      }
      .hb-sidebar .text-muted.small {
        font-size: 0.7rem;
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
    <div class="offcanvas offcanvas-start hb-offcanvas hb-offcanvas-nav d-lg-none" tabindex="-1" id="hbSidebar" aria-labelledby="hbSidebarLabel">
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
    <aside class="hb-live-panel" id="hbLivePanel" aria-labelledby="hbLivePanelLabel">
      <div class="hb-live-header">
        <h5 class="mb-0" id="hbLivePanelLabel">Live</h5>
        <button type="button" class="btn-close" aria-label="Schließen" data-hb-live-close></button>
      </div>
      <div class="hb-live-body">
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-semibold">Aktivitäten</div>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-hb-filter-toggle>Filter</button>
        </div>
        <div class="hb-live-filters mt-2" data-hb-filter-panel>
          <div class="form-check form-check-inline mb-0">
            <input class="form-check-input" type="checkbox" id="hb-filter-insert" data-hb-filter="insert" checked>
            <label class="form-check-label small" for="hb-filter-insert">Insert</label>
          </div>
          <div class="form-check form-check-inline mb-0">
            <input class="form-check-input" type="checkbox" id="hb-filter-update" data-hb-filter="update">
            <label class="form-check-label small" for="hb-filter-update">Update</label>
          </div>
          <div class="form-check form-check-inline mb-0">
            <input class="form-check-input" type="checkbox" id="hb-filter-delete" data-hb-filter="delete" checked>
            <label class="form-check-label small" for="hb-filter-delete">Delete</label>
          </div>
          <div class="form-check form-check-inline mb-0">
            <input class="form-check-input" type="checkbox" id="hb-filter-important" data-hb-filter-important checked>
            <label class="form-check-label small" for="hb-filter-important">Wichtig</label>
          </div>
        </div>
        <div id="hb-live-log" class="hb-live-log small mt-2">
          <?php if ($liveLog): ?>
            <?php foreach (array_reverse($liveLog) as $item): ?>
              <?php
              $action = $item['action'] ?? '';
              $table = $item['table'] ?? '';
              $important = !empty($item['important']) ? '1' : '0';
              ?>
              <div data-action="<?= htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   data-table="<?= htmlspecialchars($table, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   data-important="<?= $important ?>">
                <?= htmlspecialchars($item['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="text-muted">Noch keine Live-Ereignisse.</div>
          <?php endif; ?>
        </div>
        <div class="fw-semibold mt-3">Chat</div>
        <div id="hb-live-chat" class="hb-live-chat small">
          <?php if ($liveChat): ?>
            <?php foreach (array_reverse($liveChat) as $line): ?>
              <div><?= htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <form id="hb-chat-form" class="hb-live-footer d-flex gap-2">
          <input type="text" class="form-control form-control-sm" id="hb-chat-input" placeholder="Nachricht...">
          <button type="submit" class="btn btn-sm btn-primary">Senden</button>
        </form>
      </div>
    </aside>
  <?php endif; ?>
<?php else: ?>
  <main class="hb-content">
    <?= $content ?? '' ?>
  </main>
<?php endif; ?>
<?php if (!empty($currentUser) && !empty($currentHousehold)): ?>
  <div class="hb-live-backdrop" data-hb-live-close></div>
<?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?= $extraScripts ?? '' ?>
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

    const hbAppendLine = (container, text, meta = {}) => {
      if (!container) return;
      const line = document.createElement('div');
      line.textContent = text;
      if (meta.action) line.dataset.action = meta.action;
      if (meta.table) line.dataset.table = meta.table;
      if (meta.important) line.dataset.important = '1';
      container.appendChild(line);
      container.scrollTop = container.scrollHeight;
    };

    const hbGetFilters = () => {
      const actions = new Set();
      document.querySelectorAll('[data-hb-filter]').forEach((input) => {
        if (input.checked) actions.add(input.dataset.hbFilter);
      });
      const important = document.querySelector('[data-hb-filter-important]')?.checked ?? false;
      return { actions, important };
    };

    const hbApplyFilters = () => {
      const { actions, important } = hbGetFilters();
      if (!hbLiveLog) return;
      hbLiveLog.querySelectorAll('[data-action]').forEach((line) => {
        const action = line.dataset.action || '';
        const isImportant = line.dataset.important === '1';
        const matches = actions.has(action) || (important && isImportant);
        line.classList.toggle('d-none', !matches);
      });
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
            hbAppendLine(hbLiveLog, `${label}: ${payload.message || payload.action}`, {
              action: payload.action || '',
              table: payload.table || '',
              important: payload.table === 'imports' || payload.table === 'month_closures',
            });
            hbApplyFilters();
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
    hbApplyFilters();

    document.querySelectorAll('[data-hb-filter], [data-hb-filter-important]').forEach((input) => {
      input.addEventListener('change', hbApplyFilters);
    });

    document.querySelectorAll('[data-hb-filter-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const panel = document.querySelector('[data-hb-filter-panel]');
        panel?.classList.toggle('show');
      });
    });

    const hbToggleLive = () => {
      document.body.classList.toggle('hb-live-open');
    };
    document.querySelectorAll('[data-hb-live-toggle]').forEach((btn) => {
      btn.addEventListener('click', hbToggleLive);
    });
    document.querySelectorAll('[data-hb-live-close]').forEach((btn) => {
      btn.addEventListener('click', () => document.body.classList.remove('hb-live-open'));
    });

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
