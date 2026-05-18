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
    ) {
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

function hb_cloud_sqlite_create_table(PDO $sqlite, string $table, array $columns): void
{
    $defs = [];
    foreach ($columns as $column) {
        $name = '"' . str_replace('"', '""', (string)$column['name']) . '"';
        $defs[] = $name . ' ' . (string)$column['type'];
    }
    $sqlite->exec('create table "' . str_replace('"', '""', $table) . '" (' . implode(', ', $defs) . ')');
}

function hb_cloud_sqlite_insert_rows(PDO $sqlite, string $table, array $columns, array $rows): void
{
    if (!$rows) {
        return;
    }
    $quotedColumns = array_map(static fn(string $c): string => '"' . str_replace('"', '""', $c) . '"', $columns);
    $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
    $stmt = $sqlite->prepare(
        'insert into "' . str_replace('"', '""', $table) . '" (' . implode(', ', $quotedColumns) . ') values (' . implode(', ', $placeholders) . ')'
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
