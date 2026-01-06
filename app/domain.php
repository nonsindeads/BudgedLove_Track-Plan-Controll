<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';

function hb_require_login(): void
{
    if (!isset($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }
}

function hb_current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function hb_current_user(PDO $pdo, bool $forceRefresh = false): ?array
{
    static $cache = null;
    if ($forceRefresh) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }
    $userId = hb_current_user_id();
    if ($userId < 1) {
        return null;
    }
    $stmt = $pdo->prepare(
        'select id, username, email, first_name, last_name, address,
                address_street, address_house_number, address_postal_code,
                address_city, address_state, address_extra, color_hex, language, row_version
           from users
          where id = :id'
    );
    $stmt->execute(['id' => $userId]);
    $cache = $stmt->fetch();
    return $cache ?: null;
}

function hb_build_address_string(
    string $street,
    string $houseNumber,
    string $postalCode,
    string $city,
    ?string $state,
    ?string $extra
): string {
    $line1 = trim($street . ' ' . $houseNumber);
    $line2 = trim($postalCode . ' ' . $city);
    $parts = array_filter([$line1, $line2, $state ? trim($state) : null, $extra ? trim($extra) : null]);
    return implode(', ', $parts);
}

function hb_ws_token(?array $user, ?array $household): string
{
    if (!$user || !$household) {
        return '';
    }
    $secret = getenv('HB_WS_SECRET');
    if (!$secret) {
        return '';
    }
    $payload = [
        'uid' => (int)$user['id'],
        'uname' => (string)($user['username'] ?? ''),
        'hid' => (int)$household['id'],
        'exp' => time() + 21600,
    ];
    $json = json_encode($payload);
    if ($json === false) {
        return '';
    }
    $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $b64, $secret);
    return $b64 . '.' . $sig;
}

function hb_build_conflict_rows(array $fields, array $current, array $attempted): array
{
    $rows = [];
    foreach ($fields as $field => $label) {
        $currentVal = $current[$field] ?? '';
        $attemptedVal = $attempted[$field] ?? '';
        if ((string)$currentVal === (string)$attemptedVal) {
            continue;
        }
        $rows[] = [
            'label' => $label,
            'current' => (string)$currentVal,
            'attempted' => (string)$attemptedVal,
        ];
    }
    return $rows;
}

