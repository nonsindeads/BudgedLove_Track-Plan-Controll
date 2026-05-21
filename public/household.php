<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/api.php';

$layoutCompact = false;
hb_require_login();
$pdo = hb_get_pdo();
$userId = hb_current_user_id();
$currentUser = hb_current_user($pdo);
$pageTitle = 'Household';
$activeNav = 'household';
$breadcrumbs = [
    ['label' => 'Household', 'href' => '/household.php'],
];

$action = $_GET['action'] ?? $_POST['action'] ?? 'select';
$error = null;
$conflict = null;
$msg = $_GET['msg'] ?? null;
$cloudSessionConflict = !empty($_SESSION['hb_cloud_session_conflict']) && is_array($_SESSION['hb_cloud_session_conflict'])
    ? $_SESSION['hb_cloud_session_conflict']
    : null;

$households = hb_user_households($pdo, $userId);

$currentHousehold = hb_current_household($pdo);
$canManageMembers = $currentHousehold ? hb_is_household_creator($currentHousehold, $userId) : false;

function hb_household_row_exists(PDO $pdo, string $table, int $id, int $householdId): bool
{
    if (!in_array($table, ['accounts', 'categories', 'payees'], true)) {
        return false;
    }
    $stmt = $pdo->prepare("select 1 from {$table} where id = :id and household_id = :hid limit 1");
    $stmt->execute(['id' => $id, 'hid' => $householdId]);
    return (bool)$stmt->fetchColumn();
}

function hb_household_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("select to_regclass(:name) is not null");
    $stmt->execute(['name' => $table]);
    return (bool)$stmt->fetchColumn();
}

function hb_nextcloud_request(string $method, string $url, string $username, string $secret, array $headers = [], ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Nextcloud requests.');
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
        CURLOPT_TIMEOUT => 60,
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
        throw new RuntimeException('Nextcloud request failed: ' . $err);
    }
    return ['code' => $code, 'body' => (string)$response];
}

function hb_test_nextcloud_connection(array $household): array
{
    $endpoint = rtrim(trim((string)($household['cloud_endpoint_url'] ?? '')), '/');
    $username = trim((string)($household['cloud_user_identifier'] ?? ''));
    $secret = (string)($household['cloud_access_secret'] ?? '');
    $remotePath = trim((string)($household['cloud_remote_path'] ?? ''));
    if ($endpoint === '' || $username === '' || $secret === '' || $remotePath === '') {
        throw new RuntimeException('Cloud endpoint, user, app password and remote path are required.');
    }

    $basePath = $endpoint . '/' . trim($remotePath, '/');
    $testDirName = '_budgetlove_test_' . gmdate('Ymd_His');
    $testDirUrl = $basePath . '/' . $testDirName;
    $testFileName = 'write-test.txt';
    $testFileUrl = $testDirUrl . '/' . $testFileName;
    $payload = 'BudgetLove Nextcloud connectivity test ' . gmdate('c') . PHP_EOL;

    $mkcol = hb_nextcloud_request('MKCOL', $testDirUrl, $username, $secret);
    if (!in_array($mkcol['code'], [201, 405], true)) {
        throw new RuntimeException('Cannot create test directory (HTTP ' . $mkcol['code'] . ').');
    }

    $put = hb_nextcloud_request('PUT', $testFileUrl, $username, $secret, ['Content-Type: text/plain; charset=utf-8'], $payload);
    if (!in_array($put['code'], [200, 201, 204], true)) {
        throw new RuntimeException('Cannot write test file (HTTP ' . $put['code'] . ').');
    }

    $get = hb_nextcloud_request('GET', $testFileUrl, $username, $secret);
    if ($get['code'] !== 200) {
        throw new RuntimeException('Cannot read test file (HTTP ' . $get['code'] . ').');
    }
    if (strpos($get['body'], 'BudgetLove Nextcloud connectivity test') === false) {
        throw new RuntimeException('Readback content mismatch.');
    }

    hb_nextcloud_request('DELETE', $testFileUrl, $username, $secret);

    return [
        'dir_url' => $testDirUrl,
        'file_name' => $testFileName,
        'status' => 'ok',
    ];
}

