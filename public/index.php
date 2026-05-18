<?php declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = $_SESSION['is_admin'] ?? false;
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$layoutCompact = !$isLoggedIn;

if ($isLoggedIn && isset($_GET['quick'])) {
    header('Location: /transactions.php?action=new');
    exit;
}

if ($isLoggedIn) {
    $pdo = hb_get_pdo();
    $currentHousehold = hb_current_household($pdo);
    $currentUser = hb_current_user($pdo);
    if ($currentHousehold) {
        $today = new DateTimeImmutable('today');
        $rangePreset = (string)($_GET['range'] ?? '');
        if (in_array($rangePreset, ['7d', '14d', '2m', '3m'], true)) {
            $resolvedRange = hb_resolve_period_range($pdo, $currentHousehold, '', $today);
            $periodStart = $resolvedRange['start'];
            $periodEnd = $resolvedRange['end'];
            $periodLabel = $resolvedRange['label'];
            if ($rangePreset === '7d' || $rangePreset === '14d') {
                $days = $rangePreset === '7d' ? 7 : 14;
                $periodEnd = $today;
                $periodStart = $today->modify('-' . ($days - 1) . ' days');
                $periodLabel = $rangePreset === '7d' ? hb_t('Last 7 days') : hb_t('Last 14 days');
            } elseif ($rangePreset === '2m' || $rangePreset === '3m') {
                $months = $rangePreset === '2m' ? 2 : 3;
                $periodStart = $periodStart->modify('-' . ($months - 1) . ' months');
                $periodLabel = $rangePreset === '2m' ? hb_t('Last 2 months') : hb_t('Last 3 months');
            }
        } else {
            $resolvedRange = hb_resolve_period_range($pdo, $currentHousehold, $rangePreset, $today);
            $periodStart = $resolvedRange['start'];
            $periodEnd = $resolvedRange['end'];
            $periodLabel = $resolvedRange['label'];
            $rangePreset = $resolvedRange['preset'] === 'current_period' ? '' : $resolvedRange['preset'];
        }
        $planPostAction = '/plan.php' . ($rangePreset !== '' ? '?range=' . urlencode($rangePreset) : '');
        hb_ensure_month_plan($pdo, $currentHousehold, $periodStart, $periodEnd);
        hb_mark_overdue_plans($pdo, $currentHousehold['id']);

        $accountsStmt = $pdo->prepare('select * from accounts where household_id = :hid order by name asc');
        $accountsStmt->execute(['hid' => $currentHousehold['id']]);
        $accounts = $accountsStmt->fetchAll();

        $selectedAccountId = hb_selected_account_id();
        $forecastPreset = (string)($_GET['forecast'] ?? 'period');
        if (!in_array($forecastPreset, ['period', '3m', '6m', '12m'], true)) {
            $forecastPreset = 'period';
        }

        $balanceStmt = $pdo->prepare(
            'select a.id, a.name, a.opening_balance_cents, a.opening_balance_date,
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
                and (a.opening_balance_date is null or t.booking_date >= a.opening_balance_date)
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
                and (a.opening_balance_date is null or t.booking_date >= a.opening_balance_date)
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

        $expenseTotalStmt = $pdo->prepare(
            "select coalesce(sum(amount_cents), 0) as total
               from transactions
              where household_id = :hid
                and is_reviewed = true
                and type = 'expense'
                and booking_date between :start and :end"
        );
        $expenseTotalStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
        ]);
        $expenseTotal = (int)($expenseTotalStmt->fetchColumn() ?: 0);

        $categoryExpenseStmt = $pdo->prepare(
            "select coalesce(c.name, :unassigned) as label,
                    sum(case when ts.amount_cents is not null then ts.amount_cents else t.amount_cents end) as total
               from transactions t
               left join transaction_splits ts on ts.transaction_id = t.id
               left join categories c on c.id = coalesce(ts.category_id, t.category_id)
              where t.household_id = :hid
                and t.is_reviewed = true
                and t.type = 'expense'
                and t.booking_date between :start and :end
              group by label
              order by total desc"
        );
        $categoryExpenseStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
            'unassigned' => hb_t('Unassigned'),
        ]);
        $categoryExpenses = $categoryExpenseStmt->fetchAll();

        $payeeExpenseStmt = $pdo->prepare(
            "select coalesce(p.name, :unassigned) as label,
                    sum(t.amount_cents) as total
               from transactions t
               left join payees p on p.id = t.payee_id
              where t.household_id = :hid
                and t.is_reviewed = true
                and t.type = 'expense'
                and t.booking_date between :start and :end
              group by label
              order by total desc"
        );
        $payeeExpenseStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
            'unassigned' => hb_t('Unassigned'),
        ]);
        $payeeExpenses = $payeeExpenseStmt->fetchAll();

        $tagExpenseStmt = $pdo->prepare(
            "select tg.name as label, sum(t.amount_cents) as total
               from transactions t
               join transaction_tags tt on tt.transaction_id = t.id
               join tags tg on tg.id = tt.tag_id
              where t.household_id = :hid
                and t.is_reviewed = true
                and t.type = 'expense'
                and t.booking_date between :start and :end
              group by tg.name
              order by total desc"
        );
        $tagExpenseStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
        ]);
        $tagExpenses = $tagExpenseStmt->fetchAll();

        $buildBreakdown = function (array $rows, int $maxItems = 6): array {
            $labels = [];
            $values = [];
            $other = 0;
            foreach ($rows as $idx => $row) {
                $label = (string)($row['label'] ?? '');
                $value = (int)($row['total'] ?? 0);
                if ($value <= 0) {
                    continue;
                }
                if ($idx < $maxItems) {
                    $labels[] = $label;
                    $values[] = $value;
                } else {
                    $other += $value;
                }
            }
            if ($other > 0) {
                $labels[] = hb_t('Other');
                $values[] = $other;
            }
            return ['labels' => $labels, 'values' => $values];
        };
        $categoryBreakdown = $buildBreakdown($categoryExpenses);
        $tagBreakdown = $buildBreakdown($tagExpenses);
        $payeeBreakdown = $buildBreakdown($payeeExpenses);
        $hasExpenseCharts = !empty($categoryBreakdown['values']) || !empty($tagBreakdown['values']) || !empty($payeeBreakdown['values']);

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
              order by planned_date asc"
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

        $budgetStmt = $pdo->prepare(
            'select b.*
               from budgets b
              where b.household_id = :hid and b.is_active = true
              order by b.name asc'
        );
        $budgetStmt->execute(['hid' => $currentHousehold['id']]);
        $budgets = $budgetStmt->fetchAll();
        $budgetCategoryStmt = $pdo->prepare(
            'select bc.budget_id, bc.category_id
               from budget_categories bc
               join budgets b on b.id = bc.budget_id
              where b.household_id = :hid'
        );
        $budgetCategoryStmt->execute(['hid' => $currentHousehold['id']]);
        $budgetCategoryMap = [];
        foreach ($budgetCategoryStmt->fetchAll() as $budgetCategoryRow) {
            $budgetId = (int)$budgetCategoryRow['budget_id'];
            $budgetCategoryMap[$budgetId][] = (int)$budgetCategoryRow['category_id'];
        }
        $budgetRows = [];
        foreach ($budgets as $budget) {
            $catIds = $budgetCategoryMap[(int)$budget['id']] ?? [];
            $budgetAmount = (int)($budget['amount_cents'] ?? 0);
            $spent = hb_budget_spent($pdo, $currentHousehold['id'], $catIds, $periodStart, $periodEnd);
            $progressPctRaw = $budgetAmount > 0 ? (int)round(($spent / $budgetAmount) * 100) : 0;
            $progressPct = min(100, $progressPctRaw);
            $budgetRows[] = [
                'name' => (string)($budget['name'] ?? ''),
                'amount_cents' => $budgetAmount,
                'spent_cents' => $spent,
                'progress_pct' => $progressPct,
                'progress_pct_raw' => $progressPctRaw,
            ];
        }

        $perPage = 10;
        $upcomingPage = max(1, (int)($_GET['upcoming_page'] ?? 1));
        $openPage = max(1, (int)($_GET['open_page'] ?? 1));
        $upcomingTotal = count($upcomingPlans);
        $openTotal = count($openPlans);
        $upcomingPages = max(1, (int)ceil($upcomingTotal / $perPage));
        $openPages = max(1, (int)ceil($openTotal / $perPage));
        $upcomingPage = min($upcomingPage, $upcomingPages);
        $openPage = min($openPage, $openPages);
        $upcomingPlansPage = array_slice($upcomingPlans, ($upcomingPage - 1) * $perPage, $perPage);
        $openPlansPage = array_slice($openPlans, ($openPage - 1) * $perPage, $perPage);
        $buildPageUrl = function (array $overrides) use ($rangePreset): string {
            $params = array_filter([
                'range' => $rangePreset !== '' ? $rangePreset : null,
                'upcoming_page' => $overrides['upcoming_page'] ?? ($_GET['upcoming_page'] ?? null),
                'open_page' => $overrides['open_page'] ?? ($_GET['open_page'] ?? null),
            ], fn($value) => $value !== null && $value !== '');
            return '/?' . http_build_query($params);
        };

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
            $startBalance += hb_effective_opening_balance($acc, $periodStart) + (int)($startBalances[$accId] ?? 0);
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
                and (a.opening_balance_date is null or t.booking_date >= a.opening_balance_date)
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
            $startBalanceAll += hb_effective_opening_balance($acc, $periodStart) + (int)($startBalancesAll[$accId] ?? 0);
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
        $forecastNegativeDate = null;
        foreach ($chartLabels as $idx => $label) {
            if (($expectedBalances[$idx] ?? 0) < 0) {
                $forecastNegativeDate = $label;
                break;
            }
        }
        $openCount = 0;
        $overdueCount = 0;
        foreach ($openPlans as $plan) {
            if (($plan['status'] ?? '') === 'overdue') {
                $overdueCount++;
            } elseif (in_array($plan['status'] ?? '', ['open', 'suggested'], true)) {
                $openCount++;
            }
        }

        // recurring_rules structure differs by installation; keep KPI resilient.
        // In this schema recurring payload is JSON-based without amount/type columns.
        $freeIncome = 0;
        $freeFix = 0;
        $freePlannedStmt = $pdo->prepare(
            "select coalesce(sum(amount_cents), 0)
               from planned_payments
              where household_id = :hid
                and direction = 'expense'
                and status in ('open', 'overdue', 'suggested')
                and planned_date between :start and :end"
        );
        $freePlannedStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
        ]);
        $freePlanned = (int)($freePlannedStmt->fetchColumn() ?: 0);
        $freeVariableStmt = $pdo->prepare(
            "select coalesce(sum(amount_cents), 0)
               from transactions
              where household_id = :hid
                and type = 'expense'
                and is_reviewed = true
                and booking_date between :start and :end"
        );
        $freeVariableStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
        ]);
        $freeVariable = (int)($freeVariableStmt->fetchColumn() ?: 0);
        $freeThisMonth = $freeIncome - $freeFix - $freePlanned - $freeVariable;

        $openCaseStmt = $pdo->prepare(
            "select id, title, total_amount_cents, settled_amount_cents
               from open_cases
              where household_id = :hid
                and status in ('open', 'active', 'pending')
              order by id asc"
        );
        $openCaseStmt->execute(['hid' => $currentHousehold['id']]);
        $openCasesRows = $openCaseStmt->fetchAll();
        $debtOverview = [];
        foreach ($openCasesRows as $caseRow) {
            $caseId = (int)$caseRow['id'];
            $nextRateStmt = $pdo->prepare(
                "select planned_date, amount_cents
                   from planned_payments
                  where household_id = :hid
                    and status in ('open', 'overdue', 'suggested')
                    and note like :needle
                  order by planned_date asc
                  limit 1"
            );
            $nextRateStmt->execute([
                'hid' => $currentHousehold['id'],
                'needle' => '%open_case:#' . $caseId . '%',
            ]);
            $nextRate = $nextRateStmt->fetch();
            $totalCents = (int)($caseRow['total_amount_cents'] ?? 0);
            $paidCents = max(0, (int)($caseRow['settled_amount_cents'] ?? 0));
            $openCents = max(0, $totalCents - $paidCents);
            $progressRaw = $totalCents > 0 ? (int)round(($paidCents / $totalCents) * 100) : 0;
            $debtOverview[] = [
                'id' => $caseId,
                'title' => (string)($caseRow['title'] ?? ('Open Case #' . $caseId)),
                'open_cents' => $openCents,
                'progress' => max(0, min(100, $progressRaw)),
                'next_date' => $nextRate['planned_date'] ?? null,
                'next_amount_cents' => isset($nextRate['amount_cents']) ? (int)$nextRate['amount_cents'] : null,
            ];
        }
        usort($debtOverview, static function (array $a, array $b): int {
            $aDate = $a['next_date'] ?? '9999-12-31';
            $bDate = $b['next_date'] ?? '9999-12-31';
            return strcmp($aDate, $bDate);
        });

        $forecastWindowStart = $periodStart;
        $forecastWindowEnd = $periodEnd;
        $forecastWindowLabel = $periodLabel;
        if ($forecastPreset !== 'period') {
            $monthsAhead = ['3m' => 3, '6m' => 6, '12m' => 12][$forecastPreset] ?? 3;
            $forecastWindowStart = $today;
            $forecastWindowEnd = $today->modify('+' . $monthsAhead . ' months')->modify('-1 day');
            $forecastWindowLabel = $monthsAhead === 3
                ? hb_t('Next 3 months')
                : ($monthsAhead === 6 ? hb_t('Next 6 months') : hb_t('Next 12 months'));
        }

        $forecastTxStmt = $pdo->prepare(
            'select * from transactions
              where household_id = :hid
                and is_reviewed = true
                and booking_date between :start and :end'
        );
        $forecastTxStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $forecastWindowStart->format('Y-m-d'),
            'end' => $forecastWindowEnd->format('Y-m-d'),
        ]);
        $forecastTransactions = $forecastTxStmt->fetchAll();

        $forecastTxAllStmt = $pdo->prepare(
            'select * from transactions
              where household_id = :hid
                and booking_date between :start and :end'
        );
        $forecastTxAllStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $forecastWindowStart->format('Y-m-d'),
            'end' => $forecastWindowEnd->format('Y-m-d'),
        ]);
        $forecastTransactionsAll = $forecastTxAllStmt->fetchAll();

        $forecastPlanStmt = $pdo->prepare(
            "select * from planned_payments
              where household_id = :hid
                and planned_date between :start and :end"
        );
        $forecastPlanStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $forecastWindowStart->format('Y-m-d'),
            'end' => $forecastWindowEnd->format('Y-m-d'),
        ]);
        $forecastPlans = $forecastPlanStmt->fetchAll();

        $forecastStartBalanceStmt = $pdo->prepare(
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
                and (a.opening_balance_date is null or t.booking_date >= a.opening_balance_date)
              where a.household_id = :hid
              group by a.id'
        );
        $forecastStartBalanceStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $forecastWindowStart->format('Y-m-d'),
        ]);
        $forecastStartBalances = [];
        foreach ($forecastStartBalanceStmt->fetchAll() as $row) {
            $forecastStartBalances[(int)$row['id']] = (int)$row['net_cents'];
        }

        $forecastStartBalanceAllStmt = $pdo->prepare(
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
                and (a.opening_balance_date is null or t.booking_date >= a.opening_balance_date)
              where a.household_id = :hid
              group by a.id'
        );
        $forecastStartBalanceAllStmt->execute([
            'hid' => $currentHousehold['id'],
            'start' => $forecastWindowStart->format('Y-m-d'),
        ]);
        $forecastStartBalancesAll = [];
        foreach ($forecastStartBalanceAllStmt->fetchAll() as $row) {
            $forecastStartBalancesAll[(int)$row['id']] = (int)$row['net_cents'];
        }

        $forecastStartBalance = 0;
        $forecastStartBalanceAll = 0;
        foreach ($accounts as $acc) {
            $accId = (int)$acc['id'];
            if ($selectedAccountId !== null && $selectedAccountId !== $accId) {
                continue;
            }
            $forecastStartBalance += hb_effective_opening_balance($acc, $forecastWindowStart) + (int)($forecastStartBalances[$accId] ?? 0);
            $forecastStartBalanceAll += hb_effective_opening_balance($acc, $forecastWindowStart) + (int)($forecastStartBalancesAll[$accId] ?? 0);
        }

        $forecastDailyDelta = [];
        $forecastDailyExpenses = [];
        $cursor = $forecastWindowStart;
        while ($cursor <= $forecastWindowEnd) {
            $key = $cursor->format('Y-m-d');
            $forecastDailyDelta[$key] = 0;
            $forecastDailyExpenses[$key] = 0;
            $cursor = $cursor->modify('+1 day');
        }

        foreach ($forecastTransactions as $tx) {
            $dateKey = $tx['booking_date'];
            if (!isset($forecastDailyDelta[$dateKey])) {
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
                    $forecastDailyExpenses[$dateKey] += $amount;
                }
            }
            $forecastDailyDelta[$dateKey] += $delta;
        }

        foreach ($forecastPlans as $plan) {
            if (!in_array($plan['status'], ['open', 'overdue', 'suggested'], true)) {
                continue;
            }
            if ($selectedAccountId !== null && (int)$plan['account_id'] !== $selectedAccountId) {
                continue;
            }
            $dateKey = $plan['planned_date'];
            if (!isset($forecastDailyDelta[$dateKey])) {
                continue;
            }
            $amount = (int)$plan['amount_cents'];
            $delta = $plan['direction'] === 'income' ? $amount : -$amount;
            $forecastDailyDelta[$dateKey] += $delta;
            if ($plan['direction'] === 'expense') {
                $forecastDailyExpenses[$dateKey] += $amount;
            }
        }

        $forecastDailyDeltaAll = $forecastDailyDelta;
        $forecastDailyExpensesAll = $forecastDailyExpenses;
        foreach ($forecastTransactionsAll as $tx) {
            if ($tx['is_reviewed'] ?? false) {
                continue;
            }
            $dateKey = $tx['booking_date'];
            if (!isset($forecastDailyDeltaAll[$dateKey])) {
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
                    $forecastDailyExpensesAll[$dateKey] += $amount;
                }
            }
            $forecastDailyDeltaAll[$dateKey] += $delta;
        }

        $expectedBalances = [];
        $expenseCumulative = [];
        $running = $forecastStartBalance;
        $expenseSum = 0;
        foreach ($forecastDailyDelta as $dateKey => $delta) {
            $running += $delta;
            $expenseSum += $forecastDailyExpenses[$dateKey];
            $expectedBalances[] = $running;
            $expenseCumulative[] = $expenseSum;
        }

        $expectedBalancesAll = [];
        $expenseCumulativeAll = [];
        $runningAll = $forecastStartBalanceAll;
        $expenseSumAll = 0;
        foreach ($forecastDailyDeltaAll as $dateKey => $delta) {
            $runningAll += $delta;
            $expenseSumAll += $forecastDailyExpensesAll[$dateKey];
            $expectedBalancesAll[] = $runningAll;
            $expenseCumulativeAll[] = $expenseSumAll;
        }

        $chartLabels = array_keys($forecastDailyDelta);
        $forecastMin = min(array_merge($expectedBalances ?: [0], $expectedBalancesAll ?: [0], $expenseCumulative ?: [0]));
        $forecastMax = max(array_merge($expectedBalances ?: [0], $expectedBalancesAll ?: [0], $expenseCumulative ?: [0]));
        if ($forecastMin === $forecastMax) {
            $forecastMax = $forecastMin + 1;
        }
        $hasChartData = !empty($chartLabels);
        $forecastEnd = $expectedBalances ? $expectedBalances[array_key_last($expectedBalances)] : 0;
        $forecastNegativeDate = null;
        foreach ($chartLabels as $idx => $label) {
            if (($expectedBalances[$idx] ?? 0) < 0) {
                $forecastNegativeDate = $label;
                break;
            }
        }

        $futureTransactions = array_filter($forecastTransactions, function (array $tx) use ($today): bool {
            return $tx['booking_date'] >= $today->format('Y-m-d');
        });
        $futureTransactionsAll = array_filter($forecastTransactionsAll, function (array $tx) use ($today): bool {
            return $tx['booking_date'] >= $today->format('Y-m-d');
        });
        $futurePlans = array_filter($forecastPlans, function (array $plan) use ($today): bool {
            return $plan['planned_date'] >= $today->format('Y-m-d') && in_array($plan['status'], ['open', 'overdue', 'suggested'], true);
        });

        $accountForecasts = [];
        foreach ($accountBalances as $row) {
            $accId = (int)$row['id'];
            $currentBalance = hb_effective_opening_balance($row, $today) + (int)$row['net_cents'];
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
            $openingDate = $acc['opening_balance_date'] ?? null;
            foreach ($transactionsAll as $tx) {
                if ((int)$tx['account_id'] !== $accId && (int)$tx['transfer_from_account_id'] !== $accId && (int)$tx['transfer_to_account_id'] !== $accId) {
                    continue;
                }
                if ($openingDate && $tx['booking_date'] < $openingDate) {
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
            $currentAll = hb_effective_opening_balance($acc, $today) + $netAll;
            $deltaFutureAll = 0;
            foreach ($futureTransactionsAll as $tx) {
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

        $forecastEndLabel = $forecastPreset === 'period'
            ? hb_t('End of period')
            : $forecastWindowEnd->format('d.m.Y');
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
              <div class="text-muted small"><?= htmlspecialchars(hb_t('Forecast period end'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
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
        <div class="col-sm-6 col-xl-3">
          <div class="card shadow-sm h-100">
            <div class="card-body p-3">
              <div class="text-muted small"><?= htmlspecialchars(hb_t('Free this period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div class="fs-5 fw-semibold text-end <?= $freeThisMonth < 0 ? 'text-danger' : 'text-success' ?>"><?= hb_format_eur($freeThisMonth) ?></div>
              <div class="small text-muted">
                I <?= hb_format_eur($freeIncome) ?> · F <?= hb_format_eur($freeFix) ?> · P <?= hb_format_eur($freePlanned) ?> · V <?= hb_format_eur($freeVariable) ?>
              </div>
            </div>
          </div>
        </div>
      </div>
      <?php if ($forecastNegativeDate !== null): ?>
        <div class="alert alert-danger mb-3" role="alert">⚠️ <?= htmlspecialchars(hb_t('Forecast shows a negative balance on'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($forecastNegativeDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
              <div class="card-header bg-white d-flex flex-wrap gap-2 justify-content-between align-items-start">
                <div>
                  <div class="fw-semibold"><?= htmlspecialchars(hb_t('Period forecast'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  <div class="text-muted small"><?= htmlspecialchars($forecastWindowLabel ?? $periodLabel ?? $periodStart->format('F Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
              <form method="get" action="/" class="d-flex flex-wrap gap-2 align-items-center">
                <label class="form-label small mb-0"><?= htmlspecialchars(hb_t('Time range'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select form-select-sm w-auto" name="range" onchange="this.form.submit()">
                  <option value="" <?= ($rangePreset ?? '') === '' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Current period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="period:1" <?= ($rangePreset ?? '') === 'period:1' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Current salary period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="period:2" <?= ($rangePreset ?? '') === 'period:2' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 2 salary periods'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="period:3" <?= ($rangePreset ?? '') === 'period:3' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 3 salary periods'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="7d" <?= ($rangePreset ?? '') === '7d' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 7 days'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="14d" <?= ($rangePreset ?? '') === '14d' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 14 days'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="1m" <?= ($rangePreset ?? '') === '1m' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Current period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="2m" <?= ($rangePreset ?? '') === '2m' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 2 months'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="3m" <?= ($rangePreset ?? '') === '3m' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Last 3 months'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                </select>
                <label class="form-label small mb-0"><?= htmlspecialchars(hb_t('Forecast horizon'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select class="form-select form-select-sm w-auto" name="forecast" onchange="this.form.submit()">
                  <option value="period" <?= ($forecastPreset ?? 'period') === 'period' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Current period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="3m" <?= ($forecastPreset ?? '') === '3m' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Next 3 months'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="6m" <?= ($forecastPreset ?? '') === '6m' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Next 6 months'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <option value="12m" <?= ($forecastPreset ?? '') === '12m' ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Next 12 months'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
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
                    <canvas id="hb-forecast-chart" role="img" aria-label="<?= htmlspecialchars(hb_t('Period forecast'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></canvas>
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
              <?php if (!empty($hasChartData)): ?>
                <div class="border rounded-3 p-3 mt-3">
                  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars(hb_t('Can I afford this?'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <div class="small text-muted"><?= htmlspecialchars(hb_t('Simulates one additional expense against the forecast including open items.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    </div>
                  </div>
                  <div class="row g-2 align-items-end">
                    <div class="col-sm-5">
                      <label class="form-label small" for="hb-afford-amount"><?= htmlspecialchars(hb_t('Amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                      <input class="form-control" id="hb-afford-amount" type="text" inputmode="decimal" placeholder="<?= htmlspecialchars(hb_t('e.g. 250,00'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="col-sm-5">
                      <label class="form-label small" for="hb-afford-date"><?= htmlspecialchars(hb_t('Date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                      <input class="form-control" id="hb-afford-date" type="date" value="<?= htmlspecialchars($today->format('Y-m-d'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </div>
                    <div class="col-sm-2 d-grid">
                      <button class="btn btn-outline-primary" type="button" id="hb-afford-run"><?= htmlspecialchars(hb_t('Check'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                    </div>
                  </div>
                  <div class="alert alert-light border mt-3 mb-0 small" id="hb-afford-result">
                    <?= htmlspecialchars(hb_t('Enter an amount to simulate the impact on this period.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="d-grid gap-3">
            <div class="card shadow-sm">
              <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><?= htmlspecialchars(hb_t('Accounts'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="text-muted small"><?= htmlspecialchars($forecastEndLabel ?? hb_t('End of period'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
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

      <div class="row g-3 mb-3">
        <div class="col-lg-4">
          <div class="card shadow-sm h-100">
            <div class="card-header bg-white">
              <span class="fw-semibold"><?= htmlspecialchars(hb_t('Expenses by category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="card-body">
              <div style="height: 220px;">
                <?php if (!empty($categoryBreakdown['values'])): ?>
                  <canvas id="hb-expense-category" role="img" aria-label="<?= htmlspecialchars(hb_t('Expenses by category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></canvas>
                <?php else: ?>
                  <div class="d-flex align-items-center justify-content-center text-muted small h-100"><?= htmlspecialchars(hb_t('No expenses yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card shadow-sm h-100">
            <div class="card-header bg-white">
              <span class="fw-semibold"><?= htmlspecialchars(hb_t('Expenses by tag'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="card-body">
              <div style="height: 220px;">
                <?php if (!empty($tagBreakdown['values'])): ?>
                  <canvas id="hb-expense-tag" role="img" aria-label="<?= htmlspecialchars(hb_t('Expenses by tag'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></canvas>
                <?php else: ?>
                  <div class="d-flex align-items-center justify-content-center text-muted small h-100"><?= htmlspecialchars(hb_t('No expenses yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card shadow-sm h-100">
            <div class="card-header bg-white">
              <span class="fw-semibold"><?= htmlspecialchars(hb_t('Expenses by payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="card-body">
              <div style="height: 220px;">
                <?php if (!empty($payeeBreakdown['values'])): ?>
                  <canvas id="hb-expense-payee" role="img" aria-label="<?= htmlspecialchars(hb_t('Expenses by payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></canvas>
                <?php else: ?>
                  <div class="d-flex align-items-center justify-content-center text-muted small h-100"><?= htmlspecialchars(hb_t('No expenses yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
      <script type="application/json" id="hb-expense-breakdown">
        <?= json_encode([
            'category' => $categoryBreakdown ?? ['labels' => [], 'values' => []],
            'tag' => $tagBreakdown ?? ['labels' => [], 'values' => []],
            'payee' => $payeeBreakdown ?? ['labels' => [], 'values' => []],
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
      </script>

      <div class="row g-3 mb-3">
        <div class="col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-header bg-white">
              <span class="fw-semibold"><?= htmlspecialchars(hb_t('Budgets'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="card-body">
              <?php if (!empty($budgetRows)): ?>
                <?php foreach ($budgetRows as $budget): ?>
                  <?php
                  $rawPct = (int)($budget['progress_pct_raw'] ?? 0);
                  if ($rawPct <= 50) {
                      $barClass = 'bg-success';
                  } elseif ($rawPct <= 80) {
                      $barClass = 'bg-warning';
                  } elseif ($rawPct <= 100) {
                      $barClass = 'bg-danger';
                  } else {
                      $barClass = 'bg-purple';
                  }
                  ?>
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="fw-semibold"><?= htmlspecialchars($budget['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="text-muted small">
                      <?= number_format(($budget['spent_cents'] ?? 0) / 100, 2, ',', '.') ?> €
                      /
                      <?= number_format(($budget['amount_cents'] ?? 0) / 100, 2, ',', '.') ?> €
                      · <?= (int)($budget['progress_pct_raw'] ?? 0) ?>%
                    </div>
                  </div>
                  <div class="progress mb-3" style="height: 8px;">
                    <div class="progress-bar <?= $barClass ?>" role="progressbar" style="width: <?= (int)$budget['progress_pct'] ?>%;" aria-valuenow="<?= (int)($budget['progress_pct_raw'] ?? 0) ?>" aria-valuemin="0" aria-valuemax="100"></div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="text-muted small mb-2"><?= htmlspecialchars(hb_t('No budgets yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <a class="btn btn-sm btn-outline-primary" href="/budgets.php"><?= htmlspecialchars(hb_t('Budgets & Savings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <span class="fw-semibold"><?= htmlspecialchars(hb_t('Debt overview'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <a class="btn btn-sm btn-outline-secondary" href="/open_cases.php"><?= htmlspecialchars(hb_t('Open cases'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            </div>
            <div class="card-body">
              <?php if (!empty($debtOverview)): ?>
                <?php foreach ($debtOverview as $debt): ?>
                  <a class="text-decoration-none text-reset d-block border rounded p-2 mb-2" href="/open_cases.php">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <div class="fw-semibold"><?= htmlspecialchars($debt['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <div class="small text-muted"><?= hb_format_eur((int)$debt['open_cents']) ?></div>
                    </div>
                    <div class="progress mb-1" style="height:6px;">
                      <div class="progress-bar bg-info" role="progressbar" style="width: <?= (int)$debt['progress'] ?>%;" aria-valuenow="<?= (int)$debt['progress'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="small text-muted">
                      <?php if (!empty($debt['next_date']) && $debt['next_amount_cents'] !== null): ?>
                        <?= htmlspecialchars(hb_t('Next rate'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= htmlspecialchars((string)$debt['next_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= hb_format_eur((int)$debt['next_amount_cents']) ?>
                      <?php else: ?>
                        <?= htmlspecialchars(hb_t('No plan'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <?php endif; ?>
                    </div>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="text-muted small"><?= htmlspecialchars(hb_t('No open cases found.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <span class="fw-semibold"><?= htmlspecialchars(hb_t('Next payments'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <span class="text-muted small"><?= htmlspecialchars($upcomingPage . '/' . $upcomingPages, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="card-body">
              <?php foreach ($upcomingPlansPage as $plan): ?>
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
            <?php if ($upcomingPlans): ?>
              <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <a class="btn btn-sm btn-outline-secondary <?= $upcomingPage <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars($buildPageUrl(['upcoming_page' => max(1, $upcomingPage - 1)]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <?= htmlspecialchars(hb_t('Previous'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
                <span class="text-muted small"><?= htmlspecialchars(hb_t('Page'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string)$upcomingPage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <a class="btn btn-sm btn-outline-secondary <?= $upcomingPage >= $upcomingPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars($buildPageUrl(['upcoming_page' => min($upcomingPages, $upcomingPage + 1)]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <?= htmlspecialchars(hb_t('Next'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card shadow-sm h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
              <span class="fw-semibold"><?= htmlspecialchars(hb_t('Open & overdue'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <span class="text-muted small"><?= htmlspecialchars($openPage . '/' . $openPages, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="card-body">
              <?php foreach ($openPlansPage as $plan): ?>
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
                      <form method="post" action="<?= htmlspecialchars($planPostAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="mark_done">
                        <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                        <input type="hidden" name="row_version" value="<?= (int)$plan['row_version'] ?>">
                        <input type="hidden" name="redirect" value="/">
                        <button class="btn btn-sm btn-success" type="submit"><?= htmlspecialchars(hb_t('Done'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                      </form>
                      <?php if (!empty($plan['is_optional'])): ?>
                        <form method="post" action="<?= htmlspecialchars($planPostAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
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
            <?php if ($openPlans): ?>
              <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <a class="btn btn-sm btn-outline-secondary <?= $openPage <= 1 ? 'disabled' : '' ?>" href="<?= htmlspecialchars($buildPageUrl(['open_page' => max(1, $openPage - 1)]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <?= htmlspecialchars(hb_t('Previous'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
                <span class="text-muted small"><?= htmlspecialchars(hb_t('Page'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string)$openPage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <a class="btn btn-sm btn-outline-secondary <?= $openPage >= $openPages ? 'disabled' : '' ?>" href="<?= htmlspecialchars($buildPageUrl(['open_page' => min($openPages, $openPage + 1)]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <?= htmlspecialchars(hb_t('Next'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
              </div>
            <?php endif; ?>
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
    <div class="hb-auth-shell">
      <div class="hb-auth-orb hb-auth-orb-primary" aria-hidden="true"></div>
      <div class="hb-auth-orb hb-auth-orb-secondary" aria-hidden="true"></div>
      <div class="container">
        <div class="row g-4 align-items-center">
          <div class="col-lg-6">
            <div class="hb-auth-brand mb-3">
              <img class="hb-auth-logo" src="/assets/logo.svg" alt="BudgetLove logo">
              <div>
                <div class="fw-semibold">BudgetLove</div>
                <div class="text-muted small">Track. Plan. Control.</div>
              </div>
            </div>
            <h1 class="display-6 fw-semibold mb-3"><?= htmlspecialchars(hb_t('Keep your finances in view'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="text-muted mb-4"><?= htmlspecialchars(hb_t('Access is managed by your admin. Sign in to start planning your household finances.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-primary" href="/login"><?= htmlspecialchars(hb_t('Go to login'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
              <a class="btn btn-outline-secondary" href="mailto:hello@budgetlove.de"><?= htmlspecialchars(hb_t('Request access'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            </div>
          </div>
          <div class="col-lg-5 offset-lg-1">
            <div class="card hb-auth-card border-0">
              <div class="card-body p-4">
                <p class="text-muted small mb-2"><?= htmlspecialchars(hb_t('Household book'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <h2 class="h5 mb-3"><?= htmlspecialchars(hb_t('How it works'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                <ul class="mb-0">
                  <li><?= htmlspecialchars(hb_t('Sign in with your account credentials.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                  <li><?= htmlspecialchars(hb_t('Plan recurring payments to build your period plan.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                  <li><?= htmlspecialchars(hb_t('Import statements and finalize open bookings.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                  <li><?= htmlspecialchars(hb_t('Dashboards forecast balances and highlight risks.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$hasExpenseCharts = $hasExpenseCharts ?? false;
$extraScripts = '';
$extraStyles = '<style>.bg-purple{background-color:#6f42c1!important;}</style>';
if (!empty($hasChartData) || $hasExpenseCharts) {
    $extraScripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="/js/dashboard-chart.js"></script>
<script src="/js/dashboard-expense-charts.js"></script>
<script src="/js/affordability-helper.js"></script>
HTML;
}
if (!empty($extraStyles)) {
    $extraScripts = $extraStyles . $extraScripts;
}
require __DIR__ . '/../templates/layout.php';
