<?php declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = $_SESSION['is_admin'] ?? false;
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$layoutCompact = !$isLoggedIn;

if ($isLoggedIn) {
    $pdo = hb_get_pdo();
    $currentHousehold = hb_current_household($pdo);
    $currentUser = hb_current_user($pdo);
    if ($currentHousehold) {
        $today = new DateTimeImmutable('today');
        [$periodStart, $periodEnd] = hb_household_period_bounds($currentHousehold, $today);
        $rangePreset = (string)($_GET['range'] ?? '');
        $periodLabel = $periodStart->format('F Y');
        if (in_array($rangePreset, ['7d', '14d', '1m', '2m', '3m'], true)) {
            if ($rangePreset === '7d' || $rangePreset === '14d') {
                $days = $rangePreset === '7d' ? 7 : 14;
                $periodEnd = $today;
                $periodStart = $today->modify('-' . ($days - 1) . ' days');
                $periodLabel = $rangePreset === '7d' ? hb_t('Last 7 days') : hb_t('Last 14 days');
            } elseif ($rangePreset === '2m' || $rangePreset === '3m') {
                $months = $rangePreset === '2m' ? 2 : 3;
                $periodStart = $periodStart->modify('-' . ($months - 1) . ' months');
                $periodLabel = $rangePreset === '2m' ? hb_t('Last 2 months') : hb_t('Last 3 months');
            } else {
                $periodLabel = $periodStart->format('F Y');
            }
        }
        hb_ensure_month_plan($pdo, $currentHousehold, $periodStart, $periodEnd);
        hb_mark_overdue_plans($pdo, $currentHousehold['id']);

        $accountsStmt = $pdo->prepare('select * from accounts where household_id = :hid order by name asc');
        $accountsStmt->execute(['hid' => $currentHousehold['id']]);
        $accounts = $accountsStmt->fetchAll();

        $selectedAccountId = hb_selected_account_id();

        $balanceStmt = $pdo->prepare(
            'select a.id, a.name, a.opening_balance_cents,
                    coalesce(sum(case
                        when t.type = \'income\' and t.account_id = a.id then t.amount_cents
                        when t.type = \'expense\' and t.account_id = a.id then -t.amount_cents
                        when t.type = \'transfer\' and t.transfer_to_account_id = a.id then t.amount_cents
                        when t.type = \'transfer\' and t.transfer_from_account_id = a.id then -t.amount_cents
                        else 0 end), 0) as net_cents
               from accounts a
               left join transactions t
                 on t.household_id = a.household_id
                and t.booking_date <= :today
                and t.is_reviewed = true
              where a.household_id = :hid
              group by a.id
              order by a.name asc'
        );
        $balanceStmt->execute([
            'hid' => $currentHousehold['id'],
            'today' => $today->format('Y-m-d'),
        ]);
        $accountBalances = $balanceStmt->fetchAll();

        $startBalanceStmt = $pdo->prepare(
            'select a.id,
                    coalesce(sum(case
                        when t.type = \'income\' and t.account_id = a.id then t.amount_cents
                        when t.type = \'expense\' and t.account_id = a.id then -t.amount_cents
                        when t.type = \'transfer\' and t.transfer_to_account_id = a.id then t.amount_cents
                        when t.type = \'transfer\' and t.transfer_from_account_id = a.id then -t.amount_cents
                        else 0 end), 0) as net_cents
               from accounts a
               left join transactions t
                 on t.household_id = a.household_id
                and t.booking_date < :start
                and t.is_reviewed = true
              where a.household_id = :hid
              group by a.id'
        );
        $startBalanceStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
        ]);
        $startBalances = [];
        foreach ($startBalanceStmt->fetchAll() as $row) {
            $startBalances[(int)$row['id']] = (int)$row['net_cents'];
        }

        $txStmt = $pdo->prepare(
            'select * from transactions
              where household_id = :hid
                and is_reviewed = true
                and booking_date between :start and :end'
        );
        $txStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
        ]);
        $transactions = $txStmt->fetchAll();

        $txAllStmt = $pdo->prepare(
            'select * from transactions
              where household_id = :hid
                and booking_date between :start and :end'
        );
        $txAllStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
        ]);
        $transactionsAll = $txAllStmt->fetchAll();

        $planStmt = $pdo->prepare(
            "select * from planned_payments
              where household_id = :hid
                and planned_date between :start and :end"
        );
        $planStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
        ]);
        $planned = $planStmt->fetchAll();

        $upcomingStmt = $pdo->prepare(
            "select * from planned_payments
              where household_id = :hid
                and planned_date >= :today
                and status in ('open', 'overdue', 'suggested')
              order by planned_date asc
              limit 5"
        );
        $upcomingStmt->execute([
            'hid' => $currentHousehold['id'],
            'today' => $today->format('Y-m-d'),
        ]);
        $upcomingPlans = $upcomingStmt->fetchAll();

        $openStmt = $pdo->prepare(
            "select * from planned_payments
              where household_id = :hid
                and status in ('open', 'overdue', 'suggested')
              order by planned_date asc"
        );
        $openStmt->execute(['hid' => $currentHousehold['id']]);
        $openPlans = $openStmt->fetchAll();

        if ($selectedAccountId !== null) {
            $upcomingPlans = array_values(array_filter($upcomingPlans, function (array $plan) use ($selectedAccountId): bool {
                return empty($plan['account_id']) || (int)$plan['account_id'] === $selectedAccountId;
            }));
            $openPlans = array_values(array_filter($openPlans, function (array $plan) use ($selectedAccountId): bool {
                return empty($plan['account_id']) || (int)$plan['account_id'] === $selectedAccountId;
            }));
        }

        $accountById = [];
        foreach ($accounts as $acc) {
            $accountById[(int)$acc['id']] = $acc;
        }

        $startBalance = 0;
        foreach ($accounts as $acc) {
            $accId = (int)$acc['id'];
            if ($selectedAccountId !== null && $selectedAccountId !== $accId) {
                continue;
            }
            $startBalance += (int)$acc['opening_balance_cents'] + (int)($startBalances[$accId] ?? 0);
        }

        $startBalanceAllStmt = $pdo->prepare(
            'select a.id,
                    coalesce(sum(case
                        when t.type = \'income\' and t.account_id = a.id then t.amount_cents
                        when t.type = \'expense\' and t.account_id = a.id then -t.amount_cents
                        when t.type = \'transfer\' and t.transfer_to_account_id = a.id then t.amount_cents
                        when t.type = \'transfer\' and t.transfer_from_account_id = a.id then -t.amount_cents
                        else 0 end), 0) as net_cents
               from accounts a
               left join transactions t
                 on t.household_id = a.household_id
                and t.booking_date < :start
              where a.household_id = :hid
              group by a.id'
        );
        $startBalanceAllStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
        ]);
        $startBalancesAll = [];
        foreach ($startBalanceAllStmt->fetchAll() as $row) {
            $startBalancesAll[(int)$row['id']] = (int)$row['net_cents'];
        }
        $startBalanceAll = 0;
        foreach ($accounts as $acc) {
            $accId = (int)$acc['id'];
            if ($selectedAccountId !== null && $selectedAccountId !== $accId) {
                continue;
            }
            $startBalanceAll += (int)$acc['opening_balance_cents'] + (int)($startBalancesAll[$accId] ?? 0);
        }

        $dailyDelta = [];
        $dailyExpenses = [];
        $cursor = $periodStart;
        while ($cursor <= $periodEnd) {
            $key = $cursor->format('Y-m-d');
            $dailyDelta[$key] = 0;
            $dailyExpenses[$key] = 0;
            $cursor = $cursor->modify('+1 day');
        }

        foreach ($transactions as $tx) {
            $dateKey = $tx['booking_date'];
            if (!isset($dailyDelta[$dateKey])) {
                continue;
            }
            $amount = (int)$tx['amount_cents'];
            $delta = 0;
            if ($tx['type'] === 'transfer') {
                if ($selectedAccountId !== null) {
                    if ((int)$tx['transfer_to_account_id'] === $selectedAccountId) {
                        $delta = $amount;
                    } elseif ((int)$tx['transfer_from_account_id'] === $selectedAccountId) {
                        $delta = -$amount;
                    }
                }
            } else {
                if ($selectedAccountId !== null && (int)$tx['account_id'] !== $selectedAccountId) {
                    continue;
                }
                $delta = $tx['type'] === 'income' ? $amount : -$amount;
                if ($tx['type'] === 'expense') {
                    $dailyExpenses[$dateKey] += $amount;
                }
            }
            $dailyDelta[$dateKey] += $delta;
        }

        foreach ($planned as $plan) {
            if (!in_array($plan['status'], ['open', 'overdue', 'suggested'], true)) {
                continue;
            }
            if ($selectedAccountId !== null && (int)$plan['account_id'] !== $selectedAccountId) {
                continue;
            }
            $dateKey = $plan['planned_date'];
            if (!isset($dailyDelta[$dateKey])) {
                continue;
            }
            $amount = (int)$plan['amount_cents'];
            $delta = $plan['direction'] === 'income' ? $amount : -$amount;
            $dailyDelta[$dateKey] += $delta;
            if ($plan['direction'] === 'expense') {
                $dailyExpenses[$dateKey] += $amount;
            }
        }

        $dailyDeltaAll = $dailyDelta;
        $dailyExpensesAll = $dailyExpenses;
        foreach ($transactionsAll as $tx) {
            if ($tx['is_reviewed'] ?? false) {
                continue;
            }
            $dateKey = $tx['booking_date'];
            if (!isset($dailyDeltaAll[$dateKey])) {
                continue;
            }
            $amount = (int)$tx['amount_cents'];
            $delta = 0;
            if ($tx['type'] === 'transfer') {
                if ($selectedAccountId !== null) {
                    if ((int)$tx['transfer_to_account_id'] === $selectedAccountId) {
                        $delta = $amount;
                    } elseif ((int)$tx['transfer_from_account_id'] === $selectedAccountId) {
                        $delta = -$amount;
                    }
                }
            } else {
                if ($selectedAccountId !== null && (int)$tx['account_id'] !== $selectedAccountId) {
                    continue;
                }
                $delta = $tx['type'] === 'income' ? $amount : -$amount;
                if ($tx['type'] === 'expense') {
                    $dailyExpensesAll[$dateKey] += $amount;
                }
            }
            $dailyDeltaAll[$dateKey] += $delta;
        }

        $expectedBalances = [];
        $expenseCumulative = [];
        $running = $startBalance;
        $expenseSum = 0;
        foreach ($dailyDelta as $dateKey => $delta) {
            $running += $delta;
            $expenseSum += $dailyExpenses[$dateKey];
            $expectedBalances[] = $running;
            $expenseCumulative[] = $expenseSum;
        }
        $expectedBalancesAll = [];
        $expenseCumulativeAll = [];
        $runningAll = $startBalanceAll;
        $expenseSumAll = 0;
        foreach ($dailyDeltaAll as $dateKey => $delta) {
            $runningAll += $delta;
            $expenseSumAll += $dailyExpensesAll[$dateKey];
            $expectedBalancesAll[] = $runningAll;
            $expenseCumulativeAll[] = $expenseSumAll;
        }
        $chartLabels = array_keys($dailyDelta);
        $forecastMin = min(array_merge($expectedBalances ?: [0], $expectedBalancesAll ?: [0], $expenseCumulative ?: [0]));
        $forecastMax = max(array_merge($expectedBalances ?: [0], $expectedBalancesAll ?: [0], $expenseCumulative ?: [0]));
        if ($forecastMin === $forecastMax) {
            $forecastMax = $forecastMin + 1;
        }
        $hasChartData = !empty($chartLabels);
        $forecastEnd = $expectedBalances ? $expectedBalances[array_key_last($expectedBalances)] : 0;
        $openCount = 0;
        $overdueCount = 0;
        foreach ($openPlans as $plan) {
            if (($plan['status'] ?? '') === 'overdue') {
                $overdueCount++;
            } elseif (in_array($plan['status'] ?? '', ['open', 'suggested'], true)) {
                $openCount++;
            }
        }

        $futureTransactions = array_filter($transactions, function (array $tx) use ($today): bool {
            return $tx['booking_date'] >= $today->format('Y-m-d');
        });
        $futurePlans = array_filter($planned, function (array $plan) use ($today): bool {
            return $plan['planned_date'] >= $today->format('Y-m-d') && in_array($plan['status'], ['open', 'overdue', 'suggested'], true);
        });

        $accountForecasts = [];
        foreach ($accountBalances as $row) {
            $accId = (int)$row['id'];
            $currentBalance = (int)$row['opening_balance_cents'] + (int)$row['net_cents'];
            $deltaFuture = 0;
            foreach ($futureTransactions as $tx) {
                $amount = (int)$tx['amount_cents'];
                if ($tx['type'] === 'transfer') {
                    if ((int)$tx['transfer_to_account_id'] === $accId) {
                        $deltaFuture += $amount;
                    } elseif ((int)$tx['transfer_from_account_id'] === $accId) {
                        $deltaFuture -= $amount;
                    }
                } elseif ((int)$tx['account_id'] === $accId) {
                    $deltaFuture += $tx['type'] === 'income' ? $amount : -$amount;
                }
            }
            foreach ($futurePlans as $plan) {
                if ((int)$plan['account_id'] !== $accId) {
                    continue;
                }
                $amount = (int)$plan['amount_cents'];
                $deltaFuture += $plan['direction'] === 'income' ? $amount : -$amount;
            }
            $accountForecasts[$accId] = [
                'current' => $currentBalance,
                'end' => $currentBalance + $deltaFuture,
            ];
        }

        $accountForecastsAll = [];
        foreach ($accounts as $acc) {
            $accId = (int)$acc['id'];
            $netAll = 0;
            foreach ($transactionsAll as $tx) {
                if ((int)$tx['account_id'] !== $accId && (int)$tx['transfer_from_account_id'] !== $accId && (int)$tx['transfer_to_account_id'] !== $accId) {
                    continue;
                }
                $amount = (int)$tx['amount_cents'];
                if ($tx['type'] === 'transfer') {
                    if ((int)$tx['transfer_to_account_id'] === $accId) {
                        $netAll += $amount;
                    } elseif ((int)$tx['transfer_from_account_id'] === $accId) {
                        $netAll -= $amount;
                    }
                } else {
                    $netAll += $tx['type'] === 'income' ? $amount : -$amount;
                }
            }
            $currentAll = (int)$acc['opening_balance_cents'] + $netAll;
            $deltaFutureAll = 0;
            foreach ($transactionsAll as $tx) {
                if ($tx['booking_date'] < $today->format('Y-m-d')) {
                    continue;
                }
                $amount = (int)$tx['amount_cents'];
                if ($tx['type'] === 'transfer') {
                    if ((int)$tx['transfer_to_account_id'] === $accId) {
                        $deltaFutureAll += $amount;
                    } elseif ((int)$tx['transfer_from_account_id'] === $accId) {
                        $deltaFutureAll -= $amount;
                    }
                } elseif ((int)$tx['account_id'] === $accId) {
                    $deltaFutureAll += $tx['type'] === 'income' ? $amount : -$amount;
                }
            }
            foreach ($futurePlans as $plan) {
                if ((int)$plan['account_id'] !== $accId) {
                    continue;
                }
                $amount = (int)$plan['amount_cents'];
                $deltaFutureAll += $plan['direction'] === 'income' ? $amount : -$amount;
            }
            $accountForecastsAll[$accId] = [
                'current' => $currentAll,
                'end' => $currentAll + $deltaFutureAll,
            ];
        }
    }
}

