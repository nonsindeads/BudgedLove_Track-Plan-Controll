<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

hb_require_login();
$pdo = hb_get_pdo();
$household = hb_require_household($pdo);
$currentHousehold = $household;
$currentUser = hb_current_user($pdo);
$pageTitle = 'Expense report';
$activeNav = 'reports';
$layoutCompact = false;
$breadcrumbs = [
    ['label' => 'Expense report', 'href' => '/reports.php'],
];

if (!function_exists('hb_format_eur')) {
    function hb_format_eur(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        return $sign . number_format(abs($cents) / 100, 2, ',', '.') . ' €';
    }
}

$today = new DateTimeImmutable('today');

$rangePreset = (string)($_GET['range'] ?? 'current_period');
$validPresets = ['current_period', 'period:1', 'previous_period', 'period:2', 'period:3', 'current_month', 'previous_month', 'last_3_months', 'year_to_date', 'custom'];
if (!in_array($rangePreset, $validPresets, true)) {
    $rangePreset = 'current_period';
}

$resolvedRange = hb_resolve_period_range($pdo, $household, $rangePreset, $today);
$periodStart = $resolvedRange['start'];
$periodEnd = $resolvedRange['end'];
$periodLabel = $resolvedRange['label'];
$rangePreset = $resolvedRange['preset'];
switch ($rangePreset) {
    case 'year_to_date':
        $periodStart = $today->modify('first day of January');
        $periodEnd = $today;
        $periodLabel = hb_period_label($periodStart, $periodEnd);
        break;
    case 'custom':
        $fromInput = (string)($_GET['from'] ?? '');
        $toInput = (string)($_GET['to'] ?? '');
        $fromParsed = $fromInput ? DateTimeImmutable::createFromFormat('Y-m-d', $fromInput) : false;
        $toParsed = $toInput ? DateTimeImmutable::createFromFormat('Y-m-d', $toInput) : false;
        if ($fromParsed) {
            $periodStart = $fromParsed;
        }
        if ($toParsed) {
            $periodEnd = $toParsed;
        }
        if ($periodStart > $periodEnd) {
            [$periodStart, $periodEnd] = [$periodEnd, $periodStart];
        }
        $periodLabel = hb_period_label($periodStart, $periodEnd);
        break;
    default:
        break;
}

$validTabs = ['categories', 'tags', 'payees'];
$activeTab = (string)($_GET['tab'] ?? 'categories');
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'categories';
}

$selectedAccountId = hb_selected_account_id();

$accountFilterSqlTx = '';
$accountFilterParams = [];
if ($selectedAccountId !== null) {
    $accountFilterSqlTx = ' and t.account_id = :account_id';
    $accountFilterParams['account_id'] = $selectedAccountId;
}

$dateParams = [
    'hid' => $household['id'],
    'start' => $periodStart->format('Y-m-d'),
    'end' => $periodEnd->format('Y-m-d'),
];
$baseParams = $dateParams + $accountFilterParams;

$totalStmt = $pdo->prepare(
    "select coalesce(sum(t.amount_cents), 0)
       from transactions t
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'expense'
        and t.booking_date between :start and :end" . $accountFilterSqlTx
);
$totalStmt->execute($baseParams);
$totalExpense = (int)$totalStmt->fetchColumn();

$incomeStmt = $pdo->prepare(
    "select coalesce(sum(t.amount_cents), 0)
       from transactions t
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'income'
        and t.booking_date between :start and :end" . $accountFilterSqlTx
);
$incomeStmt->execute($baseParams);
$totalIncome = (int)$incomeStmt->fetchColumn();

$txCountStmt = $pdo->prepare(
    "select count(*) from transactions t
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'expense'
        and t.booking_date between :start and :end" . $accountFilterSqlTx
);
$txCountStmt->execute($baseParams);
$txCount = (int)$txCountStmt->fetchColumn();

