<?php
declare(strict_types=1);

/**
 * SQLite connection + schema migrations.
 *
 * - One connection per request, created lazily by db().
 * - Pragmas: foreign_keys=ON, journal_mode=WAL, busy_timeout=5000, synchronous=NORMAL.
 * - app_today() SQL function returns today's date in APP_TIMEZONE (SQLite's date('now') is UTC).
 * - Timestamps (created_at/updated_at) are stored in UTC via CURRENT_TIMESTAMP;
 *   convert for display with datetime_label().
 */

function db_path(): string
{
    return getenv('BUILDTRACK_DB_PATH') ?: app_path((string)config('DB_PATH', 'data/buildtrack.sqlite'));
}

function db_connect(string $path): PDO
{
    $dir = dirname($path);
    if ($path !== ':memory:' && !is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create database directory: $dir");
    }
    if ($path !== ':memory:' && !is_writable($dir)) {
        throw new RuntimeException("Database directory is not writable by PHP: $dir");
    }
    $dsn = 'sqlite:' . $path;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ];
    // PHP 8.4+: PDO::connect() returns Pdo\Sqlite, which has createFunction().
    $pdo = PHP_VERSION_ID >= 80400 ? PDO::connect($dsn, null, null, $options) : new PDO($dsn, null, null, $options);

    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($path !== ':memory:') {
        $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
    }
    $pdo->exec('PRAGMA synchronous = NORMAL');

    $today = static fn(): string => date('Y-m-d');
    if (method_exists($pdo, 'createFunction')) {
        $pdo->createFunction('app_today', $today, 0);
    } else {
        $pdo->sqliteCreateFunction('app_today', $today, 0);
    }
    return $pdo;
}

/** Shared request connection. Tests may inject one with db_set(). */
function db(): PDO
{
    return $GLOBALS['__db'] ??= db_connect(db_path());
}

function db_set(?PDO $pdo): void
{
    $GLOBALS['__db'] = $pdo;
}

/** Run $fn inside a transaction (BEGIN IMMEDIATE takes the write lock up front, avoiding SQLITE_BUSY upgrades). */
function db_transaction(PDO $pdo, callable $fn): mixed
{
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn($pdo);
        $pdo->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function db_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_all(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_value(PDO $pdo, string $sql, array $params = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/* ------------------------------------------------------------------ migrations */

function migrations_dir(): string
{
    return realpath(APP_ROOT) . '/migrations';
}

/** @return array<int,string> version => file path, sorted. Files are named NNN_description.sql */
function migration_files(): array
{
    $files = [];
    foreach (glob(migrations_dir() . '/*.sql') ?: [] as $file) {
        if (preg_match('/^(\d+)_[a-z0-9_]+\.sql$/', basename($file), $m)) {
            $files[(int)$m[1]] = $file;
        }
    }
    ksort($files);
    return $files;
}

function schema_version(PDO $pdo): int
{
    return (int)$pdo->query('PRAGMA user_version')->fetchColumn();
}

function migrations_pending(PDO $pdo): array
{
    $current = schema_version($pdo);
    return array_filter(migration_files(), fn(int $v) => $v > $current, ARRAY_FILTER_USE_KEY);
}

/**
 * Apply pending migrations in order, each in its own transaction.
 * PRAGMA user_version mirrors the latest applied version (cheap per-request check);
 * schema_migrations keeps the human-readable history.
 *
 * @return string[] names of applied migrations
 */
function migrate(PDO $pdo): array
{
    $applied = [];
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        version INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    foreach (migrations_pending($pdo) as $version => $file) {
        $sql = (string)file_get_contents($file);
        // Foreign keys must be toggled outside a transaction (needed for table rebuilds).
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (schema_version($pdo) >= $version) { // another process got there first
                $pdo->exec('ROLLBACK');
                $pdo->exec('PRAGMA foreign_keys = ON');
                continue;
            }
            $pdo->exec($sql);
            $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
            if ($violations) {
                throw new RuntimeException('Migration ' . basename($file) . ' left foreign key violations: ' . json_encode($violations));
            }
            db_exec($pdo, 'INSERT INTO schema_migrations (version, name) VALUES (?, ?)', [$version, basename($file)]);
            $pdo->exec('PRAGMA user_version = ' . (int)$version);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            $pdo->exec('PRAGMA foreign_keys = ON');
            throw new RuntimeException('Migration ' . basename($file) . ' failed: ' . $e->getMessage(), 0, $e);
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
        $applied[] = basename($file);
    }
    return $applied;
}
