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
    if ($currentHousehold) {
        $today = new DateTimeImmutable('today');
        [$periodStart, $periodEnd] = hb_household_period_bounds($currentHousehold, $today);
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
        <div class="col-lg-8">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                  <p class="text-muted small mb-1">Monatlicher Forecast</p>
                  <h2 class="h5 mb-0"><?= htmlspecialchars($periodStart->format('F Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                </div>
                <div class="text-end">
                  <div class="small text-muted">Startsaldo</div>
                  <div class="fw-semibold"><?= hb_format_eur($startBalance) ?></div>
                </div>
              </div>
              <div class="border rounded-3 p-3 bg-light-subtle">
                <div class="hb-forecast-chart">
                  <canvas id="hb-forecast-chart" role="img" aria-label="Monatlicher Forecast"></canvas>
                </div>
              </div>
              <script type="application/json" id="hb-forecast-data">
                <?= htmlspecialchars(json_encode([
                    'labels' => $chartLabels ?? [],
                    'expected_balance' => $expectedBalances ?? [],
                    'forecast_including_open' => $expectedBalancesAll ?? [],
                    'cumulative_expenses' => $expenseCumulative ?? [],
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </script>
              <div class="d-flex gap-3 mt-2 small text-muted">
                <span><span class="badge bg-success me-1">&nbsp;</span> Erwarteter Kontostand</span>
                <span><span class="badge bg-info me-1">&nbsp;</span> Prognose inkl. offene</span>
                <span><span class="badge bg-danger me-1">&nbsp;</span> Kumulierte Ausgaben</span>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card shadow-sm">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h3 class="h6 mb-0">Konten</h3>
                <span class="text-muted small">Ende Monat</span>
              </div>
              <?php foreach ($accountBalances as $acc): ?>
                <?php
                $accId = (int)$acc['id'];
                $forecast = $accountForecasts[$accId] ?? ['current' => 0, 'end' => 0];
                $statusClass = $forecast['end'] < 0 ? 'text-danger' : ($forecast['end'] < 10000 ? 'text-warning' : 'text-success');
                ?>
                <div class="border rounded-3 p-2 mb-2">
                  <div class="fw-semibold"><?= htmlspecialchars($acc['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="small text-muted">Aktuell: <?= hb_format_eur($forecast['current']) ?></div>
                <div class="small <?= $statusClass ?>">Prognose: <?= hb_format_eur($forecast['end']) ?></div>
                <?php $forecastAll = $accountForecastsAll[$accId] ?? null; ?>
                <?php if ($forecastAll): ?>
                  <div class="small text-info">Prognose inkl. offene: <?= hb_format_eur($forecastAll['end']) ?></div>
                <?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php if (!$accountBalances): ?>
                <div class="text-muted small">Noch keine Konten vorhanden.</div>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($isAdmin): ?>
            <div class="card shadow-sm mt-3">
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
          <?php endif; ?>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <h3 class="h6 mb-3">Nächste Zahlungen</h3>
              <?php foreach ($upcomingPlans as $plan): ?>
                <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($plan['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($plan['planned_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php if (empty($plan['account_id'])): ?>
                      <span class="badge bg-light text-dark border">Ohne Konto</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-end">
                    <div><?= hb_format_eur((int)$plan['amount_cents']) ?></div>
                    <span class="badge <?= $plan['is_optional'] ? 'bg-secondary' : 'bg-primary' ?>">
                      <?= $plan['is_optional'] ? 'Optional' : 'Pflicht' ?>
                    </span>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (!$upcomingPlans): ?>
                <div class="text-muted small">Keine anstehenden Zahlungen.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <h3 class="h6 mb-3">Offen & Überfällig</h3>
              <?php foreach ($openPlans as $plan): ?>
                <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($plan['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($plan['planned_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php if (empty($plan['account_id'])): ?>
                      <span class="badge bg-light text-dark border">Ohne Konto</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-end">
                    <div><?= hb_format_eur((int)$plan['amount_cents']) ?></div>
                    <div class="d-flex flex-column flex-sm-row gap-1 justify-content-end">
                      <form method="post" action="/plan.php?month=<?= htmlspecialchars($periodStart->format('Y-m'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="mark_done">
                        <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                        <input type="hidden" name="row_version" value="<?= (int)$plan['row_version'] ?>">
                        <input type="hidden" name="redirect" value="/">
                        <button class="btn btn-sm btn-success" type="submit">Erledigt</button>
                      </form>
                      <?php if (!empty($plan['is_optional'])): ?>
                        <form method="post" action="/plan.php?month=<?= htmlspecialchars($periodStart->format('Y-m'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                          <input type="hidden" name="action" value="skip">
                          <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                          <input type="hidden" name="row_version" value="<?= (int)$plan['row_version'] ?>">
                          <input type="hidden" name="redirect" value="/">
                          <button class="btn btn-sm btn-outline-secondary" type="submit">Skip</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (!$openPlans): ?>
                <div class="text-muted small">Keine offenen Zahlungen.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-warning">Du hast noch keinen Haushalt ausgewählt. <a href="/household.php">Jetzt auswählen</a></div>
    <?php endif; ?>
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
$extraScripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="/js/dashboard-chart.js"></script>
HTML;
require __DIR__ . '/../templates/layout.php';
