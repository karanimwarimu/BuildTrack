<?php
declare(strict_types=1);

/**
 * SQLite backup / restore / health checks.
 * Backups use `VACUUM INTO`, which produces a consistent, compacted snapshot even while
 * the app is running in WAL mode (copying the .sqlite file alone could miss WAL pages).
 * Skill: skills/local-ops-and-backup/SKILL.md
 */

function backups_dir(): string
{
    $dir = data_path('backups');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function create_backup(PDO $pdo, ?string $target = null): string
{
    $target ??= backups_dir() . '/buildtrack-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.sqlite';
    if (file_exists($target)) {
        throw new RuntimeException("Backup target already exists: $target");
    }
    $dir = dirname($target);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $st = $pdo->prepare('VACUUM INTO ?');
    $st->execute([$target]);
    return $target;
}

/** @return array<int,array{path:string,name:string,size:int,mtime:int}> newest first */
function list_backups(): array
{
    $items = [];
    foreach (glob(backups_dir() . '/*.sqlite') ?: [] as $path) {
        $items[] = ['path' => $path, 'name' => basename($path), 'size' => (int)filesize($path), 'mtime' => (int)filemtime($path)];
    }
    usort($items, fn($a, $b) => [$b['mtime'], $b['name']] <=> [$a['mtime'], $a['name']]);
    return $items;
}

/** Keep the newest $keep backups, delete the rest. Returns deleted file names. */
function prune_backups(int $keep): array
{
    $deleted = [];
    foreach (array_slice(list_backups(), max(1, $keep)) as $b) {
        if (@unlink($b['path'])) {
            $deleted[] = $b['name'];
        }
    }
    return $deleted;
}

/** Throws if $path is not a healthy BuildTrack SQLite database. */
function verify_database_file(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException("File not found: $path");
    }
    $header = (string)file_get_contents($path, false, null, 0, 16);
    if ($header !== "SQLite format 3\0") {
        throw new RuntimeException('Not an SQLite database file.');
    }
    $check = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $result = (string)$check->query('PRAGMA integrity_check')->fetchColumn();
    if ($result !== 'ok') {
        throw new RuntimeException("Integrity check failed: $result");
    }
    if (!$check->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn()) {
        throw new RuntimeException('This database has no users table; it is not a BuildTrack database.');
    }
    $check = null;
}

/**
 * Replace the live database with a backup file. Takes a safety backup of the current DB first.
 * Stop the web server before restoring (./stop or Ctrl+C) so no request holds the old file open.
 * @return string path of the safety backup of the replaced database (or '' if there was none)
 */
function restore_backup(string $backupFile, string $dbPath): string
{
    verify_database_file($backupFile);
    $safety = '';
    if (is_file($dbPath)) {
        $current = db_connect($dbPath);
        $safety = create_backup($current, backups_dir() . '/pre-restore-' . date('Ymd-His') . '.sqlite');
        $current->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $current = null;
    }
    $tmp = $dbPath . '.restore-tmp';
    if (!copy($backupFile, $tmp)) {
        throw new RuntimeException('Could not copy the backup into place.');
    }
    foreach (['-wal', '-shm'] as $suffix) {
        if (is_file($dbPath . $suffix)) {
            @unlink($dbPath . $suffix);
        }
    }
    if (!rename($tmp, $dbPath)) {
        throw new RuntimeException('Could not replace the database file.');
    }
    return $safety;
}

/** Facts for the admin "System health" panel and `bin/console doctor`. */
function system_health(PDO $pdo, bool $fullIntegrityCheck = false): array
{
    $path = db_path();
    $dbSize = is_file($path) ? (int)filesize($path) : 0;
    $walSize = is_file($path . '-wal') ? (int)filesize($path . '-wal') : 0;
    $latest = migration_files() ? max(array_keys(migration_files())) : 0;
    $version = schema_version($pdo);
    $integrity = (string)$pdo->query($fullIntegrityCheck ? 'PRAGMA integrity_check' : 'PRAGMA quick_check')->fetchColumn();
    $tables = [];
    foreach (['users', 'projects', 'tasks', 'progress_updates', 'resources', 'task_resources', 'expenses', 'site_logs', 'attachments', 'audit_log'] as $t) {
        $tables[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    }
    $backups = list_backups();
    $free = @disk_free_space(data_path());
    return [
        'checks' => [
            ['Database file', $path, is_writable($path)],
            ['Schema version', "$version of $latest" . ($version < $latest ? ' (run: php bin/console migrate)' : ''), $version >= $latest],
            ['Integrity check', $integrity, $integrity === 'ok'],
            ['Journal mode', (string)$pdo->query('PRAGMA journal_mode')->fetchColumn(), strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn()) === 'wal'],
            ['Foreign keys', (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn() ? 'ON' : 'OFF', (bool)$pdo->query('PRAGMA foreign_keys')->fetchColumn()],
            ['Data directory writable', data_path(), is_writable(data_path())],
            ['Latest backup', $backups ? $backups[0]['name'] . ' (' . date('d M Y H:i', $backups[0]['mtime']) . ')' : 'No backups yet', (bool)$backups],
            ['Outgoing email', smtp_configured() ? 'SMTP ' . config('SMTP_HOST') : 'Not configured (links logged to data/logs/mail.log)', smtp_configured() || is_local_env()],
        ],
        'facts' => [
            'Environment' => (string)config('APP_ENV', 'local'),
            'PHP version' => PHP_VERSION,
            'SQLite version' => (string)$pdo->query('SELECT sqlite_version()')->fetchColumn(),
            'Database size' => human_bytes($dbSize) . ($walSize ? ' + ' . human_bytes($walSize) . ' WAL' : ''),
            'Free disk space' => $free !== false ? human_bytes($free) : 'unknown',
            'Timezone' => date_default_timezone_get(),
            'Registration' => config_bool('ALLOW_REGISTRATION') ? 'Open' : 'Closed (admins create accounts)',
            'Active admins' => (string)active_admin_count($pdo),
        ],
        'tables' => $tables,
        'warnings' => config_warnings(),
    ];
}
