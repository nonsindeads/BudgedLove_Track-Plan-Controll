<?php
declare(strict_types=1);

require_once __DIR__ . '/domain.php';

function hb_api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hb_api_token_plain(): string
{
    return bin2hex(random_bytes(32));
}

function hb_api_token_hash(string $plain): string
{
    return hash('sha256', $plain);
}

function hb_api_require_token(PDO $pdo): array
{
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        hb_api_json(['error' => 'Unauthorized'], 401);
    }
    $plain = trim((string)$m[1]);
    if ($plain === '') {
        hb_api_json(['error' => 'Unauthorized'], 401);
    }
    $hash = hb_api_token_hash($plain);
    $stmt = $pdo->prepare(
        'select t.id as token_id, t.user_id, u.username, hm.household_id
           from api_tokens t
           join users u on u.id = t.user_id
      left join household_members hm on hm.user_id = u.id and hm.is_active = true
          where t.token_hash = :hash
          order by hm.household_id asc nulls last
          limit 1'
    );
    $stmt->execute(['hash' => $hash]);
    $row = $stmt->fetch();
    if (!$row) {
        hb_api_json(['error' => 'Unauthorized'], 401);
    }
    $touch = $pdo->prepare('update api_tokens set last_used_at = now() where id = :id');
    $touch->execute(['id' => (int)$row['token_id']]);
    return $row;
}

function hb_api_household_id(array $auth): int
{
    $householdId = (int)($auth['household_id'] ?? 0);
    if ($householdId < 1) {
        hb_api_json(['error' => 'No household linked to token user'], 400);
    }
    return $householdId;
}

function hb_api_read_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        hb_api_json(['error' => 'Invalid JSON'], 400);
    }
    return $data;
}

function hb_api_date(?string $value, string $field, bool $required = false): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        if ($required) {
            hb_api_json(['error' => $field . ' is required'], 400);
        }
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        hb_api_json(['error' => $field . ' is invalid'], 400);
    }
    return $value;
}

function hb_api_amount_cents(mixed $value, string $field = 'amount', bool $required = true): ?int
{
    if ($value === null || $value === '') {
        if ($required) {
            hb_api_json(['error' => $field . ' is required'], 400);
        }
        return null;
    }
    if (!is_numeric($value)) {
        hb_api_json(['error' => $field . ' is invalid'], 400);
    }
    $cents = (int)round(((float)$value) * 100);
    if ($cents < 0) {
        hb_api_json(['error' => $field . ' must not be negative'], 400);
    }
    return $cents;
}