// Categories: use coalesce so splits are accounted for
$categoryStmt = $pdo->prepare(
    "select coalesce(c.id, 0) as id,
            coalesce(c.name, :unassigned) as label,
            sum(case when ts.amount_cents is not null then ts.amount_cents else t.amount_cents end) as total,
            count(distinct t.id) as tx_count
       from transactions t
       left join transaction_splits ts on ts.transaction_id = t.id
       left join categories c on c.id = coalesce(ts.category_id, t.category_id)
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'expense'
        and t.booking_date between :start and :end" . $accountFilterSqlTx . "
      group by coalesce(c.id, 0), coalesce(c.name, :unassigned)
      order by total desc"
);
$categoryStmt->execute($baseParams + ['unassigned' => hb_t('Unassigned')]);
$categoryRows = $categoryStmt->fetchAll();

$tagStmt = $pdo->prepare(
    "select tg.id as id, tg.name as label, tg.color as color,
            sum(t.amount_cents) as total,
            count(distinct t.id) as tx_count
       from transactions t
       join transaction_tags tt on tt.transaction_id = t.id
       join tags tg on tg.id = tt.tag_id
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'expense'
        and t.booking_date between :start and :end" . $accountFilterSqlTx . "
      group by tg.id, tg.name, tg.color
      order by total desc"
);
$tagStmt->execute($baseParams);
$tagRows = $tagStmt->fetchAll();

$untaggedStmt = $pdo->prepare(
    "select coalesce(sum(t.amount_cents), 0) as total, count(*) as tx_count
       from transactions t
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'expense'
        and t.booking_date between :start and :end" . $accountFilterSqlTx . "
        and not exists (select 1 from transaction_tags tt where tt.transaction_id = t.id)"
);
$untaggedStmt->execute($baseParams);
$untaggedRow = $untaggedStmt->fetch();
$untaggedTotal = (int)($untaggedRow['total'] ?? 0);
$untaggedCount = (int)($untaggedRow['tx_count'] ?? 0);

$payeeStmt = $pdo->prepare(
    "select coalesce(p.id, 0) as id,
            coalesce(p.name, :unassigned) as label,
            sum(t.amount_cents) as total,
            count(*) as tx_count
       from transactions t
       left join payees p on p.id = t.payee_id
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'expense'
        and t.booking_date between :start and :end" . $accountFilterSqlTx . "
      group by coalesce(p.id, 0), coalesce(p.name, :unassigned)
      order by total desc"
);
$payeeStmt->execute($baseParams + ['unassigned' => hb_t('Unassigned')]);
$payeeRows = $payeeStmt->fetchAll();

// Previous period (same length, immediately preceding)
$rangeDays = (int)$periodStart->diff($periodEnd)->days + 1;
$prevEnd = $periodStart->modify('-1 day');
$prevStart = $prevEnd->modify('-' . ($rangeDays - 1) . ' days');
$prevDateParams = [
    'hid' => $household['id'],
    'start' => $prevStart->format('Y-m-d'),
    'end' => $prevEnd->format('Y-m-d'),
];
$prevBaseParams = $prevDateParams + $accountFilterParams;

$prevTotalStmt = $pdo->prepare(
    "select coalesce(sum(t.amount_cents), 0)
       from transactions t
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'expense'
        and t.booking_date between :start and :end" . $accountFilterSqlTx
);
$prevTotalStmt->execute($prevBaseParams);
$prevTotalExpense = (int)$prevTotalStmt->fetchColumn();

$prevCategoryStmt = $pdo->prepare(
    "select coalesce(c.id, 0) as id,
            sum(case when ts.amount_cents is not null then ts.amount_cents else t.amount_cents end) as total
       from transactions t
       left join transaction_splits ts on ts.transaction_id = t.id
       left join categories c on c.id = coalesce(ts.category_id, t.category_id)
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'expense'
        and t.booking_date between :start and :end" . $accountFilterSqlTx . "
      group by coalesce(c.id, 0)"
);
$prevCategoryStmt->execute($prevBaseParams);
$prevCategoryMap = [];
foreach ($prevCategoryStmt->fetchAll() as $r) {
    $prevCategoryMap[(int)$r['id']] = (int)$r['total'];
}

