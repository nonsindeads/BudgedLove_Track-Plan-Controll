<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dbal.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/cloud_sqlite.php';

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

function hb_set_current_household(int $householdId, ?PDO $pdo = null, bool $forceCloudTakeover = false): void
{
    $previousHouseholdId = (int)($_SESSION['household_id'] ?? 0);
    if ($pdo instanceof PDO && $previousHouseholdId > 0 && $previousHouseholdId !== $householdId) {
        hb_cloud_sqlite_session_stop($pdo, $previousHouseholdId);
    }
    $_SESSION['household_id'] = $householdId;
    if ($pdo instanceof PDO) {
        hb_cloud_sqlite_session_start($pdo, $householdId, $forceCloudTakeover);
    }
}

function hb_cloud_sqlite_session_enabled(array $household): bool
{
    return (string)($household['data_residency_mode'] ?? 'server') === 'cloud'
        && (string)($household['cloud_primary_provider'] ?? '') === 'nextcloud'
        && !empty($household['cloud_require_ephemeral']);
}

function hb_cloud_session_device_label(): string
{
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return 'Unknown device';
    }
    $platform = 'Desktop';
    if (stripos($ua, 'iphone') !== false) {
        $platform = 'iPhone';
    } elseif (stripos($ua, 'ipad') !== false) {
        $platform = 'iPad';
    } elseif (stripos($ua, 'android') !== false) {
        $platform = 'Android';
    } elseif (stripos($ua, 'macintosh') !== false || stripos($ua, 'mac os x') !== false) {
        $platform = 'Mac';
    } elseif (stripos($ua, 'windows') !== false) {
        $platform = 'Windows';
    } elseif (stripos($ua, 'linux') !== false) {
        $platform = 'Linux';
    }
    $browser = 'Browser';
    if (preg_match('/edg\/[\d.]+/i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/crios\/[\d.]+/i', $ua) || preg_match('/chrome\/[\d.]+/i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/fxios\/[\d.]+/i', $ua) || preg_match('/firefox\/[\d.]+/i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/version\/[\d.]+.*safari/i', $ua) && stripos($ua, 'chrome') === false) {
        $browser = 'Safari';
    }
    return $platform . ' · ' . $browser;
}

function hb_cloud_session_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        'create table if not exists household_cloud_sessions (
            household_id int primary key references households(id) on delete cascade,
            session_id varchar(190) not null,
            user_id int null references users(id) on delete set null,
            username varchar(255) null,
            device_label varchar(255) null,
            user_agent text null,
            created_at timestamptz not null default now(),
            last_seen_at timestamptz not null default now()
        )'
    );
    $done = true;
}