function hb_render_conflict_table(array $rows): string
{
    if (!$rows) {
        return '';
    }
    $html = '<div class="card border-warning mb-3">';
    $html .= '<div class="card-body">';
    $html .= '<h3 class="h6 text-warning mb-2">' . htmlspecialchars(hb_t('Conflict detected'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
    $html .= '<p class="small text-muted mb-3">' . htmlspecialchars(hb_t('The data changed in the meantime. Review the differences and save again.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    $html .= '<div class="table-responsive">';
    $html .= '<table class="table table-sm align-middle mb-0">';
    $html .= '<thead><tr><th>' . htmlspecialchars(hb_t('Field'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Current'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Your input'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $label = htmlspecialchars($row['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $current = htmlspecialchars($row['current'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $attempted = htmlspecialchars($row['attempted'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html .= '<tr><td>' . $label . '</td><td>' . $current . '</td><td>' . $attempted . '</td></tr>';
    }
    $html .= '</tbody></table></div></div></div>';
    return $html;
}

function hb_set_current_household(int $householdId): void
{
    $_SESSION['household_id'] = $householdId;
}

function hb_current_household(PDO $pdo): ?array
{
    if (!isset($_SESSION['household_id'])) {
        return null;
    }
    $householdId = (int)$_SESSION['household_id'];
    if ($householdId < 1) {
        return null;
    }
    $stmt = $pdo->prepare(
        'select h.*, m.role as member_role
           from households h
           join household_members m on m.household_id = h.id
          where h.id = :id and m.user_id = :user_id and m.is_active = true'
    );
    $stmt->execute(['id' => $householdId, 'user_id' => hb_current_user_id()]);
    $row = $stmt->fetch();
    if (!$row) {
        unset($_SESSION['household_id']);
        return null;
    }
    return $row;
}

function hb_user_households(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'select h.*, m.role as member_role
           from households h
           join household_members m on m.household_id = h.id
          where m.user_id = :user_id and m.is_active = true
          order by h.created_at asc'
    );
    $stmt->execute(['user_id' => $userId]);
    return $stmt->fetchAll();
}

function hb_require_household(PDO $pdo): array
{
    $household = hb_current_household($pdo);
    if ($household === null) {
        header('Location: /household.php');
        exit;
    }
    return $household;
}

function hb_create_household(PDO $pdo, int $userId, string $name, string $currency, string $mode, ?int $salaryDay): int
{
    $pdo->beginTransaction();
    try {
        if (hb_households_support_creator($pdo)) {
            $insert = $pdo->prepare(
                'insert into households (name, currency_code, month_close_mode, salary_day, created_by_user_id)
                 values (:name, :currency, :mode, :salary, :creator)
                 returning id'
            );
            $insert->execute([
                'name' => $name,
                'currency' => strtoupper($currency ?: 'EUR'),
                'mode' => $mode,
                'salary' => $salaryDay,
                'creator' => $userId,
            ]);
        } else {
            $insert = $pdo->prepare(
                'insert into households (name, currency_code, month_close_mode, salary_day)
                 values (:name, :currency, :mode, :salary)
                 returning id'
            );
            $insert->execute([
                'name' => $name,
                'currency' => strtoupper($currency ?: 'EUR'),
                'mode' => $mode,
                'salary' => $salaryDay,
            ]);
        }
        $householdId = (int)$insert->fetchColumn();

        $member = $pdo->prepare(
            'insert into household_members (household_id, user_id, role, is_active)
             values (:household_id, :user_id, :role, true)'
        );
        $member->execute([
            'household_id' => $householdId,
            'user_id' => $userId,
            'role' => 'admin',
        ]);

        hb_copy_defaults($pdo, $householdId);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $householdId;
}

function hb_households_support_creator(PDO $pdo): bool
{
    static $cached = null;
    if (is_bool($cached)) {
        return $cached;
    }
    $stmt = $pdo->prepare(
        'select 1 from information_schema.columns where table_name = :table and column_name = :column limit 1'
    );
    $stmt->execute(['table' => 'households', 'column' => 'created_by_user_id']);
    $cached = (bool)$stmt->fetchColumn();
    return $cached;
}

function hb_is_household_creator(?array $household, ?int $userId = null): bool
{
    if (!$household) {
        return false;
    }
    $creatorId = (int)($household['created_by_user_id'] ?? 0);
    if ($creatorId < 1) {
        return false;
    }
    $userId = $userId ?? hb_current_user_id();
    return $userId > 0 && $creatorId === $userId;
}

function hb_copy_defaults(PDO $pdo, int $householdId): void
{
    // copy categories
    $cat = $pdo->prepare(
        "insert into categories (household_id, name, type, parent_id, sort_order, is_active, created_at, updated_at)
         select :hid, name, type, null, sort_order, is_active, now(), now()
           from categories
          where household_id is null"
    );
    $cat->execute(['hid' => $householdId]);

    // copy tags
    $tag = $pdo->prepare(
        "insert into tags (household_id, name, color, is_active, created_at, updated_at)
         select :hid, name, color, is_active, now(), now()
           from tags
          where household_id is null"
    );
    $tag->execute(['hid' => $householdId]);
}

function hb_allowed_account_types(): array
{
    return ['cash', 'checking', 'savings', 'credit_card', 'loan', 'asset', 'liability', 'other'];
}

function hb_account_type_label(string $type): string
{
    $map = [
        'cash' => hb_t('Cash'),
        'checking' => hb_t('Checking'),
        'savings' => hb_t('Savings'),
        'credit_card' => hb_t('Credit card'),
        'loan' => hb_t('Loan'),
        'asset' => hb_t('Asset'),
        'liability' => hb_t('Liability'),
        'other' => hb_t('Other'),
    ];
    return $map[$type] ?? $type;
}

function hb_allowed_month_close_modes(): array
{
    return ['first_of_month', 'salary_day'];
}

function hb_parse_cents(string $amount): ?int
{
    $clean = str_replace([' ', "\u{00A0}"], '', trim($amount));
    // remove thousand separators (.) then normalize decimal comma to dot
    $clean = str_replace('.', '', $clean);
    $clean = str_replace(',', '.', $clean);
    if ($clean === '' || !is_numeric($clean)) {
        return null;
    }
    return (int)round((float)$clean * 100);
}

function hb_is_household_admin(array $household): bool
{
    return ($household['member_role'] ?? '') === 'admin';
}

function hb_upload_base_dir(): string
{
    $base = getenv('HB_UPLOAD_DIR');
    if (!$base) {
        $base = '/srv/haushaltsbuch/uploads';
    }
    return rtrim($base, '/');
}

function hb_ensure_upload_dir(int $householdId): string
{
    $path = hb_upload_base_dir() . '/' . $householdId;
    if (!is_dir($path)) {
        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden: ' . $path);
        }
    }
    return $path;
}

function hb_household_period_bounds(array $household, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $mode = $household['month_close_mode'] ?? 'first_of_month';
    $salaryDay = (int)($household['salary_day'] ?? 0);
    if ($mode === 'salary_day' && $salaryDay > 0) {
        $year = (int)$today->format('Y');
        $month = (int)$today->format('m');
        $day = min($salaryDay, (int)$today->modify('last day of this month')->format('d'));
        $candidate = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day));
        if (!$candidate) {
            $candidate = $today->modify('first day of this month');
        }
        if ($candidate > $today) {
            $candidate = $candidate->modify('-1 month');
        }
        $start = $candidate;
        $end = $start->modify('+1 month')->modify('-1 day');
        return [$start, $end];
    }
    $start = $today->modify('first day of this month');
    $end = $today->modify('last day of this month');
    return [$start, $end];
}

function hb_effective_opening_balance(array $account, DateTimeImmutable $asOf): int
{
    $opening = (int)($account['opening_balance_cents'] ?? 0);
    $openingDate = $account['opening_balance_date'] ?? null;
    if ($openingDate && $openingDate > $asOf->format('Y-m-d')) {
        return 0;
    }
    return $opening;
}

function hb_recurring_occurrences(array $recurring, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): array
{
    $startDate = new DateTimeImmutable($recurring['start_date']);
    $endDate = null;
    if (!empty($recurring['end_date'])) {
        $endDate = DateTimeImmutable::createFromFormat('Y-m-d', (string)$recurring['end_date']);
    }
    if ($endDate && $endDate < $periodStart) {
        return [];
    }
    $effectiveEnd = $endDate && $endDate < $periodEnd ? $endDate : $periodEnd;
    if ($startDate > $effectiveEnd) {
        return [];
    }
    $unit = $recurring['interval_unit'];
    $interval = max(1, (int)$recurring['interval_value']);
    $current = $startDate;
    $anchorDay = (int)$startDate->format('d');
    $anchorMonth = (int)$startDate->format('m');

    while ($current < $periodStart) {
        $current = hb_next_occurrence($current, $unit, $interval, $anchorDay, $anchorMonth);
        if ($current > $effectiveEnd) {
            return [];
        }
    }

    $dates = [];
    while ($current <= $effectiveEnd) {
        $dates[] = $current;
        $current = hb_next_occurrence($current, $unit, $interval, $anchorDay, $anchorMonth);
    }
    return $dates;
}

function hb_amount_matches_recurring(array $recurring, int $amountCents): bool
{
    $mode = $recurring['amount_mode'] ?? 'fixed';
    $base = (int)($recurring['amount_cents'] ?? 0);
    $amount = (int)$amountCents;

    if ($mode === 'range') {
        $min = isset($recurring['min_amount_cents']) ? (int)$recurring['min_amount_cents'] : $base;
        $max = isset($recurring['max_amount_cents']) ? (int)$recurring['max_amount_cents'] : $base;
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        return $amount >= $min && $amount <= $max;
    }

    if ($mode === 'tolerance') {
        $toleranceCents = isset($recurring['tolerance_cents']) ? (int)$recurring['tolerance_cents'] : 0;
        $tolerancePct = isset($recurring['tolerance_pct']) ? (float)$recurring['tolerance_pct'] : 0.0;
        $pctWindow = (int)round($base * ($tolerancePct / 100));
        $window = max($toleranceCents, $pctWindow);
        return abs($amount - $base) <= $window;
    }

    return $amount === $base;
}

function hb_suggest_planned_payment(array $plans, array $recurringById, array $transaction, int $maxDateDiffDays = 5): ?array
{
    $dateValue = $transaction['booking_date'] ?? null;
    if (!$dateValue) {
        return null;
    }
    $txDate = DateTimeImmutable::createFromFormat('Y-m-d', (string)$dateValue);
    if (!$txDate) {
        return null;
    }
    $txAmount = (int)($transaction['amount_cents'] ?? 0);
    $txDirection = (string)($transaction['type'] ?? $transaction['direction'] ?? '');
    $txAccountId = $transaction['account_id'] ?? null;
    $txPayeeId = $transaction['payee_id'] ?? null;

    $best = null;
    $bestScore = PHP_INT_MAX;

    foreach ($plans as $plan) {
        if (($plan['direction'] ?? null) !== $txDirection) {
            continue;
        }
        if (!empty($plan['account_id']) && (int)$plan['account_id'] !== (int)$txAccountId) {
            continue;
        }
        if (!empty($plan['payee_id']) && (int)$plan['payee_id'] !== (int)$txPayeeId) {
            continue;
        }

        $planDate = DateTimeImmutable::createFromFormat('Y-m-d', (string)$plan['planned_date']);
        if (!$planDate) {
            continue;
        }
        $dateDiff = (int)$planDate->diff($txDate)->days;
        if ($dateDiff > $maxDateDiffDays) {
            continue;
        }

        $matchAmount = false;
        if (!empty($plan['recurring_payment_id']) && isset($recurringById[(int)$plan['recurring_payment_id']])) {
            $matchAmount = hb_amount_matches_recurring($recurringById[(int)$plan['recurring_payment_id']], $txAmount);
        } else {
            $matchAmount = (int)($plan['amount_cents'] ?? 0) === $txAmount;
        }
        if (!$matchAmount) {
            continue;
        }

        $score = $dateDiff;
        if (empty($plan['payee_id'])) {
            $score += 2;
        }
        if (empty($plan['account_id'])) {
            $score += 1;
        }
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = $plan;
        }
    }

    return $best;
}

function hb_next_occurrence(DateTimeImmutable $date, string $unit, int $interval, ?int $anchorDay = null, ?int $anchorMonth = null): DateTimeImmutable
{
    switch ($unit) {
        case 'day':
            return $date->modify('+' . $interval . ' day');
        case 'week':
            return $date->modify('+' . $interval . ' week');
        case 'year':
            $year = (int)$date->format('Y') + $interval;
            $month = $anchorMonth ?? (int)$date->format('m');
            $day = $anchorDay ?? (int)$date->format('d');
            $base = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $month));
            if (!$base) {
                return $date->modify('+' . $interval . ' year');
            }
            $lastDay = (int)$base->modify('last day of this month')->format('d');
            $day = min($day, $lastDay);
            return DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day)) ?: $base;
        case 'month':
        default:
            $base = $date->modify('first day of this month')->modify('+' . $interval . ' month');
            $year = (int)$base->format('Y');
            $month = (int)$base->format('m');
            $day = $anchorDay ?? (int)$date->format('d');
            $lastDay = (int)$base->modify('last day of this month')->format('d');
            $day = min($day, $lastDay);
            return DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day)) ?: $base;
    }
}

