<?php
declare(strict_types=1);

require_once __DIR__ . '/domain.php';
require_once __DIR__ . '/oauth.php';
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/security.php';

// Request ID — unique per request for audit logging
$GLOBALS['hb_request_id'] = bin2hex(random_bytes(8));
$GLOBALS['hb_api_audit_written'] = false;
$GLOBALS['hb_api_auth'] = null;
$GLOBALS['hb_api_pdo'] = null;
header('X-Request-ID: ' . $GLOBALS['hb_request_id']);

// CORS headers
hb_cors_send_headers();

function hb_api_audit_once(int $status): void
{
    if (($GLOBALS['hb_api_audit_written'] ?? false) === true) {
        return;
    }
    $pdo = $GLOBALS['hb_api_pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return;
    }
    $auth = $GLOBALS['hb_api_auth'] ?? [];
    if (!is_array($auth)) {
        $auth = [];
    }
    hb_api_audit_log($pdo, $auth, $status);
    $GLOBALS['hb_api_audit_written'] = true;
}

set_exception_handler(function (Throwable $e) {
    error_log('Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    hb_api_audit_once(500);
    echo json_encode([
        'error' => ['code' => 'internal_error', 'message' => 'An error occurred. Please try again.'],
        'request_id' => $GLOBALS['hb_request_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    error_log("Error [$errno]: $errstr in $errfile:$errline");
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    hb_api_audit_once(500);
    echo json_encode([
        'error' => ['code' => 'internal_error', 'message' => 'An error occurred. Please try again.'],
        'request_id' => $GLOBALS['hb_request_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

function hb_api_error(string $code, string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    hb_api_audit_once($status);
    echo json_encode([
        'error' => ['code' => $code, 'message' => $message],
        'request_id' => $GLOBALS['hb_request_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hb_api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    hb_api_audit_once($status);

    // Backward compatibility: normalize legacy error payloads
    // from {"error":"..."} to {"error":{"code":"...","message":"..."}}
    if (isset($payload['error']) && is_string($payload['error'])) {
        $code = 'invalid_request';
        if ($status === 401) {
            $code = 'unauthorized';
        } elseif ($status === 403) {
            $code = 'insufficient_scope';
        } elseif ($status === 404) {
            $code = 'not_found';
        } elseif ($status === 405) {
            $code = 'method_not_allowed';
        } elseif ($status === 409) {
            $code = 'conflict';
        } elseif ($status >= 500) {
            $code = 'internal_error';
        }
        $payload['error'] = [
            'code' => $code,
            'message' => $payload['error'],
        ];
    }

    $payload['request_id'] = $GLOBALS['hb_request_id'] ?? null;
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
    $GLOBALS['hb_api_pdo'] = $pdo;

    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        hb_api_rate_limit($pdo, null);
        hb_api_error('unauthorized', 'Missing or invalid authorization header', 401);
    }
    $plain = trim((string)$m[1]);
    if ($plain === '') {
        hb_api_rate_limit($pdo, null);
        hb_api_error('unauthorized', 'Empty bearer token', 401);
    }

    // Try existing api_tokens first (backward compatible)
    $hash = hb_api_token_hash($plain);
    $stmt = $pdo->prepare(
        'select t.id as token_id, t.user_id, u.username, hm.household_id, t.expires_at, t.revoked_at
           from api_tokens t
           join users u on u.id = t.user_id
      left join household_members hm on hm.user_id = u.id and hm.is_active = true
          where t.token_hash = :hash
          order by hm.household_id asc nulls last
          limit 1'
    );
    $stmt->execute(['hash' => $hash]);
    $row = $stmt->fetch();

    if ($row) {
        // Check revoked and expired
        if ($row['revoked_at'] !== null) {
            hb_api_error('token_revoked', 'Token has been revoked', 401);
        }
        if ($row['expires_at'] !== null && new DateTimeImmutable() > new DateTimeImmutable($row['expires_at'])) {
            hb_api_error('token_expired', 'Token has expired', 401);
        }
        // Touch last_used_at
        try {
            $touch = $pdo->prepare('update api_tokens set last_used_at = now() where id = :id');
            $touch->execute(['id' => (int)$row['token_id']]);
        } catch (Exception) {
            // Non-blocking
        }
        // Add default scopes for backward compat
        $row['scopes'] = '*';
        $GLOBALS['hb_api_auth'] = $row;
        hb_api_rate_limit($pdo, (string)($row['token_id'] ?? ''));
        return $row;
    }

    // Try OAuth access tokens
    try {
        $oauthAuth = hb_oauth_validate_access_token($pdo, $plain);
        if ($oauthAuth) {
            $GLOBALS['hb_api_auth'] = $oauthAuth;
            hb_api_rate_limit($pdo, (string)($oauthAuth['token_id'] ?? ''));
            return $oauthAuth;
        }
    } catch (Exception) {
        // Non-blocking
    }

    hb_api_rate_limit($pdo, null);
    hb_api_error('unauthorized', 'Invalid or expired token', 401);
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

function hb_api_assert_receipt(PDO $pdo, int $householdId, ?int $id): void
{
    if ($id === null) {
        return;
    }
    $stmt = $pdo->prepare('select id from receipts where id = :id and household_id = :hid and status <> :archived');
    $stmt->execute(['id' => $id, 'hid' => $householdId, 'archived' => 'archived']);
    if (!$stmt->fetch()) {
        hb_api_json(['error' => 'receipt_id not found'], 400);
    }
}

function hb_api_dbal_assert_account(\Doctrine\DBAL\Connection $db, int $householdId, ?int $id): void
{
    if ($id === null) {
        return;
    }
    if (!$db->fetchOne('select id from accounts where id = :id and household_id = :hid', ['id' => $id, 'hid' => $householdId])) {
        hb_api_json(['error' => 'account_id not found'], 400);
    }
}

function hb_api_dbal_assert_category(\Doctrine\DBAL\Connection $db, int $householdId, ?int $id): void
{
    if ($id === null) {
        return;
    }
    if (!$db->fetchOne('select id from categories where id = :id and household_id = :hid and is_active = true', ['id' => $id, 'hid' => $householdId])) {
        hb_api_json(['error' => 'category_id not found'], 400);
    }
}

function hb_api_dbal_payee_id(\Doctrine\DBAL\Connection $db, int $householdId, mixed $payeeId, mixed $payeeName, bool $create = true): ?int
{
    $id = hb_api_int_or_null($payeeId);
    if ($id !== null) {
        if (!$db->fetchOne('select id from payees where id = :id and household_id = :hid', ['id' => $id, 'hid' => $householdId])) {
            hb_api_json(['error' => 'payee_id not found'], 400);
        }
        return $id;
    }

    $name = trim((string)$payeeName);
    if ($name === '') {
        return null;
    }
    $existing = (int)($db->fetchOne(
        'select id from payees where household_id = :hid and lower(name) = lower(:name) limit 1',
        ['hid' => $householdId, 'name' => $name]
    ) ?: 0);
    if ($existing > 0) {
        return $existing;
    }
    if (!$create) {
        return null;
    }
    return hb_dbal_insert_and_get_id($db, 'payees', ['household_id' => $householdId, 'name' => $name]);
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
                t.import_hash, t.planned_payment_id, t.receipt_id, t.split_group_id,
                t.split_parent_id, t.split_note, t.created_at, t.updated_at
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
        'receipt_id' => isset($row['receipt_id']) && $row['receipt_id'] !== null ? (int)$row['receipt_id'] : null,
        'split_group_id' => isset($row['split_group_id']) && $row['split_group_id'] !== null ? (int)$row['split_group_id'] : null,
        'split_parent_id' => isset($row['split_parent_id']) && $row['split_parent_id'] !== null ? (int)$row['split_parent_id'] : null,
        'split_note' => isset($row['split_note']) && $row['split_note'] !== null ? (string)$row['split_note'] : null,
        'tags' => $row['tags'] ?? [],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function hb_api_receipt_row(PDO $pdo, int $householdId, int $id, bool $includeGroups = true): array
{
    $stmt = $pdo->prepare(
        'select id, merchant, receipt_date, total_amount_cents, currency_code, file_path,
                storage_key, file_hash, mime_type, ocr_json, status, created_at, updated_at
           from receipts
          where household_id = :hid and id = :id'
    );
    $stmt->execute(['hid' => $householdId, 'id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        hb_api_json(['error' => 'Receipt not found'], 404);
    }
    $receipt = hb_api_format_receipt($row);
    if ($includeGroups) {
        $groupsStmt = $pdo->prepare(
            'select id
               from transaction_groups
              where household_id = :hid and receipt_id = :receipt_id and status <> :archived
              order by booking_date desc, id desc'
        );
        $groupsStmt->execute(['hid' => $householdId, 'receipt_id' => $id, 'archived' => 'archived']);
        $receipt['transaction_groups'] = array_map(
            static fn($groupId) => (int)$groupId,
            $groupsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []
        );
    }
    return $receipt;
}

function hb_api_format_receipt(array $row): array
{
    $ocrJson = null;
    if ($row['ocr_json'] !== null) {
        $decoded = json_decode((string)$row['ocr_json'], true);
        $ocrJson = is_array($decoded) ? $decoded : null;
    }
    return [
        'id' => (int)$row['id'],
        'merchant' => $row['merchant'] !== null ? (string)$row['merchant'] : null,
        'receipt_date' => $row['receipt_date'] !== null ? (string)$row['receipt_date'] : null,
        'total_amount_cents' => $row['total_amount_cents'] !== null ? (int)$row['total_amount_cents'] : null,
        'total_amount' => $row['total_amount_cents'] !== null ? ((int)$row['total_amount_cents']) / 100 : null,
        'currency_code' => (string)$row['currency_code'],
        'file_path' => $row['file_path'] !== null ? (string)$row['file_path'] : null,
        'storage_key' => $row['storage_key'] !== null ? (string)$row['storage_key'] : null,
        'file_hash' => $row['file_hash'] !== null ? (string)$row['file_hash'] : null,
        'mime_type' => $row['mime_type'] !== null ? (string)$row['mime_type'] : null,
        'ocr_json' => $ocrJson,
        'status' => (string)$row['status'],
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function hb_api_transaction_group_row(PDO $pdo, int $householdId, int $id): array
{
    $stmt = $pdo->prepare(
        'select tg.id, tg.receipt_id, tg.account_id, a.name as account_name,
                tg.payee_id, p.name as payee_name, tg.payee, tg.booking_date,
                tg.total_amount_cents, tg.currency_code, tg.type, tg.notes, tg.status,
                tg.external_id, tg.import_hash, tg.matched_transaction_id,
                tg.created_at, tg.updated_at
           from transaction_groups tg
      left join accounts a on a.id = tg.account_id
      left join payees p on p.id = tg.payee_id
          where tg.household_id = :hid and tg.id = :id'
    );
    $stmt->execute(['hid' => $householdId, 'id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        hb_api_json(['error' => 'Transaction group not found'], 404);
    }
    $splitStmt = $pdo->prepare(
        'select ts.id, ts.transaction_id, ts.amount_cents, ts.category_id, c.name as category_name,
                ts.note, ts.sort_order, ts.created_at, ts.updated_at
           from transaction_splits ts
      left join categories c on c.id = ts.category_id
          where ts.household_id = :hid and ts.transaction_group_id = :gid
          order by ts.sort_order asc, ts.id asc'
    );
    $splitStmt->execute(['hid' => $householdId, 'gid' => $id]);
    $row['splits'] = $splitStmt->fetchAll();
    return hb_api_format_transaction_group($row);
}

function hb_api_format_transaction_group(array $row): array
{
    $splits = [];
    foreach (($row['splits'] ?? []) as $split) {
        $splits[] = [
            'id' => (int)$split['id'],
            'transaction_id' => $split['transaction_id'] !== null ? (int)$split['transaction_id'] : null,
            'amount_cents' => (int)$split['amount_cents'],
            'amount' => ((int)$split['amount_cents']) / 100,
            'category' => $split['category_id'] !== null ? ['id' => (int)$split['category_id'], 'name' => (string)$split['category_name']] : null,
            'note' => $split['note'] !== null ? (string)$split['note'] : null,
            'sort_order' => (int)$split['sort_order'],
            'created_at' => (string)$split['created_at'],
            'updated_at' => (string)$split['updated_at'],
        ];
    }
    return [
        'id' => (int)$row['id'],
        'receipt_id' => $row['receipt_id'] !== null ? (int)$row['receipt_id'] : null,
        'account' => $row['account_id'] !== null ? ['id' => (int)$row['account_id'], 'name' => (string)$row['account_name']] : null,
        'payee' => $row['payee_id'] !== null ? ['id' => (int)$row['payee_id'], 'name' => (string)$row['payee_name']] : null,
        'payee_text' => $row['payee'] !== null ? (string)$row['payee'] : null,
        'booking_date' => (string)$row['booking_date'],
        'total_amount_cents' => (int)$row['total_amount_cents'],
        'total_amount' => ((int)$row['total_amount_cents']) / 100,
        'currency_code' => (string)$row['currency_code'],
        'type' => (string)$row['type'],
        'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
        'status' => (string)$row['status'],
        'external_id' => $row['external_id'] !== null ? (string)$row['external_id'] : null,
        'import_hash' => $row['import_hash'] !== null ? (string)$row['import_hash'] : null,
        'matched_transaction_id' => $row['matched_transaction_id'] !== null ? (int)$row['matched_transaction_id'] : null,
        'splits' => $splits,
        'split_total_cents' => array_sum(array_map(static fn($split) => (int)$split['amount_cents'], $splits)),
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

function hb_api_format_recurring_rule(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'kind' => (string)$row['kind'],
        'is_active' => (bool)$row['is_active'],
        'schedule' => [
            'unit' => (string)$row['schedule_unit'],
            'interval' => (int)$row['schedule_interval'],
            'weekdays' => $row['schedule_weekdays'] !== null ? (string)$row['schedule_weekdays'] : null,
            'monthday' => $row['schedule_monthday'] !== null ? (int)$row['schedule_monthday'] : null,
            'start_date' => (string)$row['schedule_start_date'],
        ],
        'next_run_at' => $row['next_run_at'] !== null ? (string)$row['next_run_at'] : null,
        'last_run_at' => $row['last_run_at'] !== null ? (string)$row['last_run_at'] : null,
        'created_at' => (string)$row['created_at'],
        'updated_at' => (string)$row['updated_at'],
    ];
}

function hb_api_require_scope(array $auth, string $requiredScope): void
{
    // Wildcard scope (*) means full access (backward compat for api_tokens)
    $scopes = $auth['scopes'] ?? '';
    if ($scopes === '*') {
        return; // Old api_tokens without scope restrictions
    }
    $scopeList = explode(' ', $scopes);
    if (!in_array($requiredScope, $scopeList, true)) {
        hb_api_error('insufficient_scope', "Scope '$requiredScope' required", 403);
    }
}

function hb_api_rate_limit(PDO $pdo, ?string $tokenHash = null, int $limit = 100): void
{
    $window = 60; // 60-second window
    $cutoff = (new DateTimeImmutable())->modify('-' . $window . ' seconds')->format('c');
    $ip = hb_request_ip();
    if ($ip === null || $ip === '') {
        $ip = '127.0.0.1';
    }

    try {
        // Cleanup old records
        $cleanup = $pdo->prepare('delete from rate_limits where created_at < :cutoff');
        $cleanup->execute(['cutoff' => $cutoff]);

        if ($tokenHash !== null) {
            // Per-token limit: 100 req/min
            $stmt = $pdo->prepare(
                'select count(*) from rate_limits where token_id = :token and created_at >= :cutoff'
            );
            $stmt->execute(['token' => $tokenHash, 'cutoff' => $cutoff]);
            $count = (int)$stmt->fetchColumn();

            if ($count >= $limit) {
                header('Retry-After: 60');
                hb_api_error('rate_limit_exceeded', 'Too many requests', 429);
            }

            // Record this request
            $rec = $pdo->prepare('insert into rate_limits (ip, token_id, action) values (:ip, :token, :action)');
            $rec->execute(['ip' => $ip, 'token' => $tokenHash, 'action' => 'api']);
        } else {
            // Per-IP limit (unauthenticated): 10 req/min
            $limit = 10;
            $stmt = $pdo->prepare(
                'select count(*) from rate_limits where ip = :ip and created_at >= :cutoff and token_id is null'
            );
            $stmt->execute(['ip' => $ip, 'cutoff' => $cutoff]);
            $count = (int)$stmt->fetchColumn();

            if ($count >= $limit) {
                header('Retry-After: 60');
                hb_api_error('rate_limit_exceeded', 'Too many requests', 429);
            }

            // Record this request
            $rec = $pdo->prepare('insert into rate_limits (ip, action) values (:ip, :action)');
            $rec->execute(['ip' => $ip, 'action' => 'api']);
        }
    } catch (PDOException $e) {
        error_log('Rate limiting error: ' . $e->getMessage());
        // Non-blocking – don't fail on rate limit errors
    }
}

function hb_api_audit_log(PDO $pdo, array $auth = [], int $statusCode = 200): void
{
    try {
        $endpoint = $_SERVER['REQUEST_URI'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
        $ip = hb_request_ip();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $pdo->prepare(
            'insert into api_audit_log (request_id, user_id, household_id, token_id, client_id, endpoint, method, ip, user_agent, status_code)
             values (:rid, :uid, :hid, :tid, :cid, :ep, :m, :ip, :ua, :status)'
        );
        $stmt->execute([
            'rid' => $GLOBALS['hb_request_id'] ?? null,
            'uid' => $auth['user_id'] ?? null,
            'hid' => $auth['household_id'] ?? null,
            'tid' => $auth['token_id'] ?? null,
            'cid' => $auth['client_id'] ?? null,
            'ep' => $endpoint,
            'm' => $method,
            'ip' => $ip,
            'ua' => $userAgent,
            'status' => $statusCode,
        ]);
    } catch (Exception $e) {
        error_log('Audit logging error: ' . $e->getMessage());
        // Non-blocking
    }
}

function hb_api_idempotency_check(PDO $pdo, array $auth, string $key, string $requestHash): ?array
{
    try {
        $householdId = $auth['household_id'] ?? null;
        $tokenId = (string)($auth['token_id'] ?? '');

        if (!$householdId || $tokenId === '') {
            return null;
        }

        $stmt = $pdo->prepare(
            'select response_body, status_code, request_hash from api_idempotency_keys
             where household_id = :hid and token_id = :tid and idempotency_key = :key'
        );
        $stmt->execute([
            'hid' => $householdId,
            'tid' => $tokenId,
            'key' => $key,
        ]);
        $row = $stmt->fetch();

        if ($row) {
            if (!hash_equals((string)$row['request_hash'], $requestHash)) {
                hb_api_error('idempotency_conflict', 'Idempotency key reused with different payload', 409);
            }
            return [
                'response_body' => $row['response_body'],
                'status_code' => (int)$row['status_code'],
            ];
        }
    } catch (Exception $e) {
        error_log('Idempotency check error: ' . $e->getMessage());
    }
    return null;
}

function hb_api_idempotency_prepare(PDO $pdo, array $auth): array
{
    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    $requestBody = file_get_contents('php://input') ?: '';
    $requestHash = hash('sha256', $_SERVER['REQUEST_METHOD'] . $_SERVER['REQUEST_URI'] . $requestBody);
    if ($idempotencyKey) {
        $cached = hb_api_idempotency_check($pdo, $auth, (string)$idempotencyKey, $requestHash);
        if ($cached) {
            hb_api_send_cached_idempotent($cached);
        }
    }
    return [$idempotencyKey ? (string)$idempotencyKey : null, $requestHash];
}

function hb_api_send_cached_idempotent(array $cached): void
{
    $status = (int)($cached['status_code'] ?? 200);
    $body = (string)($cached['response_body'] ?? '{}');
    $decoded = json_decode($body, true);

    if (is_array($decoded)) {
        $decoded['request_id'] = $GLOBALS['hb_request_id'] ?? null;
        hb_api_json($decoded, $status);
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    hb_api_audit_once($status);
    echo json_encode([
        'error' => ['code' => 'internal_error', 'message' => 'Invalid cached idempotency response'],
        'request_id' => $GLOBALS['hb_request_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function hb_api_idempotency_store(PDO $pdo, array $auth, string $key, string $requestHash, string $responseBody, int $statusCode): void
{
    try {
        $householdId = $auth['household_id'] ?? null;
        $tokenId = (string)($auth['token_id'] ?? '');

        if (!$householdId || $tokenId === '') {
            return;
        }

        $stmt = $pdo->prepare(
            'insert into api_idempotency_keys (household_id, token_id, idempotency_key, request_hash, response_body, status_code)
             values (:hid, :tid, :key, :hash, :body, :status)
             on conflict (household_id, token_id, idempotency_key) do update
             set response_body = :body, status_code = :status, created_at = now()'
        );
        $stmt->execute([
            'hid' => $householdId,
            'tid' => $tokenId,
            'key' => $key,
            'hash' => $requestHash,
            'body' => $responseBody,
            'status' => $statusCode,
        ]);
    } catch (Exception $e) {
        error_log('Idempotency store error: ' . $e->getMessage());
        // Non-blocking
    }
}

function hb_api_idempotency_store_if_needed(PDO $pdo, array $auth, ?string $key, string $requestHash, array $payload, int $statusCode = 200): void
{
    if ($key === null || $key === '') {
        return;
    }
    hb_api_idempotency_store($pdo, $auth, $key, $requestHash, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', $statusCode);
}
