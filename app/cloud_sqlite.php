<?php
declare(strict_types=1);

function hb_cloud_sqlite_bootstrap_if_needed(PDO $sourcePdo, string $sqlitePath, int $householdId): void
{
    if ($sqlitePath === '' || !is_file($sqlitePath) || $householdId < 1) {
        return;
    }

    $sqlite = new PDO('sqlite:' . $sqlitePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if (
        hb_cloud_sqlite_has_table($sqlite, 'transactions')
        && hb_cloud_sqlite_has_table($sqlite, 'accounts')
        && hb_cloud_sqlite_has_table($sqlite, 'month_closures')
        && hb_cloud_sqlite_has_table($sqlite, 'savings_plans')
        && hb_cloud_sqlite_has_table($sqlite, 'savings_plan_categories')
        && hb_cloud_sqlite_has_table($sqlite, 'audit_events')
    ) {
        hb_cloud_sqlite_repair_if_needed($sourcePdo, $sqlite);
        return;
    }

    $tables = hb_cloud_sqlite_export_tables($sourcePdo, $householdId);
    $sqlite->exec('pragma foreign_keys = off');
    $sqlite->beginTransaction();
    try {
        foreach ($tables as $table => $rows) {
            $columns = hb_cloud_sqlite_postgres_columns($sourcePdo, $table);
            if (!$columns) {
                continue;
            }
            hb_cloud_sqlite_drop_table($sqlite, $table);
            hb_cloud_sqlite_create_table($sqlite, $table, $columns);
            hb_cloud_sqlite_insert_rows($sqlite, $table, array_column($columns, 'name'), $rows);
        }
        $sqlite->commit();
        hb_cloud_sqlite_repair_if_needed($sourcePdo, $sqlite);
    } catch (Throwable $e) {
        if ($sqlite->inTransaction()) {
            $sqlite->rollBack();
        }
        throw $e;
    }
}

function hb_cloud_sqlite_has_table(PDO $sqlite, string $table): bool
{
    $stmt = $sqlite->prepare("select 1 from sqlite_master where type = 'table' and name = :table limit 1");
    $stmt->execute(['table' => $table]);
    return (bool)$stmt->fetchColumn();
}

function hb_cloud_sqlite_pg_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('select to_regclass(:name) is not null');
    $stmt->execute(['name' => $table]);
    return (bool)$stmt->fetchColumn();
}

function hb_cloud_sqlite_export_tables(PDO $pdo, int $householdId): array
{
    $tables = [];
    $households = hb_cloud_sqlite_fetch_rows($pdo, 'select * from households where id = :hid', ['hid' => $householdId]);
    foreach ($households as &$row) {
        if (array_key_exists('cloud_access_secret', $row)) {
            $row['cloud_access_secret'] = null;
        }
    }
    unset($row);
    $tables['households'] = $households;
    $tables['household_members'] = hb_cloud_sqlite_fetch_rows($pdo, 'select * from household_members where household_id = :hid order by user_id asc', ['hid' => $householdId]);

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
        'month_closures',
        'savings_plans',
        'audit_events',
        'payee_mappings',
        'saving_goals',
        'saving_goal_contributions',
    ] as $table) {
        if (hb_cloud_sqlite_pg_table_exists($pdo, $table)) {
            $tables[$table] = hb_cloud_sqlite_fetch_rows($pdo, "select * from {$table} where household_id = :hid order by id asc", ['hid' => $householdId]);
        }
    }

    $transactionIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $tables['transactions'] ?? []));
    $transactionGroupIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $tables['transaction_groups'] ?? []));
    $budgetIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $tables['budgets'] ?? []));
    $savingsPlanIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $tables['savings_plans'] ?? []));
    $savingGoalIds = array_values(array_map(static fn(array $row): int => (int)$row['id'], $tables['saving_goals'] ?? []));

    if (hb_cloud_sqlite_pg_table_exists($pdo, 'transaction_tags')) {
        $tables['transaction_tags'] = $transactionIds ? hb_cloud_sqlite_fetch_rows_in($pdo, 'transaction_tags', 'transaction_id', $transactionIds, 'transaction_id asc, tag_id asc') : [];
    }
    if (hb_cloud_sqlite_pg_table_exists($pdo, 'transaction_splits')) {
        $tables['transaction_splits'] = hb_cloud_sqlite_fetch_transaction_splits($pdo, $transactionIds, $transactionGroupIds);
    }
    if (hb_cloud_sqlite_pg_table_exists($pdo, 'budget_categories')) {
        $tables['budget_categories'] = $budgetIds ? hb_cloud_sqlite_fetch_rows_in($pdo, 'budget_categories', 'budget_id', $budgetIds, 'budget_id asc, category_id asc') : [];
    }
    if (hb_cloud_sqlite_pg_table_exists($pdo, 'savings_plan_categories')) {
        $tables['savings_plan_categories'] = $savingsPlanIds ? hb_cloud_sqlite_fetch_rows_in($pdo, 'savings_plan_categories', 'savings_plan_id', $savingsPlanIds, 'savings_plan_id asc, category_id asc') : [];
    }
    if (hb_cloud_sqlite_pg_table_exists($pdo, 'saving_goal_contributions') && isset($tables['saving_goal_contributions']) && !$tables['saving_goal_contributions'] && $savingGoalIds) {
        $tables['saving_goal_contributions'] = hb_cloud_sqlite_fetch_rows_in($pdo, 'saving_goal_contributions', 'saving_goal_id', $savingGoalIds, 'saving_goal_id asc, id asc');
    }

    return $tables;
}