function hb_ensure_month_plan(PDO $pdo, array $household, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): void
{
    $recurringStmt = $pdo->prepare(
        'select * from recurring_payments
          where household_id = :hid and is_active = true'
    );
    $recurringStmt->execute(['hid' => $household['id']]);
    $recurrings = $recurringStmt->fetchAll();
    if (!$recurrings) {
        return;
    }

    $insertStmt = $pdo->prepare(
        'insert into planned_payments
            (household_id, recurring_payment_id, name, direction, amount_cents, planned_date, status, priority, is_optional,
             account_id, category_id, payee_id, note, savings_plan_id)
         select
            :hid, :rid, :name, :direction, :amount, :planned_date, :status, :priority, :is_optional,
            :account_id, :category_id, :payee_id, :note, :savings_plan_id
         where not exists (
            select 1 from planned_payments
             where (recurring_payment_id = :rid or savings_plan_id = :savings_plan_id) and planned_date = :planned_date
         )'
    );

    foreach ($recurrings as $recurring) {
        $occurrences = hb_recurring_occurrences($recurring, $periodStart, $periodEnd);
        foreach ($occurrences as $date) {
            $plannedDate = $date->format('Y-m-d');
            $insertStmt->execute([
                'hid' => $household['id'],
                'rid' => $recurring['id'],
                'savings_plan_id' => null,
                'name' => $recurring['name'],
                'direction' => $recurring['direction'],
                'amount' => $recurring['amount_cents'],
                'planned_date' => $plannedDate,
                'status' => 'open',
                'priority' => $recurring['priority'],
                'is_optional' => !empty($recurring['is_optional']) ? 1 : 0,
                'account_id' => $recurring['account_id'],
                'category_id' => $recurring['category_id'],
                'payee_id' => $recurring['payee_id'],
                'note' => $recurring['note'],
            ]);
        }
    }

    // Savings plans with intervals become planned expenses
    $savingsStmt = $pdo->prepare(
        'select sp.*, coalesce(array_agg(spc.category_id) filter (where spc.category_id is not null), array[]::bigint[]) as category_ids
           from savings_plans sp
           left join savings_plan_categories spc on spc.savings_plan_id = sp.id
          where sp.household_id = :hid
            and sp.is_active = true
            and sp.interval_unit is not null
            and sp.start_date <= :period_end
            and (sp.end_date is null or sp.end_date >= :period_start)
          group by sp.id'
    );
    $savingsStmt->execute([
        'hid' => $household['id'],
        'period_end' => $periodEnd->format('Y-m-d'),
        'period_start' => $periodStart->format('Y-m-d'),
    ]);
    $savingsPlans = $savingsStmt->fetchAll();

    foreach ($savingsPlans as $plan) {
        $occurrences = hb_recurring_occurrences($plan, $periodStart, $periodEnd);
        $categoryId = null;
        if (!empty($plan['category_ids']) && is_array($plan['category_ids'])) {
            $categoryId = (int)$plan['category_ids'][0];
        }
        foreach ($occurrences as $date) {
            $plannedDate = $date->format('Y-m-d');
            $insertStmt->execute([
                'hid' => $household['id'],
                'rid' => null,
                'savings_plan_id' => $plan['id'],
                'name' => $plan['name'],
                'direction' => 'expense',
                'amount' => $plan['amount_cents'],
                'planned_date' => $plannedDate,
                'status' => 'open',
                'priority' => $plan['priority'] ?? 3,
                'is_optional' => !empty($plan['is_optional']) ? 1 : 0,
                'account_id' => $plan['account_id'],
                'category_id' => $categoryId,
                'payee_id' => null,
                'note' => $plan['note'],
            ]);
        }
    }
}