$prevTagStmt = $pdo->prepare(
    "select tg.id as id, sum(t.amount_cents) as total
       from transactions t
       join transaction_tags tt on tt.transaction_id = t.id
       join tags tg on tg.id = tt.tag_id
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'expense'
        and t.booking_date between :start and :end" . $accountFilterSqlTx . "
      group by tg.id"
);
$prevTagStmt->execute($prevBaseParams);
$prevTagMap = [];
foreach ($prevTagStmt->fetchAll() as $r) {
    $prevTagMap[(int)$r['id']] = (int)$r['total'];
}

$prevPayeeStmt = $pdo->prepare(
    "select coalesce(t.payee_id, 0) as id, sum(t.amount_cents) as total
       from transactions t
      where t.household_id = :hid
        and t.is_reviewed = true
        and t.type = 'expense'
        and t.booking_date between :start and :end" . $accountFilterSqlTx . "
      group by coalesce(t.payee_id, 0)"
);
$prevPayeeStmt->execute($prevBaseParams);
$prevPayeeMap = [];
foreach ($prevPayeeStmt->fetchAll() as $r) {
    $prevPayeeMap[(int)$r['id']] = (int)$r['total'];
}

// Budget allocation per category, scaled to the report range
$budgetStmt = $pdo->prepare(
    "select bc.category_id,
            sum(
                round(
                    b.amount_cents::numeric * :range_days::numeric /
                    case b.period_unit
                      when 'day' then b.period_value
                      when 'week' then b.period_value * 7
                      when 'month' then b.period_value * 30
                      when 'year' then b.period_value * 365
                      else b.period_value * 30
                    end
                )
            )::int as budget_cents
       from budgets b
       join budget_categories bc on bc.budget_id = b.id
      where b.household_id = :hid
        and b.is_active = true
        and b.start_date <= :end
        and (b.end_date is null or b.end_date >= :start)
      group by bc.category_id"
);
$budgetStmt->execute([
    'hid' => $household['id'],
    'start' => $periodStart->format('Y-m-d'),
    'end' => $periodEnd->format('Y-m-d'),
    'range_days' => $rangeDays,
]);
$budgetMap = [];
foreach ($budgetStmt->fetchAll() as $r) {
    $budgetMap[(int)$r['category_id']] = (int)$r['budget_cents'];
}

$formatDelta = static function (int $current, ?int $previous): array {
    if ($previous === null || $previous === 0) {
        if ($current > 0 && ($previous === 0 || $previous === null)) {
            return ['label' => 'neu', 'class' => 'text-muted', 'arrow' => 'arrow-right'];
        }
        return ['label' => '', 'class' => '', 'arrow' => ''];
    }
    $diff = $current - $previous;
    $pct = ((float)$diff / $previous) * 100.0;
    if (abs($pct) < 0.5) {
        return ['label' => '±0 %', 'class' => 'text-muted', 'arrow' => 'dash'];
    }
    $sign = $diff >= 0 ? '+' : '−';
    $label = $sign . number_format(abs($pct), 1, ',', '.') . ' %';
    // for expenses: more is bad (red), less is good (green)
    $cls = $diff > 0 ? 'text-danger' : 'text-success';
    $arrow = $diff > 0 ? 'arrow-up-right' : 'arrow-down-right';
    return ['label' => $label, 'class' => $cls, 'arrow' => $arrow];
};

$budgetStatus = static function (int $spent, ?int $budget): array {
    if ($budget === null || $budget <= 0) {
        return ['ratio' => null, 'class' => '', 'label' => '—'];
    }
    $ratio = $spent / $budget;
    if ($ratio >= 1.0) {
        $cls = 'bg-danger';
    } elseif ($ratio >= 0.85) {
        $cls = 'bg-warning';
    } else {
        $cls = 'bg-success';
    }
    return [
        'ratio' => $ratio,
        'class' => $cls,
        'label' => number_format($ratio * 100, 0, ',', '.') . ' %',
    ];
};

