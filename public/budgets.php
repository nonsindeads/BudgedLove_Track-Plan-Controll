<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);
$pageTitle = 'Budgets & Savings';
$activeNav = 'budgets';
$layoutCompact = false;
$breadcrumbs = [
    ['label' => 'Budgets & Savings', 'href' => '/budgets.php'],
];

$accounts = $pdo->prepare('select * from accounts where household_id = :hid and is_archived = false order by name asc');
$accounts->execute(['hid' => $household['id']]);
$accounts = $accounts->fetchAll();

$categoriesStmt = $pdo->prepare('select * from categories where household_id = :hid and is_active = true order by type asc, name asc');
$categoriesStmt->execute(['hid' => $household['id']]);
$categories = $categoriesStmt->fetchAll();

// Period selection
$today = new DateTimeImmutable('today');
$salaryPeriods = hb_get_salary_periods($pdo, $household, $today, 3);
[$periodStart, $periodEnd] = hb_household_period_bounds($household, $today, $pdo);
$rangePreset = (string)($_GET['range'] ?? '');
$periodLabel = hb_period_label($periodStart, $periodEnd);
if (preg_match('/^period:([123])$/', $rangePreset, $periodMatch)) {
    $periodCount = (int)$periodMatch[1];
    if (count($salaryPeriods) >= $periodCount) {
        $periodStart = $salaryPeriods[$periodCount - 1]['start'];
        $periodEnd = $salaryPeriods[0]['end'];
        $periodLabel = $periodCount === 1 ? $salaryPeriods[0]['label'] : hb_period_label($periodStart, $periodEnd);
    }
} elseif (isset($_GET['month'])) {
    $monthParam = (string)$_GET['month'];
    $periodStart = DateTimeImmutable::createFromFormat('Y-m-d', $monthParam . '-01') ?: $today->modify('first day of this month');
    $periodEnd = $periodStart->modify('last day of this month');
    $periodLabel = hb_period_label($periodStart, $periodEnd);
}
$periodUrlSuffix = $rangePreset !== '' ? '&range=' . urlencode($rangePreset) : '';
$periodListUrl = '/budgets.php' . ($rangePreset !== '' ? '?range=' . urlencode($rangePreset) : '');
$periodSavedUrl = '/budgets.php?msg=saved' . ($rangePreset !== '' ? '&range=' . urlencode($rangePreset) : '');
$periodDeletedUrl = '/budgets.php?msg=deleted' . ($rangePreset !== '' ? '&range=' . urlencode($rangePreset) : '');

$msg = $_GET['msg'] ?? null;
$error = null;

function hb_budget_amount(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' €';
}