function hb_cloud_sqlite_fetch_transaction_splits(PDO $pdo, array $transactionIds, array $transactionGroupIds): array
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

function hb_cloud_sqlite_fetch_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

function hb_cloud_sqlite_fetch_rows_in(PDO $pdo, string $table, string $column, array $ids, string $orderBy): array
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

function hb_cloud_sqlite_postgres_columns(PDO $pdo, string $table): array
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
            'type' => hb_cloud_sqlite_type((string)$row['data_type']),
        ];
    }
    return $columns;
}

function hb_cloud_sqlite_type(string $type): string
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

function hb_cloud_sqlite_drop_table(PDO $sqlite, string $table): void
{
    $sqlite->exec('drop table if exists "' . str_replace('"', '""', $table) . '"');
}

function hb_cloud_sqlite_repair_if_needed(PDO $sourcePdo, PDO $sqlite): void
{
    if (!hb_cloud_sqlite_schema_needs_repair($sqlite)) {
        return;
    }

    $tableNames = hb_cloud_sqlite_existing_table_names($sqlite);
    $tableRows = [];
    foreach ($tableNames as $table) {
        $tableRows[$table] = hb_cloud_sqlite_fetch_rows($sqlite, 'select * from "' . str_replace('"', '""', $table) . '"');
    }

    $sqlite->exec('pragma foreign_keys = off');
    $sqlite->beginTransaction();
    try {
        foreach ($tableNames as $table) {
            $columns = hb_cloud_sqlite_pg_table_exists($sourcePdo, $table)
                ? hb_cloud_sqlite_postgres_columns($sourcePdo, $table)
                : hb_cloud_sqlite_sqlite_columns($sqlite, $table);
            if (!$columns) {
                continue;
            }
            hb_cloud_sqlite_drop_table($sqlite, $table);
            hb_cloud_sqlite_create_table($sqlite, $table, $columns);
            hb_cloud_sqlite_insert_rows($sqlite, $table, array_column($columns, 'name'), $tableRows[$table] ?? []);
        }
        $sqlite->commit();
    } catch (Throwable $e) {
        if ($sqlite->inTransaction()) {
            $sqlite->rollBack();
        }
        throw $e;
    }
}

function hb_cloud_sqlite_schema_needs_repair(PDO $sqlite): bool
{
    foreach (['transactions', 'accounts', 'planned_payments', 'payee_mappings', 'audit_events'] as $table) {
        if (!hb_cloud_sqlite_has_table($sqlite, $table)) {
            return true;
        }
        if (!hb_cloud_sqlite_table_has_primary_key($sqlite, $table)) {
            return true;
        }
    }

    foreach (['transactions', 'payee_mappings', 'audit_events'] as $table) {
        if (hb_cloud_sqlite_table_has_null_ids($sqlite, $table)) {
            return true;
        }
    }

    return false;
}