function hb_create_cloud_snapshot(PDO $pdo, array $household, bool $includeReceiptFiles = false): array
{
    $householdId = (int)($household['id'] ?? 0);
    $provider = (string)($household['cloud_primary_provider'] ?? '');
    $remotePath = trim((string)($household['cloud_remote_path'] ?? ''));
    if ($householdId < 1) {
        throw new RuntimeException('Invalid household context.');
    }
    if ($provider === '') {
        throw new RuntimeException('Cloud provider is not configured.');
    }
    if ($remotePath === '') {
        throw new RuntimeException('Cloud remote path is required.');
    }
    $isNextcloud = $provider === 'nextcloud';
    if (!$isNextcloud) {
        if (!is_dir($remotePath)) {
            if (!@mkdir($remotePath, 0750, true) && !is_dir($remotePath)) {
                throw new RuntimeException('Cloud remote path cannot be created.');
            }
        }
        if (!is_writable($remotePath)) {
            throw new RuntimeException('Cloud remote path is not writable.');
        }
    }

    $snapshot = [
        'snapshot_version' => 1,
        'created_at_utc' => gmdate('c'),
        'household' => [
            'id' => $householdId,
            'name' => (string)($household['name'] ?? ''),
            'currency_code' => (string)($household['currency_code'] ?? ''),
        ],
        'tables' => [],
    ];

    $tableMap = [
        'accounts' => ['household_id'],
        'categories' => ['household_id'],
        'payees' => ['household_id'],
        'tags' => ['household_id'],
        'receipts' => ['household_id'],
        'transaction_groups' => ['household_id'],
        'transactions' => ['household_id'],
        'attachments' => ['household_id'],
        'transaction_splits' => ['transaction_id'],
        'transaction_tags' => ['transaction_id'],
        'planned_payments' => ['household_id'],
        'open_cases' => ['household_id'],
        'recurring_payments' => ['household_id'],
        'budgets' => ['household_id'],
        'budget_categories' => ['budget_id'],
        'payee_mappings' => ['household_id'],
    ];

    $transactionIds = [];
    $transactionGroupIds = [];
    $budgetIds = [];
    $attachments = [];
    foreach ($tableMap as $table => $keys) {
        if (!hb_household_table_exists($pdo, $table)) {
            continue;
        }
        if ($table === 'transaction_tags') {
            if (!$transactionIds) {
                continue;
            }
            $ph = implode(',', array_fill(0, count($transactionIds), '?'));
            $stmt = $pdo->prepare("select * from {$table} where transaction_id in ({$ph}) order by transaction_id asc");
            $stmt->execute($transactionIds);
            $rows = $stmt->fetchAll() ?: [];
            $snapshot['tables'][$table] = $rows;
            continue;
        }
        if ($table === 'transaction_splits') {
            if (!$transactionIds && !$transactionGroupIds) {
                continue;
            }
            $clauses = [];
            $params = [];
            if ($transactionIds) {
                $clauses[] = 'transaction_id in (' . implode(',', array_fill(0, count($transactionIds), '?')) . ')';
                array_push($params, ...$transactionIds);
            }
            if ($transactionGroupIds) {
                $clauses[] = 'transaction_group_id in (' . implode(',', array_fill(0, count($transactionGroupIds), '?')) . ')';
                array_push($params, ...$transactionGroupIds);
            }
            $stmt = $pdo->prepare("select * from {$table} where " . implode(' or ', $clauses) . ' order by coalesce(transaction_id, 0) asc, coalesce(transaction_group_id, 0) asc, id asc');
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];
            $snapshot['tables'][$table] = $rows;
            continue;
        }
        if ($table === 'budget_categories') {
            if (!$budgetIds) {
                continue;
            }
            $ph = implode(',', array_fill(0, count($budgetIds), '?'));
            $stmt = $pdo->prepare("select * from {$table} where budget_id in ({$ph}) order by budget_id asc");
            $stmt->execute($budgetIds);
            $rows = $stmt->fetchAll() ?: [];
            $snapshot['tables'][$table] = $rows;
            continue;
        }

        $stmt = $pdo->prepare("select * from {$table} where household_id = :hid order by id asc");
        $stmt->execute(['hid' => $householdId]);
        $rows = $stmt->fetchAll() ?: [];
        $snapshot['tables'][$table] = $rows;
        if ($table === 'transactions') {
            $transactionIds = array_values(array_map(static fn(array $r): int => (int)$r['id'], $rows));
        }
        if ($table === 'transaction_groups') {
            $transactionGroupIds = array_values(array_map(static fn(array $r): int => (int)$r['id'], $rows));
        }
        if ($table === 'budgets') {
            $budgetIds = array_values(array_map(static fn(array $r): int => (int)$r['id'], $rows));
        }
        if ($table === 'attachments') {
            $attachments = $rows;
        }
    }

    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Snapshot encoding failed.');
    }

    $ts = gmdate('Ymd\THis\Z');
    $base = 'budgetlove-snapshot-household-' . $householdId . '-' . $ts;
    $tmpJson = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base . '.json';
    $tmpGz = $tmpJson . '.gz';
    $finalName = $base . '.json.gz';
    $finalGz = rtrim($remotePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $finalName;
    $finalSha = $finalGz . '.sha256';
    $finalRef = $finalGz;
    $receiptUploadedCount = 0;

    try {
        if (file_put_contents($tmpJson, $json) === false) {
            throw new RuntimeException('Temp snapshot write failed.');
        }
        $gz = gzencode($json, 9);
        if ($gz === false || file_put_contents($tmpGz, $gz) === false) {
            throw new RuntimeException('Snapshot compression failed.');
        }
        if ($isNextcloud) {
            $endpoint = trim((string)($household['cloud_endpoint_url'] ?? ''));
            $username = trim((string)($household['cloud_user_identifier'] ?? ''));
            $secret = (string)($household['cloud_access_secret'] ?? '');
            if ($endpoint === '' || $username === '' || $secret === '') {
                throw new RuntimeException('Nextcloud endpoint, username and app password are required.');
            }
            if (!function_exists('curl_init')) {
                throw new RuntimeException('PHP cURL extension is required for Nextcloud upload.');
            }
            $hash = hash_file('sha256', $tmpGz);
            if ($hash === false) {
                throw new RuntimeException('Snapshot checksum generation failed.');
            }

            $endpoint = rtrim($endpoint, '/');
            $remoteDir = trim($remotePath, '/');
            $targetBase = $endpoint . '/' . ($remoteDir !== '' ? $remoteDir . '/' : '');
            $targetGz = $targetBase . $finalName;
            $targetSha = $targetBase . $finalName . '.sha256';

            $request = static function (string $method, string $url, string $user, string $pass, array $headers = [], ?string $body = null): array {
                $ch = curl_init($url);
                $baseHeaders = ['Expect:'];
                if ($body !== null) {
                    $baseHeaders[] = 'Content-Length: ' . strlen($body);
                }
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_USERPWD => $user . ':' . $pass,
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
                    throw new RuntimeException('Nextcloud request failed: ' . $err);
                }
                return ['code' => $code, 'body' => (string)$response];
            };
            $uploadPut = static function (string $url, string $filePath, string $user, string $pass) use ($request): void {
                $fh = fopen($filePath, 'rb');
                if (!$fh) {
                    throw new RuntimeException('Cannot open upload source file.');
                }
                $size = filesize($filePath);
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_USERPWD => $user . ':' . $pass,
                    CURLOPT_PUT => true,
                    CURLOPT_INFILE => $fh,
                    CURLOPT_INFILESIZE => $size !== false ? $size : 0,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 120,
                ]);
                $response = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                fclose($fh);
                if ($response === false || $err !== '') {
                    throw new RuntimeException('Nextcloud upload failed: ' . $err);
                }
                if (!in_array($code, [200, 201, 204], true)) {
                    throw new RuntimeException('Nextcloud upload failed with HTTP ' . $code);
                }
            };
            $mkcolEnsure = static function (string $baseUrl, string $relativeDir, string $user, string $pass) use ($request): void {
                $parts = array_filter(explode('/', trim($relativeDir, '/')), static fn(string $p): bool => $p !== '');
                $path = rtrim($baseUrl, '/');
                foreach ($parts as $part) {
                    $path .= '/' . rawurlencode($part);
                    $res = $request('MKCOL', $path, $user, $pass);
                    if (!in_array((int)$res['code'], [201, 405], true)) {
                        throw new RuntimeException('Nextcloud folder creation failed (HTTP ' . (int)$res['code'] . ') at ' . $path);
                    }
                }
            };

            $tmpSha = $tmpJson . '.sha256';
            if (file_put_contents($tmpSha, $hash . '  ' . $finalName . PHP_EOL) === false) {
                throw new RuntimeException('Cannot create checksum sidecar.');
            }
            try {
                $uploadPut($targetGz, $tmpGz, $username, $secret);
                $uploadPut($targetSha, $tmpSha, $username, $secret);
            } finally {
                @unlink($tmpSha);
            }
            if ($includeReceiptFiles && !empty($attachments)) {
                $uploadBase = rtrim($endpoint, '/');
                $receiptRootRel = trim($remotePath, '/') . '/receipts/household-' . $householdId;
                $mkcolEnsure($uploadBase, $receiptRootRel, $username, $secret);
                foreach ($attachments as $attachment) {
                    $storagePath = (string)($attachment['storage_path'] ?? '');
                    if ($storagePath === '') {
                        continue;
                    }
                    $exportRelPath = str_starts_with($storagePath, 'nextcloud:')
                        ? ltrim(substr($storagePath, strlen('nextcloud:')), '/')
                        : ltrim($storagePath, '/');
                    $targetRel = $receiptRootRel . '/' . $exportRelPath;
                    $targetDirRel = trim(dirname($targetRel), '.');
                    if ($targetDirRel !== '') {
                        $mkcolEnsure($uploadBase, $targetDirRel, $username, $secret);
                    }
                    $targetFileUrl = $uploadBase . '/' . str_replace('%2F', '/', rawurlencode($targetRel));
                    $tmpReceipt = tempnam(sys_get_temp_dir(), 'hb_receipt_export_');
                    if ($tmpReceipt === false) {
                        throw new RuntimeException('Could not create temporary receipt file.');
                    }
                    try {
                        $content = hb_attachment_read_binary($pdo, $householdId, $storagePath);
                        if (file_put_contents($tmpReceipt, $content) === false) {
                            throw new RuntimeException('Could not write temporary receipt file.');
                        }
                        $uploadPut($targetFileUrl, $tmpReceipt, $username, $secret);
                        $receiptUploadedCount++;
                    } finally {
                        @unlink($tmpReceipt);
                    }
                }
                $snapshot['receipts_export'] = [
                    'enabled' => true,
                    'uploaded_files' => $receiptUploadedCount,
                    'base_path' => $receiptRootRel,
                ];
            }
            $finalRef = $targetGz;
        } else {
            if (!@rename($tmpGz, $finalGz)) {
                throw new RuntimeException('Snapshot move to cloud path failed.');
            }
            $hash = hash_file('sha256', $finalGz);
            if ($hash === false || file_put_contents($finalSha, $hash . '  ' . basename($finalGz) . PHP_EOL) === false) {
                throw new RuntimeException('Snapshot checksum write failed.');
            }
            if ($includeReceiptFiles && !empty($attachments)) {
                $receiptRoot = rtrim($remotePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'receipts' . DIRECTORY_SEPARATOR . 'household-' . $householdId;
                if (!is_dir($receiptRoot) && !@mkdir($receiptRoot, 0750, true) && !is_dir($receiptRoot)) {
                    throw new RuntimeException('Could not create receipt export directory.');
                }
                foreach ($attachments as $attachment) {
                    $storagePath = (string)($attachment['storage_path'] ?? '');
                    if ($storagePath === '') {
                        continue;
                    }
                    $exportRelPath = str_starts_with($storagePath, 'nextcloud:')
                        ? ltrim(substr($storagePath, strlen('nextcloud:')), '/')
                        : ltrim($storagePath, '/');
                    $targetFile = $receiptRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $exportRelPath);
                    $targetDir = dirname($targetFile);
                    if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
                        throw new RuntimeException('Could not create receipt target directory.');
                    }
                    $content = hb_attachment_read_binary($pdo, $householdId, $storagePath);
                    if (file_put_contents($targetFile, $content) === false) {
                        throw new RuntimeException('Could not copy receipt file: ' . basename($targetFile));
                    }
                    $receiptUploadedCount++;
                }
            }
        }
    } finally {
        @unlink($tmpJson);
        @unlink($tmpGz);
    }

    return [
        'file' => $finalRef,
        'sha' => $finalSha,
        'bytes' => isset($hash) && is_string($hash) ? strlen($json) : (int)strlen($json),
        'receipts_uploaded' => $receiptUploadedCount,
    ];
}