// Drill-down
$detailType = (string)($_GET['detail'] ?? '');
$detailId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$detailRows = [];
$detailTitle = '';
$detailTotal = 0;
if ($detailType !== '' && $detailId !== null) {
    $where = [
        't.household_id = :hid',
        't.is_reviewed = true',
        "t.type = 'expense'",
        't.booking_date between :start and :end',
    ];
    $params = $dateParams;
    if ($selectedAccountId !== null) {
        $where[] = 't.account_id = :account_id';
        $params['account_id'] = $selectedAccountId;
    }
    if ($detailType === 'category') {
        if ($detailId === 0) {
            $where[] = '(coalesce(ts.category_id, t.category_id) is null)';
            $detailTitle = hb_t('Unassigned') . ' (' . hb_t('Categories') . ')';
        } else {
            $where[] = 'coalesce(ts.category_id, t.category_id) = :detail_id';
            $params['detail_id'] = $detailId;
            $nameStmt = $pdo->prepare('select name from categories where id = :id and household_id = :hid');
            $nameStmt->execute(['id' => $detailId, 'hid' => $household['id']]);
            $detailTitle = (string)($nameStmt->fetchColumn() ?: '');
        }
    } elseif ($detailType === 'tag') {
        if ($detailId === 0) {
            $where[] = 'not exists (select 1 from transaction_tags tt where tt.transaction_id = t.id)';
            $detailTitle = hb_t('Without tag');
        } else {
            $where[] = 'exists (select 1 from transaction_tags tt where tt.transaction_id = t.id and tt.tag_id = :detail_id)';
            $params['detail_id'] = $detailId;
            $nameStmt = $pdo->prepare('select name from tags where id = :id and household_id = :hid');
            $nameStmt->execute(['id' => $detailId, 'hid' => $household['id']]);
            $detailTitle = (string)($nameStmt->fetchColumn() ?: '');
        }
    } elseif ($detailType === 'payee') {
        if ($detailId === 0) {
            $where[] = 't.payee_id is null';
            $detailTitle = hb_t('Unassigned') . ' (' . hb_t('Payees') . ')';
        } else {
            $where[] = 't.payee_id = :detail_id';
            $params['detail_id'] = $detailId;
            $nameStmt = $pdo->prepare('select name from payees where id = :id and household_id = :hid');
            $nameStmt->execute(['id' => $detailId, 'hid' => $household['id']]);
            $detailTitle = (string)($nameStmt->fetchColumn() ?: '');
        }
    } else {
        $detailType = '';
    }

    if ($detailType !== '') {
        $whereSql = implode(' and ', $where);
        $sql = "select distinct t.id, t.booking_date, t.amount_cents, t.note,
                       a.name as account_name, c.name as category_name, p.name as payee_name
                  from transactions t
                  left join transaction_splits ts on ts.transaction_id = t.id
                  left join accounts a on a.id = t.account_id
                  left join categories c on c.id = coalesce(ts.category_id, t.category_id)
                  left join payees p on p.id = t.payee_id
                 where $whereSql
                 order by t.booking_date desc, t.id desc
                 limit 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $detailRows = $stmt->fetchAll();
        foreach ($detailRows as $row) {
            $detailTotal += (int)$row['amount_cents'];
        }
    }
}

$buildDetailUrl = static function (string $type, int $id) use ($rangePreset, $periodStart, $periodEnd, $activeTab): string {
    $params = [
        'tab' => $activeTab,
        'range' => $rangePreset,
        'detail' => $type,
        'id' => $id,
    ];
    if ($rangePreset === 'custom') {
        $params['from'] = $periodStart->format('Y-m-d');
        $params['to'] = $periodEnd->format('Y-m-d');
    }
    return '/reports.php?' . http_build_query($params);
};

