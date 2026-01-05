<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

if (!hb_is_admin()) {
    http_response_code(403);
    echo render_alert(hb_t('Only admins can perform this action.'));
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$isHx = !empty($_SERVER['HTTP_HX_REQUEST']);
function hb_admin_validate_password(string $password): ?string
{
    if (strlen($password) < 12) {
        return hb_t('Password too short (minimum 12 characters).');
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return hb_t('Password needs at least one uppercase letter.');
    }
    if (!preg_match('/[a-z]/', $password)) {
        return hb_t('Password needs at least one lowercase letter.');
    }
    if (!preg_match('/\d/', $password)) {
        return hb_t('Password needs at least one number.');
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return hb_t('Password needs at least one symbol.');
    }
    return null;
}

function hb_admin_seed_demo_data(PDO $pdo, int $householdId, int $userId): void
{
    $householdStmt = $pdo->prepare('select currency_code from households where id = :id');
    $householdStmt->execute(['id' => $householdId]);
    $currency = (string)($householdStmt->fetchColumn() ?: 'EUR');
    $today = new DateTimeImmutable('today');
    $monthStart = $today->modify('first day of this month');
    $monthEnd = $today->modify('last day of this month');

    $accountsStmt = $pdo->prepare('select id, name, type from accounts where household_id = :hid order by created_at asc');
    $accountsStmt->execute(['hid' => $householdId]);
    $accounts = $accountsStmt->fetchAll();
    $accountMap = [];
    foreach ($accounts as $account) {
        $accountMap[$account['type']] = (int)$account['id'];
    }
    if (!$accounts) {
    $insertAccount = $pdo->prepare(
        'insert into accounts (household_id, name, type, currency_code, opening_balance_cents, is_archived)
         values (:hid, :name, :type, :currency, :opening, false)
         returning id'
    );
        $insertAccount->execute([
            'hid' => $householdId,
            'name' => 'Demo Checking',
            'type' => 'checking',
            'currency' => $currency,
            'opening' => 250000,
        ]);
        $accountMap['checking'] = (int)$insertAccount->fetchColumn();
        $insertAccount->execute([
            'hid' => $householdId,
            'name' => 'Demo Savings',
            'type' => 'savings',
            'currency' => $currency,
            'opening' => 100000,
        ]);
        $accountMap['savings'] = (int)$insertAccount->fetchColumn();
        $insertAccount->execute([
            'hid' => $householdId,
            'name' => 'Demo Cash Wallet',
            'type' => 'cash',
            'currency' => $currency,
            'opening' => 5000,
        ]);
        $accountMap['cash'] = (int)$insertAccount->fetchColumn();
    }
    $checkingId = $accountMap['checking'] ?? (int)($accounts[0]['id'] ?? 0);
    $savingsId = $accountMap['savings'] ?? $checkingId;
    $cashId = $accountMap['cash'] ?? $checkingId;

    $categoriesStmt = $pdo->prepare('select id, name, type from categories where household_id = :hid and is_active = true');
    $categoriesStmt->execute(['hid' => $householdId]);
    $categories = $categoriesStmt->fetchAll();
    if (!$categories) {
        $insertCategory = $pdo->prepare(
            'insert into categories (household_id, name, type, parent_id, sort_order, is_active)
             values (:hid, :name, :type, null, :order, true)'
        );
        $seedCats = [
            ['Salary', 'income', 0],
            ['Rent', 'expense', 10],
            ['Groceries', 'expense', 20],
            ['Utilities', 'expense', 30],
            ['Leisure', 'expense', 40],
            ['Transport', 'expense', 50],
            ['Savings', 'expense', 60],
        ];
        foreach ($seedCats as [$name, $type, $order]) {
            $insertCategory->execute([
                'hid' => $householdId,
                'name' => $name,
                'type' => $type,
                'order' => $order,
            ]);
        }
        $categoriesStmt->execute(['hid' => $householdId]);
        $categories = $categoriesStmt->fetchAll();
    }
    $categoryByName = [];
    $incomeCategoryId = null;
    $expenseCategoryId = null;
    foreach ($categories as $category) {
        $categoryByName[strtolower($category['name'])] = (int)$category['id'];
        if ($category['type'] === 'income' && !$incomeCategoryId) {
            $incomeCategoryId = (int)$category['id'];
        }
        if ($category['type'] === 'expense' && !$expenseCategoryId) {
            $expenseCategoryId = (int)$category['id'];
        }
    }
    $rentCategoryId = $categoryByName['rent'] ?? $expenseCategoryId;
    $groceriesCategoryId = $categoryByName['groceries'] ?? $expenseCategoryId;
    $utilitiesCategoryId = $categoryByName['utilities'] ?? $expenseCategoryId;
    $leisureCategoryId = $categoryByName['leisure'] ?? $expenseCategoryId;
    $transportCategoryId = $categoryByName['transport'] ?? $expenseCategoryId;
    $savingsCategoryId = $categoryByName['savings'] ?? $expenseCategoryId;

    $insertTag = $pdo->prepare(
        'insert into tags (household_id, name, color, is_active)
         values (:hid, :name, :color, true)
         on conflict (household_id, name) do nothing'
    );
    $tagSeeds = [
        ['Demo: Fixed', '#0ea5e9'],
        ['Demo: Variable', '#22c55e'],
        ['Demo: Family', '#f97316'],
    ];
    foreach ($tagSeeds as [$name, $color]) {
        $insertTag->execute(['hid' => $householdId, 'name' => $name, 'color' => $color]);
    }
    $tagsStmt = $pdo->prepare('select id, name from tags where household_id = :hid');
    $tagsStmt->execute(['hid' => $householdId]);
    $tagMap = [];
    foreach ($tagsStmt->fetchAll() as $tag) {
        $tagMap[$tag['name']] = (int)$tag['id'];
    }

    $insertPayee = $pdo->prepare(
        'insert into payees (household_id, name, notes)
         values (:hid, :name, :notes)
         on conflict (household_id, name) do nothing'
    );
    $payeeSeeds = [
        ['Demo Employer', 'Monthly salary payout'],
        ['Demo Landlord', 'Apartment rent'],
        ['Demo Supermarket', 'Groceries and supplies'],
        ['Demo Utilities', 'Electricity and utilities'],
        ['Demo Streaming', 'Subscription'],
        ['Demo Online Shop', 'Online orders'],
        ['Demo Cafe', 'Coffee and snacks'],
    ];
    foreach ($payeeSeeds as [$name, $notes]) {
        $insertPayee->execute(['hid' => $householdId, 'name' => $name, 'notes' => $notes]);
    }
    $payeeStmt = $pdo->prepare('select id, name from payees where household_id = :hid');
    $payeeStmt->execute(['hid' => $householdId]);
    $payeeMap = [];
    foreach ($payeeStmt->fetchAll() as $payee) {
        $payeeMap[$payee['name']] = (int)$payee['id'];
    }

    $matchRuleId = null;
    if (!empty($payeeMap['Demo Online Shop']) && hb_admin_table_exists($pdo, 'payee_match_rules')) {
        $ruleStmt = $pdo->prepare(
            'insert into payee_match_rules (household_id, pattern, match_type, payee_id, priority, is_active)
             values (:hid, :pattern, :match_type, :payee_id, :priority, true)
             returning id'
        );
        $ruleStmt->execute([
            'hid' => $householdId,
            'pattern' => 'DEMO MARKETPLACE',
            'match_type' => 'contains',
            'payee_id' => $payeeMap['Demo Online Shop'],
            'priority' => 5,
        ]);
        $matchRuleId = (int)$ruleStmt->fetchColumn();
    }

    $insertRecurring = $pdo->prepare(
        'insert into recurring_payments
            (household_id, name, direction, amount_cents, interval_unit, interval_value, start_date, end_date,
             priority, is_optional, account_id, category_id, payee_id, note)
         values
            (:hid, :name, :direction, :amount, :unit, :interval, :start_date, :end_date,
             :priority, :is_optional, :account_id, :category_id, :payee_id, :note)
         returning id'
    );
    $insertRecurring->execute([
        'hid' => $householdId,
        'name' => 'Salary',
        'direction' => 'income',
        'amount' => 320000,
        'unit' => 'month',
        'interval' => 1,
        'start_date' => $monthStart->format('Y-m-d'),
        'end_date' => null,
        'priority' => 1,
        'is_optional' => 0,
        'account_id' => $checkingId,
        'category_id' => $incomeCategoryId,
        'payee_id' => $payeeMap['Demo Employer'] ?? null,
        'note' => 'Monthly salary',
    ]);
    $insertRecurring->execute([
        'hid' => $householdId,
        'name' => 'Rent',
        'direction' => 'expense',
        'amount' => 120000,
        'unit' => 'month',
        'interval' => 1,
        'start_date' => $monthStart->format('Y-m-d'),
        'end_date' => null,
        'priority' => 1,
        'is_optional' => 0,
        'account_id' => $checkingId,
        'category_id' => $rentCategoryId,
        'payee_id' => $payeeMap['Demo Landlord'] ?? null,
        'note' => 'Monthly rent',
    ]);
    $insertRecurring->execute([
        'hid' => $householdId,
        'name' => 'Utilities',
        'direction' => 'expense',
        'amount' => 8500,
        'unit' => 'month',
        'interval' => 1,
        'start_date' => $monthStart->format('Y-m-d'),
        'end_date' => null,
        'priority' => 2,
        'is_optional' => 0,
        'account_id' => $checkingId,
        'category_id' => $utilitiesCategoryId,
        'payee_id' => $payeeMap['Demo Utilities'] ?? null,
        'note' => 'Electricity & utilities',
    ]);
    $insertRecurring->execute([
        'hid' => $householdId,
        'name' => 'Streaming subscription',
        'direction' => 'expense',
        'amount' => 1299,
        'unit' => 'month',
        'interval' => 1,
        'start_date' => $monthStart->format('Y-m-d'),
        'end_date' => null,
        'priority' => 3,
        'is_optional' => 1,
        'account_id' => $checkingId,
        'category_id' => $leisureCategoryId,
        'payee_id' => $payeeMap['Demo Streaming'] ?? null,
        'note' => 'Monthly subscription',
    ]);

    $budgetStmt = $pdo->prepare(
        'insert into budgets (household_id, name, amount_cents, period_unit, period_value, start_date, end_date, is_active, note)
         values (:hid, :name, :amount, :unit, :value, :start_date, :end_date, true, :note)
         returning id'
    );
    $budgetStmt->execute([
        'hid' => $householdId,
        'name' => 'Leisure budget',
        'amount' => 15000,
        'unit' => 'month',
        'value' => 1,
        'start_date' => $monthStart->format('Y-m-d'),
        'end_date' => null,
        'note' => 'Monthly leisure spending limit',
    ]);
    $budgetId = (int)$budgetStmt->fetchColumn();
    if ($budgetId && $leisureCategoryId) {
        $pdo->prepare(
            'insert into budget_categories (budget_id, category_id) values (:budget_id, :category_id)'
        )->execute([
            'budget_id' => $budgetId,
            'category_id' => $leisureCategoryId,
        ]);
    }

    $savingsStmt = $pdo->prepare(
        'insert into savings_plans (household_id, name, amount_cents, interval_unit, interval_value, start_date, end_date,
            target_amount_cents, target_date, account_id, note, is_active, priority, is_optional)
         values (:hid, :name, :amount, :unit, :value, :start_date, :end_date, :target_amount, :target_date, :account_id, :note, true, :priority, :optional)
         returning id'
    );
    $savingsStmt->execute([
        'hid' => $householdId,
        'name' => 'Emergency fund',
        'amount' => 5000,
        'unit' => 'month',
        'value' => 1,
        'start_date' => $monthStart->format('Y-m-d'),
        'end_date' => null,
        'target_amount' => 100000,
        'target_date' => $monthStart->modify('+6 months')->format('Y-m-d'),
        'account_id' => $savingsId ?: $checkingId,
        'note' => 'Savings goal example',
        'priority' => 3,
        'optional' => 0,
    ]);
    $savingId = (int)$savingsStmt->fetchColumn();
    if ($savingId && $savingsCategoryId) {
        $pdo->prepare(
            'insert into savings_plan_categories (savings_plan_id, category_id) values (:plan_id, :category_id)'
        )->execute([
            'plan_id' => $savingId,
            'category_id' => $savingsCategoryId,
        ]);
    }

    $txColumns = [
        'household_id',
        'type',
        'booking_date',
        'amount_cents',
        'currency_code',
        'account_id',
        'category_id',
        'payee_id',
        'note',
        'transfer_from_account_id',
        'transfer_to_account_id',
    ];
    $txValues = [
        ':hid',
        ':type',
        ':date',
        ':amount',
        ':currency',
        ':account_id',
        ':category_id',
        ':payee_id',
        ':note',
        ':transfer_from',
        ':transfer_to',
    ];
    $optionalCols = [
        'is_reviewed' => ':is_reviewed',
        'counterparty_name' => ':counterparty',
        'suggested_payee_id' => ':suggested_payee',
        'suggested_match_rule_id' => ':suggested_rule',
    ];
    foreach ($optionalCols as $column => $param) {
        if (hb_admin_column_exists($pdo, 'transactions', $column)) {
            $txColumns[] = $column;
            $txValues[] = $param;
        }
    }
    $insertTx = $pdo->prepare(
        'insert into transactions (' . implode(', ', $txColumns) . ')
         values (' . implode(', ', $txValues) . ')
         returning id'
    );

    $insertTx->execute([
        'hid' => $householdId,
        'type' => 'income',
        'date' => $monthStart->modify('+1 day')->format('Y-m-d'),
        'amount' => 320000,
        'currency' => $currency,
        'account_id' => $checkingId,
        'category_id' => $incomeCategoryId,
        'payee_id' => $payeeMap['Demo Employer'] ?? null,
        'note' => 'Monthly salary',
        'transfer_from' => null,
        'transfer_to' => null,
        'is_reviewed' => 1,
        'counterparty' => 'Demo Employer',
        'suggested_payee' => null,
        'suggested_rule' => null,
    ]);

    $insertTx->execute([
        'hid' => $householdId,
        'type' => 'expense',
        'date' => $monthStart->modify('+2 day')->format('Y-m-d'),
        'amount' => 120000,
        'currency' => $currency,
        'account_id' => $checkingId,
        'category_id' => $rentCategoryId,
        'payee_id' => $payeeMap['Demo Landlord'] ?? null,
        'note' => 'Rent paid',
        'transfer_from' => null,
        'transfer_to' => null,
        'is_reviewed' => 1,
        'counterparty' => 'Demo Landlord',
        'suggested_payee' => null,
        'suggested_rule' => null,
    ]);

    $splitTxId = (int)$insertTx->execute([
        'hid' => $householdId,
        'type' => 'expense',
        'date' => $monthStart->modify('+5 day')->format('Y-m-d'),
        'amount' => 8000,
        'currency' => $currency,
        'account_id' => $checkingId,
        'category_id' => null,
        'payee_id' => $payeeMap['Demo Supermarket'] ?? null,
        'note' => 'Shopping trip with split categories',
        'transfer_from' => null,
        'transfer_to' => null,
        'is_reviewed' => 1,
        'counterparty' => 'Demo Supermarket',
        'suggested_payee' => null,
        'suggested_rule' => null,
    ]) ? (int)$insertTx->fetchColumn() : 0;

    if ($splitTxId) {
        $splitStmt = $pdo->prepare(
            'insert into transaction_splits (transaction_id, category_id, amount_cents, note)
             values (:tx_id, :category_id, :amount, :note)'
        );
        $splitStmt->execute([
            'tx_id' => $splitTxId,
            'category_id' => $groceriesCategoryId,
            'amount' => 5000,
            'note' => 'Groceries',
        ]);
        $splitStmt->execute([
            'tx_id' => $splitTxId,
            'category_id' => $leisureCategoryId,
            'amount' => 3000,
            'note' => 'Snacks & leisure',
        ]);
        if (!empty($tagMap['Demo: Variable'])) {
            $pdo->prepare(
                'insert into transaction_tags (transaction_id, tag_id) values (:tx_id, :tag_id)'
            )->execute([
                'tx_id' => $splitTxId,
                'tag_id' => $tagMap['Demo: Variable'],
            ]);
        }
    }

    $insertTx->execute([
        'hid' => $householdId,
        'type' => 'expense',
        'date' => $monthStart->modify('+10 day')->format('Y-m-d'),
        'amount' => 4200,
        'currency' => $currency,
        'account_id' => $checkingId,
        'category_id' => $utilitiesCategoryId,
        'payee_id' => $payeeMap['Demo Utilities'] ?? null,
        'note' => 'Utility bill',
        'transfer_from' => null,
        'transfer_to' => null,
        'is_reviewed' => 1,
        'counterparty' => 'Demo Utilities',
        'suggested_payee' => null,
        'suggested_rule' => null,
    ]);

    $insertTx->execute([
        'hid' => $householdId,
        'type' => 'transfer',
        'date' => $monthStart->modify('+12 day')->format('Y-m-d'),
        'amount' => 20000,
        'currency' => $currency,
        'account_id' => null,
        'category_id' => null,
        'payee_id' => null,
        'note' => 'Monthly savings transfer',
        'transfer_from' => $checkingId,
        'transfer_to' => $savingsId,
        'is_reviewed' => 1,
        'counterparty' => null,
        'suggested_payee' => null,
        'suggested_rule' => null,
    ]);

    $insertTx->execute([
        'hid' => $householdId,
        'type' => 'expense',
        'date' => $monthStart->modify('+15 day')->format('Y-m-d'),
        'amount' => 4599,
        'currency' => $currency,
        'account_id' => $checkingId,
        'category_id' => $leisureCategoryId,
        'payee_id' => null,
        'note' => 'Online order pending review',
        'transfer_from' => null,
        'transfer_to' => null,
        'is_reviewed' => 0,
        'counterparty' => 'DEMO MARKETPLACE',
        'suggested_payee' => $payeeMap['Demo Online Shop'] ?? null,
        'suggested_rule' => $matchRuleId,
    ]);

    $insertTx->execute([
        'hid' => $householdId,
        'type' => 'expense',
        'date' => $monthStart->modify('+18 day')->format('Y-m-d'),
        'amount' => 1599,
        'currency' => $currency,
        'account_id' => $cashId,
        'category_id' => $leisureCategoryId,
        'payee_id' => null,
        'note' => 'Cafe visit pending review',
        'transfer_from' => null,
        'transfer_to' => null,
        'is_reviewed' => 0,
        'counterparty' => 'DEMO CAFE',
        'suggested_payee' => $payeeMap['Demo Cafe'] ?? null,
        'suggested_rule' => null,
    ]);

    $caseStmt = $pdo->prepare(
        'insert into open_cases (household_id, title, status, reference, contact_name, contact_details, notes)
         values (:hid, :title, :status, :reference, :contact_name, :contact_details, :notes)'
    );
    $caseStmt->execute([
        'hid' => $householdId,
        'title' => 'Demo open invoice',
        'status' => 'open',
        'reference' => 'INV-2024-001',
        'contact_name' => 'Demo Service Desk',
        'contact_details' => 'demo@example.com',
        'notes' => 'Example open case for testing workflow.',
    ]);

    hb_ensure_month_plan($pdo, ['id' => $householdId], $monthStart, $monthEnd);
}