function hb_create_nextcloud_session_sqlite(PDO $pdo, array $household): array
{
    $householdId = (int)($household['id'] ?? 0);
    $endpoint = trim((string)($household['cloud_endpoint_url'] ?? ''));
    $username = trim((string)($household['cloud_user_identifier'] ?? ''));
    $secret = (string)($household['cloud_access_secret'] ?? '');
    $remotePath = trim((string)($household['cloud_remote_path'] ?? ''));
    if ($householdId < 1 || $endpoint === '' || $username === '' || $secret === '' || $remotePath === '') {
        throw new RuntimeException('Nextcloud SQLite migration configuration is incomplete.');
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO SQLite driver is required for cloud SQLite migration.');
    }

    $sessionId = 'migration-h' . $householdId . '-' . bin2hex(random_bytes(8));
    $sessionRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'budgetlove-sessions';
    $sessionDir = $sessionRoot . DIRECTORY_SEPARATOR . $sessionId;
    if (!is_dir($sessionDir) && !@mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
        throw new RuntimeException('Could not create temporary SQLite migration directory.');
    }
    @chmod($sessionDir, 0700);

    $sqlitePath = $sessionDir . DIRECTORY_SEPARATOR . 'db.sqlite';
    $sqliteRemote = rtrim($remotePath, '/') . '/session-db/household-' . $householdId . '.sqlite.enc';

    try {
        $sqlite = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $sqlite->exec('pragma foreign_keys = off');
        $sqlite->exec('begin immediate transaction');
        try {
            hb_write_household_sqlite_export($pdo, $sqlite, $householdId);
            $sqlite->exec('commit');
        } catch (Throwable $e) {
            $sqlite->exec('rollback');
            throw $e;
        }
        $sqlite = null;
        @chmod($sqlitePath, 0600);

        $script = realpath(__DIR__ . '/../tools/cloud/sqlite-session-stop.sh');
        if ($script === false || !is_file($script)) {
            throw new RuntimeException('SQLite upload script is missing.');
        }
        $result = hb_run_script_with_env($script, [
            'NC_WEBDAV_BASE' => $endpoint,
            'NC_USER' => $username,
            'NC_PASS' => $secret,
            'SQLITE_REMOTE' => $sqliteRemote,
            'SQLITE_KEY' => $secret,
            'SESSION_ID' => $sessionId,
            'SESSION_ROOT' => $sessionRoot,
        ]);
        if ($result['code'] !== 0) {
            throw new RuntimeException('SQLite upload failed: ' . trim((string)$result['stderr']));
        }
        return [
            'remote' => rtrim($endpoint, '/') . '/' . ltrim($sqliteRemote, '/'),
            'remote_path' => $sqliteRemote,
            'session_id' => $sessionId,
        ];
    } finally {
        if (is_file($sqlitePath)) {
            @unlink($sqlitePath);
        }
        foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($sessionDir);
    }
}

function hb_write_household_sqlite_export(PDO $source, PDO $sqlite, int $householdId): void
{
    $tables = hb_household_sqlite_export_tables($source, $householdId);
    hb_sqlite_create_export_meta($sqlite, $householdId);
    foreach ($tables as $table => $rows) {
        $columns = hb_postgres_table_columns($source, $table);
        if (!$columns) {
            continue;
        }
        hb_sqlite_create_table($sqlite, $table, $columns);
        hb_sqlite_insert_rows($sqlite, $table, array_column($columns, 'name'), $rows);
    }
}

function hb_sqlite_create_export_meta(PDO $sqlite, int $householdId): void
{
    $sqlite->exec(
        'create table if not exists budgetlove_sqlite_export_meta (
            key text primary key,
            value text not null
        )'
    );
    $stmt = $sqlite->prepare('insert into budgetlove_sqlite_export_meta (key, value) values (:key, :value)');
    foreach ([
        'exported_at_utc' => gmdate('c'),
        'household_id' => (string)$householdId,
        'format_version' => '1',
        'runtime_ready' => '0',
    ] as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
}

function hb_household_sqlite_export_tables(PDO $pdo, int $householdId): array
{
    $tables = [];
    $tables['households'] = hb_sanitize_sqlite_export_rows(
        'households',
        hb_fetch_rows($pdo, 'select * from households where id = :hid', ['hid' => $householdId])
    );
    $tables['household_members'] = hb_fetch_rows($pdo, 'select * from household_members where household_id = :hid order by id asc', ['hid' => $householdId]);

    foreach ([
        'accounts',
        'categories',
        'payees',
        'tags',
        'receipts',
        'transaction_groups',
        'transactions',
        'attachments',
        'planned_payments',
        'open_cases',
        'recurring_payments',
        'budgets',
        'payee_mappings',
    ] as $table) {
        if (hb_household_table_exists($pdo, $table)) {
            $tables[$table] = hb_sanitize_sqlite_export_rows(
                $table,
                hb_fetch_rows($pdo, "select * from {$table} where household_id = :hid order by id asc", ['hid' => $householdId])
            );
        }
    }

    $transactionIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $tables['transactions'] ?? []));
    $transactionGroupIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $tables['transaction_groups'] ?? []));
    $budgetIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $tables['budgets'] ?? []));

    if (hb_household_table_exists($pdo, 'transaction_tags')) {
        $tables['transaction_tags'] = $transactionIds ? hb_fetch_rows_in($pdo, 'transaction_tags', 'transaction_id', $transactionIds, 'transaction_id asc, tag_id asc') : [];
    }
    if (hb_household_table_exists($pdo, 'transaction_splits')) {
        $tables['transaction_splits'] = hb_fetch_transaction_splits_for_export($pdo, $transactionIds, $transactionGroupIds);
    }
    if (hb_household_table_exists($pdo, 'budget_categories')) {
        $tables['budget_categories'] = $budgetIds ? hb_fetch_rows_in($pdo, 'budget_categories', 'budget_id', $budgetIds, 'budget_id asc, category_id asc') : [];
    }

    return $tables;
}

function hb_sanitize_sqlite_export_rows(string $table, array $rows): array
{
    if ($table !== 'households') {
        return $rows;
    }
    foreach ($rows as &$row) {
        if (array_key_exists('cloud_access_secret', $row)) {
            $row['cloud_access_secret'] = null;
        }
    }
    unset($row);
    return $rows;
}

function hb_fetch_transaction_splits_for_export(PDO $pdo, array $transactionIds, array $transactionGroupIds): array
{
    if (!$transactionIds && !$transactionGroupIds) {
        return [];
    }
    $clauses = [];
    $params = [];
    if ($transactionIds) {
        $clauses[] = 'transaction_id in (' . implode(',', array_fill(0, count($transactionIds), '?')) . ')';
        array_push($params, ...$transactionIds);
    }
    if ($transactionGroupIds) {
        $clauses[] = 'transaction_group_id in (' . implode(',', array_fill(0, count($transactionGroupIds), '?')) . ')';
        array_push($params, ...$transactionGroupIds);
    }
    $stmt = $pdo->prepare('select * from transaction_splits where ' . implode(' or ', $clauses) . ' order by coalesce(transaction_id, 0) asc, coalesce(transaction_group_id, 0) asc, id asc');
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function hb_fetch_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function hb_fetch_rows_in(PDO $pdo, string $table, string $column, array $ids, string $orderBy): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("select * from {$table} where {$column} in ({$ph}) order by {$orderBy}");
    $stmt->execute($ids);
    return $stmt->fetchAll() ?: [];
}

function hb_postgres_table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        "select column_name, data_type
           from information_schema.columns
          where table_schema = 'public'
            and table_name = :table
          order by ordinal_position asc"
    );
    $stmt->execute(['table' => $table]);
    $columns = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $columns[] = [
            'name' => (string)$row['column_name'],
            'type' => hb_sqlite_type_for_postgres_type((string)$row['data_type']),
        ];
    }
    return $columns;
}

function hb_sqlite_type_for_postgres_type(string $type): string
{
    $type = strtolower($type);
    if (str_contains($type, 'int') || $type === 'boolean') {
        return 'integer';
    }
    if (in_array($type, ['numeric', 'real', 'double precision'], true)) {
        return 'real';
    }
    return 'text';
}

function hb_sqlite_create_table(PDO $sqlite, string $table, array $columns): void
{
    $defs = [];
    foreach ($columns as $column) {
        $defs[] = hb_sqlite_quote_identifier((string)$column['name']) . ' ' . (string)$column['type'];
    }
    $sqlite->exec('create table if not exists ' . hb_sqlite_quote_identifier($table) . ' (' . implode(', ', $defs) . ')');
}

function hb_sqlite_insert_rows(PDO $sqlite, string $table, array $columns, array $rows): void
{
    if (!$rows) {
        return;
    }
    $quotedColumns = array_map('hb_sqlite_quote_identifier', $columns);
    $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
    $stmt = $sqlite->prepare(
        'insert into ' . hb_sqlite_quote_identifier($table) .
        ' (' . implode(', ', $quotedColumns) . ') values (' . implode(', ', $placeholders) . ')'
    );
    foreach ($rows as $row) {
        $params = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $params[$column] = $value;
        }
        $stmt->execute($params);
    }
}

function hb_sqlite_quote_identifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

if ($action === 'settings' && !$currentHousehold) {
    header('Location: /household.php');
    exit;
}

if ($action === 'set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $householdId = (int)($_POST['household_id'] ?? 0);
    if ($householdId > 0) {
        $membership = $pdo->prepare(
            'select 1 from household_members where household_id = :hid and user_id = :uid and is_active = true'
        );
        $membership->execute(['hid' => $householdId, 'uid' => $userId]);
        if ($membership->fetch()) {
            try {
                hb_set_current_household($householdId, $pdo);
                header('Location: /accounts.php');
                exit;
            } catch (Throwable $e) {
                if (!empty($_SESSION['hb_cloud_session_conflict'])) {
                    $cloudSessionConflict = $_SESSION['hb_cloud_session_conflict'];
                    $msg = 'cloud_session_conflict';
                } else {
                    $error = $e->getMessage();
                }
            }
        } else {
            $error = hb_t('You are not active in this household.');
        }
    } else {
        $error = hb_t('Invalid selection.');
    }
}

