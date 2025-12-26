<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/domain.php';

$pdo = hb_get_pdo();

function set_db_context(PDO $pdo, int $userId, string $username, int $householdId): void
{
    $stmt = $pdo->prepare(
        "select
            set_config('hb.user_id', :user_id, true),
            set_config('hb.username', :username, true),
            set_config('hb.household_id', :household_id, true)"
    );
    $stmt->execute([
        'user_id' => (string)$userId,
        'username' => $username,
        'household_id' => (string)$householdId,
    ]);
}

function find_user(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare('select * from users where username = :username');
    $stmt->execute(['username' => $username]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsert_user(PDO $pdo, string $username, string $password, string $email, array $address): array
{
    $user = find_user($pdo, $username);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($user) {
        $stmt = $pdo->prepare(
            'update users
                set email = :email,
                    first_name = :first_name,
                    last_name = :last_name,
                    address = :address,
                    address_street = :street,
                    address_house_number = :house_number,
                    address_postal_code = :postal_code,
                    address_city = :city,
                    address_state = :state,
                    address_extra = :extra,
                    consent_contact = true,
                    password_hash = :hash,
                    is_active = true
              where id = :id'
        );
        $stmt->execute([
            'email' => $email,
            'first_name' => $address['first_name'],
            'last_name' => $address['last_name'],
            'address' => $address['full'],
            'street' => $address['street'],
            'house_number' => $address['house_number'],
            'postal_code' => $address['postal_code'],
            'city' => $address['city'],
            'state' => $address['state'],
            'extra' => $address['extra'],
            'hash' => $hash,
            'id' => $user['id'],
        ]);
        return find_user($pdo, $username) ?? $user;
    }

    $stmt = $pdo->prepare(
        'insert into users
            (username, email, first_name, last_name, address,
             address_street, address_house_number, address_postal_code,
             address_city, address_state, address_extra,
             consent_contact, password_hash, is_active, is_admin)
         values
            (:username, :email, :first_name, :last_name, :address,
             :street, :house_number, :postal_code,
             :city, :state, :extra,
             true, :hash, true, false)
         returning *'
    );
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'first_name' => $address['first_name'],
        'last_name' => $address['last_name'],
        'address' => $address['full'],
        'street' => $address['street'],
        'house_number' => $address['house_number'],
        'postal_code' => $address['postal_code'],
        'city' => $address['city'],
        'state' => $address['state'],
        'extra' => $address['extra'],
        'hash' => $hash,
    ]);
    return $stmt->fetch();
}