$buildTabUrl = static function (string $tab) use ($rangePreset, $periodStart, $periodEnd): string {
    $params = ['tab' => $tab, 'range' => $rangePreset];
    if ($rangePreset === 'custom') {
        $params['from'] = $periodStart->format('Y-m-d');
        $params['to'] = $periodEnd->format('Y-m-d');
    }
    return '/reports.php?' . http_build_query($params);
};

$buildRangeUrl = static function (string $preset) use ($activeTab, $periodStart, $periodEnd): string {
    $params = ['tab' => $activeTab, 'range' => $preset];
    if ($preset === 'custom') {
        $params['from'] = $periodStart->format('Y-m-d');
        $params['to'] = $periodEnd->format('Y-m-d');
    }
    return '/reports.php?' . http_build_query($params);
};

$rangeButtons = [
    'current_period' => hb_t('Current period'),
    'previous_period' => hb_t('Previous period'),
    'period:2' => hb_t('Last 2 salary periods'),
    'period:3' => hb_t('Last 3 salary periods'),
    'year_to_date' => hb_t('Year to date'),
    'custom' => hb_t('Custom'),
];

$activeRows = [];
$activeKind = $activeTab;
if ($activeTab === 'categories') {
    $activeRows = $categoryRows;
} elseif ($activeTab === 'tags') {
    $activeRows = $tagRows;
} elseif ($activeTab === 'payees') {
    $activeRows = $payeeRows;
}

$maxValue = 0;
foreach ($activeRows as $row) {
    $maxValue = max($maxValue, (int)$row['total']);
}