if ($action === 'takeover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $householdId = (int)($_POST['household_id'] ?? 0);
    if ($householdId > 0) {
        $membership = $pdo->prepare(
            'select 1 from household_members where household_id = :hid and user_id = :uid and is_active = true'
        );
        $membership->execute(['hid' => $householdId, 'uid' => $userId]);
        if ($membership->fetch()) {
            hb_set_current_household($householdId, $pdo, true);
            unset($_SESSION['hb_cloud_session_conflict']);
            header('Location: /accounts.php?msg=cloud_session_taken_over');
            exit;
        }
        $error = hb_t('You are not active in this household.');
    } else {
        $error = hb_t('Invalid selection.');
    }
}

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $currency = strtoupper(trim((string)($_POST['currency_code'] ?? 'EUR')));
    $mode = (string)($_POST['month_close_mode'] ?? 'first_of_month');
    $salaryDay = (($_POST['salary_day'] ?? '') !== '') ? (int)$_POST['salary_day'] : null;

    if ($name === '') {
        $error = hb_t('Please provide a household name.');
    } elseif (!in_array($mode, hb_allowed_month_close_modes(), true)) {
        $error = hb_t('Invalid mode.');
    } elseif ($mode === 'salary_day' && ($salaryDay === null || $salaryDay < 1 || $salaryDay > 31)) {
        $error = hb_t('Valid salary day (1-31) required.');
    }

    if ($error === null) {
        $householdId = hb_create_household($pdo, $userId, $name, $currency, $mode, $salaryDay);
        hb_set_current_household($householdId, $pdo);
        header('Location: /accounts.php');
        exit;
    }
}

if ($action === 'update_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$currentHousehold || !hb_is_household_admin($currentHousehold)) {
        $error = hb_t('Only household admins can change settings.');
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $currency = strtoupper(trim((string)($_POST['currency_code'] ?? $currentHousehold['currency_code'])));
        $mode = (string)($_POST['month_close_mode'] ?? $currentHousehold['month_close_mode']);
        $salaryDayRaw = (string)($_POST['salary_day'] ?? '');
        $anchorAccountRaw = (string)($_POST['salary_anchor_account_id'] ?? '');
        $anchorCategoryRaw = (string)($_POST['salary_anchor_category_id'] ?? '');
        $anchorPayeeRaw = (string)($_POST['salary_anchor_payee_id'] ?? '');
        $dataResidencyMode = (string)($_POST['data_residency_mode'] ?? ($currentHousehold['data_residency_mode'] ?? 'server'));
        $cloudPrimaryProviderRaw = trim((string)($_POST['cloud_primary_provider'] ?? ''));
        $cloudSyncMode = (string)($_POST['cloud_sync_mode'] ?? ($currentHousehold['cloud_sync_mode'] ?? 'disabled'));
        $cloudUserIdentifier = trim((string)($_POST['cloud_user_identifier'] ?? ''));
        $cloudRemotePath = trim((string)($_POST['cloud_remote_path'] ?? ''));
        $cloudEndpointUrl = trim((string)($_POST['cloud_endpoint_url'] ?? ''));
        $cloudAccessSecretRaw = (string)($_POST['cloud_access_secret'] ?? '');
        $cloudSessionTtl = (int)($_POST['cloud_session_ttl_minutes'] ?? ($currentHousehold['cloud_session_ttl_minutes'] ?? 120));
        $cloudRequireEphemeral = !empty($_POST['cloud_require_ephemeral']);
        $salaryDay = $salaryDayRaw !== '' ? (int)$salaryDayRaw : null;
        $anchorAccountId = $anchorAccountRaw !== '' ? (int)$anchorAccountRaw : null;
        $anchorCategoryId = $anchorCategoryRaw !== '' ? (int)$anchorCategoryRaw : null;
        $anchorPayeeId = $anchorPayeeRaw !== '' ? (int)$anchorPayeeRaw : null;
        $cloudPrimaryProvider = $cloudPrimaryProviderRaw !== '' ? strtolower($cloudPrimaryProviderRaw) : null;
        $cloudAccessSecret = $cloudAccessSecretRaw !== '' ? $cloudAccessSecretRaw : (string)($currentHousehold['cloud_access_secret'] ?? '');
        $rowVersion = (int)($_POST['row_version'] ?? 0);
        if ($name === '') {
            $error = hb_t('Name is required.');
        } elseif (!in_array($mode, hb_allowed_month_close_modes(), true)) {
            $error = hb_t('Invalid mode.');
        } elseif ($mode === 'salary_day' && ($salaryDay === null || $salaryDay < 1 || $salaryDay > 31)) {
            $error = hb_t('Valid salary day (1-31) required.');
        } elseif ($salaryDay !== null && ($salaryDay < 1 || $salaryDay > 31)) {
            $error = hb_t('Valid salary day (1-31) required.');
        } elseif ($anchorAccountId !== null && !hb_household_row_exists($pdo, 'accounts', $anchorAccountId, (int)$currentHousehold['id'])) {
            $error = hb_t('Account does not belong to the household.');
        } elseif ($anchorCategoryId !== null && !hb_household_row_exists($pdo, 'categories', $anchorCategoryId, (int)$currentHousehold['id'])) {
            $error = hb_t('Category does not belong to the household.');
        } elseif ($anchorPayeeId !== null && !hb_household_row_exists($pdo, 'payees', $anchorPayeeId, (int)$currentHousehold['id'])) {
            $error = hb_t('Payee does not belong to the household.');
        } elseif (!in_array($dataResidencyMode, ['server', 'cloud'], true)) {
            $error = hb_t('Invalid data residency mode.');
        } elseif (!in_array($cloudSyncMode, ['disabled', 'exports_only', 'receipts_and_exports', 'sqlite_snapshots'], true)) {
            $error = hb_t('Invalid cloud sync mode.');
        } elseif ($cloudPrimaryProvider !== null && !in_array($cloudPrimaryProvider, ['icloud', 'nextcloud', 'gmail'], true)) {
            $error = hb_t('Invalid cloud provider.');
        } elseif ($cloudSessionTtl < 5 || $cloudSessionTtl > 1440) {
            $error = hb_t('Session TTL must be between 5 and 1440 minutes.');
        } elseif ($dataResidencyMode === 'cloud' && $cloudPrimaryProvider === null) {
            $error = hb_t('Cloud provider is required in cloud mode.');
        } elseif ($dataResidencyMode === 'cloud' && $cloudPrimaryProvider === 'nextcloud' && ($cloudEndpointUrl === '' || $cloudUserIdentifier === '' || $cloudAccessSecret === '')) {
            $error = hb_t('Nextcloud endpoint, username and app password are required.');
        } else {
            $stmt = $pdo->prepare(
                'update households
                    set name = :name,
                        currency_code = :currency,
                        month_close_mode = :mode,
                        salary_day = :salary,
                        salary_anchor_account_id = :anchor_account,
                        salary_anchor_category_id = :anchor_category,
                        salary_anchor_payee_id = :anchor_payee,
                        data_residency_mode = :data_residency_mode,
                        cloud_primary_provider = :cloud_primary_provider,
                        cloud_sync_mode = :cloud_sync_mode,
                        cloud_user_identifier = :cloud_user_identifier,
                        cloud_remote_path = :cloud_remote_path,
                        cloud_endpoint_url = :cloud_endpoint_url,
                        cloud_access_secret = :cloud_access_secret,
                        cloud_session_ttl_minutes = :cloud_session_ttl_minutes,
                        cloud_require_ephemeral = :cloud_require_ephemeral,
                        updated_at = :updated_at
                  where id = :id and row_version = :row_version'
            );
            $stmt->execute([
                'name' => $name,
                'currency' => $currency,
                'mode' => $mode,
                'salary' => $salaryDay,
                'anchor_account' => $anchorAccountId,
                'anchor_category' => $anchorCategoryId,
                'anchor_payee' => $anchorPayeeId,
                'data_residency_mode' => $dataResidencyMode,
                'cloud_primary_provider' => $cloudPrimaryProvider,
                'cloud_sync_mode' => $cloudSyncMode,
                'cloud_user_identifier' => $cloudUserIdentifier !== '' ? $cloudUserIdentifier : null,
                'cloud_remote_path' => $cloudRemotePath !== '' ? $cloudRemotePath : null,
                'cloud_endpoint_url' => $cloudEndpointUrl !== '' ? $cloudEndpointUrl : null,
                'cloud_access_secret' => $cloudAccessSecret !== '' ? $cloudAccessSecret : null,
                'cloud_session_ttl_minutes' => $cloudSessionTtl,
                'cloud_require_ephemeral' => $cloudRequireEphemeral ? 1 : 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => $currentHousehold['id'],
                'row_version' => $rowVersion,
            ]);
            if ($stmt->rowCount() === 0) {
                $currentHousehold = hb_current_household($pdo);
                $conflictRows = hb_build_conflict_rows(
                    [
                        'name' => hb_t('Name'),
                        'currency_code' => hb_t('Currency'),
                        'month_close_mode' => hb_t('Period calculation'),
                        'salary_day' => hb_t('Salary day'),
                        'salary_anchor_account_id' => hb_t('Salary account'),
                        'salary_anchor_category_id' => hb_t('Salary category'),
                        'salary_anchor_payee_id' => hb_t('Salary payee'),
                        'data_residency_mode' => hb_t('Data residency'),
                        'cloud_primary_provider' => hb_t('Cloud provider'),
                        'cloud_sync_mode' => hb_t('Cloud sync mode'),
                        'cloud_user_identifier' => hb_t('Cloud user'),
                        'cloud_remote_path' => hb_t('Cloud path'),
                        'cloud_endpoint_url' => hb_t('Cloud endpoint'),
                        'cloud_session_ttl_minutes' => hb_t('Session TTL'),
                        'cloud_require_ephemeral' => hb_t('Ephemeral mode'),
                    ],
                    $currentHousehold ?? [],
                    [
                        'name' => $name,
                        'currency_code' => $currency,
                        'month_close_mode' => $mode,
                        'salary_day' => $salaryDay !== null ? (string)$salaryDay : '',
                        'salary_anchor_account_id' => $anchorAccountId !== null ? (string)$anchorAccountId : '',
                        'salary_anchor_category_id' => $anchorCategoryId !== null ? (string)$anchorCategoryId : '',
                        'salary_anchor_payee_id' => $anchorPayeeId !== null ? (string)$anchorPayeeId : '',
                        'data_residency_mode' => $dataResidencyMode,
                        'cloud_primary_provider' => $cloudPrimaryProvider ?? '',
                        'cloud_sync_mode' => $cloudSyncMode,
                        'cloud_user_identifier' => $cloudUserIdentifier,
                        'cloud_remote_path' => $cloudRemotePath,
                        'cloud_endpoint_url' => $cloudEndpointUrl,
                        'cloud_session_ttl_minutes' => (string)$cloudSessionTtl,
                        'cloud_require_ephemeral' => $cloudRequireEphemeral ? '1' : '0',
                    ]
                );
                $conflict = hb_render_conflict_table($conflictRows);
                $currentHousehold = array_merge($currentHousehold ?? [], [
                    'name' => $name,
                    'currency_code' => $currency,
                    'month_close_mode' => $mode,
                    'salary_day' => $salaryDay,
                    'salary_anchor_account_id' => $anchorAccountId,
                    'salary_anchor_category_id' => $anchorCategoryId,
                    'salary_anchor_payee_id' => $anchorPayeeId,
                    'data_residency_mode' => $dataResidencyMode,
                    'cloud_primary_provider' => $cloudPrimaryProvider,
                    'cloud_sync_mode' => $cloudSyncMode,
                    'cloud_user_identifier' => $cloudUserIdentifier !== '' ? $cloudUserIdentifier : null,
                    'cloud_remote_path' => $cloudRemotePath !== '' ? $cloudRemotePath : null,
                    'cloud_endpoint_url' => $cloudEndpointUrl !== '' ? $cloudEndpointUrl : null,
                    'cloud_access_secret' => $cloudAccessSecret !== '' ? $cloudAccessSecret : null,
                    'cloud_session_ttl_minutes' => $cloudSessionTtl,
                    'cloud_require_ephemeral' => $cloudRequireEphemeral,
                ]);
            } else {
                header('Location: /household.php?action=settings&msg=saved');
                exit;
            }
        }
    }
}

