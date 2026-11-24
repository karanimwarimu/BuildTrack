<?php
declare(strict_types=1);

test('migrations create every table, set WAL + foreign keys, and are idempotent', function () {
    $pdo = fresh_db();
    $latest = max(array_keys(migration_files()));
    assert_same($latest, schema_version($pdo), 'schema version');
    foreach (['users', 'projects', 'tasks', 'progress_updates', 'resources', 'task_resources', 'password_resets', 'login_attempts', 'audit_log', 'expenses', 'site_logs', 'attachments', 'schema_migrations'] as $t) {
        assert_true(db_value($pdo, "SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$t]) !== null, "table $t exists");
    }
    assert_same('wal', strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn()));
    assert_same(1, (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn());
    assert_same(5000, (int)$pdo->query('PRAGMA busy_timeout')->fetchColumn());
    assert_same([], migrate($pdo), 'second migrate is a no-op');
    assert_same(count(migration_files()), (int)db_value($pdo, 'SELECT COUNT(*) FROM schema_migrations'));
});

test('migration files are named NNN_snake_case.sql and contain no transaction statements', function () {
    foreach (glob(migrations_dir() . '/*') as $f) {
        assert_true((bool)preg_match('/^\d{3}_[a-z0-9_]+\.sql$/', basename($f)), basename($f) . ' naming');
        assert_false((bool)preg_match('/^\s*(BEGIN(\s+(TRANSACTION|IMMEDIATE|EXCLUSIVE|DEFERRED))?\s*;|COMMIT\b|END\s+TRANSACTION)/mi', (string)file_get_contents($f)), basename($f) . ' must not BEGIN/COMMIT (the migrator wraps each file)');
    }
});

test('a failing migration rolls back completely', function () {
    $pdo = fresh_db();
    $bad = migrations_dir() . '/999_test_broken.sql';
    file_put_contents($bad, "CREATE TABLE should_not_exist (id INTEGER);\nTHIS IS NOT SQL;");
    try {
        assert_throws(RuntimeException::class, fn() => migrate($pdo), '999_test_broken.sql');
    } finally {
        unlink($bad);
    }
    assert_same(null, db_value($pdo, "SELECT name FROM sqlite_master WHERE name = 'should_not_exist'"));
    assert_same(max(array_keys(migration_files())), schema_version($pdo));
    assert_same(1, (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'foreign keys re-enabled');
});

test('CHECK constraints replace MySQL ENUMs', function () {
    $pdo = fresh_db();
    assert_throws(PDOException::class, fn() => db_exec($pdo, "INSERT INTO users (full_name, email, password_hash, role) VALUES ('X', 'x@x.x', 'h', 'superuser')"));
    $u = make_user($pdo, 'admin');
    assert_throws(PDOException::class, fn() => db_exec($pdo, "INSERT INTO projects (name, status, created_by) VALUES ('P', 'cancelled', ?)", [$u['id']]));
    assert_throws(PDOException::class, fn() => db_exec($pdo, "INSERT INTO projects (name, start_date, end_date, created_by) VALUES ('P', '2026-05-01', '2026-01-01', ?)", [$u['id']]));
    assert_throws(PDOException::class, fn() => db_exec($pdo, "INSERT INTO projects (name, budget, created_by) VALUES ('P', -5, ?)", [$u['id']]));
});

test('foreign keys cascade and restrict like the MariaDB schema', function () {
    $pdo = fresh_db();
    $u = make_user($pdo, 'admin');
    db_exec($pdo, "INSERT INTO projects (name, created_by) VALUES ('P', ?)", [$u['id']]);
    $p = (int)$pdo->lastInsertId();
    db_exec($pdo, "INSERT INTO tasks (project_id, title, created_by) VALUES (?, 'T', ?)", [$p, $u['id']]);
    db_exec($pdo, "INSERT INTO expenses (project_id, description, amount, spent_on, recorded_by) VALUES (?, 'E', 10, '2026-01-01', ?)", [$p, $u['id']]);
    assert_throws(PDOException::class, fn() => db_exec($pdo, 'DELETE FROM users WHERE id = ?', [$u['id']]), 'FOREIGN KEY');
    assert_throws(PDOException::class, fn() => db_exec($pdo, "INSERT INTO tasks (project_id, title, created_by) VALUES (999, 'orphan', ?)", [$u['id']]));
    db_exec($pdo, 'DELETE FROM projects WHERE id = ?', [$p]);
    assert_same(0, (int)db_value($pdo, 'SELECT COUNT(*) FROM tasks'));
    assert_same(0, (int)db_value($pdo, 'SELECT COUNT(*) FROM expenses'));
});

test('updated_at triggers emulate ON UPDATE CURRENT_TIMESTAMP', function () {
    $pdo = fresh_db();
    $u = make_user($pdo, 'admin');
    db_exec($pdo, "INSERT INTO projects (name, created_by, updated_at) VALUES ('P', ?, '2000-01-01 00:00:00')", [$u['id']]);
    $id = (int)$pdo->lastInsertId();
    db_exec($pdo, "UPDATE projects SET name = 'P2' WHERE id = ?", [$id]);
    $updated = (string)db_value($pdo, 'SELECT updated_at FROM projects WHERE id = ?', [$id]);
    assert_true($updated > '2020-01-01', "updated_at refreshed (got $updated)");
});

test('app_today() SQL function uses the application timezone', function () {
    $pdo = fresh_db();
    assert_same(date('Y-m-d'), $pdo->query('SELECT app_today()')->fetchColumn());
    assert_same(date('Y-m-d', strtotime('+7 days')), $pdo->query("SELECT date(app_today(), '+7 days')")->fetchColumn());
});

test('demo seed loads realistic data once', function () {
    $pdo = fresh_db();
    $accounts = seed_demo($pdo);
    assert_same(5, count($accounts));
    assert_same(3, (int)db_value($pdo, 'SELECT COUNT(*) FROM projects'));
    assert_true((int)db_value($pdo, "SELECT COUNT(*) FROM tasks WHERE due_date < app_today() AND status <> 'completed'") > 0, 'has overdue tasks');
    assert_same([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    assert_throws(UserError::class, fn() => seed_demo($pdo), '--fresh');
    $u = attempt_login($pdo, 'worker@buildtrack.local', DEMO_PASSWORD, '127.0.0.1');
    assert_same('worker', $u['role']);
});