ob_start();
?>
<div class="hb-reports">
  <div class="hb-whitebox mb-3">
    <div class="hb-whitebox-body">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
          <div class="text-muted small text-uppercase"><?= htmlspecialchars(hb_t('Expense report'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <div class="fw-semibold fs-5">
            <?= htmlspecialchars($periodLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </div>
        </div>
        <div class="hb-reports-totals d-flex flex-wrap gap-3">
          <div class="text-end">
            <div class="text-muted small"><?= htmlspecialchars(hb_t('Expenses'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="fs-5 fw-semibold text-danger">-<?= hb_format_eur($totalExpense) ?></div>
            <?php
              $totalDelta = $formatDelta($totalExpense, $prevTotalExpense);
              if ($totalDelta['label'] !== ''): ?>
              <div class="small <?= $totalDelta['class'] ?>" title="<?= htmlspecialchars(hb_t('vs. previous period') . ': -' . hb_format_eur($prevTotalExpense), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <i class="bi bi-<?= $totalDelta['arrow'] ?>"></i> <?= $totalDelta['label'] ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="text-end">
            <div class="text-muted small"><?= htmlspecialchars(hb_t('Income'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="fs-5 fw-semibold text-success">+<?= hb_format_eur($totalIncome) ?></div>
          </div>
          <div class="text-end">
            <div class="text-muted small"><?= htmlspecialchars(hb_t('Net'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="fs-5 fw-semibold"><?= hb_format_eur($totalIncome - $totalExpense) ?></div>
          </div>
          <div class="text-end">
            <div class="text-muted small"><?= htmlspecialchars(hb_t('Transactions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="fs-5 fw-semibold"><?= (int)$txCount ?></div>
          </div>
        </div>
      </div>

      <div class="hb-range-buttons mb-3">
        <?php foreach ($rangeButtons as $preset => $label): ?>
          <a class="btn btn-sm <?= $rangePreset === $preset ? 'btn-primary' : 'btn-outline-secondary' ?>"
             href="<?= htmlspecialchars($buildRangeUrl($preset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($rangePreset === 'custom'): ?>
        <form method="get" class="row g-2 align-items-end mb-2" action="/reports.php">
          <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="range" value="custom">
          <div class="col-12 col-sm-auto">
            <label class="form-label small mb-1" for="hb-report-from"><?= htmlspecialchars(hb_t('From'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="date" class="form-control form-control-sm" id="hb-report-from" name="from" value="<?= htmlspecialchars($periodStart->format('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          </div>
          <div class="col-12 col-sm-auto">
            <label class="form-label small mb-1" for="hb-report-to"><?= htmlspecialchars(hb_t('To'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="date" class="form-control form-control-sm" id="hb-report-to" name="to" value="<?= htmlspecialchars($periodEnd->format('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          </div>
          <div class="col-12 col-sm-auto">
            <button type="submit" class="btn btn-primary btn-sm w-100"><?= htmlspecialchars(hb_t('Apply'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </div>
        </form>
      <?php endif; ?>

      <ul class="nav nav-tabs hb-report-tabs">
        <li class="nav-item">
          <a class="nav-link <?= $activeTab === 'categories' ? 'active' : '' ?>" href="<?= htmlspecialchars($buildTabUrl('categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <i class="bi bi-diagram-3 me-1"></i><?= htmlspecialchars(hb_t('Categories'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab === 'tags' ? 'active' : '' ?>" href="<?= htmlspecialchars($buildTabUrl('tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <i class="bi bi-tags me-1"></i><?= htmlspecialchars(hb_t('Tags'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $activeTab === 'payees' ? 'active' : '' ?>" href="<?= htmlspecialchars($buildTabUrl('payees'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <i class="bi bi-people me-1"></i><?= htmlspecialchars(hb_t('Payees'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </a>
        </li>
      </ul>
    </div>
  </div>

  <div class="hb-whitebox">
    <div class="hb-whitebox-body">
      <?php if (!$activeRows && !($activeTab === 'tags' && $untaggedTotal > 0)): ?>
        <div class="text-muted small py-3 text-center"><?= htmlspecialchars(hb_t('No expenses in this period.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php else: ?>
        <div class="hb-report-list">
          <?php foreach ($activeRows as $row):
              $rowId = (int)($row['id'] ?? 0);
              $rowLabel = (string)($row['label'] ?? '');
              $rowTotal = (int)($row['total'] ?? 0);
              $rowCount = (int)($row['tx_count'] ?? 0);
              $share = $totalExpense > 0 ? ($rowTotal / $totalExpense) * 100 : 0;
              $bar = $maxValue > 0 ? ($rowTotal / $maxValue) * 100 : 0;
              $detailType = $activeTab === 'categories' ? 'category' : ($activeTab === 'tags' ? 'tag' : 'payee');
              $color = $activeTab === 'tags' ? trim((string)($row['color'] ?? '')) : '';
              $detailUrl = $buildDetailUrl($detailType, $rowId);

              $prevValue = null;
              if ($activeTab === 'categories') {
                  $prevValue = $prevCategoryMap[$rowId] ?? 0;
              } elseif ($activeTab === 'tags') {
                  $prevValue = $prevTagMap[$rowId] ?? 0;
              } elseif ($activeTab === 'payees') {
                  $prevValue = $prevPayeeMap[$rowId] ?? 0;
              }
              $delta = $formatDelta($rowTotal, $prevValue);

              $budget = null;
              $budgetInfo = ['ratio' => null, 'class' => '', 'label' => ''];
              if ($activeTab === 'categories' && $rowId > 0) {
                  $budget = $budgetMap[$rowId] ?? null;
                  $budgetInfo = $budgetStatus($rowTotal, $budget);
              }
              ?>
            <a class="hb-report-row<?= $activeTab === 'categories' ? ' hb-report-row-cat' : '' ?>" href="<?= htmlspecialchars($detailUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <div class="hb-report-row-label">
                <?php if ($color !== ''): ?>
                  <span class="hb-tag-dot" style="background:<?= htmlspecialchars($color, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>;"></span>
                <?php endif; ?>
                <span class="hb-report-row-name"><?= htmlspecialchars($rowLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="hb-report-row-count text-muted small">(<?= $rowCount ?>)</span>
              </div>
              <div class="hb-report-row-progress">
                <?php if ($budget !== null && $budget > 0): ?>
                  <div class="hb-report-row-bar">
                    <div class="hb-report-row-bar-fill <?= $budgetInfo['class'] ?>"
                         style="width: <?= number_format(max(0.0, min(100.0, ($rowTotal / $budget) * 100)), 2, '.', '') ?>%;"></div>
                  </div>
                  <div class="text-muted small text-end mt-1">
                    <?= hb_format_eur($rowTotal) ?> / <?= hb_format_eur($budget) ?>
                    <span class="<?= str_replace('bg-', 'text-', $budgetInfo['class']) ?> fw-semibold">&middot; <?= htmlspecialchars($budgetInfo['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </div>
                <?php else: ?>
                  <div class="hb-report-row-bar">
                    <div class="hb-report-row-bar-fill" style="width: <?= number_format(max(0.0, min(100.0, $bar)), 2, '.', '') ?>%;"></div>
                  </div>
                <?php endif; ?>
              </div>
              <div class="hb-report-row-meta">
                <div class="hb-report-row-amount">-<?= hb_format_eur($rowTotal) ?></div>
                <div class="hb-report-row-share text-muted small">
                  <?= number_format($share, 1, ',', '.') ?>&nbsp;%
                  <?php if ($delta['label'] !== ''): ?>
                    <span class="<?= $delta['class'] ?> ms-1" title="<?= htmlspecialchars(hb_t('vs. previous period') . ': -' . hb_format_eur((int)$prevValue), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      <i class="bi bi-<?= $delta['arrow'] ?>"></i> <?= htmlspecialchars($delta['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
              <i class="bi bi-chevron-right hb-report-row-chev text-muted"></i>
            </a>
          <?php endforeach; ?>

          <?php if ($activeTab === 'tags' && $untaggedTotal > 0):
              $share = $totalExpense > 0 ? ($untaggedTotal / $totalExpense) * 100 : 0;
              $bar = $maxValue > 0 ? ($untaggedTotal / $maxValue) * 100 : 0;
              $untaggedUrl = $buildDetailUrl('tag', 0);
              ?>
            <a class="hb-report-row hb-report-row-untagged" href="<?= htmlspecialchars($untaggedUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <div class="hb-report-row-label">
                <span class="hb-tag-dot"></span>
                <span class="hb-report-row-name fst-italic"><?= htmlspecialchars(hb_t('Without tag'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="hb-report-row-count text-muted small">(<?= (int)$untaggedCount ?>)</span>
              </div>
              <div class="hb-report-row-progress">
                <div class="hb-report-row-bar">
                  <div class="hb-report-row-bar-fill" style="width: <?= number_format(max(0.0, min(100.0, $bar)), 2, '.', '') ?>%;"></div>
                </div>
              </div>
              <div class="hb-report-row-meta">
                <div class="hb-report-row-amount">-<?= hb_format_eur($untaggedTotal) ?></div>
                <div class="hb-report-row-share text-muted small"><?= number_format($share, 1, ',', '.') ?>&nbsp;%</div>
              </div>
              <i class="bi bi-chevron-right hb-report-row-chev text-muted"></i>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($detailType !== '' && $detailRows): ?>
    <div class="hb-whitebox mt-3">
      <div class="hb-whitebox-header">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-muted small text-uppercase"><?= htmlspecialchars(hb_t('Details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <h2 class="h6 mb-0"><?= htmlspecialchars($detailTitle ?: hb_t('Details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          </div>
          <div class="text-end">
            <div class="text-muted small"><?= count($detailRows) ?> <?= htmlspecialchars(hb_t('transactions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <div class="fw-semibold text-danger">-<?= hb_format_eur($detailTotal) ?></div>
          </div>
        </div>
      </div>
      <div class="hb-whitebox-body">
        <div class="table-responsive d-none d-md-block">
          <table class="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th><?= htmlspecialchars(hb_t('Date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(hb_t('Account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(hb_t('Category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(hb_t('Payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(hb_t('Note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th class="text-end"><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($detailRows as $tx): ?>
                <tr>
                  <td><?= htmlspecialchars((string)$tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string)($tx['account_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string)($tx['category_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string)($tx['payee_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td class="text-truncate" style="max-width: 18rem;"><?= htmlspecialchars((string)($tx['note'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td class="text-end text-danger fw-semibold">-<?= hb_format_eur((int)$tx['amount_cents']) ?></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-secondary" href="/transactions.php?action=show&amp;id=<?= (int)$tx['id'] ?>"><i class="bi bi-arrow-up-right"></i></a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="d-md-none">
          <?php foreach ($detailRows as $tx): ?>
            <a class="hb-mobile-card d-block text-decoration-none text-body p-3 mb-2" href="/transactions.php?action=show&amp;id=<?= (int)$tx['id'] ?>">
              <div class="hb-mobile-card-row">
                <div>
                  <div class="fw-semibold"><?= htmlspecialchars((string)($tx['payee_name'] ?? $tx['note'] ?? hb_t('Transaction')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <div class="text-muted small">
                    <?= htmlspecialchars((string)$tx['booking_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    &middot; <?= htmlspecialchars((string)($tx['account_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </div>
                  <?php if (!empty($tx['category_name'])): ?>
                    <div class="text-muted small"><?= htmlspecialchars((string)$tx['category_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <?php endif; ?>
                </div>
                <div class="text-end text-danger fw-semibold">-<?= hb_format_eur((int)$tx['amount_cents']) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php elseif ($detailType !== ''): ?>
    <div class="alert alert-info mt-3 mb-0"><?= htmlspecialchars(hb_t('No transactions for this entry in the selected period.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$extraScripts = <<<HTML
<style>
.hb-report-tabs {
  margin-bottom: -1px;
  flex-wrap: nowrap;
  overflow-x: auto;
}
.hb-report-tabs .nav-link {
  white-space: nowrap;
  border-radius: 10px 10px 0 0;
}
.hb-range-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}
.hb-report-list {
  display: flex;
  flex-direction: column;
}
.hb-report-row {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) minmax(120px, 1fr) auto auto;
  gap: 0.75rem;
  align-items: center;
  padding: 0.65rem 0.5rem;
  border-bottom: 1px solid rgba(15, 23, 42, 0.06);
  text-decoration: none;
  color: inherit;
  transition: background-color 0.12s ease;
}
.hb-report-row:hover {
  background-color: rgba(13, 110, 253, 0.04);
}
.hb-report-row:last-child {
  border-bottom: none;
}
.hb-report-row-label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
}
.hb-report-row-name {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.hb-report-row-progress {
  min-width: 0;
}
.hb-report-row-bar {
  position: relative;
  height: 8px;
  border-radius: 999px;
  background-color: rgba(15, 23, 42, 0.06);
  overflow: hidden;
}
.hb-report-row-bar-fill {
  position: absolute;
  inset: 0 auto 0 0;
  background: linear-gradient(90deg, #0ea5e9, #1d4ed8);
  border-radius: 999px;
}
.hb-report-row-bar-fill.bg-success {
  background: linear-gradient(90deg, #22c55e, #16a34a) !important;
}
.hb-report-row-bar-fill.bg-warning {
  background: linear-gradient(90deg, #f59e0b, #d97706) !important;
}
.hb-report-row-bar-fill.bg-danger {
  background: linear-gradient(90deg, #ef4444, #b91c1c) !important;
}
.hb-report-row-untagged .hb-report-row-bar-fill {
  background: linear-gradient(90deg, #94a3b8, #475569);
}
.hb-report-row-meta {
  text-align: right;
  white-space: nowrap;
}
.hb-report-row-amount {
  font-weight: 600;
  color: #b91c1c;
}
.hb-report-row-chev {
  font-size: 0.9rem;
}
@media (max-width: 575.98px) {
  .hb-report-row {
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
      "label amount"
      "bar bar";
    row-gap: 0.4rem;
  }
  .hb-report-row-label { grid-area: label; }
  .hb-report-row-meta { grid-area: amount; }
  .hb-report-row-progress { grid-area: bar; }
  .hb-report-row-chev { display: none; }
}
</style>
HTML;
require __DIR__ . '/../templates/layout.php';