if ($action === 'add_member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$currentHousehold) {
        $error = hb_t('No household selected.');
    } elseif (!$canManageMembers) {
        $error = hb_t('Only the household creator can add members.');
    } else {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        if ($identifier === '') {
            $error = hb_t('Please enter a username or email.');
        } else {
            $userStmt = $pdo->prepare(
                'select id, username, email, is_active
                   from users
                  where lower(username) = lower(:ident)
                     or lower(email) = lower(:ident)
                  limit 1'
            );
            $userStmt->execute(['ident' => $identifier]);
            $user = $userStmt->fetch();
            if (!$user) {
                $error = hb_t('User not found.');
            } elseif (!$user['is_active']) {
                $error = hb_t('User is not active yet.');
            } else {
                $existsStmt = $pdo->prepare(
                    'select is_active from household_members where household_id = :hid and user_id = :uid'
                );
                $existsStmt->execute(['hid' => $currentHousehold['id'], 'uid' => $user['id']]);
                $existing = $existsStmt->fetch();
                if ($existing) {
                    if (!$existing['is_active']) {
                        $activate = $pdo->prepare(
                            'update household_members set is_active = true where household_id = :hid and user_id = :uid'
                        );
                        $activate->execute(['hid' => $currentHousehold['id'], 'uid' => $user['id']]);
                        $msg = hb_t('Member reactivated.');
                    } else {
                        $error = hb_t('User already belongs to this household.');
                    }
                } else {
                    $addStmt = $pdo->prepare(
                        'insert into household_members (household_id, user_id, role, is_active)
                         values (:hid, :uid, :role, true)'
                    );
                    $addStmt->execute([
                        'hid' => $currentHousehold['id'],
                        'uid' => $user['id'],
                        'role' => 'editor',
                    ]);
                    $msg = hb_t('Member added.');
                }
            }
        }
    }
}

if ($action === 'create_api_token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim((string)($_POST['token_label'] ?? ''));
    if ($label === '') {
        $label = 'API Token';
    }
    $plain = hb_api_token_plain();
    $hash = hb_api_token_hash($plain);
    $ins = $pdo->prepare('insert into api_tokens (user_id, token_hash, label) values (:uid, :hash, :label)');
    $ins->execute(['uid' => $userId, 'hash' => $hash, 'label' => $label]);
    $_SESSION['hb_new_api_token'] = $plain;
    header('Location: /household.php?action=settings&msg=token_created');
    exit;
}

if ($action === 'delete_api_token' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenId = (int)($_POST['token_id'] ?? 0);
    if ($tokenId > 0) {
        $deleteStmt = $pdo->prepare('update api_tokens set revoked_at = now() where id = :id and user_id = :uid');
        $deleteStmt->execute(['id' => $tokenId, 'uid' => $userId]);
        if ($deleteStmt->rowCount() > 0) {
            header('Location: /household.php?action=settings&msg=token_deleted');
            exit;
        } else {
            $error = hb_t('Token not found.');
        }
    }
}

if ($action === 'revoke_oauth_client' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    if ($clientId > 0) {
        try {
            // Revoke all access tokens for this client+user
            $revokeAccess = $pdo->prepare(
                'update oauth_access_tokens set revoked = true
                 where client_id = :client_id and user_id = :uid'
            );
            $revokeAccess->execute(['client_id' => $clientId, 'uid' => $userId]);

            // Revoke all refresh tokens for this client+user
            $revokeRefresh = $pdo->prepare(
                'update oauth_refresh_tokens set revoked = true
                 where client_id = :client_id and user_id = :uid'
            );
            $revokeRefresh->execute(['client_id' => $clientId, 'uid' => $userId]);

            header('Location: /household.php?action=settings&msg=oauth_revoked');
            exit;
        } catch (Exception $e) {
            $error = hb_t('Failed to revoke OAuth client.');
        }
    }
}

if ($action === 'create_cloud_snapshot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$currentHousehold || !hb_is_household_admin($currentHousehold)) {
        $error = hb_t('Only household admins can create cloud snapshots.');
    } elseif ((string)($currentHousehold['data_residency_mode'] ?? 'server') !== 'cloud') {
        $error = hb_t('Enable cloud mode first.');
    } else {
        try {
            $syncMode = (string)($currentHousehold['cloud_sync_mode'] ?? 'disabled');
            $includeReceipts = in_array($syncMode, ['receipts_and_exports'], true);
            $snapshotResult = hb_create_cloud_snapshot($pdo, $currentHousehold, $includeReceipts);
            $_SESSION['hb_cloud_snapshot_info'] = [
                'file' => $snapshotResult['file'],
                'bytes' => $snapshotResult['bytes'],
                'receipts_uploaded' => (int)($snapshotResult['receipts_uploaded'] ?? 0),
            ];
            header('Location: /household.php?action=settings&msg=cloud_snapshot_created');
            exit;
        } catch (Throwable $e) {
            $error = hb_t('Cloud snapshot failed: ') . $e->getMessage();
        }
    }
}

if ($action === 'test_nextcloud_connection' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$currentHousehold || !hb_is_household_admin($currentHousehold)) {
        $error = hb_t('Only household admins can test cloud connections.');
    } elseif ((string)($currentHousehold['cloud_primary_provider'] ?? '') !== 'nextcloud') {
        $error = hb_t('Set cloud provider to Nextcloud first.');
    } else {
        try {
            $testResult = hb_test_nextcloud_connection($currentHousehold);
            $_SESSION['hb_cloud_test_info'] = $testResult;
            header('Location: /household.php?action=settings&msg=nextcloud_test_ok');
            exit;
        } catch (Throwable $e) {
            $error = hb_t('Nextcloud test failed: ') . $e->getMessage();
        }
    }
}