function hb_admin_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'select 1 from information_schema.tables where table_name = :table and table_schema = current_schema() limit 1'
    );
    $stmt->execute(['table' => $table]);
    return (bool)$stmt->fetchColumn();
}

function hb_admin_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'select 1 from information_schema.columns where table_name = :table and column_name = :column limit 1'
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (bool)$stmt->fetchColumn();
}
if ($isHx) {
    switch ($action) {
        case 'activate':
            handle_activate();
            break;
        case 'list':
        default:
            render_pending();
    }
}

function handle_activate(): void
{
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId < 1) {
        http_response_code(400);
        echo render_alert(hb_t('Invalid user id.'));
        return;
    }

    $pdo = hb_get_pdo();
    $update = $pdo->prepare('update users set is_active = true where id = :id');
    $update->execute(['id' => $userId]);

    render_pending();
}

function render_pending(bool $wrap = false): void
{
    $pdo = hb_get_pdo();
    $stmt = $pdo->query(
        'select id, username, email, first_name, last_name, address,
                address_street, address_house_number, address_postal_code,
                address_city, address_state, address_extra,
                consent_contact, created_at
           from users
          where is_active = false and is_admin = false
          order by created_at asc'
    );
    $users = $stmt->fetchAll();

    if ($wrap) {
        echo '<div class="card shadow-sm">';
        echo '<div class="card-body">';
    }
    if (!$users) {
        echo '<div class="alert alert-info mb-0">' . htmlspecialchars(hb_t('No pending approvals.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        if ($wrap) {
            echo '</div></div>';
        }
        return;
    }

    echo '<div class="table-responsive">';
    echo '<table class="table align-middle mb-0">';
    echo '<thead><tr><th>' . htmlspecialchars(hb_t('User'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Consent'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th><th>' . htmlspecialchars(hb_t('Action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th></tr></thead>';
    echo '<tbody>';

    foreach ($users as $user) {
        $consent = $user['consent_contact'] ? hb_t('Yes') : hb_t('No');
        $fullName = htmlspecialchars($user['first_name'] . ' ' . $user['last_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $username = htmlspecialchars($user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $email = htmlspecialchars($user['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $address = htmlspecialchars(hb_format_address_admin($user), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo '<tr>';
        echo '<td>' . $username . '</td>';
        echo '<td>' . $fullName . '</td>';
        echo '<td>' . $email . '</td>';
        echo '<td class="small">' . $address . '</td>';
        echo '<td>' . $consent . '</td>';
        echo '<td>';
        echo '<form hx-post="/admin.php?action=activate" hx-target="#pending-list" hx-swap="innerHTML">';
        echo '<input type="hidden" name="user_id" value="' . (int)$user['id'] . '">';
        echo '<button type="submit" class="btn btn-sm btn-success">' . htmlspecialchars(hb_t('Activate'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    if ($wrap) {
        echo '</div></div>';
    }
}

function hb_is_admin(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function hb_format_address_admin(array $user): string
{
    $street = trim((string)($user['address_street'] ?? ''));
    $houseNumber = trim((string)($user['address_house_number'] ?? ''));
    $postalCode = trim((string)($user['address_postal_code'] ?? ''));
    $city = trim((string)($user['address_city'] ?? ''));
    $state = trim((string)($user['address_state'] ?? ''));
    $extra = trim((string)($user['address_extra'] ?? ''));
    if ($street !== '' || $houseNumber !== '' || $postalCode !== '' || $city !== '' || $state !== '' || $extra !== '') {
        $line1 = trim($street . ' ' . $houseNumber);
        $line2 = trim($postalCode . ' ' . $city);
        $parts = array_filter([$line1, $line2, $state !== '' ? $state : null, $extra !== '' ? $extra : null]);
        return implode(', ', $parts);
    }
    return (string)($user['address'] ?? '');
}

function render_alert(string $message, string $type = 'danger'): string
{
    $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<div class="alert alert-' . $type . ' mb-0" role="alert">' . $escaped . '</div>';
}

if (!$isHx) {
    if ($action === 'seed_demo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo = hb_get_pdo();
        $householdId = (int)($_POST['household_id'] ?? 0);
        $detail = '';
        if ($householdId < 1) {
            header('Location: /admin.php?msg=demo_missing');
            exit;
        }
        $exists = $pdo->prepare('select 1 from households where id = :id');
        $exists->execute(['id' => $householdId]);
        if (!$exists->fetchColumn()) {
            header('Location: /admin.php?msg=demo_missing');
            exit;
        }
        try {
            hb_admin_seed_demo_data($pdo, $householdId, hb_current_user_id());
            header('Location: /admin.php?msg=demo_seeded');
            exit;
        } catch (Throwable $e) {
            $detail = substr($e->getMessage(), 0, 300);
            error_log('Admin demo seed failed: ' . $detail);
            header('Location: /admin.php?msg=demo_error&detail=' . urlencode($detail));
            exit;
        }
    }

    if ($action === 'create_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pdo = hb_get_pdo();
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $firstName = trim((string)($_POST['first_name'] ?? ''));
        $lastName = trim((string)($_POST['last_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        $language = hb_normalize_locale($_POST['language'] ?? 'de');
        $householdId = (int)($_POST['household_id'] ?? 0);
        $memberRole = $_POST['member_role'] === 'admin' ? 'admin' : 'editor';
        $createDemo = !empty($_POST['create_demo']);

        if ($username === '' || $email === '' || $firstName === '' || $lastName === '' || $password === '') {
            $msg = 'missing';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'invalid_email';
        } elseif ($password !== $confirm) {
            $msg = 'password_mismatch';
        } else {
            $passwordError = hb_admin_validate_password($password);
            if ($passwordError !== null) {
                $msg = 'password_policy';
            } else {
                $exists = $pdo->prepare('select 1 from users where lower(username) = lower(:username) or lower(email) = lower(:email)');
                $exists->execute(['username' => $username, 'email' => $email]);
                if ($exists->fetch()) {
                    $msg = 'exists';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $insert = $pdo->prepare(
                        'insert into users (username, email, first_name, last_name, address, consent_contact, password_hash, is_active, is_admin, language)
                         values (:username, :email, :first_name, :last_name, :address, false, :hash, true, false, :language)
                         returning id'
                    );
                    try {
                        $pdo->beginTransaction();
                        $insert->execute([
                            'username' => $username,
                            'email' => $email,
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'address' => 'N/A',
                            'hash' => $hash,
                            'language' => $language,
                        ]);
                        $newUserId = (int)$insert->fetchColumn();
                        if ($householdId > 0) {
                            $member = $pdo->prepare(
                                'insert into household_members (household_id, user_id, role, is_active)
                                 values (:household_id, :user_id, :role, true)
                                 on conflict (household_id, user_id)
                                 do update set role = excluded.role, is_active = true'
                            );
                            $member->execute([
                                'household_id' => $householdId,
                                'user_id' => $newUserId,
                                'role' => $memberRole,
                            ]);
                        }
                        $pdo->commit();

                        $redirectMsg = 'created';
                        if ($createDemo) {
                            try {
                                if ($householdId < 1) {
                                    $householdId = hb_create_household($pdo, $newUserId, 'Demo Household', 'EUR', 'first_of_month', null);
                                }
                                hb_admin_seed_demo_data($pdo, $householdId, $newUserId);
                            } catch (Throwable $e) {
                                $redirectMsg = 'created_demo_error';
                                $detail = substr($e->getMessage(), 0, 300);
                                error_log('Admin demo seed failed: ' . $detail);
                            }
                        }

                        $redirect = '/admin.php?msg=' . urlencode($redirectMsg);
                        if (!empty($detail)) {
                            $redirect .= '&detail=' . urlencode($detail);
                        }
                        header('Location: ' . $redirect);
                        exit;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $msg = 'error';
                        $detail = substr($e->getMessage(), 0, 300);
                        error_log('Admin create user failed: ' . $detail);
                    }
                }
            }
        }
        $redirect = '/admin.php?msg=' . urlencode($msg ?? 'error');
        if (!empty($detail)) {
            $redirect .= '&detail=' . urlencode($detail);
        }
        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'activate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $pdo = hb_get_pdo();
            $update = $pdo->prepare('update users set is_active = true where id = :id');
            $update->execute(['id' => $userId]);
        }
        header('Location: /admin.php');
        exit;
    }
    $pdo = hb_get_pdo();
    $currentHousehold = hb_current_household($pdo);
    $currentUser = hb_current_user($pdo);
    $languageOptions = hb_available_locales();
    $householdOptions = $pdo->query('select id, name from households order by name asc')->fetchAll();
    $pageTitle = 'Admin';
    $activeNav = 'admin';
    $breadcrumbs = [
        ['label' => 'Admin', 'href' => '/admin.php'],
    ];

    ob_start();
    ?>
    <div class="container-fluid">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Admin'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
          <div class="text-muted small"><?= htmlspecialchars(hb_t('User management'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        </div>
      </div>
      <?php if (!empty($_GET['msg'])): ?>
        <?php
        $msgKey = $_GET['msg'];
        $msgMap = [
            'created' => ['type' => 'success', 'text' => hb_t('User created.')],
            'created_demo_error' => ['type' => 'warning', 'text' => hb_t('User created, but demo data failed.')],
            'demo_seeded' => ['type' => 'success', 'text' => hb_t('Demo data created.')],
            'demo_missing' => ['type' => 'danger', 'text' => hb_t('Select a household for demo data.')],
            'demo_error' => ['type' => 'danger', 'text' => hb_t('Demo data could not be created.')],
            'missing' => ['type' => 'danger', 'text' => hb_t('Please fill in all required fields.')],
            'invalid_email' => ['type' => 'danger', 'text' => hb_t('Invalid email address.')],
            'password_mismatch' => ['type' => 'danger', 'text' => hb_t('Passwords do not match.')],
            'password_policy' => ['type' => 'danger', 'text' => hb_t('Password does not meet the policy.')],
            'exists' => ['type' => 'danger', 'text' => hb_t('Username or email already exists.')],
            'error' => ['type' => 'danger', 'text' => hb_t('Could not create user.')],
        ];
        $alert = $msgMap[$msgKey] ?? null;
        $detail = trim((string)($_GET['detail'] ?? ''));
        ?>
        <?php if ($alert): ?>
          <div class="alert alert-<?= $alert['type'] ?>"><?= htmlspecialchars($alert['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php if ($detail !== '' && $alert['type'] === 'danger'): ?>
            <div class="text-muted small mb-3"><?= htmlspecialchars(hb_t('Details: {detail}', null, ['detail' => $detail]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
      <div class="row g-4">
        <div class="col-lg-5">
          <div class="card shadow-sm">
            <div class="card-body">
              <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Create user'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
              <form method="post" action="/admin.php?action=create_user">
                <input type="hidden" name="action" value="create_user">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label" for="admin-first-name"><?= htmlspecialchars(hb_t('First name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <input type="text" class="form-control" id="admin-first-name" name="first_name" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="admin-last-name"><?= htmlspecialchars(hb_t('Last name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <input type="text" class="form-control" id="admin-last-name" name="last_name" required>
                  </div>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-username"><?= htmlspecialchars(hb_t('Username'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="text" class="form-control" id="admin-username" name="username" required>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-email"><?= htmlspecialchars(hb_t('Email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="email" class="form-control" id="admin-email" name="email" required>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-password"><?= htmlspecialchars(hb_t('Password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="password" class="form-control" id="admin-password" name="password" autocomplete="new-password" required minlength="12">
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-password-confirm"><?= htmlspecialchars(hb_t('Confirm password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <input type="password" class="form-control" id="admin-password-confirm" name="password_confirm" autocomplete="new-password" required minlength="12">
                  <div class="form-text text-muted"><?= htmlspecialchars(hb_t('At least 12 characters, upper/lowercase, number & symbol.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-language"><?= htmlspecialchars(hb_t('Language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" id="admin-language" name="language">
                    <?php foreach ($languageOptions as $lang => $label): ?>
                      <option value="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-household"><?= htmlspecialchars(hb_t('Assign household (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" id="admin-household" name="household_id">
                    <option value="0"><?= htmlspecialchars(hb_t('No household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php foreach ($householdOptions as $household): ?>
                      <option value="<?= (int)$household['id'] ?>"><?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mt-3">
                  <label class="form-label" for="admin-role"><?= htmlspecialchars(hb_t('Member role'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" id="admin-role" name="member_role">
                    <option value="editor"><?= htmlspecialchars(hb_t('Editor'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <option value="admin"><?= htmlspecialchars(hb_t('Admin'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  </select>
                </div>
                <div class="form-check mt-3">
                  <input class="form-check-input" type="checkbox" id="admin-demo" name="create_demo" value="1">
                  <label class="form-check-label" for="admin-demo"><?= htmlspecialchars(hb_t('Create demo data'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <div class="form-text text-muted"><?= htmlspecialchars(hb_t('Adds a demo household with sample data if no household is selected.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
                <button class="btn btn-primary mt-3" type="submit"><?= htmlspecialchars(hb_t('Create user'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </form>
            </div>
          </div>
          <div class="card shadow-sm mt-4">
            <div class="card-body">
              <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Seed demo data'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
              <form method="post" action="/admin.php?action=seed_demo">
                <input type="hidden" name="action" value="seed_demo">
                <div class="mb-2">
                  <label class="form-label" for="demo-household"><?= htmlspecialchars(hb_t('Household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" id="demo-household" name="household_id" required>
                    <option value=""><?= htmlspecialchars(hb_t('Select household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php foreach ($householdOptions as $household): ?>
                      <option value="<?= (int)$household['id'] ?>"><?= htmlspecialchars($household['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-text text-muted mb-3"><?= htmlspecialchars(hb_t('Adds fictional demo records for testing.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <button class="btn btn-outline-primary btn-sm" type="submit"><?= htmlspecialchars(hb_t('Seed demo data'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </form>
            </div>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="mb-3">
            <div class="text-muted small"><?= htmlspecialchars(hb_t('Open registrations'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          </div>
          <?php render_pending(true); ?>
        </div>
      </div>
    </div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/../templates/layout.php';
    exit;
}
