<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

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

function hb_current_user(PDO $pdo): ?array
{
    static $cache = null;
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
                address_city, address_state, address_extra
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
        'cash' => 'Bargeld',
        'checking' => 'Girokonto',
        'savings' => 'Sparkonto',
        'credit_card' => 'Kreditkarte',
        'loan' => 'Darlehen',
        'asset' => 'Vermögen',
        'liability' => 'Verbindlichkeit',
        'other' => 'Sonstiges',
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