if ($action === 'migrate_to_nextcloud' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$currentHousehold || !hb_is_household_admin($currentHousehold)) {
        $error = hb_t('Only household admins can run migrations.');
    } elseif ((string)($currentHousehold['cloud_primary_provider'] ?? '') !== 'nextcloud') {
        $error = hb_t('Set cloud provider to Nextcloud first.');
    } elseif ((string)($currentHousehold['data_residency_mode'] ?? 'server') !== 'cloud') {
        $error = hb_t('Enable cloud mode first.');
    } elseif (trim((string)($currentHousehold['cloud_endpoint_url'] ?? '')) === '' ||
        trim((string)($currentHousehold['cloud_user_identifier'] ?? '')) === '' ||
        (string)($currentHousehold['cloud_access_secret'] ?? '') === '' ||
        trim((string)($currentHousehold['cloud_remote_path'] ?? '')) === '') {
        $error = hb_t('Cloud connection is incomplete. Please fill endpoint, user, app password and remote path.');
    } else {
        try {
            $testResult = hb_test_nextcloud_connection($currentHousehold);
            $snapshotResult = hb_create_cloud_snapshot($pdo, $currentHousehold, true);
            $sqliteResult = hb_create_nextcloud_session_sqlite($pdo, $currentHousehold);
            $_SESSION['hb_cloud_migration_info'] = [
                'dir_url' => $testResult['dir_url'] ?? '',
                'file' => $snapshotResult['file'] ?? '',
                'bytes' => (int)($snapshotResult['bytes'] ?? 0),
                'receipts_uploaded' => (int)($snapshotResult['receipts_uploaded'] ?? 0),
                'sqlite_remote_path' => $sqliteResult['remote_path'] ?? '',
                'migrated_at' => gmdate('c'),
            ];
            header('Location: /household.php?action=settings&msg=nextcloud_migration_done');
            exit;
        } catch (Throwable $e) {
            $error = hb_t('Migration to Nextcloud failed: ') . $e->getMessage();
        }
    }
}