function hb_savings_contributions(PDO $pdo, int $householdId, array $categoryIds, ?int $accountId, ?DateTimeImmutable $start, ?DateTimeImmutable $end): int
{
    $conditions = ['t.household_id = ?'];
    $params = [$householdId];

    if ($start) {
        $conditions[] = 't.booking_date >= ?';
        $params[] = $start->format('Y-m-d');
    }
    if ($end) {
        $conditions[] = 't.booking_date <= ?';
        $params[] = $end->format('Y-m-d');
    }
    $conditions[] = "t.type = 'expense'";

    $categoryFilter = '';
    if ($categoryIds) {
        $ph = implode(',', array_fill(0, count($categoryIds), '?'));
        $categoryFilter = "(ts.id is not null and ts.category_id in ($ph)) or (ts.id is null and t.category_id in ($ph))";
        $params = array_merge($params, $categoryIds, $categoryIds);
    }

    if ($accountId) {
        $conditions[] = '(t.account_id = ? or t.transfer_from_account_id = ? or t.transfer_to_account_id = ?)';
        $params[] = $accountId;
        $params[] = $accountId;
        $params[] = $accountId;
    }

    $where = implode(' and ', $conditions);
    $sql = "
        select coalesce(sum(
            case when ts.id is not null then ts.amount_cents else t.amount_cents end
        ), 0) as total_cents
          from transactions t
          left join transaction_splits ts on ts.transaction_id = t.id
         where $where
           " . ($categoryFilter ? "and ($categoryFilter)" : '') . "
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'store_budget' || $action === 'update_budget') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $amount = hb_parse_cents((string)($_POST['amount'] ?? ''));
        $periodUnit = $_POST['period_unit'] ?? 'month';
        $periodValue = max(1, (int)($_POST['period_value'] ?? 1));
        $startDate = $_POST['start_date'] ?: $today->format('Y-m-d');
        $endDate = $_POST['end_date'] ?: null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $categoryIds = array_filter(array_map('intval', $_POST['category_ids'] ?? []));
        $note = trim((string)($_POST['note'] ?? ''));

        if ($name === '') {
            $error = hb_t('Name is required.');
        } elseif ($amount === null || $amount <= 0) {
            $error = hb_t('Amount is invalid.');
        } elseif (!in_array($periodUnit, ['day', 'week', 'month', 'year'], true)) {
            $error = hb_t('Invalid interval.');
        } elseif (!$categoryIds) {
            $error = hb_t('At least one category is required.');
        }

        if ($error === null) {
            if ($action === 'store_budget') {
                $stmt = $pdo->prepare(
                    'insert into budgets (household_id, name, amount_cents, period_unit, period_value, start_date, end_date, is_active, note)
                     values (:hid, :name, :amount, :unit, :val, :start, :end, :active, :note)
                     returning id'
                );
                $stmt->execute([
                    'hid' => $household['id'],
                    'name' => $name,
                    'amount' => $amount,
                    'unit' => $periodUnit,
                    'val' => $periodValue,
                    'start' => $startDate,
                    'end' => $endDate,
                    'active' => $isActive,
                    'note' => $note,
                ]);
                $id = (int)$stmt->fetchColumn();
            } else {
                $own = $pdo->prepare('select id from budgets where id = :id and household_id = :hid');
                $own->execute(['id' => $id, 'hid' => $household['id']]);
                if (!$own->fetch()) {
                    $error = hb_t('Budget not found.');
                } else {
                    $pdo->prepare(
                        'update budgets
                            set name = :name, amount_cents = :amount, period_unit = :unit, period_value = :val,
                                start_date = :start, end_date = :end, is_active = :active, note = :note, updated_at = now()
                          where id = :id and household_id = :hid'
                    )->execute([
                        'name' => $name,
                        'amount' => $amount,
                        'unit' => $periodUnit,
                        'val' => $periodValue,
                        'start' => $startDate,
                        'end' => $endDate,
                        'active' => $isActive,
                        'note' => $note,
                        'id' => $id,
                        'hid' => $household['id'],
                    ]);
                }
            }
        }

        if ($error === null && $id > 0) {
            $pdo->prepare('delete from budget_categories where budget_id = :bid')->execute(['bid' => $id]);
            $ins = $pdo->prepare('insert into budget_categories (budget_id, category_id) values (:bid, :cid)');
            foreach ($categoryIds as $cid) {
                $ins->execute(['bid' => $id, 'cid' => $cid]);
            }
            header('Location: ' . $periodSavedUrl);
            exit;
        }
    }

    if ($action === 'delete_budget') {
        $id = (int)($_POST['id'] ?? 0);
        $own = $pdo->prepare('select id from budgets where id = :id and household_id = :hid');
        $own->execute(['id' => $id, 'hid' => $household['id']]);
        if (!$own->fetch()) {
            $error = hb_t('Budget not found.');
        } else {
            $pdo->prepare('delete from budgets where id = :id and household_id = :hid')->execute(['id' => $id, 'hid' => $household['id']]);
            header('Location: ' . $periodDeletedUrl);
            exit;
        }
    }

    if ($action === 'store_saving' || $action === 'update_saving') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $amount = hb_parse_cents((string)($_POST['amount'] ?? '0'));
        $intervalUnit = $_POST['interval_unit'] !== '' ? $_POST['interval_unit'] : null;
        $intervalValue = max(1, (int)($_POST['interval_value'] ?? 1));
        $startDate = $_POST['start_date'] ?: $today->format('Y-m-d');
        $endDate = $_POST['end_date'] ?: null;
        $targetAmount = $_POST['target_amount'] !== '' ? hb_parse_cents((string)$_POST['target_amount']) : null;
        $targetDate = $_POST['target_date'] ?: null;
        $accountId = $_POST['account_id'] !== '' ? (int)$_POST['account_id'] : null;
        $note = trim((string)($_POST['note'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $isOptional = isset($_POST['is_optional']) ? 1 : 0;
        $categoryIds = array_filter(array_map('intval', $_POST['category_ids'] ?? []));

        if ($name === '') {
            $error = hb_t('Name is required.');
        } elseif ($intervalUnit !== null && !in_array($intervalUnit, ['day', 'week', 'month', 'year'], true)) {
            $error = hb_t('Invalid interval.');
        } elseif ($intervalUnit !== null && ($amount === null || $amount <= 0)) {
            $error = hb_t('Amount is invalid.');
        }

        if ($error === null) {
            if ($action === 'store_saving') {
                $stmt = $pdo->prepare(
                    'insert into savings_plans (household_id, name, amount_cents, interval_unit, interval_value, start_date, end_date, target_amount_cents, target_date, account_id, note, is_active, is_optional)
                     values (:hid, :name, :amount, :unit, :val, :start, :end, :target_amount, :target_date, :account_id, :note, :active, :optional)
                     returning id'
                );
                $stmt->execute([
                    'hid' => $household['id'],
                    'name' => $name,
                    'amount' => $amount ?? 0,
                    'unit' => $intervalUnit,
                    'val' => $intervalValue,
                    'start' => $startDate,
                    'end' => $endDate,
                    'target_amount' => $targetAmount,
                    'target_date' => $targetDate,
                    'account_id' => $accountId,
                    'note' => $note,
                    'active' => $isActive,
                    'optional' => $isOptional,
                ]);
                $id = (int)$stmt->fetchColumn();
            } else {
                $own = $pdo->prepare('select id from savings_plans where id = :id and household_id = :hid');
                $own->execute(['id' => $id, 'hid' => $household['id']]);
                if (!$own->fetch()) {
                    $error = hb_t('Saving plan not found.');
                } else {
                    $pdo->prepare(
                        'update savings_plans
                            set name = :name, amount_cents = :amount, interval_unit = :unit, interval_value = :val,
                                start_date = :start, end_date = :end, target_amount_cents = :target_amount, target_date = :target_date,
                                account_id = :account_id, note = :note, is_active = :active, is_optional = :optional, updated_at = now()
                          where id = :id and household_id = :hid'
                    )->execute([
                        'name' => $name,
                        'amount' => $amount ?? 0,
                        'unit' => $intervalUnit,
                        'val' => $intervalValue,
                        'start' => $startDate,
                        'end' => $endDate,
                        'target_amount' => $targetAmount,
                        'target_date' => $targetDate,
                        'account_id' => $accountId,
                        'note' => $note,
                        'active' => $isActive,
                        'optional' => $isOptional,
                        'id' => $id,
                        'hid' => $household['id'],
                    ]);
                }
            }
        }

        if ($error === null && $id > 0) {
            $pdo->prepare('delete from savings_plan_categories where savings_plan_id = :id')->execute(['id' => $id]);
            $ins = $pdo->prepare('insert into savings_plan_categories (savings_plan_id, category_id) values (:sid, :cid)');
            foreach ($categoryIds as $cid) {
                $ins->execute(['sid' => $id, 'cid' => $cid]);
            }
            header('Location: ' . $periodSavedUrl);
            exit;
        }
    }

    if ($action === 'delete_saving') {
        $id = (int)($_POST['id'] ?? 0);
        $own = $pdo->prepare('select id from savings_plans where id = :id and household_id = :hid');
        $own->execute(['id' => $id, 'hid' => $household['id']]);
        if (!$own->fetch()) {
            $error = hb_t('Saving plan not found.');
        } else {
            $pdo->prepare('delete from savings_plans where id = :id and household_id = :hid')->execute(['id' => $id, 'hid' => $household['id']]);
            header('Location: ' . $periodDeletedUrl);
            exit;
        }
    }
}

// Load budgets
$budgetStmt = $pdo->prepare(
    'select b.*, coalesce(array_agg(c.name) filter (where c.id is not null), array[]::text[]) as category_names,
            coalesce(array_agg(c.id) filter (where c.id is not null), array[]::bigint[]) as category_ids
       from budgets b
       left join budget_categories bc on bc.budget_id = b.id
       left join categories c on c.id = bc.category_id
      where b.household_id = :hid
      group by b.id
      order by b.name asc'
);
$budgetStmt->execute(['hid' => $household['id']]);
$budgets = $budgetStmt->fetchAll();

// Load savings plans
$savingStmt = $pdo->prepare(
    'select sp.*, a.name as account_name,
            coalesce(array_agg(c.name) filter (where c.id is not null), array[]::text[]) as category_names,
            coalesce(array_agg(c.id) filter (where c.id is not null), array[]::bigint[]) as category_ids
       from savings_plans sp
       left join accounts a on a.id = sp.account_id
       left join savings_plan_categories spc on spc.savings_plan_id = sp.id
       left join categories c on c.id = spc.category_id
      where sp.household_id = :hid
      group by sp.id, a.name
      order by sp.name asc'
);
$savingStmt->execute(['hid' => $household['id']]);
$savings = $savingStmt->fetchAll();

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Budgets & Savings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small">
        <?= htmlspecialchars(hb_t('Period:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <?= htmlspecialchars($periodLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    </div>
    <form method="get" action="/budgets.php" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch align-items-sm-center w-100 w-md-auto">
      <select class="form-select form-select-sm" name="range">
        <option value="" <?= $rangePreset === '' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Current period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
        <option value="period:1" <?= $rangePreset === 'period:1' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Current salary period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
        <option value="period:2" <?= $rangePreset === 'period:2' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 2 salary periods'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
        <option value="period:3" <?= $rangePreset === 'period:3' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 3 salary periods'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
      </select>
      <button type="submit" class="btn btn-sm btn-outline-secondary"><?= htmlspecialchars(hb_t('Change'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
    </form>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Deleted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="hb-whitebox mb-4">
    <div class="hb-whitebox-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h2 class="h6 mb-0"><?= htmlspecialchars(hb_t('Budgets'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <div class="text-muted small"><?= htmlspecialchars(hb_t('Track spend against category budgets.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
        <a class="btn btn-sm btn-primary" href="/budgets.php?action=new_budget<?= htmlspecialchars($periodUrlSuffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <?= htmlspecialchars(hb_t('New budget'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </a>
      </div>
      <div class="table-responsive d-none d-md-block">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Budget'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Spent'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Remaining'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th class="text-end"><?= htmlspecialchars(hb_t('Actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($budgets as $budget): ?>
              <?php
              $catNames = $budget['category_names'] ?? [];
              if (!is_array($catNames)) {
                $catNames = trim((string)$catNames, '{}');
                $catNames = $catNames !== '' ? array_map('trim', explode(',', $catNames)) : [];
              }
              $catIds = $budget['category_ids'] ?? [];
              if (!is_array($catIds)) {
                $catIds = trim((string)$catIds, '{}');
                $catIds = $catIds !== '' ? array_map('intval', explode(',', $catIds)) : [];
              } else {
                $catIds = array_map('intval', $catIds);
              }
              $spent = hb_budget_spent($pdo, $household['id'], $catIds, $periodStart, $periodEnd);
              $remaining = (int)$budget['amount_cents'] - $spent;
              $statusClass = $remaining < 0 ? 'text-danger' : 'text-success';
              $periodLabel = (int)$budget['period_value'] . ' ' . hb_t(ucfirst((string)$budget['period_unit']));
              ?>
              <tr>
                <td><?= htmlspecialchars($budget['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= empty($budget['is_active']) ? '<span class="badge bg-secondary ms-1">'.htmlspecialchars(hb_t('Inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>' : '' ?></td>
                <td>
                  <?php if (!empty($catNames)): ?>
                    <div class="small"><?= htmlspecialchars(implode(', ', $catNames), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($periodLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= hb_budget_amount((int)$budget['amount_cents']) ?></td>
                <td><?= hb_budget_amount($spent) ?></td>
                <td class="<?= $statusClass ?>"><?= hb_budget_amount($remaining) ?></td>
                <td class="text-end">
                  <div class="d-flex justify-content-end gap-2">
                    <a class="btn btn-sm btn-outline-primary" href="/budgets.php?action=edit_budget&id=<?= (int)$budget['id'] ?><?= htmlspecialchars($periodUrlSuffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                    <form method="post" action="/budgets.php" data-confirm="<?= htmlspecialchars(hb_t('Delete budget?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete_budget">
                      <input type="hidden" name="id" value="<?= (int)$budget['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$budgets): ?>
              <tr><td colspan="7" class="text-muted"><?= htmlspecialchars(hb_t('No budgets yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="d-md-none">
        <?php foreach ($budgets as $budget): ?>
          <?php
          $catNames = $budget['category_names'] ?? [];
          if (!is_array($catNames)) {
            $catNames = trim((string)$catNames, '{}');
            $catNames = $catNames !== '' ? array_map('trim', explode(',', $catNames)) : [];
          }
          $catIds = $budget['category_ids'] ?? [];
          if (!is_array($catIds)) {
            $catIds = trim((string)$catIds, '{}');
            $catIds = $catIds !== '' ? array_map('intval', explode(',', $catIds)) : [];
          } else {
            $catIds = array_map('intval', $catIds);
          }
          $spent = hb_budget_spent($pdo, $household['id'], $catIds, $periodStart, $periodEnd);
          $remaining = (int)$budget['amount_cents'] - $spent;
          $statusClass = $remaining < 0 ? 'text-danger' : 'text-success';
          $periodLabel = (int)$budget['period_value'] . ' ' . hb_t(ucfirst((string)$budget['period_unit']));
          ?>
          <div class="hb-mobile-card p-3">
            <div class="hb-mobile-card-row mb-2">
              <div class="fw-semibold">
                <?= htmlspecialchars($budget['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                <?= empty($budget['is_active']) ? '<span class="badge bg-secondary ms-1">'.htmlspecialchars(hb_t('Inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>' : '' ?>
              </div>
            </div>
            <div class="hb-mobile-meta">
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= htmlspecialchars(!empty($catNames) ? implode(', ', $catNames) : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= htmlspecialchars($periodLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Budget'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= hb_budget_amount((int)$budget['amount_cents']) ?></div>
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Spent'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= hb_budget_amount($spent) ?></div>
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Remaining'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><span class="<?= $statusClass ?>"><?= hb_budget_amount($remaining) ?></span></div>
            </div>
            <div class="hb-mobile-actions mt-3">
              <a class="btn btn-sm btn-outline-primary" href="/budgets.php?action=edit_budget&id=<?= (int)$budget['id'] ?><?= htmlspecialchars($periodUrlSuffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
              <form method="post" action="/budgets.php" data-confirm="<?= htmlspecialchars(hb_t('Delete budget?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="delete_budget">
                <input type="hidden" name="id" value="<?= (int)$budget['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="hb-whitebox">
    <div class="hb-whitebox-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h2 class="h6 mb-0"><?= htmlspecialchars(hb_t('Saving plans'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <div class="text-muted small"><?= htmlspecialchars(hb_t('Plan recurring or ad-hoc savings linked to categories and accounts.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
        <a class="btn btn-sm btn-primary" href="/budgets.php?action=new_saving<?= htmlspecialchars($periodUrlSuffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <?= htmlspecialchars(hb_t('New saving plan'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </a>
      </div>
      <div class="table-responsive d-none d-md-block">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Contribution'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Target'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(hb_t('Progress'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th class="text-end"><?= htmlspecialchars(hb_t('Actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($savings as $saving): ?>
              <?php
              $catNames = $saving['category_names'] ?? [];
              if (!is_array($catNames)) {
                $catNames = trim((string)$catNames, '{}');
                $catNames = $catNames !== '' ? array_map('trim', explode(',', $catNames)) : [];
              }
              $catIds = $saving['category_ids'] ?? [];
              if (!is_array($catIds)) {
                $catIds = trim((string)$catIds, '{}');
                $catIds = $catIds !== '' ? array_map('intval', explode(',', $catIds)) : [];
              } else {
                $catIds = array_map('intval', $catIds);
              }
              $periodSpent = hb_savings_contributions($pdo, $household['id'], $catIds, $saving['account_id'] ? (int)$saving['account_id'] : null, $periodStart, $periodEnd);
              $totalSpent = hb_savings_contributions($pdo, $household['id'], $catIds, $saving['account_id'] ? (int)$saving['account_id'] : null, null, null);
              $target = (int)($saving['target_amount_cents'] ?? 0);
              $progressPct = $target > 0 ? min(100, max(0, (int)round(($totalSpent / $target) * 100))) : null;
              $intervalLabel = $saving['interval_unit'] ? ((int)$saving['interval_value'] . ' ' . hb_t(ucfirst((string)$saving['interval_unit']))) : hb_t('Ad-hoc');
              ?>
              <tr>
                <td><?= htmlspecialchars($saving['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= empty($saving['is_active']) ? '<span class="badge bg-secondary ms-1">'.htmlspecialchars(hb_t('Inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>' : '' ?></td>
                <td><?= htmlspecialchars($saving['account_name'] ?? hb_t('Not set'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td>
                  <?php if (!empty($catNames)): ?>
                    <div class="small"><?= htmlspecialchars(implode(', ', $catNames), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($saving['interval_unit']): ?>
                    <?= hb_budget_amount((int)$saving['amount_cents']) ?> <span class="text-muted small">/ <?= htmlspecialchars($intervalLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php else: ?>
                    <?= htmlspecialchars(hb_t('Ad-hoc'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  <?php endif; ?>
                </td>
                <td><?= $target > 0 ? hb_budget_amount($target) : htmlspecialchars(hb_t('No target'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td>
                  <div class="small"><?= htmlspecialchars(hb_t('This period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= hb_budget_amount($periodSpent) ?></div>
                  <div class="small"><?= htmlspecialchars(hb_t('Total'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= hb_budget_amount($totalSpent) ?></div>
                  <?php if ($progressPct !== null): ?>
                    <div class="progress" style="height: 6px;">
                      <div class="progress-bar" role="progressbar" style="width: <?= $progressPct ?>%;" aria-valuenow="<?= $progressPct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="small text-muted"><?= $progressPct ?>%</div>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="d-flex justify-content-end gap-2">
                    <a class="btn btn-sm btn-outline-primary" href="/budgets.php?action=edit_saving&id=<?= (int)$saving['id'] ?><?= htmlspecialchars($periodUrlSuffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                    <form method="post" action="/budgets.php" data-confirm="<?= htmlspecialchars(hb_t('Delete saving plan?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete_saving">
                      <input type="hidden" name="id" value="<?= (int)$saving['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$savings): ?>
              <tr><td colspan="7" class="text-muted"><?= htmlspecialchars(hb_t('No saving plans yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="d-md-none">
        <?php foreach ($savings as $saving): ?>
          <?php
          $catNames = $saving['category_names'] ?? [];
          if (!is_array($catNames)) {
            $catNames = trim((string)$catNames, '{}');
            $catNames = $catNames !== '' ? array_map('trim', explode(',', $catNames)) : [];
          }
          $catIds = $saving['category_ids'] ?? [];
          if (!is_array($catIds)) {
            $catIds = trim((string)$catIds, '{}');
            $catIds = $catIds !== '' ? array_map('intval', explode(',', $catIds)) : [];
          } else {
            $catIds = array_map('intval', $catIds);
          }
          $periodSpent = hb_savings_contributions($pdo, $household['id'], $catIds, $saving['account_id'] ? (int)$saving['account_id'] : null, $periodStart, $periodEnd);
          $totalSpent = hb_savings_contributions($pdo, $household['id'], $catIds, $saving['account_id'] ? (int)$saving['account_id'] : null, null, null);
          $target = (int)($saving['target_amount_cents'] ?? 0);
          $progressPct = $target > 0 ? min(100, max(0, (int)round(($totalSpent / $target) * 100))) : null;
          $intervalLabel = $saving['interval_unit'] ? ((int)$saving['interval_value'] . ' ' . hb_t(ucfirst((string)$saving['interval_unit']))) : hb_t('Ad-hoc');
          ?>
          <div class="hb-mobile-card p-3">
            <div class="hb-mobile-card-row mb-2">
              <div class="fw-semibold">
                <?= htmlspecialchars($saving['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                <?= empty($saving['is_active']) ? '<span class="badge bg-secondary ms-1">'.htmlspecialchars(hb_t('Inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>' : '' ?>
              </div>
            </div>
            <div class="hb-mobile-meta">
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= htmlspecialchars($saving['account_name'] ?? hb_t('Not set'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= htmlspecialchars(!empty($catNames) ? implode(', ', $catNames) : '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Contribution'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= $saving['interval_unit'] ? hb_budget_amount((int)$saving['amount_cents']) . ' / ' . htmlspecialchars($intervalLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : htmlspecialchars(hb_t('Ad-hoc'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Target'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= $target > 0 ? hb_budget_amount($target) : htmlspecialchars(hb_t('No target'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('This period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= hb_budget_amount($periodSpent) ?></div>
              <div><span class="hb-mobile-meta-label"><?= htmlspecialchars(hb_t('Total'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?= hb_budget_amount($totalSpent) ?></div>
            </div>
            <?php if ($progressPct !== null): ?>
              <div class="mt-2">
                <div class="progress" style="height: 6px;">
                  <div class="progress-bar" role="progressbar" style="width: <?= $progressPct ?>%;" aria-valuenow="<?= $progressPct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div class="small text-muted mt-1"><?= $progressPct ?>%</div>
              </div>
            <?php endif; ?>
            <div class="hb-mobile-actions mt-3">
              <a class="btn btn-sm btn-outline-primary" href="/budgets.php?action=edit_saving&id=<?= (int)$saving['id'] ?><?= htmlspecialchars($periodUrlSuffix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(hb_t('Edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
              <form method="post" action="/budgets.php" data-confirm="<?= htmlspecialchars(hb_t('Delete saving plan?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="delete_saving">
                <input type="hidden" name="id" value="<?= (int)$saving['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><?= htmlspecialchars(hb_t('Delete'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php
// Modal handling
$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$editBudget = null;
$editSaving = null;
if ($action === 'edit_budget' && $editId) {
    $stmt = $pdo->prepare('select * from budgets where id = :id and household_id = :hid');
    $stmt->execute(['id' => $editId, 'hid' => $household['id']]);
    $editBudget = $stmt->fetch();
    if ($editBudget) {
        $catStmt = $pdo->prepare('select category_id from budget_categories where budget_id = :id');
        $catStmt->execute(['id' => $editId]);
        $editBudget['category_ids'] = array_map('intval', $catStmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
if ($action === 'edit_saving' && $editId) {
    $stmt = $pdo->prepare('select * from savings_plans where id = :id and household_id = :hid');
    $stmt->execute(['id' => $editId, 'hid' => $household['id']]);
    $editSaving = $stmt->fetch();
    if ($editSaving) {
        $catStmt = $pdo->prepare('select category_id from savings_plan_categories where savings_plan_id = :id');
        $catStmt->execute(['id' => $editId]);
        $editSaving['category_ids'] = array_map('intval', $catStmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

$budgetModalMode = $editBudget ? 'edit' : 'new';
$savingModalMode = $editSaving ? 'edit' : 'new';
$modalContent = '';
?>

<?php
$content = ob_get_clean();

$budgetFormData = $editBudget ?: [
    'period_value' => 1,
    'period_unit' => 'month',
    'start_date' => $today->format('Y-m-d'),
    'is_active' => 1,
    'category_ids' => [],
];
$savingFormData = $editSaving ?: [
    'interval_value' => 1,
    'start_date' => $today->format('Y-m-d'),
    'is_active' => 1,
    'is_optional' => 0,
    'category_ids' => [],
];

ob_start();
?>
    <form method="post" action="/budgets.php<?= htmlspecialchars($rangePreset !== '' ? '?range=' . urlencode($rangePreset) : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <input type="hidden" name="action" value="<?= $budgetModalMode === 'edit' ? 'update_budget' : 'store_budget' ?>">
      <?php if ($budgetModalMode === 'edit'): ?>
        <input type="hidden" name="id" value="<?= (int)$budgetFormData['id'] ?>">
      <?php endif; ?>
      <div class="mb-3">
        <label class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($budgetFormData['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      </div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label"><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="text" name="amount" class="form-control" required value="<?= isset($budgetFormData['amount_cents']) ? number_format(((int)$budgetFormData['amount_cents']) / 100, 2, ',', '.') : '' ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label"><?= htmlspecialchars(hb_t('Period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <div class="input-group">
            <input type="number" min="1" name="period_value" class="form-control" value="<?= htmlspecialchars((string)($budgetFormData['period_value'] ?? 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <select name="period_unit" class="form-select">
              <?php foreach (['day','week','month','year'] as $unit): ?>
                <option value="<?= $unit ?>" <?= (($budgetFormData['period_unit'] ?? 'month') === $unit) ? 'selected' : '' ?>><?= htmlspecialchars(hb_t(ucfirst($unit)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label"><?= htmlspecialchars(hb_t('Start date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($budgetFormData['start_date'] ?? $today->format('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
      </div>
      <div class="row g-3 mt-2">
        <div class="col-md-6">
          <label class="form-label"><?= htmlspecialchars(hb_t('End date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($budgetFormData['end_date'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select name="category_ids[]" class="form-select" multiple required size="6">
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat['id'] ?>" <?= in_array((int)$cat['id'], $budgetFormData['category_ids'] ?? [], true) ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= htmlspecialchars(hb_t(ucfirst($cat['type'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mt-3">
        <label class="form-label"><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <textarea name="note" class="form-control" rows="2"><?= htmlspecialchars($budgetFormData['note'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
      </div>
      <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" name="is_active" id="budget-active" <?= (!isset($budgetFormData['is_active']) || $budgetFormData['is_active']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="budget-active"><?= htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
      </div>
      <div class="mt-3 d-flex justify-content-end gap-2">
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($periodListUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        <button type="submit" class="btn btn-primary"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      </div>
    </form>
    <?php
$modalContent = ob_get_clean();
$modalTitle = $budgetModalMode === 'edit' ? hb_t('Edit budget') : hb_t('New budget');
$modalTitleEsc = htmlspecialchars($modalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$closeLabel = htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$content .= <<<HTML
    <div class="modal fade" id="budget-modal" tabindex="-1" aria-labelledby="budget-modal-label" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="budget-modal-label">{$modalTitleEsc}</h5>
            <a href="{$periodListUrl}" class="btn-close" aria-label="{$closeLabel}"></a>
          </div>
          <div class="modal-body">
            {$modalContent}
          </div>
        </div>
      </div>
    </div>
HTML;

ob_start();
?>
    <form method="post" action="/budgets.php<?= htmlspecialchars($rangePreset !== '' ? '?range=' . urlencode($rangePreset) : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <input type="hidden" name="action" value="<?= $savingModalMode === 'edit' ? 'update_saving' : 'store_saving' ?>">
      <?php if ($savingModalMode === 'edit'): ?>
        <input type="hidden" name="id" value="<?= (int)$savingFormData['id'] ?>">
      <?php endif; ?>
      <div class="mb-3">
        <label class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($savingFormData['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label"><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select name="account_id" class="form-select">
            <option value=""><?= htmlspecialchars(hb_t('Not set'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php foreach ($accounts as $acc): ?>
              <option value="<?= (int)$acc['id'] ?>" <?= ((int)($savingFormData['account_id'] ?? 0) === (int)$acc['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select name="category_ids[]" class="form-select" multiple size="6">
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat['id'] ?>" <?= in_array((int)$cat['id'], $savingFormData['category_ids'] ?? [], true) ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= htmlspecialchars(hb_t(ucfirst($cat['type'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row g-3 mt-2">
        <div class="col-md-4">
          <label class="form-label"><?= htmlspecialchars(hb_t('Contribution amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="text" name="amount" class="form-control" value="<?= isset($savingFormData['amount_cents']) ? number_format(((int)$savingFormData['amount_cents']) / 100, 2, ',', '.') : '' ?>">
          <div class="form-text"><?= htmlspecialchars(hb_t('Leave empty for ad-hoc saving.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
        <div class="col-md-4">
          <label class="form-label"><?= htmlspecialchars(hb_t('Interval'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <div class="input-group">
            <input type="number" min="1" name="interval_value" class="form-control" value="<?= htmlspecialchars((string)($savingFormData['interval_value'] ?? 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <select name="interval_unit" class="form-select">
              <option value=""><?= htmlspecialchars(hb_t('Ad-hoc'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php foreach (['day','week','month','year'] as $unit): ?>
                <option value="<?= $unit ?>" <?= (($savingFormData['interval_unit'] ?? '') === $unit) ? 'selected' : '' ?>><?= htmlspecialchars(hb_t(ucfirst($unit)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label"><?= htmlspecialchars(hb_t('Start date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($savingFormData['start_date'] ?? $today->format('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
      </div>
      <div class="row g-3 mt-2">
        <div class="col-md-4">
          <label class="form-label"><?= htmlspecialchars(hb_t('End date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($savingFormData['end_date'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label"><?= htmlspecialchars(hb_t('Target amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="text" name="target_amount" class="form-control" value="<?= isset($savingFormData['target_amount_cents']) && $savingFormData['target_amount_cents'] !== null ? number_format(((int)$savingFormData['target_amount_cents']) / 100, 2, ',', '.') : '' ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label"><?= htmlspecialchars(hb_t('Target date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="date" name="target_date" class="form-control" value="<?= htmlspecialchars($savingFormData['target_date'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
      </div>
      <div class="mt-3">
        <label class="form-label"><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <textarea name="note" class="form-control" rows="2"><?= htmlspecialchars($savingFormData['note'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
      </div>
      <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" name="is_active" id="saving-active" <?= (!isset($savingFormData['is_active']) || $savingFormData['is_active']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="saving-active"><?= htmlspecialchars(hb_t('Active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
      </div>
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" name="is_optional" id="saving-optional" <?= !empty($savingFormData['is_optional']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="saving-optional"><?= htmlspecialchars(hb_t('Optional'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
      </div>
      <div class="mt-3 d-flex justify-content-end gap-2">
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($periodListUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        <button type="submit" class="btn btn-primary"><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      </div>
    </form>
    <?php
$modalContent = ob_get_clean();
$modalTitle = $savingModalMode === 'edit' ? hb_t('Edit saving plan') : hb_t('New saving plan');
$modalTitleEsc = htmlspecialchars($modalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$closeLabel = htmlspecialchars(hb_t('Close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$content .= <<<HTML
    <div class="modal fade" id="saving-modal" tabindex="-1" aria-labelledby="saving-modal-label" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="saving-modal-label">{$modalTitleEsc}</h5>
            <a href="{$periodListUrl}" class="btn-close" aria-label="{$closeLabel}"></a>
          </div>
          <div class="modal-body">
            {$modalContent}
          </div>
        </div>
      </div>
    </div>
    HTML;

$extraScripts = '';
if (in_array($action, ['new_budget', 'edit_budget', 'new_saving', 'edit_saving'], true)) {
    $modalId = in_array($action, ['new_budget', 'edit_budget'], true) ? 'budget-modal' : 'saving-modal';
    $modalIdEsc = htmlspecialchars($modalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $extraScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('{$modalIdEsc}');
  if (modalEl && typeof bootstrap !== 'undefined') {
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
});
</script>
HTML;
}
require __DIR__ . '/../templates/layout.php';
