<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$serverPdo = hb_get_pdo();
$household = hb_require_household($serverPdo);
$pdo = hb_household_pdo($serverPdo, (int)$household['id']);
$currentHousehold = $household;
$currentUser = hb_current_user($serverPdo);

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
$showImportItems = isset($_GET['show_import_items']);

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
    $where[] = '(lower(coalesce(username, \'\')) like lower(:search) or lower(coalesce(cast(data_new as text), \'\')) like lower(:search) or lower(coalesce(cast(data_old as text), \'\')) like lower(:search))';
    $params['search'] = '%' . $search . '%';
}
if (!$showImportItems) {
    $where[] = "not (table_name = 'transactions' and action = 'insert' and lower(coalesce(cast(data_new as text), '')) like '%import_hash%')";
}

$whereSql = $where ? 'where ' . implode(' and ', $where) : '';
$stmt = $pdo->prepare(
    "select id, event_at, username, user_id, action, table_name, entity_id, data_old, data_new
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
    'users' => 'Users',
    'households' => 'Households',
    'household_members' => 'Members',
    'accounts' => 'Accounts',
    'transactions' => 'Transactions',
    'transaction_splits' => 'Splits',
    'transaction_tags' => 'Transaction tags',
    'categories' => 'Categories',
    'tags' => 'Tags',
    'payees' => 'Payees',
    'recurring_payments' => 'Recurring payments',
    'planned_payments' => 'Period plan',
    'open_cases' => 'Open cases',
    'month_closures' => 'Period close',
    'attachments' => 'Attachments',
    'chat_messages' => 'Chat',
    'imports' => 'Imports',
];
$actionOptions = [
    'insert' => hb_t('Created'),
    'update' => hb_t('Updated'),
    'delete' => hb_t('Deleted'),
    'import' => hb_t('Import'),
];