$members = [];
if ($action === 'settings' && $currentHousehold) {
    $membersStmt = $pdo->prepare(
        'select u.id, u.username, u.email, u.first_name, u.last_name, m.role, m.is_active, m.created_at
           from household_members m
           join users u on u.id = m.user_id
          where m.household_id = :hid
          order by m.created_at asc, u.username asc'
    );
    $membersStmt->execute(['hid' => $currentHousehold['id']]);
    $members = $membersStmt->fetchAll();
}
$anchorAccounts = [];
$anchorCategories = [];
$anchorPayees = [];
if ($action === 'settings' && $currentHousehold) {
    $accountsStmt = $pdo->prepare('select id, name from accounts where household_id = :hid and is_archived = false order by name asc');
    $accountsStmt->execute(['hid' => $currentHousehold['id']]);
    $anchorAccounts = $accountsStmt->fetchAll();

    $categoriesStmt = $pdo->prepare("select id, name from categories where household_id = :hid and is_active = true and type = 'income' order by name asc");
    $categoriesStmt->execute(['hid' => $currentHousehold['id']]);
    $anchorCategories = $categoriesStmt->fetchAll();

    $payeesStmt = $pdo->prepare('select id, name from payees where household_id = :hid order by name asc');
    $payeesStmt->execute(['hid' => $currentHousehold['id']]);
    $anchorPayees = $payeesStmt->fetchAll();
}
$apiTokens = [];
$oauthApps = [];
if ($action === 'settings') {
    $tokenStmt = $pdo->prepare('select id, label, created_at, last_used_at, revoked_at from api_tokens where user_id = :uid order by created_at desc');
    $tokenStmt->execute(['uid' => $userId]);
    $apiTokens = $tokenStmt->fetchAll();

    // Get active OAuth authorizations
    $oauthStmt = $pdo->prepare(
        'select distinct c.id, c.name,
                string_agg(distinct oat.scopes, \', \' order by oat.scopes) as scopes,
                max(oat.created_at) as created_at,
                max(al.created_at) as last_used_at
         from oauth_access_tokens oat
         join oauth_clients c on c.id = oat.client_id
         left join api_audit_log al on al.token_id = oat.id
         where oat.user_id = :uid and oat.revoked = false
         group by c.id, c.name
         order by max(oat.created_at) desc'
    );
    $oauthStmt->execute(['uid' => $userId]);
    $oauthApps = $oauthStmt->fetchAll();
}

function hb_scope_badges(?string $scopes): string
{
    $value = trim((string)$scopes);
    if ($value === '') {
        return '<span class="badge text-bg-secondary">none</span>';
    }
    $parts = preg_split('/[\s,]+/', $value) ?: [];
    $parts = array_values(array_unique(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== '')));
    if (!$parts) {
        return '<span class="badge text-bg-secondary">none</span>';
    }
    $chunks = [];
    foreach ($parts as $scope) {
        $chunks[] = '<span class="badge text-bg-light border">' . htmlspecialchars($scope, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }
    return implode(' ', $chunks);
}

$newApiToken = (string)($_SESSION['hb_new_api_token'] ?? '');
unset($_SESSION['hb_new_api_token']);
$snapshotInfo = $_SESSION['hb_cloud_snapshot_info'] ?? null;
unset($_SESSION['hb_cloud_snapshot_info']);
$cloudTestInfo = $_SESSION['hb_cloud_test_info'] ?? null;
unset($_SESSION['hb_cloud_test_info']);
$migrationInfo = $_SESSION['hb_cloud_migration_info'] ?? null;
unset($_SESSION['hb_cloud_migration_info']);

ob_start();
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h1 class="h4 mb-0"><?= htmlspecialchars(hb_t('Household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <div class="text-muted small"><?= htmlspecialchars(hb_t('User:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($currentUser['username'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <?php if ($currentHousehold): ?>
      <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-primary" href="/household.php?action=settings"><?= htmlspecialchars(hb_t('Settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        <a class="btn btn-sm btn-outline-secondary" href="/accounts.php"><?= htmlspecialchars(hb_t('Go to dashboard'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('Settings saved.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($msg === 'cloud_session_conflict' && is_array($cloudSessionConflict)): ?>
    <div class="alert alert-warning">
      <div class="fw-semibold mb-1"><?= htmlspecialchars(hb_t('Another device is currently editing this cloud household.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <div class="small text-muted mb-3">
        <?= htmlspecialchars(hb_t('Active device:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <strong><?= htmlspecialchars((string)($cloudSessionConflict['device_label'] ?? hb_t('Unknown device')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
        <?php if (!empty($cloudSessionConflict['last_seen_at'])): ?>
          · <?= htmlspecialchars(hb_t('Last seen:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          <?= htmlspecialchars((string)$cloudSessionConflict['last_seen_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <?php endif; ?>
      </div>
      <form method="post" action="/household.php" class="d-flex gap-2 flex-wrap">
        <?= hb_csrf_field() ?>
        <input type="hidden" name="action" value="takeover">
        <input type="hidden" name="household_id" value="<?= (int)($cloudSessionConflict['household_id'] ?? 0) ?>">
        <button type="submit" class="btn btn-warning btn-sm"><?= htmlspecialchars(hb_t('Take over session'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        <a href="/household.php" class="btn btn-outline-secondary btn-sm"><?= htmlspecialchars(hb_t('Cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      </form>
    </div>
  <?php endif; ?>
  <?php if ($msg === 'token_created' && $newApiToken !== ''): ?>
    <div class="alert alert-warning">API token (nur jetzt sichtbar): <code><?= htmlspecialchars($newApiToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></div>
  <?php endif; ?>
  <?php if ($msg === 'token_deleted'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('API token deleted.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($msg === 'oauth_revoked'): ?>
    <div class="alert alert-success"><?= htmlspecialchars(hb_t('OAuth authorization revoked.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($msg === 'cloud_snapshot_created' && is_array($snapshotInfo)): ?>
    <div class="alert alert-success">
      <?= htmlspecialchars(hb_t('Cloud snapshot created:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      <code><?= htmlspecialchars((string)($snapshotInfo['file'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code>
      (<?= (int)($snapshotInfo['bytes'] ?? 0) ?> bytes,
      <?= (int)($snapshotInfo['receipts_uploaded'] ?? 0) ?> <?= htmlspecialchars(hb_t('receipts uploaded'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
    </div>
  <?php endif; ?>
  <?php if ($msg === 'nextcloud_test_ok' && is_array($cloudTestInfo)): ?>
    <div class="alert alert-success">
      <?= htmlspecialchars(hb_t('Nextcloud connection test successful. Test directory:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      <code><?= htmlspecialchars((string)($cloudTestInfo['dir_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code>
    </div>
  <?php endif; ?>
  <?php if ($msg === 'nextcloud_migration_done' && is_array($migrationInfo)): ?>
    <div class="alert alert-success">
      <?= htmlspecialchars(hb_t('Migration to Nextcloud completed. Snapshot:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      <code><?= htmlspecialchars((string)($migrationInfo['file'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code>
      (<?= (int)($migrationInfo['bytes'] ?? 0) ?> bytes,
      <?= (int)($migrationInfo['receipts_uploaded'] ?? 0) ?> <?= htmlspecialchars(hb_t('receipts uploaded'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
      <?php if (!empty($migrationInfo['sqlite_remote_path'])): ?>
        <div class="small mt-1">
          <?= htmlspecialchars(hb_t('Encrypted SQLite runtime file:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          <code><?= htmlspecialchars((string)$migrationInfo['sqlite_remote_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php if ($action === 'settings' && $currentHousehold): ?>
    <div class="row g-4">
      <div class="col-lg-8">
          <div class="card shadow-sm">
            <div class="card-body">
              <?php if (!empty($conflict)): ?>
                <?= $conflict ?>
              <?php endif; ?>
              <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Household settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <form method="post" action="/household.php?action=update_settings">
              <input type="hidden" name="action" value="update_settings">
              <input type="hidden" name="row_version" value="<?= (int)($currentHousehold['row_version'] ?? 0) ?>">
              <div class="mb-3">
                <label class="form-label" for="name"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($currentHousehold['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="currency">
                    <?= htmlspecialchars(hb_t('Currency'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('3-letter ISO code, e.g. EUR.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <input type="text" class="form-control" id="currency" name="currency_code" maxlength="3" value="<?= htmlspecialchars($currentHousehold['currency_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="mode">
                    <?= htmlspecialchars(hb_t('Period calculation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Defines how BudgetLove calculates budget, forecast, reports and close periods.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <select class="form-select" id="mode" name="month_close_mode">
                    <?php foreach (hb_allowed_month_close_modes() as $mode): ?>
                      <option value="<?= $mode ?>" <?= $currentHousehold['month_close_mode'] === $mode ? 'selected' : '' ?>>
                        <?= htmlspecialchars(hb_month_close_mode_label($mode), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label" for="salary-day">
                  <?= htmlspecialchars(hb_t('Salary day'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Only relevant for salary_day mode; day (1-31) when a new billing month starts.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                </label>
                <input type="number" class="form-control" id="salary-day" name="salary_day" min="1" max="31" value="<?= htmlspecialchars((string)($currentHousehold['salary_day'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <div class="border rounded-3 p-3 mt-3">
                <div class="fw-semibold mb-2"><?= htmlspecialchars(hb_t('Actual salary payment'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="text-muted small mb-3"><?= htmlspecialchars(hb_t('BudgetLove uses matching income bookings as the start of a new budget period.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="alert alert-info small py-2">
                  <?= htmlspecialchars(hb_t('Setup: create/import the salary booking first, then select its account, income category and payee here. The mode can be changed later by a household admin.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label" for="salary-anchor-account"><?= htmlspecialchars(hb_t('Salary account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <select class="form-select" id="salary-anchor-account" name="salary_anchor_account_id">
                      <option value=""><?= htmlspecialchars(hb_t('Any account'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php foreach ($anchorAccounts as $account): ?>
                        <option value="<?= (int)$account['id'] ?>" <?= (int)($currentHousehold['salary_anchor_account_id'] ?? 0) === (int)$account['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string)$account['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="salary-anchor-category"><?= htmlspecialchars(hb_t('Salary category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <select class="form-select" id="salary-anchor-category" name="salary_anchor_category_id">
                      <option value=""><?= htmlspecialchars(hb_t('Any income category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php foreach ($anchorCategories as $category): ?>
                        <option value="<?= (int)$category['id'] ?>" <?= (int)($currentHousehold['salary_anchor_category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string)$category['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="salary-anchor-payee"><?= htmlspecialchars(hb_t('Salary payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <select class="form-select" id="salary-anchor-payee" name="salary_anchor_payee_id">
                      <option value=""><?= htmlspecialchars(hb_t('Any payee'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php foreach ($anchorPayees as $payee): ?>
                        <option value="<?= (int)$payee['id'] ?>" <?= (int)($currentHousehold['salary_anchor_payee_id'] ?? 0) === (int)$payee['id'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string)$payee['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>
              <div class="border rounded-3 p-3 mt-3">
                <div class="fw-semibold mb-2"><?= htmlspecialchars(hb_t('Cloud and data residency'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="text-muted small mb-3">
                  <?= htmlspecialchars(hb_t('Configure where finance data should live. Cloud mode is prepared for iCloud-first workflows with ephemeral session handling.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
                <div class="alert alert-light border small py-2">
                  <?= htmlspecialchars(hb_t('Self-hosted local database remains fully supported. Use "Server" mode if you want to keep finance data in your local DB stack.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
                <?php if (($currentHousehold['data_residency_mode'] ?? 'server') === 'cloud'): ?>
                  <div class="alert alert-warning small py-2">
                    <?= htmlspecialchars(hb_t('Cloud mode is active. Use ephemeral sessions and cloud snapshots. Avoid long-lived local files on the server.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </div>
                <?php endif; ?>
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label" for="data-residency-mode"><?= htmlspecialchars(hb_t('Data residency'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <select class="form-select" id="data-residency-mode" name="data_residency_mode">
                      <option value="server" <?= (($currentHousehold['data_residency_mode'] ?? 'server') === 'server') ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Server'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <option value="cloud" <?= (($currentHousehold['data_residency_mode'] ?? '') === 'cloud') ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Cloud'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="cloud-primary-provider"><?= htmlspecialchars(hb_t('Cloud provider'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <select class="form-select" id="cloud-primary-provider" name="cloud_primary_provider">
                      <option value=""><?= htmlspecialchars(hb_t('Not set'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <option value="icloud" <?= (($currentHousehold['cloud_primary_provider'] ?? '') === 'icloud') ? 'selected' : '' ?>>iCloud</option>
                      <option value="nextcloud" <?= (($currentHousehold['cloud_primary_provider'] ?? '') === 'nextcloud') ? 'selected' : '' ?>>Nextcloud</option>
                      <option value="gmail" <?= (($currentHousehold['cloud_primary_provider'] ?? '') === 'gmail') ? 'selected' : '' ?>>Gmail</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label class="form-label" for="cloud-sync-mode"><?= htmlspecialchars(hb_t('Cloud sync mode'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <select class="form-select" id="cloud-sync-mode" name="cloud_sync_mode">
                      <option value="disabled" <?= (($currentHousehold['cloud_sync_mode'] ?? 'disabled') === 'disabled') ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Disabled'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <option value="exports_only" <?= (($currentHousehold['cloud_sync_mode'] ?? '') === 'exports_only') ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Exports only'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <option value="receipts_and_exports" <?= (($currentHousehold['cloud_sync_mode'] ?? '') === 'receipts_and_exports') ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('Receipts and exports'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <option value="sqlite_snapshots" <?= (($currentHousehold['cloud_sync_mode'] ?? '') === 'sqlite_snapshots') ? 'selected' : '' ?>><?= htmlspecialchars(hb_t('SQLite snapshots'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="cloud-user-identifier"><?= htmlspecialchars(hb_t('Cloud user identifier'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <input type="text" class="form-control" id="cloud-user-identifier" name="cloud_user_identifier" value="<?= htmlspecialchars((string)($currentHousehold['cloud_user_identifier'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="appleid@example.com">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="cloud-remote-path"><?= htmlspecialchars(hb_t('Cloud remote path'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <input type="text" class="form-control" id="cloud-remote-path" name="cloud_remote_path" value="<?= htmlspecialchars((string)($currentHousehold['cloud_remote_path'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="BudgetLove/Household-A or local sync folder">
                  </div>
                  <div class="col-md-12">
                    <label class="form-label" for="cloud-endpoint-url"><?= htmlspecialchars(hb_t('Cloud endpoint URL (Nextcloud WebDAV)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <input type="url" class="form-control" id="cloud-endpoint-url" name="cloud_endpoint_url" value="<?= htmlspecialchars((string)($currentHousehold['cloud_endpoint_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="https://cloud.example.com/remote.php/dav/files/USER">
                  </div>
                  <div class="col-md-12">
                    <label class="form-label" for="cloud-access-secret"><?= htmlspecialchars(hb_t('Cloud app password / token'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <input type="password" class="form-control" id="cloud-access-secret" name="cloud_access_secret" value="" placeholder="<?= htmlspecialchars(hb_t('Leave empty to keep current secret'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <div class="form-text"><?= htmlspecialchars(hb_t('Used for Nextcloud WebDAV uploads in cloud mode.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label" for="cloud-session-ttl"><?= htmlspecialchars(hb_t('Session TTL (minutes)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                    <input type="number" class="form-control" id="cloud-session-ttl" name="cloud_session_ttl_minutes" min="5" max="1440" value="<?= (int)($currentHousehold['cloud_session_ttl_minutes'] ?? 120) ?>">
                  </div>
                  <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check mb-2">
                      <input class="form-check-input" type="checkbox" id="cloud-require-ephemeral" name="cloud_require_ephemeral" value="1" <?= !empty($currentHousehold['cloud_require_ephemeral']) ? 'checked' : '' ?>>
                      <label class="form-check-label" for="cloud-require-ephemeral">
                        <?= htmlspecialchars(hb_t('Ephemeral local cache only (session-limited)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
              <button class="btn btn-success mt-3" type="submit" <?= hb_is_household_admin($currentHousehold) ? '' : 'disabled' ?>><?= htmlspecialchars(hb_t('Save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              <?php if (!hb_is_household_admin($currentHousehold)): ?>
                <p class="text-muted small mb-0 mt-2"><?= htmlspecialchars(hb_t('Only household admins can save.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
          <div class="card-body">
            <h2 class="h6 mb-3">API Tokens</h2>
            <form method="post" action="/household.php?action=create_api_token" class="mb-3">
              <input type="hidden" name="action" value="create_api_token">
              <label class="form-label" for="token-label">Label</label>
              <input id="token-label" name="token_label" class="form-control mb-2" type="text" placeholder="Claude / ChatGPT / Script">
              <button class="btn btn-sm btn-primary" type="submit">Token erstellen</button>
            </form>
            <?php if ($apiTokens): ?>
              <ul class="list-group list-group-flush">
                <?php foreach ($apiTokens as $t): ?>
                  <li class="list-group-item px-0">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <div class="fw-semibold"><?= htmlspecialchars((string)$t['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <div class="small text-muted">Created: <?= htmlspecialchars((string)$t['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php if ($t['last_used_at']): ?>
                          <div class="small text-muted">Last used: <?= htmlspecialchars((string)$t['last_used_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php else: ?>
                          <div class="small text-muted">Last used: never</div>
                        <?php endif; ?>
                        <?php if ($t['revoked_at']): ?>
                          <span class="badge bg-danger small">Revoked: <?= htmlspecialchars((string)$t['revoked_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <?php endif; ?>
                      </div>
                      <?php if (!$t['revoked_at']): ?>
                        <form method="post" action="/household.php?action=delete_api_token" style="display: inline;">
                          <input type="hidden" name="action" value="delete_api_token">
                          <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Diesen Token wirklich löschen?');">Delete</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
        <div class="card shadow-sm">
          <div class="card-body">
            <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Household members'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <?php if ($members): ?>
              <ul class="list-group list-group-flush mb-3">
                <?php foreach ($members as $member): ?>
                  <?php
                  $fullName = trim((string)($member['first_name'] ?? '') . ' ' . (string)($member['last_name'] ?? ''));
                  $creator = (int)($currentHousehold['created_by_user_id'] ?? 0) === (int)$member['id'];
                  ?>
                  <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                    <div>
                      <div class="fw-semibold"><?= htmlspecialchars($member['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <div class="text-muted small">
                        <?= htmlspecialchars($fullName !== '' ? $fullName : ($member['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </div>
                    </div>
                    <div class="d-flex flex-column align-items-end gap-1">
                      <span class="badge bg-light text-dark border"><?= htmlspecialchars(hb_t($member['role'] === 'admin' ? 'Admin' : 'Editor'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <?php if (!$member['is_active']): ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars(hb_t('Inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <?php elseif ($creator): ?>
                        <span class="badge bg-primary"><?= htmlspecialchars(hb_t('Creator'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="text-muted small mb-3"><?= htmlspecialchars(hb_t('No members yet.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="post" action="/household.php?action=add_member">
              <input type="hidden" name="action" value="add_member">
              <div class="mb-2">
                <label class="form-label" for="member-identifier"><?= htmlspecialchars(hb_t('Username or email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" id="member-identifier" name="identifier" <?= $canManageMembers ? '' : 'disabled' ?> required>
              </div>
              <button class="btn btn-primary btn-sm" type="submit" <?= $canManageMembers ? '' : 'disabled' ?>>
                <?= htmlspecialchars(hb_t('Add member'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </button>
              <?php if (!$canManageMembers): ?>
                <p class="text-muted small mt-2 mb-0"><?= htmlspecialchars(hb_t('Only the household creator can add members.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </form>
          </div>
        </div>
        <div class="card shadow-sm">
          <div class="card-body">
            <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Connected Apps'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <?php if ($oauthApps): ?>
              <ul class="list-group list-group-flush">
                <?php foreach ($oauthApps as $app): ?>
                  <li class="list-group-item px-0">
                    <div class="d-flex justify-content-between align-items-start">
                      <div class="flex-grow-1">
                        <div class="fw-semibold"><?= htmlspecialchars((string)$app['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <div class="small text-muted">Authorized: <?= htmlspecialchars((string)$app['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php if ($app['last_used_at']): ?>
                          <div class="small text-muted">Last access: <?= htmlspecialchars((string)$app['last_used_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php else: ?>
                          <div class="small text-muted">Last access: never</div>
                        <?php endif; ?>
                        <div class="small text-muted">Scopes:</div>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                          <?= hb_scope_badges((string)($app['scopes'] ?? '')) ?>
                        </div>
                      </div>
                      <form method="post" action="/household.php?action=revoke_oauth_client" style="display: inline;">
                        <input type="hidden" name="action" value="revoke_oauth_client">
                        <input type="hidden" name="client_id" value="<?= (int)$app['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Zugang wirklich widerrufen? Die App kann danach nicht mehr auf deine Daten zugreifen.');">Revoke</button>
                      </form>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="text-muted small"><?= htmlspecialchars(hb_t('No connected apps.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="card shadow-sm mt-3">
          <div class="card-body">
            <h2 class="h6 mb-3"><?= htmlspecialchars(hb_t('Cloud snapshots'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <div class="small text-muted mb-2">
              <?= htmlspecialchars(hb_t('Create a compressed household snapshot and write it to the configured cloud path.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
            <form method="post" action="/household.php?action=create_cloud_snapshot">
              <input type="hidden" name="action" value="create_cloud_snapshot">
              <button class="btn btn-sm btn-outline-primary" type="submit" <?= hb_is_household_admin($currentHousehold) ? '' : 'disabled' ?>>
                <?= htmlspecialchars(hb_t('Create cloud snapshot now'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </button>
            </form>
            <form method="post" action="/household.php?action=test_nextcloud_connection" class="mt-2">
              <input type="hidden" name="action" value="test_nextcloud_connection">
              <button class="btn btn-sm btn-outline-secondary" type="submit" <?= hb_is_household_admin($currentHousehold) ? '' : 'disabled' ?>>
                <?= htmlspecialchars(hb_t('Test Nextcloud connection'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </button>
            </form>
            <form method="post" action="/household.php?action=migrate_to_nextcloud" class="mt-2">
              <input type="hidden" name="action" value="migrate_to_nextcloud">
              <button class="btn btn-sm btn-warning" type="submit" <?= hb_is_household_admin($currentHousehold) ? '' : 'disabled' ?> onclick="return confirm('Lokale Haushaltsdaten jetzt als Snapshot zu Nextcloud migrieren?');">
                <?= htmlspecialchars(hb_t('Migrate local data to Nextcloud'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </button>
            </form>
            <?php if (($currentHousehold['data_residency_mode'] ?? 'server') !== 'cloud'): ?>
              <div class="small text-muted mt-2"><?= htmlspecialchars(hb_t('Switch to cloud mode to use this action.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="row g-4">
      <?php if ($households): ?>
        <div class="col-lg-6">
          <div class="card shadow-sm">
            <div class="card-body">
              <h2 class="h6">Bestehende Haushalte</h2>
              <form method="post" action="/household.php?action=set">
                <input type="hidden" name="action" value="set">
                <div class="mb-3">
                  <label class="form-label" for="household-id"><?= htmlspecialchars(hb_t('Select household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                  <select class="form-select" id="household-id" name="household_id" required>
                    <?php foreach ($households as $h): ?>
                      <option value="<?= (int)$h['id'] ?>">
                        <?= htmlspecialchars($h['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= htmlspecialchars($h['member_role'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary"><?= htmlspecialchars(hb_t('Enter household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                <?php if ($currentHousehold): ?>
                  <a class="btn btn-link btn-sm" href="/household.php?action=settings"><?= htmlspecialchars(hb_t('Settings'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                <?php endif; ?>
              </form>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-body">
            <h2 class="h6"><?= htmlspecialchars(hb_t('Create new household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <form method="post" action="/household.php?action=create">
              <input type="hidden" name="action" value="create">
              <div class="mb-3">
                <label for="name" class="form-label"><?= htmlspecialchars(hb_t('Name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input type="text" class="form-control" id="name" name="name" required>
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label for="currency" class="form-label">
                    <?= htmlspecialchars(hb_t('Currency'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('3-letter ISO code, e.g. EUR.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <input type="text" class="form-control" id="currency" name="currency_code" value="EUR" maxlength="3" required>
                </div>
                <div class="col-md-6">
                  <label for="mode" class="form-label">
                    <?= htmlspecialchars(hb_t('Period calculation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Defines how BudgetLove calculates budget, forecast, reports and close periods.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                  </label>
                  <select class="form-select" id="mode" name="month_close_mode">
                    <?php foreach (hb_allowed_month_close_modes() as $mode): ?>
                      <option value="<?= $mode ?>"><?= htmlspecialchars(hb_month_close_mode_label($mode), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="mt-3">
                <label for="salary-day" class="form-label">
                  <?= htmlspecialchars(hb_t('Salary day (optional)'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  <span class="text-muted" data-bs-toggle="tooltip" title="<?= htmlspecialchars(hb_t('Only required for Salary day mode.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">ℹ️</span>
                </label>
                <input type="number" class="form-control" id="salary-day" name="salary_day" min="1" max="31" placeholder="<?= htmlspecialchars(hb_t('1-31'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <div class="form-text"><?= htmlspecialchars(hb_t('Only required when Salary day mode is selected.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </div>
              <button type="submit" class="btn btn-success mt-3"><?= htmlspecialchars(hb_t('Create household'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </form>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../templates/layout.php';
