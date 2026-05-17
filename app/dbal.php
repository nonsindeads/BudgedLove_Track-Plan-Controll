<?php
declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * DBAL connection factory.
 *
 * Existing pages still use PDO directly. New and ported code should use this
 * layer so PostgreSQL and SQLite can be supported without duplicating SQL.
 */
function hb_dbal_server(): Connection
{
    static $connection = null;
    if ($connection instanceof Connection) {
        return $connection;
    }

    $connection = DriverManager::getConnection(hb_dbal_params_from_env());
    return $connection;
}

function hb_dbal_household(): Connection
{
    static $cacheKey = null;
    static $connection = null;

    $params = hb_dbal_household_params();
    $newCacheKey = hash('sha256', json_encode($params, JSON_THROW_ON_ERROR));
    if ($connection instanceof Connection && $cacheKey === $newCacheKey) {
        return $connection;
    }

    $cacheKey = $newCacheKey;
    $connection = DriverManager::getConnection($params);
    return $connection;
}

function hb_dbal_household_params(): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sqlitePath = (string)($_SESSION['hb_cloud_sqlite_path'] ?? '');
        if ($sqlitePath !== '' && is_file($sqlitePath)) {
            return [
                'driver' => 'pdo_sqlite',
                'path' => $sqlitePath,
            ];
        }
    }

    return hb_dbal_params_from_env();
}

function hb_dbal_params_from_env(): array
{
    $dsn = getenv('HB_DB_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=haushaltsbuch';
    $user = getenv('HB_DB_USER') ?: 'hb_app';
    $pass = getenv('HB_DB_PASS') ?: 'hb_app_pw_change_me';

    if (str_starts_with($dsn, 'pgsql:')) {
        return array_merge(hb_parse_pdo_dsn($dsn, 'pdo_pgsql'), [
            'user' => $user,
            'password' => $pass,
        ]);
    }

    if (str_starts_with($dsn, 'sqlite:')) {
        return [
            'driver' => 'pdo_sqlite',
            'path' => substr($dsn, strlen('sqlite:')),
        ];
    }

    throw new RuntimeException('Unsupported HB_DB_DSN for DBAL: ' . $dsn);
}

function hb_parse_pdo_dsn(string $dsn, string $driver): array
{
    $params = ['driver' => $driver];
    $body = substr($dsn, strpos($dsn, ':') + 1);
    foreach (explode(';', $body) as $part) {
        if ($part === '' || !str_contains($part, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $part, 2);
        $key = trim($key);
        if ($key === 'dbname') {
            $params['dbname'] = $value;
        } elseif ($key === 'host') {
            $params['host'] = $value;
        } elseif ($key === 'port') {
            $params['port'] = (int)$value;
        } else {
            $params[$key] = $value;
        }
    }
    return $params;
}

function hb_dbal_platform(Connection $connection): string
{
    $class = strtolower(get_class($connection->getDatabasePlatform()));
    if (str_contains($class, 'sqlite')) {
        return 'sqlite';
    }
    if (str_contains($class, 'postgres')) {
        return 'postgresql';
    }
    if (str_contains($class, 'mysql')) {
        return 'mysql';
    }
    return $class;
}

function hb_dbal_insert_and_get_id(Connection $connection, string $table, array $data, string $idColumn = 'id', array $types = []): int
{
    $connection->insert($table, $data, $types);
    if (hb_dbal_platform($connection) === 'postgresql') {
        $id = $connection->fetchOne(
            "select currval(pg_get_serial_sequence(:table_name, :id_column))",
            ['table_name' => $table, 'id_column' => $idColumn]
        );
        return (int)$id;
    }
    return (int)$connection->lastInsertId();
}