function hb_history_decode($data): array
{
    if (is_array($data)) {
        return $data;
    }
    if (is_string($data) && $data !== '') {
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function hb_history_format_summary(array $event, array $maps): string
{
    $action = $event['action'] ?? '';
    $table = $event['table_name'] ?? '';
    $dataNew = hb_history_decode($event['data_new'] ?? null);
    $dataOld = hb_history_decode($event['data_old'] ?? null);

    if ($table === 'imports' && $action === 'import') {
        $accountName = $dataNew['account_name'] ?? hb_t('Account');
        $inserted = (int)($dataNew['inserted'] ?? 0);
        $files = (int)($dataNew['files'] ?? 0);
        $dateFrom = $dataNew['date_from'] ?? null;
        $dateTo = $dataNew['date_to'] ?? null;
        $range = '';
        if ($dateFrom && $dateTo) {
            $range = $dateFrom === $dateTo ? $dateFrom : ($dateFrom . '–' . $dateTo);
        }
        $parts = [
            hb_t('Import: {count} transactions in {account}', null, ['count' => $inserted, 'account' => $accountName]),
            $range !== '' ? $range : null,
            hb_t('{count} file(s)', null, ['count' => $files]),
        ];
        return implode(' · ', array_filter($parts));
    }

    if ($table === 'transactions') {
        $amount = isset($dataNew['amount_cents']) ? number_format(((int)$dataNew['amount_cents']) / 100, 2, ',', '.') . ' €' : null;
        $account = $maps['accounts'][$dataNew['account_id'] ?? 0] ?? null;
        $payee = $maps['payees'][$dataNew['payee_id'] ?? 0] ?? ($dataNew['counterparty_name'] ?? null);
        $direction = $dataNew['type'] ?? null;
        $bits = array_filter([$direction, $amount, $payee, $account]);
        if ($bits) {
            return implode(' · ', $bits);
        }
    }

    if ($table === 'planned_payments' && isset($dataNew['name'])) {
        return (string)$dataNew['name'];
    }

    if ($action === 'update' && $dataNew && $dataOld) {
        $changes = [];
        foreach ($dataNew as $key => $val) {
            $old = $dataOld[$key] ?? null;
            if ($old !== $val) {
                $changes[] = $key;
            }
        }
        if ($changes) {
            $fields = implode(', ', array_slice($changes, 0, 4)) . (count($changes) > 4 ? '…' : '');
            return hb_t('Changed: {fields}', null, ['fields' => $fields]);
        }
    }

    return '';
}

function hb_history_format_changes(array $event, array $maps): array
{
    $action = $event['action'] ?? '';
    $table = $event['table_name'] ?? '';
    $dataNew = hb_history_decode($event['data_new'] ?? null);
    $dataOld = hb_history_decode($event['data_old'] ?? null);
    $rows = [];

    $formatValue = function ($key, $value) use ($maps, $table): string {
        if ($value === null || $value === '') {
            return '-';
        }
        if (in_array($key, ['amount_cents', 'opening_balance_cents'], true)) {
            return number_format(((int)$value) / 100, 2, ',', '.') . ' €';
        }
        if ($key === 'account_id') {
            return $maps['accounts'][(int)$value] ?? (string)$value;
        }
        if ($key === 'category_id') {
            return $maps['categories'][(int)$value] ?? (string)$value;
        }
        if ($key === 'payee_id') {
            return $maps['payees'][(int)$value] ?? (string)$value;
        }
        if ($key === 'planned_payment_id') {
            return $maps['plans'][(int)$value] ?? (string)$value;
        }
        if ($table === 'imports' && $key === 'files') {
            return (string)$value;
        }
        return is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
    };

    $fields = array_keys($dataNew + $dataOld);
    foreach ($fields as $field) {
        $newVal = $dataNew[$field] ?? null;
        $oldVal = $dataOld[$field] ?? null;
        if ($action === 'update' && $newVal === $oldVal) {
            continue;
        }
        if ($action === 'insert' && $newVal === null) {
            continue;
        }
        $rows[] = [
            'field' => $field,
            'old' => $formatValue($field, $oldVal),
            'new' => $formatValue($field, $newVal),
        ];
    }

    return $rows;
}

$userMap = [];
foreach ($users as $user) {
    $userMap[(int)$user['id']] = $user['username'];
}

$accountsStmt = $pdo->prepare('select id, name from accounts where household_id = :hid');
$accountsStmt->execute(['hid' => $household['id']]);
$accountMap = [];
foreach ($accountsStmt->fetchAll() as $row) {
    $accountMap[(int)$row['id']] = $row['name'];
}

$categoriesStmt = $pdo->prepare('select id, name from categories where household_id = :hid');
$categoriesStmt->execute(['hid' => $household['id']]);
$categoryMap = [];
foreach ($categoriesStmt->fetchAll() as $row) {
    $categoryMap[(int)$row['id']] = $row['name'];
}

$payeesStmt = $pdo->prepare('select id, name from payees where household_id = :hid');
$payeesStmt->execute(['hid' => $household['id']]);
$payeeMap = [];
foreach ($payeesStmt->fetchAll() as $row) {
    $payeeMap[(int)$row['id']] = $row['name'];
}

$plansStmt = $pdo->prepare('select id, name from planned_payments where household_id = :hid');
$plansStmt->execute(['hid' => $household['id']]);
$planMap = [];
foreach ($plansStmt->fetchAll() as $row) {
    $planMap[(int)$row['id']] = $row['name'];
}

$maps = [
    'accounts' => $accountMap,
    'categories' => $categoryMap,
    'payees' => $payeeMap,
    'plans' => $planMap,
];

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('History'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('All changes in the household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="get" action="/history.php" class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label"><?= htmlspecialchars(hb_t('Table'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select class="form-select" name="table">
            <option value=""><?= htmlspecialchars(hb_t('All'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php foreach ($tables as $key => $label): ?>
              <option value="<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $tableFilter === $key ? 'selected' : '' ?>>
                <?= htmlspecialchars(hb_t($label), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label"><?= htmlspecialchars(hb_t('Action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select class="form-select" name="action">
            <option value=""><?= htmlspecialchars(hb_t('All'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php foreach ($actionOptions as $key => $label): ?>
              <option value="<?= $key ?>" <?= $actionFilter === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label"><?= htmlspecialchars(hb_t('User'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select class="form-select" name="user">
            <option value=""><?= htmlspecialchars(hb_t('All'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php foreach ($users as $user): ?>
              <option value="<?= (int)$user['id'] ?>" <?= $userFilter !== '' && (int)$userFilter === (int)$user['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label"><?= htmlspecialchars(hb_t('From'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="date" class="form-control" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label"><?= htmlspecialchars(hb_t('To'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="date" class="form-control" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= htmlspecialchars(hb_t('Search'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(hb_t('Free text (name, fields, IDs)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="col-md-6">
          <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="show-import-items" name="show_import_items" value="1" <?= $showImportItems ? 'checked' : '' ?>>
            <label class="form-check-label" for="show-import-items"><?= htmlspecialchars(hb_t('Show import details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          </div>
        </div>
        <div class="col-md-6 text-end">
          <button type="submit" class="btn btn-primary"><?= htmlspecialchars(hb_t('Filter'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          <a href="/history.php" class="btn btn-outline-secondary"><?= htmlspecialchars(hb_t('Reset'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <?php if (!$events): ?>
        <div class="text-muted"><?= htmlspecialchars(hb_t('No entries found.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th><?= htmlspecialchars(hb_t('Time'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(hb_t('User'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(hb_t('Action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(hb_t('Table'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th>Entity</th>
                <th><?= htmlspecialchars(hb_t('Details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($events as $event): ?>
                <?php
                $username = $event['username'] ?? null;
                if ($username === null && !empty($event['user_id'])) {
                    $username = $userMap[(int)$event['user_id']] ?? null;
                }
                $summary = hb_history_format_summary($event, $maps);
                $changes = hb_history_format_changes($event, $maps);
                $actionLabel = $actionOptions[$event['action']] ?? $event['action'];
                $tableLabel = $tables[$event['table_name']] ?? $event['table_name'];
                ?>
                <tr>
                  <td class="small"><?= htmlspecialchars($event['event_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($username ?? hb_t('System'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><span class="badge bg-light text-dark"><?= htmlspecialchars($actionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                  <td><?= htmlspecialchars(hb_t($tableLabel), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td class="small"><?= htmlspecialchars($event['entity_id'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td class="small">
                    <?php if ($summary): ?>
                      <div class="fw-semibold"><?= htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($changes): ?>
                      <details class="mt-1">
                        <summary><?= htmlspecialchars(hb_t('Details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></summary>
                        <div class="table-responsive">
                          <table class="table table-sm mb-0">
                            <thead>
                              <tr>
                                <th><?= htmlspecialchars(hb_t('Field'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(hb_t('Before'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(hb_t('After'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($changes as $change): ?>
                                <tr>
                                  <td><?= htmlspecialchars($change['field'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                  <td><?= htmlspecialchars($change['old'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                  <td><?= htmlspecialchars($change['new'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      </details>
                    <?php endif; ?>
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