function hb_format_eur(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' €';
}

function hb_svg_points(array $values, float $min, float $max, int $width, int $height): string
{
    $count = count($values);
    if ($count === 0) {
        return '';
    }
    $range = $max - $min;
    $points = [];
    foreach ($values as $idx => $value) {
        $x = $count === 1 ? 0 : ($idx / ($count - 1)) * $width;
        $y = $height - (($value - $min) / $range) * $height;
        $points[] = round($x, 2) . ',' . round($y, 2);
    }
    return implode(' ', $points);
}

ob_start();
?>
<div class="container-fluid">
  <?php if ($isLoggedIn): ?>
    <?php if ($currentHousehold): ?>
      <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-3">
          <div class="card shadow-sm h-100">
            <div class="card-body p-3">
              <div class="text-muted small"><?= htmlspecialchars(hb_t('Starting balance'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div class="fs-5 fw-semibold text-end"><?= hb_format_eur($startBalance) ?></div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="card shadow-sm h-100">
            <div class="card-body p-3">
              <div class="text-muted small"><?= htmlspecialchars(hb_t('Forecast end'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div class="fs-5 fw-semibold text-end"><?= hb_format_eur($forecastEnd ?? 0) ?></div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="card shadow-sm h-100">
            <div class="card-body p-3">
              <div class="text-muted small"><?= htmlspecialchars(hb_t('Open'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div class="fs-5 fw-semibold text-end"><?= (int)($openCount ?? 0) ?></div>
            </div>
          </div>
        </div>
        <div class="col-sm-6 col-xl-3">
          <div class="card shadow-sm h-100">
            <div class="card-body p-3">
              <div class="text-muted small"><?= htmlspecialchars(hb_t('Overdue'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div class="fs-5 fw-semibold text-end text-danger"><?= (int)($overdueCount ?? 0) ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-lg-8">
          <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex flex-wrap gap-2 justify-content-between align-items-start">
              <div>
                <div class="fw-semibold"><?= htmlspecialchars(hb_t('Monthly forecast'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="text-muted small"><?= htmlspecialchars($periodLabel ?? $periodStart->format('F Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </div>
              <form method="get" action="/" class="d-flex flex-wrap gap-2 align-items-center">
                <label class="form-label small mb-0"><?= htmlspecialchars(hb_t('Time range'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select form-select-sm w-auto" name="range" onchange="this.form.submit()">
                  <option value=""><?= htmlspecialchars(hb_t('Current month'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="7d" <?= ($rangePreset ?? '') === '7d' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 7 days'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="14d" <?= ($rangePreset ?? '') === '14d' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 14 days'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="1m" <?= ($rangePreset ?? '') === '1m' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Current month'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="2m" <?= ($rangePreset ?? '') === '2m' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 2 months'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="3m" <?= ($rangePreset ?? '') === '3m' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 3 months'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                </select>
              </form>
            </div>
            <div class="card-body">
              <div class="small text-muted mb-2">
                <?= htmlspecialchars(hb_t('Lines show expected balance, forecast incl. open, and cumulative expenses.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </div>
              <div class="border rounded-3 p-3 bg-light-subtle">
                <?php if (!empty($hasChartData)): ?>
                  <div class="hb-forecast-chart">
                    <canvas id="hb-forecast-chart" role="img" aria-label="<?= htmlspecialchars(hb_t('Monthly forecast'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></canvas>
                  </div>
                  <script type="application/json" id="hb-forecast-data">
                    <?= json_encode([
                        'labels' => $chartLabels ?? [],
                        'expected_balance' => $expectedBalances ?? [],
                        'forecast_including_open' => $expectedBalancesAll ?? [],
                        'cumulative_expenses' => $expenseCumulative ?? [],
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                  </script>
                <?php else: ?>
                  <div class="d-flex flex-column align-items-center justify-content-center text-center gap-2" style="min-height: 220px;">
                    <div class="fw-semibold"><?= htmlspecialchars(hb_t('Forecast needs data.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="small text-muted"><?= htmlspecialchars(hb_t('Create an account or import transactions to populate the forecast.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                      <a class="btn btn-sm btn-primary" href="/accounts.php?action=new"><?= htmlspecialchars(hb_t('New account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                      <a class="btn btn-sm btn-outline-secondary" href="/import.php"><?= htmlspecialchars(hb_t('Start import'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="d-grid gap-3">
            <div class="card shadow-sm">
              <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><?= htmlspecialchars(hb_t('Accounts'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="text-muted small"><?= htmlspecialchars(hb_t('End of month'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </div>
              <div class="card-body">
                <?php foreach ($accountBalances as $acc): ?>
                  <?php
                  $accId = (int)$acc['id'];
                  $forecast = $accountForecasts[$accId] ?? ['current' => 0, 'end' => 0];
                  $statusClass = $forecast['end'] < 0 ? 'text-danger' : ($forecast['end'] < 10000 ? 'text-warning' : 'text-success');
                  ?>
                  <div class="border rounded-3 p-2 mb-2">
                    <div class="fw-semibold"><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="small text-muted text-end"><?= htmlspecialchars(hb_t('Current:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= hb_format_eur($forecast['current']) ?></div>
                    <div class="small text-end <?= $statusClass ?>"><?= htmlspecialchars(hb_t('Forecast:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= hb_format_eur($forecast['end']) ?></div>
                    <?php $forecastAll = $accountForecastsAll[$accId] ?? null; ?>
                    <?php if ($forecastAll): ?>
                      <div class="small text-end text-info"><?= htmlspecialchars(hb_t('Forecast incl. open:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= hb_format_eur($forecastAll['end']) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <?php if (!$accountBalances): ?>
                  <div class="text-muted small mb-2"><?= htmlspecialchars(hb_t('No accounts yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-sm btn-primary" href="/accounts.php?action=new"><?= htmlspecialchars(hb_t('New account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                    <a class="btn btn-sm btn-outline-secondary" href="/import.php"><?= htmlspecialchars(hb_t('Start import'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($isAdmin): ?>
              <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                  <span class="fw-semibold"><?= htmlspecialchars(hb_t('Open registrations'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-outline-primary"
                            hx-get="/admin.php?action=list"
                            hx-target="#pending-list"
                            hx-swap="innerHTML"
                            hx-indicator="#pending-spinner">
                      <?= htmlspecialchars(hb_t('Refresh'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </button>
                    <div id="pending-spinner" class="spinner-border spinner-border-sm text-secondary d-none" role="status"></div>
                  </div>
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
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <span class="fw-semibold"><?= htmlspecialchars(hb_t('Next payments'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="card-body">
              <?php foreach ($upcomingPlans as $plan): ?>
                <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($plan['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($plan['planned_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php if (empty($plan['account_id'])): ?>
                      <span class="badge bg-light text-dark border"><?= htmlspecialchars(hb_t('No account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="text-end">
                    <div class="fw-semibold"><?= hb_format_eur((int)$plan['amount_cents']) ?></div>
                    <span class="badge <?= $plan['is_optional'] ? 'bg-secondary' : 'bg-primary' ?>">
                      <?= htmlspecialchars($plan['is_optional'] ? hb_t('Optional') : hb_t('Required'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (!$upcomingPlans): ?>
                <div class="text-muted small mb-2"><?= htmlspecialchars(hb_t('No upcoming payments.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="d-flex flex-wrap gap-2">
                  <a class="btn btn-sm btn-primary" href="/recurring.php"><?= htmlspecialchars(hb_t('Add recurring payment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                  <a class="btn btn-sm btn-outline-secondary" href="/transactions.php?action=new"><?= htmlspecialchars(hb_t('Add transaction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <span class="fw-semibold"><?= htmlspecialchars(hb_t('Open & overdue'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="card-body">
              <?php foreach ($openPlans as $plan): ?>
                <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($plan['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($plan['planned_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php if (empty($plan['account_id'])): ?>
                      <span class="badge bg-light text-dark border"><?= htmlspecialchars(hb_t('No account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="text-end">
                    <div class="fw-semibold"><?= hb_format_eur((int)$plan['amount_cents']) ?></div>
                    <div class="d-flex flex-column flex-sm-row gap-1 justify-content-end">
                      <form method="post" action="/plan.php?month=<?= htmlspecialchars($periodStart->format('Y-m'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="mark_done">
                        <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                        <input type="hidden" name="row_version" value="<?= (int)$plan['row_version'] ?>">
                        <input type="hidden" name="redirect" value="/">
                        <button class="btn btn-sm btn-success" type="submit"><?= htmlspecialchars(hb_t('Done'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                      </form>
                      <?php if (!empty($plan['is_optional'])): ?>
                        <form method="post" action="/plan.php?month=<?= htmlspecialchars($periodStart->format('Y-m'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                          <input type="hidden" name="action" value="skip">
                          <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                          <input type="hidden" name="row_version" value="<?= (int)$plan['row_version'] ?>">
                          <input type="hidden" name="redirect" value="/">
                          <button class="btn btn-sm btn-outline-secondary" type="submit"><?= htmlspecialchars(hb_t('Skip'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (!$openPlans): ?>
                <div class="text-muted small mb-2"><?= htmlspecialchars(hb_t('No open payments.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="d-flex flex-wrap gap-2">
                  <a class="btn btn-sm btn-outline-secondary" href="/plan.php"><?= htmlspecialchars(hb_t('Review plan'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-warning">
        <?= htmlspecialchars(hb_t('You have not selected a household yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <a href="/household.php"><?= htmlspecialchars(hb_t('Select now'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="card shadow-sm border-0">
          <div class="card-body">
            <p class="text-muted small mb-1"><?= htmlspecialchars(hb_t('Household book'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <h1 class="h4 mb-2"><?= htmlspecialchars(hb_t('Keep your finances in view'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="text-muted"><?= htmlspecialchars(hb_t('Access is managed by your admin. Sign in to start planning your household finances.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-outline-primary" href="/login"><?= htmlspecialchars(hb_t('Go to login'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
          <div class="card bg-white border-0 shadow-sm h-100">
            <div class="card-body">
              <h2 class="h6"><?= htmlspecialchars(hb_t('How it works'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
              <ul class="mb-3">
                <li><?= htmlspecialchars(hb_t('Sign in with your account credentials.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(hb_t('Plan recurring payments to build your monthly plan.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(hb_t('Import statements and finalize open bookings.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(hb_t('Dashboards forecast balances and highlight risks.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
              </ul>
            </div>
          </div>
        </div>
      </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$extraScripts = '';
if (!empty($hasChartData)) {
    $extraScripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="/js/dashboard-chart.js"></script>
HTML;
}
require __DIR__ . '/../templates/layout.php';