function hb_mark_overdue_plans(PDO $pdo, int $householdId): void
{
    $pdo->prepare(
        "update planned_payments
            set status = 'overdue', updated_at = now()
          where household_id = :hid
            and status = 'open'
            and planned_date < current_date"
    )->execute(['hid' => $householdId]);
}

function hb_plan_status_label(string $status): string
{
    $map = [
        'open' => hb_t('Open'),
        'done' => hb_t('Done'),
        'skipped' => hb_t('Skipped'),
        'overdue' => hb_t('Overdue'),
        'suggested' => hb_t('Suggested'),
    ];
    return $map[$status] ?? $status;
}

function hb_selected_account_id(): ?int
{
    if (!isset($_SESSION['account_filter_id'])) {
        return null;
    }
    $value = (int)$_SESSION['account_filter_id'];
    return $value > 0 ? $value : null;
}

function hb_set_selected_account_id(?int $accountId): void
{
    if ($accountId === null || $accountId < 1) {
        unset($_SESSION['account_filter_id']);
        return;
    }
    $_SESSION['account_filter_id'] = $accountId;
}

function hb_is_period_closed(PDO $pdo, int $householdId, DateTimeImmutable $date): bool
{
    $stmt = $pdo->prepare(
        'select 1 from month_closures
          where household_id = :hid
            and :date between period_start and period_end'
    );
    $stmt->execute([
        'hid' => $householdId,
        'date' => $date->format('Y-m-d'),
    ]);
    return (bool)$stmt->fetchColumn();
}
