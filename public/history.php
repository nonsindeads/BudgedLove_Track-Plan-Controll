<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../app/domain.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);

$pageTitle = 'History';
$activeNav = 'history';
$breadcrumbs = [
    ['label' => 'History', 'href' => '/history.php'],
];

$tableFilter = trim((string)($_GET['table'] ?? ''));
$actionFilter = trim((string)($_GET['action'] ?? ''));
$userFilter = trim((string)($_GET['user'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));

$where = ['household_id = :hid'];
$params = ['hid' => $household['id']];

if ($tableFilter !== '') {
    $where[] = 'table_name = :table';
    $params['table'] = $tableFilter;
}
if ($actionFilter !== '') {
    $where[] = 'action = :action';
    $params['action'] = $actionFilter;
}
if ($userFilter !== '') {
    $where[] = 'user_id = :user_id';
    $params['user_id'] = (int)$userFilter;
}
if ($from !== '') {
    $where[] = 'event_at >= :from';
    $params['from'] = $from;
}
if ($to !== '') {
    $where[] = 'event_at <= :to';
    $params['to'] = $to;
}
if ($search !== '') {
    $where[] = '(coalesce(username, \'\') ilike :search or coalesce(data_new::text, \'\') ilike :search or coalesce(data_old::text, \'\') ilike :search)';
    $params['search'] = '%' . $search . '%';
}

$whereSql = $where ? 'where ' . implode(' and ', $where) : '';
$stmt = $pdo->prepare(
    "select id, event_at, username, action, table_name, entity_id, data_old, data_new
       from audit_events
       {$whereSql}
      order by event_at desc
      limit 200"
);
$stmt->execute($params);
$events = $stmt->fetchAll();

$usersStmt = $pdo->prepare(
    'select u.id, u.username
       from users u
       join household_members m on m.user_id = u.id
      where m.household_id = :hid and m.is_active = true
      order by u.username asc'
);
$usersStmt->execute(['hid' => $household['id']]);
$users = $usersStmt->fetchAll();

$tables = [
    'users' => 'Benutzer',
    'households' => 'Haushalte',
    'household_members' => 'Mitglieder',
    'accounts' => 'Konten',
    'transactions' => 'Transaktionen',
    'transaction_splits' => 'Splits',
    'transaction_tags' => 'Transaktions-Tags',
    'categories' => 'Kategorien',
    'tags' => 'Tags',
    'payees' => 'Empfänger',
    'recurring_payments' => 'Wiederkehrend',
    'planned_payments' => 'Monatsplan',
    'open_cases' => 'Offene Posten',
    'month_closures' => 'Monatsabschluss',
    'recurring_rules' => 'Regeln',
    'tasks' => 'Tasks',
    'attachments' => 'Anhänge',
    'recurring_executions' => 'Ausführungen',
    'chat_messages' => 'Chat',
];

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0">History</h1>
      <div class="text-muted small">Alle Änderungen im Haushalt</div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="get" action="/history.php" class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Tabelle</label>
          <select class="form-select" name="table">
            <option value="">Alle</option>
            <?php foreach ($tables as $key => $label): ?>
              <option value="<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $tableFilter === $key ? 'selected' : '' ?>>
                <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Aktion</label>
          <select class="form-select" name="action">
            <option value="">Alle</option>
            <?php foreach (['insert' => 'Neu', 'update' => 'Update', 'delete' => 'Delete'] as $key => $label): ?>
              <option value="<?= $key ?>" <?= $actionFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Nutzer</label>
          <select class="form-select" name="user">
            <option value="">Alle</option>
            <?php foreach ($users as $user): ?>
              <option value="<?= (int)$user['id'] ?>" <?= $userFilter !== '' && (int)$userFilter === (int)$user['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Von</label>
          <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Bis</label>
          <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Suche</label>
          <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Freitext (Name, Felder, IDs)">
        </div>
        <div class="col-md-6 text-end">
          <button type="submit" class="btn btn-primary">Filtern</button>
          <a href="/history.php" class="btn btn-outline-secondary">Zurücksetzen</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <?php if (!$events): ?>
        <div class="text-muted">Keine Einträge gefunden.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Zeit</th>
                <th>Nutzer</th>
                <th>Aktion</th>
                <th>Tabelle</th>
                <th>Entity</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($events as $event): ?>
                <tr>
                  <td class="small"><?= htmlspecialchars($event['event_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($event['username'] ?? 'System', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><span class="badge bg-light text-dark"><?= htmlspecialchars($event['action'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                  <td><?= htmlspecialchars($event['table_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td class="small"><?= htmlspecialchars($event['entity_id'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td class="small">
                    <details>
                      <summary>Diff</summary>
                      <pre class="small mb-0"><?= htmlspecialchars(json_encode($event['data_old'], JSON_PRETTY_PRINT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
                      <pre class="small mb-0"><?= htmlspecialchars(json_encode($event['data_new'], JSON_PRETTY_PRINT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
                    </details>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