function find_or_create_household(PDO $pdo, string $name): array
{
    $stmt = $pdo->prepare('select * from households where name = :name');
    $stmt->execute(['name' => $name]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $stmt = $pdo->prepare(
        'insert into households (name, currency_code, month_close_mode, salary_day)
         values (:name, :currency, :mode, :salary)
         returning *'
    );
    $stmt->execute([
        'name' => $name,
        'currency' => 'EUR',
        'mode' => 'first_of_month',
        'salary' => null,
    ]);
    return $stmt->fetch();
}

function ensure_member(PDO $pdo, int $householdId, int $userId, string $role): void
{
    $stmt = $pdo->prepare(
        'insert into household_members (household_id, user_id, role, is_active)
         values (:hid, :uid, :role, true)
         on conflict (household_id, user_id)
         do update set role = excluded.role, is_active = true'
    );
    $stmt->execute([
        'hid' => $householdId,
        'uid' => $userId,
        'role' => $role,
    ]);
}

function find_or_create_account(PDO $pdo, int $householdId, string $name, string $type, int $openingCents): array
{
    $stmt = $pdo->prepare('select * from accounts where household_id = :hid and name = :name');
    $stmt->execute(['hid' => $householdId, 'name' => $name]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $stmt = $pdo->prepare(
        'insert into accounts (household_id, name, type, currency_code, opening_balance_cents, is_archived)
         values (:hid, :name, :type, :cur, :open, false)
         returning *'
    );
    $stmt->execute([
        'hid' => $householdId,
        'name' => $name,
        'type' => $type,
        'cur' => 'EUR',
        'open' => $openingCents,
    ]);
    return $stmt->fetch();
}

function find_or_create_category(PDO $pdo, int $householdId, string $name, string $type): array
{
    $stmt = $pdo->prepare('select * from categories where household_id = :hid and name = :name');
    $stmt->execute(['hid' => $householdId, 'name' => $name]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $stmt = $pdo->prepare(
        'insert into categories (household_id, name, type, sort_order, is_active)
         values (:hid, :name, :type, 0, true)
         returning *'
    );
    $stmt->execute([
        'hid' => $householdId,
        'name' => $name,
        'type' => $type,
    ]);
    return $stmt->fetch();
}

function find_or_create_payee(PDO $pdo, int $householdId, string $name): array
{
    $stmt = $pdo->prepare('select * from payees where household_id = :hid and name = :name');
    $stmt->execute(['hid' => $householdId, 'name' => $name]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }
    $stmt = $pdo->prepare(
        'insert into payees (household_id, name)
         values (:hid, :name)
         returning *'
    );
    $stmt->execute([
        'hid' => $householdId,
        'name' => $name,
    ]);
    return $stmt->fetch();
}

function insert_transaction(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare(
        'select id from transactions
          where household_id = :hid and booking_date = :date and amount_cents = :amount and note = :note
          limit 1'
    );
    $stmt->execute([
        'hid' => $data['household_id'],
        'date' => $data['booking_date'],
        'amount' => $data['amount_cents'],
        'note' => $data['note'],
    ]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    $stmt = $pdo->prepare(
        'insert into transactions
            (household_id, type, booking_date, amount_cents, currency_code, account_id, category_id, payee_id, note,
             transfer_from_account_id, transfer_to_account_id, planned_payment_id)
         values
            (:hid, :type, :date, :amount, :cur, :account_id, :category_id, :payee_id, :note,
             :tf, :tt, :planned_id)
         returning id'
    );
    $stmt->execute([
        'hid' => $data['household_id'],
        'type' => $data['type'],
        'date' => $data['booking_date'],
        'amount' => $data['amount_cents'],
        'cur' => 'EUR',
        'account_id' => $data['account_id'],
        'category_id' => $data['category_id'],
        'payee_id' => $data['payee_id'],
        'note' => $data['note'],
        'tf' => $data['transfer_from_account_id'],
        'tt' => $data['transfer_to_account_id'],
        'planned_id' => $data['planned_payment_id'],
    ]);
    return (int)$stmt->fetchColumn();
}

$addressDemo = [
    'first_name' => 'Demo',
    'last_name' => 'User',
    'street' => 'Musterstrasse',
    'house_number' => '12',
    'postal_code' => '10115',
    'city' => 'Berlin',
    'state' => 'Berlin',
    'extra' => '3. OG',
];
$addressDemo['full'] = hb_build_address_string(
    $addressDemo['street'],
    $addressDemo['house_number'],
    $addressDemo['postal_code'],
    $addressDemo['city'],
    $addressDemo['state'],
    $addressDemo['extra']
);

$addressDemo2 = [
    'first_name' => 'Demo',
    'last_name' => 'Zwei',
    'street' => 'Testweg',
    'house_number' => '5a',
    'postal_code' => '20095',
    'city' => 'Hamburg',
    'state' => 'Hamburg',
    'extra' => 'Hinterhaus',
];
$addressDemo2['full'] = hb_build_address_string(
    $addressDemo2['street'],
    $addressDemo2['house_number'],
    $addressDemo2['postal_code'],
    $addressDemo2['city'],
    $addressDemo2['state'],
    $addressDemo2['extra']
);

$demoUser = upsert_user($pdo, 'demo', 'de,u', 'demo@example.test', $addressDemo);
$demo2User = upsert_user($pdo, 'demo2', 'de,u', 'demo2@example.test', $addressDemo2);

$household = find_or_create_household($pdo, 'Demo Haushalt');
ensure_member($pdo, (int)$household['id'], (int)$demoUser['id'], 'admin');
ensure_member($pdo, (int)$household['id'], (int)$demo2User['id'], 'editor');

set_db_context($pdo, (int)$demoUser['id'], 'demo', (int)$household['id']);

$giro = find_or_create_account($pdo, (int)$household['id'], 'Girokonto', 'checking', 1049);
$savings = find_or_create_account($pdo, (int)$household['id'], 'Sparkonto', 'savings', 0);

$catSalary = find_or_create_category($pdo, (int)$household['id'], 'Gehalt', 'income');
$catRent = find_or_create_category($pdo, (int)$household['id'], 'Miete', 'expense');
$catUtilities = find_or_create_category($pdo, (int)$household['id'], 'Strom', 'expense');
$catGroceries = find_or_create_category($pdo, (int)$household['id'], 'Lebensmittel', 'expense');
$catInsurance = find_or_create_category($pdo, (int)$household['id'], 'Versicherung', 'expense');
$catSubscriptions = find_or_create_category($pdo, (int)$household['id'], 'Abos', 'expense');
$catSavings = find_or_create_category($pdo, (int)$household['id'], 'Sparen', 'expense');

$payeeEmployer = find_or_create_payee($pdo, (int)$household['id'], 'Beispiel GmbH');
$payeeLandlord = find_or_create_payee($pdo, (int)$household['id'], 'Hausverwaltung Mitte');
$payeeEnergy = find_or_create_payee($pdo, (int)$household['id'], 'Stadtwerke Berlin');
$payeeGrocery = find_or_create_payee($pdo, (int)$household['id'], 'Supermarkt Nord');
$payeeInsurance = find_or_create_payee($pdo, (int)$household['id'], 'Versicherung AG');
$payeeStreaming = find_or_create_payee($pdo, (int)$household['id'], 'Streamly');
$payeeSavings = find_or_create_payee($pdo, (int)$household['id'], 'Tagesgeld');

$year = (int)date('Y');
$month = 12;

$recurring = $pdo->prepare(
    'insert into recurring_payments
        (household_id, name, direction, amount_cents, interval_unit, interval_value, start_date,
         priority, is_optional, account_id, category_id, payee_id, note, is_active)
     values
        (:hid, :name, :direction, :amount, :unit, :ival, :start_date,
         :priority, :is_optional, :account_id, :category_id, :payee_id, :note, true)
     on conflict do nothing
     returning id'
);

$recurringItems = [
    ['Gehalt', 'income', 265000, 'month', 1, sprintf('%04d-%02d-22', $year, $month), 1, false, $giro['id'], $catSalary['id'], $payeeEmployer['id'], 'Monatsgehalt'],
    ['Miete', 'expense', 89000, 'month', 1, sprintf('%04d-%02d-03', $year, $month), 1, false, $giro['id'], $catRent['id'], $payeeLandlord['id'], 'Warmmiete'],
    ['Strom', 'expense', 6500, 'month', 1, sprintf('%04d-%02d-15', $year, $month), 2, false, $giro['id'], $catUtilities['id'], $payeeEnergy['id'], 'Abschlag'],
    ['Wocheneinkauf', 'expense', 4200, 'week', 1, sprintf('%04d-%02d-07', $year, $month), 3, true, $giro['id'], $catGroceries['id'], $payeeGrocery['id'], 'Optional'],
    ['Streaming', 'expense', 1299, 'month', 1, sprintf('%04d-%02d-12', $year, $month), 4, true, $giro['id'], $catSubscriptions['id'], $payeeStreaming['id'], 'Abo'],
    ['Versicherung', 'expense', 18000, 'year', 1, sprintf('%04d-12-10', $year), 2, false, $giro['id'], $catInsurance['id'], $payeeInsurance['id'], 'Jahresbeitrag'],
    ['Kaffee', 'expense', 250, 'day', 1, sprintf('%04d-%02d-01', $year, $month), 5, true, $giro['id'], $catSubscriptions['id'], $payeeStreaming['id'], 'Daily optional'],
];

$recurringIds = [];
foreach ($recurringItems as $item) {
    $recurring->execute([
        'hid' => $household['id'],
        'name' => $item[0],
        'direction' => $item[1],
        'amount' => $item[2],
        'unit' => $item[3],
        'ival' => $item[4],
        'start_date' => $item[5],
        'priority' => $item[6],
        'is_optional' => $item[7] ? 1 : 0,
        'account_id' => $item[8],
        'category_id' => $item[9],
        'payee_id' => $item[10],
        'note' => $item[11],
    ]);
    $id = $recurring->fetchColumn();
    if ($id) {
        $recurringIds[$item[0]] = (int)$id;
    }
}

[$periodStart, $periodEnd] = hb_household_period_bounds($household, new DateTimeImmutable(sprintf('%04d-%02d-26', $year, $month)));
hb_ensure_month_plan($pdo, $household, $periodStart, $periodEnd);

$planStmt = $pdo->prepare(
    'select * from planned_payments
      where household_id = :hid
        and planned_date between :start and :end'
);
$planStmt->execute([
    'hid' => $household['id'],
    'start' => $periodStart->format('Y-m-d'),
    'end' => $periodEnd->format('Y-m-d'),
]);
$plans = $planStmt->fetchAll();

$planByNameDate = [];
foreach ($plans as $plan) {
    $key = $plan['name'] . '|' . $plan['planned_date'];
    $planByNameDate[$key] = $plan;
}

$donePlan = $planByNameDate['Gehalt|' . sprintf('%04d-%02d-22', $year, $month)] ?? null;
$rentPlan = $planByNameDate['Miete|' . sprintf('%04d-%02d-03', $year, $month)] ?? null;
$insurancePlan = $planByNameDate['Versicherung|' . sprintf('%04d-12-10', $year)] ?? null;
$streamPlan = $planByNameDate['Streaming|' . sprintf('%04d-%02d-12', $year, $month)] ?? null;

if ($donePlan) {
    $txId = insert_transaction($pdo, [
        'household_id' => $household['id'],
        'type' => 'income',
        'booking_date' => $donePlan['planned_date'],
        'amount_cents' => (int)$donePlan['amount_cents'],
        'account_id' => $giro['id'],
        'category_id' => $catSalary['id'],
        'payee_id' => $payeeEmployer['id'],
        'note' => 'Demo Seed: Gehalt',
        'transfer_from_account_id' => null,
        'transfer_to_account_id' => null,
        'planned_payment_id' => $donePlan['id'],
    ]);
    $pdo->prepare(
        "update planned_payments
            set status = 'done', resolved_at = now(), resolved_transaction_id = :tx
          where id = :id"
    )->execute(['tx' => $txId, 'id' => $donePlan['id']]);
}

if ($rentPlan) {
    $txId = insert_transaction($pdo, [
        'household_id' => $household['id'],
        'type' => 'expense',
        'booking_date' => $rentPlan['planned_date'],
        'amount_cents' => (int)$rentPlan['amount_cents'],
        'account_id' => $giro['id'],
        'category_id' => $catRent['id'],
        'payee_id' => $payeeLandlord['id'],
        'note' => 'Demo Seed: Miete',
        'transfer_from_account_id' => null,
        'transfer_to_account_id' => null,
        'planned_payment_id' => $rentPlan['id'],
    ]);
    $pdo->prepare(
        "update planned_payments
            set status = 'done', resolved_at = now(), resolved_transaction_id = :tx
          where id = :id"
    )->execute(['tx' => $txId, 'id' => $rentPlan['id']]);
}

if ($insurancePlan) {
    $pdo->prepare("update planned_payments set status = 'overdue' where id = :id")
        ->execute(['id' => $insurancePlan['id']]);
}

if ($streamPlan) {
    $pdo->prepare("update planned_payments set status = 'suggested' where id = :id")
        ->execute(['id' => $streamPlan['id']]);
}

$pdo->prepare(
    "insert into planned_payments
        (household_id, name, direction, amount_cents, planned_date, status, priority, is_optional,
         account_id, category_id, payee_id, note)
     values
        (:hid, :name, :direction, :amount, :planned_date, :status, :priority, :is_optional,
         :account_id, :category_id, :payee_id, :note)
     on conflict do nothing"
)->execute([
    'hid' => $household['id'],
    'name' => 'Einmalige Reparatur',
    'direction' => 'expense',
    'amount' => 9500,
    'planned_date' => sprintf('%04d-%02d-28', $year, $month),
    'status' => 'open',
    'priority' => 2,
    'is_optional' => 0,
    'account_id' => $giro['id'],
    'category_id' => $catUtilities['id'],
    'payee_id' => $payeeEnergy['id'],
    'note' => 'Unerwartete Reparatur',
]);

insert_transaction($pdo, [
    'household_id' => $household['id'],
    'type' => 'expense',
    'booking_date' => sprintf('%04d-%02d-18', $year, $month),
    'amount_cents' => 6700,
    'account_id' => $giro['id'],
    'category_id' => $catGroceries['id'],
    'payee_id' => $payeeGrocery['id'],
    'note' => 'Demo Seed: Einkauf',
    'transfer_from_account_id' => null,
    'transfer_to_account_id' => null,
    'planned_payment_id' => null,
]);

insert_transaction($pdo, [
    'household_id' => $household['id'],
    'type' => 'transfer',
    'booking_date' => sprintf('%04d-%02d-15', $year, $month),
    'amount_cents' => 20000,
    'account_id' => null,
    'category_id' => null,
    'payee_id' => $payeeSavings['id'],
    'note' => 'Demo Seed: Rücklage',
    'transfer_from_account_id' => $giro['id'],
    'transfer_to_account_id' => $savings['id'],
    'planned_payment_id' => null,
]);

set_db_context($pdo, (int)$demo2User['id'], 'demo2', (int)$household['id']);

$pdo->prepare(
    'insert into open_cases (household_id, title, status, reference, contact_name, contact_details, notes)
     values (:hid, :title, :status, :reference, :contact, :details, :notes)'
)->execute([
    'hid' => $household['id'],
    'title' => 'Inkasso Vorgang',
    'status' => 'clarifying',
    'reference' => 'AZ-2024-11',
    'contact' => 'Herr Muster',
    'details' => 'inkasso@example.test, 030-123456',
    'notes' => 'Unterlagen geprüft, Antwort offen',
]);

$pdo->prepare(
    'insert into open_cases (household_id, title, status, reference, contact_name, contact_details, notes)
     values (:hid, :title, :status, :reference, :contact, :details, :notes)'
)->execute([
    'hid' => $household['id'],
    'title' => 'Rückzahlung Vereinbarung',
    'status' => 'agreed',
    'reference' => 'V-7782',
    'contact' => 'Frau Partner',
    'details' => 'partner@example.test',
    'notes' => 'Monatliche Rate vereinbart',
]);

$pdo->prepare(
    'insert into chat_messages (household_id, user_id, message)
     values (:hid, :uid, :msg)'
)->execute([
    'hid' => $household['id'],
    'uid' => $demo2User['id'],
    'msg' => 'Demo2 hat eine neue Klärung hinzugefügt.',
]);

$pdo->prepare(
    'insert into month_closures (household_id, period_start, period_end, closed_by, note)
     values (:hid, :start, :end, :user_id, :note)
     on conflict do nothing'
)->execute([
    'hid' => $household['id'],
    'start' => sprintf('%04d-%02d-01', $year, $month),
    'end' => sprintf('%04d-%02d-31', $year, $month),
    'user_id' => $demoUser['id'],
    'note' => 'Demo Monatsabschluss',
]);

echo "Demo seed complete for household: {$household['name']}\n";