function hb_api_bool(mixed $value, bool $default = false): bool
{
    if ($value === null || $value === '') {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
}

function hb_api_int_or_null(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $int = (int)$value;
    return $int > 0 ? $int : null;
}

function hb_api_limit(mixed $value, int $default = 100, int $max = 500): int
{
    $limit = (int)($value ?: $default);
    if ($limit < 1) {
        return $default;
    }
    return min($limit, $max);
}

function hb_api_offset(mixed $value): int
{
    return max(0, (int)($value ?: 0));
}

function hb_api_assert_account(PDO $pdo, int $householdId, ?int $id): void
{
    if ($id === null) {
        return;
    }
    $stmt = $pdo->prepare('select id from accounts where id = :id and household_id = :hid');
    $stmt->execute(['id' => $id, 'hid' => $householdId]);
    if (!$stmt->fetch()) {
        hb_api_json(['error' => 'account_id not found'], 400);
    }
}

function hb_api_assert_category(PDO $pdo, int $householdId, ?int $id): void
{
    if ($id === null) {
        return;
    }
    $stmt = $pdo->prepare('select id from categories where id = :id and household_id = :hid and is_active = true');
    $stmt->execute(['id' => $id, 'hid' => $householdId]);
    if (!$stmt->fetch()) {
        hb_api_json(['error' => 'category_id not found'], 400);
    }
}

function hb_api_assert_tag(PDO $pdo, int $householdId, int $id): void
{
    $stmt = $pdo->prepare('select id from tags where id = :id and household_id = :hid and is_active = true');
    $stmt->execute(['id' => $id, 'hid' => $householdId]);
    if (!$stmt->fetch()) {
        hb_api_json(['error' => 'tag_id not found: ' . $id], 400);
    }
}

function hb_api_payee_id(PDO $pdo, int $householdId, mixed $payeeId, mixed $payeeName, bool $create = true): ?int
{
    $id = hb_api_int_or_null($payeeId);
    if ($id !== null) {
        $stmt = $pdo->prepare('select id from payees where id = :id and household_id = :hid');
        $stmt->execute(['id' => $id, 'hid' => $householdId]);
        if (!$stmt->fetch()) {
            hb_api_json(['error' => 'payee_id not found'], 400);
        }
        return $id;
    }

    $name = trim((string)$payeeName);
    if ($name === '') {
        return null;
    }
    $stmt = $pdo->prepare('select id from payees where household_id = :hid and lower(name) = lower(:name) limit 1');
    $stmt->execute(['hid' => $householdId, 'name' => $name]);
    $existing = (int)($stmt->fetchColumn() ?: 0);
    if ($existing > 0) {
        return $existing;
    }
    if (!$create) {
        return null;
    }
    $ins = $pdo->prepare('insert into payees (household_id, name) values (:hid, :name) returning id');
    $ins->execute(['hid' => $householdId, 'name' => $name]);
    return (int)$ins->fetchColumn();
}

function hb_api_set_transaction_tags(PDO $pdo, int $householdId, int $transactionId, array $tagIds): void
{
    $tagIds = hb_normalize_id_list($tagIds);
    foreach ($tagIds as $tagId) {
        hb_api_assert_tag($pdo, $householdId, $tagId);
    }
    $del = $pdo->prepare('delete from transaction_tags where transaction_id = :id');
    $del->execute(['id' => $transactionId]);
    if (!$tagIds) {
        return;
    }
    $ins = $pdo->prepare('insert into transaction_tags (transaction_id, tag_id) values (:tid, :tag) on conflict do nothing');
    foreach ($tagIds as $tagId) {
        $ins->execute(['tid' => $transactionId, 'tag' => $tagId]);
    }
}

function hb_api_transaction_row(PDO $pdo, int $householdId, int $id): array
{
    $stmt = $pdo->prepare(
        "select t.id, t.type, t.booking_date, t.amount_cents, t.currency_code,
                t.account_id, a.name as account_name,
                t.category_id, c.name as category_name,
                t.payee_id, p.name as payee_name,
                t.counterparty_name, t.note, t.is_reviewed, t.external_id,
                t.import_hash, t.planned_payment_id, t.created_at, t.updated_at
           from transactions t
      left join accounts a on a.id = t.account_id
      left join categories c on c.id = t.category_id
      left join payees p on p.id = t.payee_id
          where t.household_id = :hid and t.id = :id"
    );
    $stmt->execute(['hid' => $householdId, 'id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        hb_api_json(['error' => 'Transaction not found'], 404);
    }
    $tagStmt = $pdo->prepare(
        'select tg.id, tg.name, tg.color
           from transaction_tags tt
           join tags tg on tg.id = tt.tag_id
          where tt.transaction_id = :id
          order by tg.name asc'
    );
    $tagStmt->execute(['id' => $id]);
    $row['tags'] = array_map(static fn($t) => [
        'id' => (int)$t['id'],
        'name' => (string)$t['name'],
        'color' => $t['color'] !== null ? (string)$t['color'] : null,
    ], $tagStmt->fetchAll());
    return hb_api_format_transaction($row);
}

function hb_api_format_transaction(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'type' => (string)$row['type'],
        'date' => (string)$row['booking_date'],
        'amount_cents' => (int)$row['amount_cents'],
        'amount' => ((int)$row['amount_cents']) / 100,
        'currency_code' => (string)$row['currency_code'],
        'account' => $row['account_id'] !== null ? ['id' => (int)$row['account_id'], 'name' => (string)$row['account_name']] : null,
        'category' => $row['category_id'] !== null ? ['id' => (int)$row['category_id'], 'name' => (string)$row['category_name']] : null,
        'payee' => $row['payee_id'] !== null ? ['id' => (int)$row['payee_id'], 'name' => (string)$row['payee_name']] : null,
        'counterparty_name' => $row['counterparty_name'] !== null ? (string)$row['counterparty_name'] : null,
        'notes' => $row['note'] !== null ? (string)$row['note'] : null,
        'is_reviewed' => (bool)$row['is_reviewed'],
        'external_id' => $row['external_id'] !== null ? (string)$row['external_id'] : null,
        'import_hash' => $row['import_hash'] !== null ? (string)$row['import_hash'] : null,
        'planned_payment_id' => $row['planned_payment_id'] !== null ? (int)$row['planned_payment_id'] : null,
        'tags' => $row['tags'] ?? [],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function hb_api_format_planned_payment(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'direction' => (string)$row['direction'],
        'amount_cents' => (int)$row['amount_cents'],
        'amount' => ((int)$row['amount_cents']) / 100,
        'planned_date' => (string)$row['planned_date'],
        'status' => (string)$row['status'],
        'priority' => (int)$row['priority'],
        'is_optional' => (bool)$row['is_optional'],
        'account_id' => $row['account_id'] !== null ? (int)$row['account_id'] : null,
        'category_id' => $row['category_id'] !== null ? (int)$row['category_id'] : null,
        'payee_id' => $row['payee_id'] !== null ? (int)$row['payee_id'] : null,
        'note' => $row['note'] !== null ? (string)$row['note'] : null,
        'resolved_at' => $row['resolved_at'] !== null ? (string)$row['resolved_at'] : null,
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function hb_api_format_open_case(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'title' => (string)$row['title'],
        'status' => (string)$row['status'],
        'reference' => $row['reference'] !== null ? (string)$row['reference'] : null,
        'contact_name' => $row['contact_name'] !== null ? (string)$row['contact_name'] : null,
        'contact_details' => $row['contact_details'] !== null ? (string)$row['contact_details'] : null,
        'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}
