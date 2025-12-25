<?php
declare(strict_types=1);

/**
 * Shared PDO connection with minimal bootstrap for the users table.
 */
function hb_get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = getenv('HB_DB_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=haushaltsbuch';
    $user = getenv('HB_DB_USER') ?: 'hb_app';
    $pass = getenv('HB_DB_PASS') ?: 'hb_app_pw_change_me';

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    hb_ensure_schema($pdo);
    hb_run_migrations($pdo);

    return $pdo;
}

/**
 * Create/align the users table and seed the default admin/admin account.
 */
function hb_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        <<<SQL
        create table if not exists users (
            id serial primary key,
            username varchar(64) unique not null,
            email varchar(255) unique not null,
            first_name varchar(100) not null,
            last_name varchar(100) not null,
            address text not null,
            consent_contact boolean not null default false,
            password_hash text not null,
            is_active boolean not null default false,
            is_admin boolean not null default false,
            created_at timestamptz default now()
        )
        SQL
    );

    // Align schema if the table already existed with fewer columns.
    $pdo->exec('alter table users add column if not exists email varchar(255)');
    $pdo->exec('alter table users add column if not exists first_name varchar(100)');
    $pdo->exec('alter table users add column if not exists last_name varchar(100)');
    $pdo->exec('alter table users add column if not exists address text');
    $pdo->exec('alter table users add column if not exists consent_contact boolean default false');
    $pdo->exec('alter table users add column if not exists is_active boolean default false');
    $pdo->exec('alter table users add column if not exists is_admin boolean default false');

    // Make sure indexes exist.
    $pdo->exec('create unique index if not exists users_email_lower_idx on users (lower(email))');

    // Fill missing data for legacy rows so NOT NULL constraints succeed.
    $pdo->exec("update users set email = coalesce(nullif(email, ''), username || '@example.test')");
    $pdo->exec("update users set first_name = coalesce(nullif(first_name, ''), 'Admin') where first_name is null");
    $pdo->exec("update users set last_name = coalesce(nullif(last_name, ''), 'User') where last_name is null");
    $pdo->exec("update users set address = coalesce(nullif(address, ''), 'N/A') where address is null");
    $pdo->exec("update users set consent_contact = coalesce(consent_contact, true) where consent_contact is null");
    $pdo->exec("update users set is_active = coalesce(is_active, false) where is_active is null");
    $pdo->exec("update users set is_admin = coalesce(is_admin, false) where is_admin is null");

    // Enforce NOT NULL after defaults.
    $pdo->exec('alter table users alter column email set not null');
    $pdo->exec('alter table users alter column first_name set not null');
    $pdo->exec('alter table users alter column last_name set not null');
    $pdo->exec('alter table users alter column address set not null');
    $pdo->exec('alter table users alter column consent_contact set not null');
    $pdo->exec('alter table users alter column is_active set not null');
    $pdo->exec('alter table users alter column is_admin set not null');

    hb_seed_admin($pdo);
}

function hb_seed_admin(PDO $pdo): void
{
    $stmt = $pdo->prepare('select id from users where username = :username limit 1');
    $stmt->execute(['username' => 'admin']);

    if (!$stmt->fetch()) {
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $insert = $pdo->prepare(
            'insert into users (username, email, first_name, last_name, address, consent_contact, password_hash, is_active, is_admin)
             values (:username, :email, :first_name, :last_name, :address, :consent_contact, :password_hash, :is_active, :is_admin)'
        );
        $insert->execute([
            'username' => 'admin',
            'email' => 'admin@example.test',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'address' => 'N/A',
            'consent_contact' => true,
            'password_hash' => $hash,
            'is_active' => true,
            'is_admin' => true,
        ]);
    } else {
        // Ensure admin row has required flags and data.
        $update = $pdo->prepare(
            'update users
               set email = coalesce(nullif(email, \'\'), :email),
                   first_name = coalesce(nullif(first_name, \'\'), :first_name),
                   last_name = coalesce(nullif(last_name, \'\'), :last_name),
                   address = coalesce(nullif(address, \'\'), :address),
                   consent_contact = true,
                   is_active = true,
                   is_admin = true
             where username = :username'
        );
        $update->execute([
            'username' => 'admin',
            'email' => 'admin@example.test',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'address' => 'N/A',
        ]);
    }
}

/**
 * Minimal migration runner that applies *.sql files in app/migrations.
 */
function hb_run_migrations(PDO $pdo): void
{
    $pdo->exec(
        <<<SQL
        create table if not exists migrations (
            id serial primary key,
            name varchar(255) unique not null,
            applied_at timestamptz not null default now()
        )
        SQL
    );

    $stmt = $pdo->query('select name from migrations');
    $applied = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $appliedSet = array_flip($applied);

    $files = glob(__DIR__ . '/migrations/*.sql');
    natsort($files);

    foreach ($files as $file) {
        $name = basename($file);
        if (isset($appliedSet[$name])) {
            continue;
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException('Migration konnte nicht gelesen werden: ' . $file);
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $insert = $pdo->prepare('insert into migrations (name) values (:name)');
            $insert->execute(['name' => $name]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