function hb_cloud_session_fetch_lease(PDO $pdo, int $householdId): ?array
{
    hb_cloud_session_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'select household_id, session_id, user_id, username, device_label, user_agent, created_at, last_seen_at
           from household_cloud_sessions
          where household_id = :hid
          limit 1'
    );
    $stmt->execute(['hid' => $householdId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function hb_cloud_session_touch(PDO $pdo, int $householdId): void
{
    hb_cloud_session_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'update household_cloud_sessions
            set last_seen_at = now(),
                device_label = :device_label,
                user_agent = :user_agent
          where household_id = :hid and session_id = :session_id'
    );
    $stmt->execute([
        'hid' => $householdId,
        'session_id' => session_id(),
        'device_label' => hb_cloud_session_device_label(),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);
}

function hb_cloud_session_release(PDO $pdo, int $householdId): void
{
    hb_cloud_session_ensure_table($pdo);
    $stmt = $pdo->prepare('delete from household_cloud_sessions where household_id = :hid and session_id = :session_id');
    $stmt->execute([
        'hid' => $householdId,
        'session_id' => session_id(),
    ]);
}

function hb_cloud_session_claim(PDO $pdo, int $householdId, bool $force = false): void
{
    hb_cloud_session_ensure_table($pdo);
    $currentSessionId = session_id();
    $stmt = $pdo->prepare(
        'insert into household_cloud_sessions (household_id, session_id, user_id, username, device_label, user_agent, created_at, last_seen_at)
         values (:hid, :session_id, :user_id, :username, :device_label, :user_agent, now(), now())
         on conflict (household_id) do update
            set session_id = excluded.session_id,
                user_id = excluded.user_id,
                username = excluded.username,
                device_label = excluded.device_label,
                user_agent = excluded.user_agent,
                last_seen_at = now()'
    );
    $stmt->execute([
        'hid' => $householdId,
        'session_id' => $currentSessionId,
        'user_id' => hb_current_user_id() ?: null,
        'username' => (string)($_SESSION['username'] ?? ''),
        'device_label' => hb_cloud_session_device_label(),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);
    unset($_SESSION['hb_cloud_session_conflict']);
}

function hb_cloud_sqlite_session_start(PDO $pdo, int $householdId, bool $forceTakeover = false): void
{
    if ($householdId < 1) {
        return;
    }
    if ((int)($_SESSION['hb_cloud_sqlite_household_id'] ?? 0) === $householdId) {
        $sqlitePath = (string)($_SESSION['hb_cloud_sqlite_path'] ?? '');
        if ($sqlitePath !== '' && is_file($sqlitePath) && filesize($sqlitePath) > 0) {
            return;
        }
        unset(
            $_SESSION['hb_cloud_sqlite_household_id'],
            $_SESSION['hb_cloud_sqlite_env'],
            $_SESSION['hb_cloud_sqlite_path'],
            $_SESSION['hb_cloud_sqlite_session_dir']
        );
    }
    $failure = static function (string $message): void {
        throw new RuntimeException($message);
    };
    $configFailure = hb_t('Cloud mode is enabled, but the cloud SQLite session cannot be started. Please check the Nextcloud configuration.');
    $runtimeFailure = hb_t('Cloud mode is enabled, but the encrypted cloud database cannot be opened. No server-side fallback was used.');

    $stmt = $pdo->prepare(
        'select data_residency_mode, cloud_primary_provider, cloud_user_identifier, cloud_remote_path,
                cloud_endpoint_url, cloud_access_secret, cloud_require_ephemeral
           from households
          where id = :id
          limit 1'
    );
    $stmt->execute(['id' => $householdId]);
    $household = $stmt->fetch();
    if (!$household || !hb_cloud_sqlite_session_enabled($household)) {
        return;
    }

    $endpoint = trim((string)($household['cloud_endpoint_url'] ?? ''));
    $user = trim((string)($household['cloud_user_identifier'] ?? ''));
    $secret = (string)($household['cloud_access_secret'] ?? '');
    $remotePath = trim((string)($household['cloud_remote_path'] ?? ''));
    if ($endpoint === '' || $user === '' || $secret === '' || $remotePath === '') {
        $failure($configFailure);
    }
    hb_cloud_session_claim($pdo, $householdId, $forceTakeover);

    $sessionId = hb_cloud_runtime_session_id($householdId);
    $sqliteRemote = rtrim($remotePath, '/') . '/session-db/household-' . $householdId . '.sqlite.enc';
    $script = realpath(__DIR__ . '/../tools/cloud/sqlite-session-start.sh');
    if ($script === false || !is_file($script)) {
        $failure($configFailure);
    }
    $env = [
        'NC_WEBDAV_BASE' => $endpoint,
        'NC_USER' => $user,
        'NC_PASS' => $secret,
        'SQLITE_REMOTE' => $sqliteRemote,
        'SQLITE_KEY' => $secret,
        'SESSION_ID' => $sessionId,
        'SESSION_ROOT' => '/tmp/budgetlove-runtime',
        'ALLOW_INIT_EMPTY' => '1',
    ];
    $result = hb_run_script_with_env($script, $env);
    if ($result['code'] !== 0) {
        error_log('BudgetLove cloud sqlite start failed: ' . $result['stderr']);
        $failure($runtimeFailure);
    }
    $exports = hb_parse_env_lines((string)$result['stdout']);
    $sqlitePath = (string)($exports['HB_SQLITE_PATH'] ?? '');
    $sessionDir = (string)($exports['HB_SQLITE_SESSION_DIR'] ?? '');
    if ($sqlitePath === '' || !is_file($sqlitePath) || filesize($sqlitePath) <= 0) {
        error_log('BudgetLove cloud sqlite start failed: missing sqlite path in script output');
        $failure($runtimeFailure);
    }
    try {
        hb_cloud_sqlite_bootstrap_if_needed($pdo, $sqlitePath, $householdId);
    } catch (Throwable $e) {
        error_log('BudgetLove cloud sqlite bootstrap failed: ' . $e->getMessage());
        $failure($runtimeFailure);
    }
    $_SESSION['hb_cloud_sqlite_household_id'] = $householdId;
    $_SESSION['hb_cloud_sqlite_path'] = $sqlitePath;
    $_SESSION['hb_cloud_sqlite_session_dir'] = $sessionDir;
    $_SESSION['hb_cloud_sqlite_env'] = [
        'NC_WEBDAV_BASE' => $endpoint,
        'NC_USER' => $user,
        'NC_PASS' => $secret,
        'SQLITE_REMOTE' => $sqliteRemote,
        'SQLITE_KEY' => $secret,
        'SESSION_ID' => $sessionId,
        'SESSION_ROOT' => '/tmp/budgetlove-runtime',
        'KEEP_LOCAL' => '1',
    ];
    hb_cloud_sqlite_register_sync_shutdown($_SESSION['hb_cloud_sqlite_env']);
}

function hb_cloud_sqlite_session_stop(PDO $pdo, int $householdId): void
{
    if ($householdId < 1) {
        return;
    }
    $activeHouseholdId = (int)($_SESSION['hb_cloud_sqlite_household_id'] ?? 0);
    if ($activeHouseholdId !== $householdId) {
        return;
    }
    $env = $_SESSION['hb_cloud_sqlite_env'] ?? null;
    if (!is_array($env)) {
        unset(
            $_SESSION['hb_cloud_sqlite_household_id'],
            $_SESSION['hb_cloud_sqlite_env'],
            $_SESSION['hb_cloud_sqlite_path'],
            $_SESSION['hb_cloud_sqlite_session_dir']
        );
        return;
    }
    $script = realpath(__DIR__ . '/../tools/cloud/sqlite-session-stop.sh');
    if ($script === false || !is_file($script)) {
        unset(
            $_SESSION['hb_cloud_sqlite_household_id'],
            $_SESSION['hb_cloud_sqlite_env'],
            $_SESSION['hb_cloud_sqlite_path'],
            $_SESSION['hb_cloud_sqlite_session_dir']
        );
        return;
    }
    $result = hb_run_script_with_env($script, $env);
    if ($result['code'] !== 0) {
        error_log('BudgetLove cloud sqlite stop failed: ' . $result['stderr']);
    }
    hb_cloud_session_release($pdo, $householdId);
    unset(
        $_SESSION['hb_cloud_sqlite_household_id'],
        $_SESSION['hb_cloud_sqlite_env'],
        $_SESSION['hb_cloud_sqlite_path'],
        $_SESSION['hb_cloud_sqlite_session_dir']
    );
}

function hb_cloud_runtime_session_id(int $householdId): string
{
    return 'household-' . $householdId;
}

function hb_cloud_sqlite_register_sync_shutdown(array $env): void
{
    $GLOBALS['hb_cloud_sqlite_shutdown_env'] = $env;
    if (empty($GLOBALS['hb_cloud_sqlite_shutdown_registered'])) {
        register_shutdown_function('hb_cloud_sqlite_shutdown_sync');
        $GLOBALS['hb_cloud_sqlite_shutdown_registered'] = true;
    }
}

function hb_cloud_sqlite_shutdown_sync(): void
{
    $env = $GLOBALS['hb_cloud_sqlite_shutdown_env'] ?? null;
    if (!is_array($env)) {
        return;
    }
    $script = realpath(__DIR__ . '/../tools/cloud/sqlite-session-stop.sh');
    if ($script !== false && is_file($script)) {
        $result = hb_run_script_with_env($script, $env);
        if ($result['code'] !== 0) {
            error_log('BudgetLove cloud sqlite shutdown sync failed: ' . trim((string)$result['stderr']));
        }
    }
}

function hb_parse_env_lines(string $stdout): array
{
    $result = [];
    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if (preg_match('/^[A-Z0-9_]+$/', $key) !== 1) {
            continue;
        }
        $result[$key] = $value;
    }
    return $result;
}

function hb_run_script_with_env(string $script, array $env): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $baseEnv = [];
    foreach ($_ENV as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $baseEnv[(string)$k] = (string)$v;
        }
    }
    foreach ($env as $k => $v) {
        if (!is_string($k) || $k === '') {
            continue;
        }
        if (is_scalar($v) || $v === null) {
            $baseEnv[$k] = (string)$v;
        }
    }
    $proc = proc_open($script, $descriptor, $pipes, null, $baseEnv);
    if (!is_resource($proc)) {
        return ['code' => 1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['code' => (int)$code, 'stdout' => $stdout, 'stderr' => $stderr];
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
    $db = hb_dbal_server();
    $pdo->beginTransaction();
    try {
        if (hb_households_support_creator($pdo)) {
            $householdId = hb_dbal_insert_and_get_id($db, 'households', [
                'name' => $name,
                'currency_code' => strtoupper($currency ?: 'EUR'),
                'month_close_mode' => $mode,
                'salary_day' => $salaryDay,
                'created_by_user_id' => $userId,
            ]);
        } else {
            $householdId = hb_dbal_insert_and_get_id($db, 'households', [
                'name' => $name,
                'currency_code' => strtoupper($currency ?: 'EUR'),
                'month_close_mode' => $mode,
                'salary_day' => $salaryDay,
            ]);
        }

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
         select :hid, name, type, null, sort_order, is_active, :created_at, :updated_at
           from categories
          where household_id is null"
    );
    $cat->execute([
        'hid' => $householdId,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);

    // copy tags
    $tag = $pdo->prepare(
        "insert into tags (household_id, name, color, is_active, created_at, updated_at)
         select :hid, name, color, is_active, :created_at, :updated_at
           from tags
          where household_id is null"
    );
    $tag->execute([
        'hid' => $householdId,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
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
    return ['first_of_month', 'salary_day', 'income_anchor'];
}

function hb_month_close_mode_label(string $mode): string
{
    $labels = [
        'first_of_month' => hb_t('Start of month'),
        'salary_day' => hb_t('Salary day'),
        'income_anchor' => hb_t('Actual salary payment'),
    ];
    return $labels[$mode] ?? $mode;
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

function hb_normalize_id_list(array $values): array
{
    $ids = [];
    foreach ($values as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function hb_pg_int_array_to_php(mixed $value): array
{
    if (is_array($value)) {
        return hb_normalize_id_list($value);
    }
    $raw = trim((string)$value);
    if ($raw === '' || $raw === '{}') {
        return [];
    }
    $raw = trim($raw, '{}');
    if ($raw === '') {
        return [];
    }
    return hb_normalize_id_list(str_getcsv($raw));
}

function hb_php_int_array_to_pg(array $values): string
{
    return '{' . implode(',', hb_normalize_id_list($values)) . '}';
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

function hb_household_cloud_config(PDO $pdo, int $householdId): ?array
{
    if ($householdId < 1) {
        return null;
    }
    $stmt = $pdo->prepare(
        'select id, data_residency_mode, cloud_primary_provider, cloud_user_identifier, cloud_remote_path,
                cloud_endpoint_url, cloud_access_secret, cloud_require_ephemeral
           from households
          where id = :id
          limit 1'
    );
    $stmt->execute(['id' => $householdId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function hb_household_finance_cloud_mode(PDO $pdo, int $householdId): bool
{
    $config = hb_household_cloud_config($pdo, $householdId);
    if (!$config) {
        return false;
    }
    return (string)($config['data_residency_mode'] ?? 'server') === 'cloud'
        && (string)($config['cloud_primary_provider'] ?? '') === 'nextcloud'
        && !empty($config['cloud_require_ephemeral']);
}

function hb_household_runtime_sqlite_path(): string
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $path = (string)($_SESSION['hb_cloud_sqlite_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }
    $path = (string)($GLOBALS['hb_cloud_sqlite_request_path'] ?? '');
    if ($path !== '' && is_file($path)) {
        return $path;
    }
    return '';
}

function hb_household_pdo(?PDO $serverPdo = null, ?int $householdId = null): PDO
{
    static $cache = [];

    $serverPdo = $serverPdo ?? hb_get_pdo();
    if ($householdId !== null && $householdId > 0) {
        hb_cloud_sqlite_request_start($serverPdo, $householdId);
    }

    $sqlitePath = hb_household_runtime_sqlite_path();
    if ($sqlitePath !== '') {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['hb_cloud_sqlite_env']) && is_array($_SESSION['hb_cloud_sqlite_env'])) {
            hb_cloud_sqlite_register_sync_shutdown($_SESSION['hb_cloud_sqlite_env']);
        }
        if (!isset($cache[$sqlitePath])) {
            $cache[$sqlitePath] = new PDO('sqlite:' . $sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $cache[$sqlitePath]->exec('pragma busy_timeout = 5000');
        }
        return $cache[$sqlitePath];
    }

    return $serverPdo;
}

function hb_cloud_sqlite_request_start(PDO $pdo, int $householdId): void
{
    if ($householdId < 1) {
        return;
    }
    if (hb_household_runtime_sqlite_path() !== '') {
        return;
    }
    if (!hb_household_finance_cloud_mode($pdo, $householdId)) {
        return;
    }
    if (!empty($GLOBALS['hb_cloud_sqlite_request_started']) && (int)($GLOBALS['hb_cloud_sqlite_request_household_id'] ?? 0) === $householdId) {
        return;
    }

    $config = hb_household_cloud_config($pdo, $householdId);
    if (!$config) {
        throw new RuntimeException('Cloud configuration missing.');
    }
    $endpoint = trim((string)($config['cloud_endpoint_url'] ?? ''));
    $user = trim((string)($config['cloud_user_identifier'] ?? ''));
    $secret = (string)($config['cloud_access_secret'] ?? '');
    $remotePath = trim((string)($config['cloud_remote_path'] ?? ''));
    if ($endpoint === '' || $user === '' || $secret === '' || $remotePath === '') {
        throw new RuntimeException('Cloud configuration incomplete.');
    }

    $runtimeId = hb_cloud_runtime_session_id($householdId);
    $sqliteRemote = rtrim($remotePath, '/') . '/session-db/household-' . $householdId . '.sqlite.enc';
    $script = realpath(__DIR__ . '/../tools/cloud/sqlite-session-start.sh');
    if ($script === false || !is_file($script)) {
        throw new RuntimeException('Cloud SQLite start script missing.');
    }
    $env = [
        'NC_WEBDAV_BASE' => $endpoint,
        'NC_USER' => $user,
        'NC_PASS' => $secret,
        'SQLITE_REMOTE' => $sqliteRemote,
        'SQLITE_KEY' => $secret,
        'SESSION_ID' => $runtimeId,
        'SESSION_ROOT' => '/tmp/budgetlove-runtime',
        'ALLOW_INIT_EMPTY' => '1',
        'KEEP_LOCAL' => '1',
    ];
    $result = hb_run_script_with_env($script, $env);
    if ($result['code'] !== 0) {
        throw new RuntimeException('Cloud SQLite request start failed: ' . trim((string)$result['stderr']));
    }
    $exports = hb_parse_env_lines((string)$result['stdout']);
    $sqlitePath = (string)($exports['HB_SQLITE_PATH'] ?? '');
    if ($sqlitePath === '' || !is_file($sqlitePath)) {
        throw new RuntimeException('Cloud SQLite request path missing.');
    }
    hb_cloud_sqlite_bootstrap_if_needed($pdo, $sqlitePath, $householdId);
    $GLOBALS['hb_cloud_sqlite_request_started'] = true;
    $GLOBALS['hb_cloud_sqlite_request_household_id'] = $householdId;
    $GLOBALS['hb_cloud_sqlite_request_path'] = $sqlitePath;
    $GLOBALS['hb_cloud_sqlite_request_env'] = $env;
    hb_cloud_sqlite_register_sync_shutdown($env);
}

function hb_cloud_sqlite_request_stop(): void
{
    unset(
        $GLOBALS['hb_cloud_sqlite_request_started'],
        $GLOBALS['hb_cloud_sqlite_request_household_id'],
        $GLOBALS['hb_cloud_sqlite_request_path'],
        $GLOBALS['hb_cloud_sqlite_request_env']
    );
}

function hb_cloud_webdav_request(string $method, string $url, string $username, string $secret, array $headers = [], ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for cloud storage.');
    }
    $ch = curl_init($url);
    $baseHeaders = ['Expect:'];
    if ($body !== null) {
        $baseHeaders[] = 'Content-Length: ' . strlen($body);
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $username . ':' . $secret,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => array_merge($baseHeaders, $headers),
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($response === false || $err !== '') {
        throw new RuntimeException('Cloud request failed: ' . $err);
    }
    return ['code' => $code, 'body' => (string)$response];
}

function hb_cloud_webdav_mkcol_tree(array $config, string $relativeDir): void
{
    $relativeDir = trim($relativeDir, '/');
    if ($relativeDir === '') {
        return;
    }
    $remoteBase = rtrim((string)$config['cloud_endpoint_url'], '/');
    $pathParts = explode('/', trim((string)$config['cloud_remote_path'], '/'));
    $dirParts = explode('/', $relativeDir);
    $curr = $remoteBase;
    foreach (array_merge($pathParts, $dirParts) as $part) {
        if ($part === '') {
            continue;
        }
        $curr .= '/' . $part;
        $res = hb_cloud_webdav_request('MKCOL', $curr, (string)$config['cloud_user_identifier'], (string)$config['cloud_access_secret']);
        if (!in_array($res['code'], [201, 405], true)) {
            throw new RuntimeException('Cloud directory could not be created (HTTP ' . $res['code'] . ').');
        }
    }
}

function hb_attachment_allowed_mime_types(): array
{
    return [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'application/pdf',
    ];
}

function hb_attachment_assert_allowed_mime(string $mime): void
{
    if (!in_array($mime, hb_attachment_allowed_mime_types(), true)) {
        throw new RuntimeException('Only images or PDF are allowed.');
    }
}

function hb_attachment_store_binary(PDO $serverPdo, int $householdId, string $binary, string $originalName, ?string $mimeHint = null): array
{
    if ($binary === '') {
        throw new RuntimeException('Attachment is empty.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $mimeHint ?: ($finfo->buffer($binary) ?: 'application/octet-stream');
    hb_attachment_assert_allowed_mime($mime);
    $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^A-Za-z0-9]/', '', $ext);
    $stored = bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');

    if (hb_household_finance_cloud_mode($serverPdo, $householdId)) {
        $config = hb_household_cloud_config($serverPdo, $householdId);
        if (!$config) {
            throw new RuntimeException('Cloud storage configuration missing.');
        }
        $relativeDir = 'attachments/household-' . $householdId;
        hb_cloud_webdav_mkcol_tree($config, $relativeDir);
        $relativePath = $relativeDir . '/' . $stored;
        $remoteUrl = rtrim((string)$config['cloud_endpoint_url'], '/') . '/' . trim((string)$config['cloud_remote_path'], '/') . '/' . $relativePath;
        $res = hb_cloud_webdav_request(
            'PUT',
            $remoteUrl,
            (string)$config['cloud_user_identifier'],
            (string)$config['cloud_access_secret'],
            ['Content-Type: ' . $mime],
            $binary
        );
        if (!in_array($res['code'], [200, 201, 204], true)) {
            throw new RuntimeException('Attachment upload failed (HTTP ' . $res['code'] . ').');
        }
        return [
            'storage_path' => 'nextcloud:' . $relativePath,
            'stored_filename' => $stored,
            'mime_type' => $mime,
            'size_bytes' => strlen($binary),
        ];
    }

    $dir = hb_ensure_upload_dir($householdId);
    $target = $dir . '/' . $stored;
    if (file_put_contents($target, $binary) === false) {
        throw new RuntimeException('Attachment file could not be saved.');
    }
    @chmod($target, 0640);
    return [
        'storage_path' => $householdId . '/' . $stored,
        'stored_filename' => $stored,
        'mime_type' => $mime,
        'size_bytes' => strlen($binary),
    ];
}

function hb_attachment_delete_binary(PDO $serverPdo, int $householdId, string $storagePath): void
{
    if ($storagePath === '') {
        return;
    }
    if (str_starts_with($storagePath, 'nextcloud:')) {
        $config = hb_household_cloud_config($serverPdo, $householdId);
        if (!$config) {
            return;
        }
        $relativePath = substr($storagePath, strlen('nextcloud:'));
        $remoteUrl = rtrim((string)$config['cloud_endpoint_url'], '/') . '/' . trim((string)$config['cloud_remote_path'], '/') . '/' . ltrim($relativePath, '/');
        hb_cloud_webdav_request(
            'DELETE',
            $remoteUrl,
            (string)$config['cloud_user_identifier'],
            (string)$config['cloud_access_secret']
        );
        return;
    }
    $filePath = hb_upload_base_dir() . '/' . $storagePath;
    if (is_file($filePath)) {
        @unlink($filePath);
    }
}

function hb_attachment_read_binary(PDO $serverPdo, int $householdId, string $storagePath): string
{
    if (str_starts_with($storagePath, 'nextcloud:')) {
        $config = hb_household_cloud_config($serverPdo, $householdId);
        if (!$config) {
            throw new RuntimeException('Cloud storage configuration missing.');
        }
        $relativePath = substr($storagePath, strlen('nextcloud:'));
        $remoteUrl = rtrim((string)$config['cloud_endpoint_url'], '/') . '/' . trim((string)$config['cloud_remote_path'], '/') . '/' . ltrim($relativePath, '/');
        $res = hb_cloud_webdav_request(
            'GET',
            $remoteUrl,
            (string)$config['cloud_user_identifier'],
            (string)$config['cloud_access_secret']
        );
        if ($res['code'] !== 200) {
            throw new RuntimeException('Attachment download failed (HTTP ' . $res['code'] . ').');
        }
        return $res['body'];
    }

    $filePath = hb_upload_base_dir() . '/' . $storagePath;
    if (!is_file($filePath)) {
        throw new RuntimeException('Attachment file is missing.');
    }
    $content = file_get_contents($filePath);
    if ($content === false) {
        throw new RuntimeException('Attachment file could not be read.');
    }
    return $content;
}

function hb_household_period_bounds(array $household, ?DateTimeImmutable $today = null, ?PDO $pdo = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $mode = $household['month_close_mode'] ?? 'first_of_month';
    if ($mode === 'income_anchor' && $pdo instanceof PDO) {
        $periods = hb_get_salary_periods($pdo, $household, $today, 1);
        if ($periods) {
            return [$periods[0]['start'], $periods[0]['end']];
        }
    }

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

function hb_get_salary_periods(PDO $pdo, array $household, ?DateTimeImmutable $today = null, int $count = 3): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $count = max(1, min(12, $count));
    $mode = (string)($household['month_close_mode'] ?? 'first_of_month');
    if ($mode !== 'income_anchor') {
        [$start, $end] = hb_household_period_bounds($household, $today);
        $periods = [];
        for ($i = 0; $i < $count; $i++) {
            $periodStart = $start->modify('-' . $i . ' months');
            $periodEnd = $periodStart->modify('+1 month')->modify('-1 day');
            $periods[] = hb_salary_period_row($periodStart, $periodEnd, false);
        }
        return $periods;
    }

    $anchors = hb_income_anchor_dates($pdo, $household, $today, $count + 1);
    if (!$anchors) {
        [$start, $end] = hb_household_period_bounds(array_merge($household, ['month_close_mode' => 'salary_day']), $today);
        return [hb_salary_period_row($start, $end, true)];
    }

    $periods = [];
    $salaryDay = (int)($household['salary_day'] ?? 0);
    for ($i = 0; $i < min($count, count($anchors)); $i++) {
        $start = $anchors[$i];
        $next = $anchors[$i - 1] ?? null;
        if ($i === 0) {
            $end = hb_expected_next_salary_boundary($start, $today, $salaryDay)->modify('-1 day');
        } else {
            $end = $next instanceof DateTimeImmutable ? $next->modify('-1 day') : $start->modify('+1 month')->modify('-1 day');
        }
        if ($end < $start) {
            $end = $start;
        }
        $periods[] = hb_salary_period_row($start, $end, false);
    }
    return $periods;
}

function hb_income_anchor_dates(PDO $pdo, array $household, DateTimeImmutable $today, int $limit = 4): array
{
    $householdId = (int)($household['id'] ?? 0);
    if ($householdId < 1) {
        return [];
    }

    $conditions = [
        't.household_id = :hid',
        "t.type = 'income'",
        't.is_reviewed = true',
        't.booking_date <= :today',
    ];
    $params = [
        'hid' => $householdId,
        'today' => $today->format('Y-m-d'),
        'limit' => max(1, min(24, $limit)),
    ];

    foreach (['account_id', 'category_id', 'payee_id'] as $column) {
        $key = 'salary_anchor_' . $column;
        $value = (int)($household[$key] ?? 0);
        if ($value > 0) {
            $conditions[] = 't.' . $column . ' = :' . $key;
            $params[$key] = $value;
        }
    }

    $sql = 'select distinct t.booking_date
              from transactions t
             where ' . implode(' and ', $conditions) . '
             order by t.booking_date desc
             limit :limit';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $type = $key === 'limit' || is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue(':' . $key, $value, $type);
    }
    $stmt->execute();

    $dates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $value) {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', (string)$value);
        if ($date instanceof DateTimeImmutable) {
            $dates[] = $date;
        }
    }
    return $dates;
}

function hb_expected_next_salary_boundary(DateTimeImmutable $start, DateTimeImmutable $today, int $salaryDay): DateTimeImmutable
{
    if ($salaryDay < 1 || $salaryDay > 31) {
        return max($today->modify('+1 day'), $start->modify('+1 month'));
    }

    $candidate = hb_salary_day_for_month((int)$start->format('Y'), (int)$start->format('m'), $salaryDay);
    if ($candidate <= $start) {
        $candidate = hb_salary_day_for_month((int)$start->modify('+1 month')->format('Y'), (int)$start->modify('+1 month')->format('m'), $salaryDay);
    }
    if ($candidate <= $today) {
        return $today->modify('+1 day');
    }
    return $candidate;
}

function hb_salary_day_for_month(int $year, int $month, int $salaryDay): DateTimeImmutable
{
    $first = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $year, $month));
    if (!$first) {
        return new DateTimeImmutable('first day of this month');
    }
    $day = min($salaryDay, (int)$first->modify('last day of this month')->format('d'));
    return DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day)) ?: $first;
}

function hb_salary_period_row(DateTimeImmutable $start, DateTimeImmutable $end, bool $fallback): array
{
    return [
        'start' => $start,
        'end' => $end,
        'label' => hb_period_label($start, $end),
        'fallback' => $fallback,
    ];
}

function hb_period_label(DateTimeImmutable $start, DateTimeImmutable $end): string
{
    return $start->format('d.m.Y') . ' - ' . $end->format('d.m.Y');
}

function hb_resolve_period_range(PDO $pdo, array $household, string $preset = '', ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $preset = trim($preset);
    if ($preset === '' || $preset === 'current_month' || $preset === '1m') {
        $preset = 'current_period';
    } elseif ($preset === 'previous_month') {
        $preset = 'previous_period';
    } elseif ($preset === 'last_3_months') {
        $preset = 'period:3';
    }

    $periods = hb_get_salary_periods($pdo, $household, $today, 3);
    [$currentStart, $currentEnd] = hb_household_period_bounds($household, $today, $pdo);
    $start = $currentStart;
    $end = $currentEnd;

    if ($preset === 'previous_period') {
        if (isset($periods[1])) {
            $start = $periods[1]['start'];
            $end = $periods[1]['end'];
        } else {
            $days = (int)$currentStart->diff($currentEnd)->days + 1;
            $end = $currentStart->modify('-1 day');
            $start = $end->modify('-' . ($days - 1) . ' days');
        }
    } elseif (preg_match('/^period:([123])$/', $preset, $match)) {
        $count = (int)$match[1];
        if ($count === 1 && isset($periods[0])) {
            $start = $periods[0]['start'];
            $end = $periods[0]['end'];
        } elseif (count($periods) >= $count) {
            $start = $periods[$count - 1]['start'];
            $end = $periods[0]['end'];
        }
    }

    return [
        'start' => $start,
        'end' => $end,
        'label' => hb_period_label($start, $end),
        'preset' => $preset,
        'periods' => $periods,
    ];
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

function hb_budget_spent(PDO $pdo, int $householdId, array $categoryIds, DateTimeImmutable $start, DateTimeImmutable $end): int
{
    if (!$categoryIds) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
    $params = array_merge([$householdId, $start->format('Y-m-d'), $end->format('Y-m-d')], $categoryIds, $categoryIds);
    $sql = "
        select coalesce(sum(
            case when ts.id is not null then ts.amount_cents else t.amount_cents end
        ), 0) as spent_cents
          from transactions t
          left join transaction_splits ts on ts.transaction_id = t.id
         where t.household_id = ?
           and t.type = 'expense'
           and t.booking_date between ? and ?
           and (
                (ts.id is not null and ts.category_id in ($placeholders))
             or (ts.id is null and t.category_id in ($placeholders))
           )
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
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
        'select sp.*
           from savings_plans sp
          where sp.household_id = :hid
            and sp.is_active = true
            and sp.interval_unit is not null
            and sp.start_date <= :period_end
            and (sp.end_date is null or sp.end_date >= :period_start)'
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
        $catStmt = $pdo->prepare('select category_id from savings_plan_categories where savings_plan_id = :id order by category_id asc limit 1');
        $catStmt->execute(['id' => $plan['id']]);
        $catRow = $catStmt->fetch();
        if ($catRow && isset($catRow['category_id'])) {
            $categoryId = (int)$catRow['category_id'];
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
            set status = 'overdue', updated_at = :updated_at
          where household_id = :hid
            and status = 'open'
            and planned_date < current_date"
    )->execute(['hid' => $householdId, 'updated_at' => gmdate('Y-m-d H:i:s')]);
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
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $check = $pdo->prepare("select 1 from sqlite_master where type = 'table' and name = 'month_closures' limit 1");
        $check->execute();
        if (!$check->fetchColumn()) {
            return false;
        }
    }
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