function hb_cloud_sqlite_existing_table_names(PDO $sqlite): array
{
    $stmt = $sqlite->query("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name asc");
    $names = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $name = (string)($row['name'] ?? '');
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names;
}

function hb_cloud_sqlite_sqlite_columns(PDO $sqlite, string $table): array
{
    $stmt = $sqlite->query('pragma table_info("' . str_replace('"', '""', $table) . '")');
    $columns = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $columns[] = [
            'name' => (string)$row['name'],
            'type' => hb_cloud_sqlite_type((string)($row['type'] ?? 'text')),
        ];
    }
    return $columns;
}

function hb_cloud_sqlite_table_has_primary_key(PDO $sqlite, string $table): bool
{
    $stmt = $sqlite->query('pragma table_info("' . str_replace('"', '""', $table) . '")');
    foreach ($stmt->fetchAll() ?: [] as $row) {
        if ((string)($row['name'] ?? '') === 'id' && (int)($row['pk'] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}

function hb_cloud_sqlite_table_has_null_ids(PDO $sqlite, string $table): bool
{
    if (!hb_cloud_sqlite_has_table($sqlite, $table)) {
        return false;
    }
    $stmt = $sqlite->query('select 1 from "' . str_replace('"', '""', $table) . '" where id is null limit 1');
    return (bool)$stmt->fetchColumn();
}

function hb_cloud_sqlite_create_table(PDO $sqlite, string $table, array $columns): void
{
    $compositePrimaryKeyTables = [
        'transaction_tags',
        'budget_categories',
        'savings_plan_categories',
    ];
    $defs = [];
    foreach ($columns as $column) {
        $columnName = (string)$column['name'];
        $name = '"' . str_replace('"', '""', (string)$column['name']) . '"';
        $type = (string)$column['type'];

        if ($columnName === 'id' && $type === 'integer' && !in_array($table, $compositePrimaryKeyTables, true)) {
            $defs[] = $name . ' integer primary key';
            continue;
        }

        $def = $name . ' ' . $type;
        if (in_array($columnName, ['created_at', 'updated_at', 'event_at', 'last_seen_at'], true)) {
            $def .= ' default current_timestamp';
        } elseif ($columnName === 'row_version') {
            $def .= ' default 1';
        } elseif ($columnName === 'is_active') {
            $def .= ' default 1';
        } elseif (str_starts_with($columnName, 'is_')) {
            $def .= ' default 0';
        }
        $defs[] = $def;
    }

    if ($table === 'transaction_tags') {
        $defs[] = 'primary key ("transaction_id", "tag_id")';
    } elseif ($table === 'budget_categories') {
        $defs[] = 'primary key ("budget_id", "category_id")';
    } elseif ($table === 'savings_plan_categories') {
        $defs[] = 'primary key ("savings_plan_id", "category_id")';
    } elseif ($table === 'payee_mappings') {
        $defs[] = 'unique ("household_id", "counterparty_name")';
    }
    $sqlite->exec('create table "' . str_replace('"', '""', $table) . '" (' . implode(', ', $defs) . ')');
}

function hb_cloud_sqlite_insert_rows(PDO $sqlite, string $table, array $columns, array $rows): void
{
    if (!$rows) {
        return;
    }
    foreach ($rows as $row) {
        if (array_key_exists('id', $row) && $row['id'] === null) {
            continue;
        }
        $insertColumns = [];
        $params = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;
            if ($column === 'id' && $value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $insertColumns[] = $column;
            $params[$column] = $value;
        }
        if (!$insertColumns) {
            continue;
        }
        $quotedColumns = array_map(static fn(string $c): string => '"' . str_replace('"', '""', $c) . '"', $insertColumns);
        $placeholders = array_map(static fn(string $c): string => ':' . $c, $insertColumns);
        $stmt = $sqlite->prepare(
            'insert into "' . str_replace('"', '""', $table) . '" (' . implode(', ', $quotedColumns) . ') values (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
    }
}
