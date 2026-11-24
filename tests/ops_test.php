<?php
declare(strict_types=1);

test('backup (VACUUM INTO) produces a verified copy; prune keeps the newest N', function () {
    $pdo = fresh_db();
    make_user($pdo, 'admin', 'backup@example.com');
    $file = create_backup($pdo);
    verify_database_file($file);
    $copy = new PDO('sqlite:' . $file);
    assert_same('backup@example.com', $copy->query('SELECT email FROM users')->fetchColumn());
    $copy = null;
    assert_throws(RuntimeException::class, fn() => create_backup($pdo, $file), 'already exists');
    touch($file, time() - 100);
    create_backup($pdo);
    create_backup($pdo);
    assert_same(3, count(list_backups()));
    assert_same([basename($file)], prune_backups(2), 'oldest pruned');
    assert_same(2, count(list_backups()));
    foreach (list_backups() as $b) {
        unlink($b['path']);
    }
});

test('restore replaces the database and keeps a safety copy', function () {
    $pdo = fresh_db();
    make_user($pdo, 'admin', 'before@example.com');
    $backup = create_backup($pdo);
    make_user($pdo, 'worker', 'after@example.com');
    $path = db_path();
    db_set(null);
    $pdo = null;
    $safety = restore_backup($backup, $path);
    assert_true(is_file($safety));
    $restored = db_connect($path);
    assert_same(['before@example.com'], $restored->query('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN));
    $restored = null;
    file_put_contents($backup . '.txt', 'not a database');
    assert_throws(RuntimeException::class, fn() => restore_backup($backup . '.txt', $path), 'Not an SQLite');
    foreach (list_backups() as $b) {
        unlink($b['path']);
    }
});

test('system_health reports a healthy fresh database', function () {
    $pdo = fresh_db();
    make_user($pdo, 'admin');
    $h = system_health($pdo, true);
    $checks = array_column($h['checks'], 2, 0);
    assert_true($checks['Integrity check']);
    assert_true($checks['Journal mode']);
    assert_true($checks['Foreign keys']);
    assert_true($checks['Schema version']);
    assert_same(1, $h['tables']['users']);
});

const SAMPLE_DUMP = <<<'SQL'
-- MySQL dump 10.13
/*!40101 SET NAMES utf8mb4 */;
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (`id` int unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB;
LOCK TABLES `users` WRITE;
INSERT INTO `users` VALUES (1,'Ann O\'Brien','ann@example.com','$2y$10$abcdefghijklmnopqrstuuJ7nWQ3hzv7DYlWzFJ1Z8Y9F7z0mQe2K','admin','2026-03-01 09:00:00'),(2,'Bob','bob@example.com','$2y$10$abcdefghijklmnopqrstuuJ7nWQ3hzv7DYlWzFJ1Z8Y9F7z0mQe2K','worker','2026-03-02 10:30:00');
UNLOCK TABLES;
INSERT INTO `projects` (`id`, `name`, `description`, `location`, `start_date`, `end_date`, `budget`, `status`, `manager_id`, `created_by`, `created_at`, `updated_at`) VALUES
(5, 'Bridge; phase 1', 'Line one\nLine two with \\ backslash and \"quotes\"', NULL, '2026-01-01', '0000-00-00', 1500000.50, 'active', 1, 1, '2026-03-01 12:00:00', '2026-03-01 12:00:00');
INSERT INTO `tasks` VALUES (9,5,'Piling','It''s doubled',NULL,'2026-02-01',2,'in_progress','high',40,1,'2026-03-01 12:00:00','2026-03-01 12:00:00');
INSERT INTO `pma__bookmark` VALUES (1,'ignored');
SQL;

test('MariaDB dump import keeps IDs, decodes escapes, converts zero dates and timezones', function () {
    $pdo = fresh_db();
    $counts = import_mysql_dump($pdo, SAMPLE_DUMP, 'Africa/Nairobi');
    assert_same(2, $counts['users']);
    assert_same(1, $counts['projects']);
    assert_same(1, $counts['tasks']);
    assert_same("Ann O'Brien", db_value($pdo, 'SELECT full_name FROM users WHERE id = 1'));
    assert_same('2026-03-01 06:00:00', db_value($pdo, 'SELECT created_at FROM users WHERE id = 1'), 'EAT (UTC+3) -> UTC');
    $p = db_one($pdo, 'SELECT * FROM projects WHERE id = 5');
    assert_same('Bridge; phase 1', $p['name']);
    assert_same("Line one\nLine two with \\ backslash and \"quotes\"", $p['description']);
    assert_same(null, $p['end_date'], 'zero date -> NULL');
    assert_same(1500000.5, (float)$p['budget']);
    assert_same("It's doubled", db_value($pdo, 'SELECT description FROM tasks WHERE id = 9'));
    assert_same(2, (int)db_value($pdo, 'SELECT assigned_to FROM tasks WHERE id = 9'));
    db_exec($pdo, "INSERT INTO projects (name, created_by) VALUES ('next', 1)");
    assert_same(6, (int)$pdo->lastInsertId(), 'AUTOINCREMENT continues after imported IDs');
});

test('MariaDB import rolls back when rows reference missing records', function () {
    $pdo = fresh_db();
    make_user($pdo, 'admin', 'keep@example.com');
    $broken = "INSERT INTO `users` VALUES (1,'A','a@example.com','h','admin','2026-01-01 00:00:00');\nINSERT INTO `tasks` VALUES (1,77,'orphan',NULL,NULL,NULL,NULL,'pending','low',0,1,'2026-01-01 00:00:00','2026-01-01 00:00:00');";
    assert_throws(UserError::class, fn() => import_mysql_dump($pdo, $broken), 'missing records');
    assert_same('keep@example.com', db_value($pdo, 'SELECT email FROM users'), 'original data untouched');
    assert_throws(UserError::class, fn() => import_mysql_dump($pdo, 'CREATE TABLE x (id int);'), 'No INSERT');
});

test('CSV cells are protected against spreadsheet formula injection', function () {
    assert_same("'=SUM(A1)", csv_safe('=SUM(A1)'));
    assert_same("'+1", csv_safe('+1'));
    assert_same("'@cmd", csv_safe('@cmd'));
    assert_same('Plain text', csv_safe('Plain text'));
    assert_same('', csv_safe(null));
});

test('attachments: valid image stored outside web root; bad types and spoofed content rejected', function () {
    $pdo = fresh_db();
    $u = make_user($pdo, 'admin');
    db_exec($pdo, "INSERT INTO projects (name, created_by) VALUES ('P', ?)", [$u['id']]);
    $tmp = getenv('BUILDTRACK_DATA_DIR') . '/upload.tmp';
    $copy = fn(string $from, string $to) => copy($from, $to);

    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $a = attachment_store($pdo, $tmp, 'site photo.png', 1, null, (int)$u['id'], $copy);
    assert_same('image/png', $a['mime_type']);
    assert_true(is_file(attachment_path($a)));
    assert_false(str_contains(attachment_path($a), '/public/'), 'stored outside public/');
    assert_same('site photo.png', $a['original_name']);

    file_put_contents($tmp, '<?php echo "pwned";');
    assert_throws(UserError::class, fn() => attachment_store($pdo, $tmp, 'shell.php', 1, null, (int)$u['id'], $copy), 'not allowed');
    assert_throws(UserError::class, fn() => attachment_store($pdo, $tmp, 'fake.png', 1, null, (int)$u['id'], $copy), 'does not match');

    $files = attachments_files_for($pdo, 'project', 1);
    db_exec($pdo, 'DELETE FROM projects WHERE id = 1');
    attachments_unlink($files);
    assert_false(is_file(attachment_path($a)), 'file removed with its project');
    assert_same(0, (int)db_value($pdo, 'SELECT COUNT(*) FROM attachments'));
});

test('construction queries: low stock and budget vs actual', function () {
    $pdo = fresh_db();
    $u = make_user($pdo, 'admin');
    db_exec($pdo, "INSERT INTO projects (name, budget, status, created_by) VALUES ('P', 1000, 'active', ?), ('Q', NULL, 'active', ?)", [$u['id'], $u['id']]);
    db_exec($pdo, "INSERT INTO tasks (project_id, title, created_by, due_date) VALUES (1, 'late', ?, date(app_today(), '-2 days'))", [$u['id']]);
    db_exec($pdo, "INSERT INTO expenses (project_id, description, amount, spent_on, recorded_by) VALUES (1, 'a', 700, app_today(), ?), (1, 'b', 450, app_today(), ?)", [$u['id'], $u['id']]);
    db_exec($pdo, "INSERT INTO resources (name, quantity_available, low_stock_threshold, created_by) VALUES ('cement', 100, 30, ?), ('sand', 100, 10, ?), ('untracked', 0, NULL, ?)", [$u['id'], $u['id'], $u['id']]);
    db_exec($pdo, 'INSERT INTO task_resources (task_id, resource_id, quantity_assigned, assigned_by) VALUES (1, 1, 75, ?)', [$u['id']]);
    assert_same(['cement'], array_column(low_stock_resources($pdo), 'name'));
    $fin = array_column(project_financials($pdo), null, 'name');
    assert_same(1150.0, (float)$fin['P']['spent']);
    assert_true($fin['P']['over_budget']);
    assert_same(1, (int)$fin['P']['task_overdue']);
    assert_same(null, $fin['Q']['budget_used_pct']);
    assert_same('bar-danger', budget_bar_class(115.0));
    assert_same('bar-warning', budget_bar_class(90.0));
});

test('mail message is RFC-safe: encoded subject, dot-stuffing, CRLF', function () {
    fresh_db(['MAIL_FROM' => 'noreply@site.test', 'MAIL_FROM_NAME' => 'BuildTrack']);
    $msg = build_mail_message('to@example.com', 'Résumé', "Hello\n.dangerous line\nBye");
    assert_contains('Subject: =?UTF-8?B?', $msg);
    assert_contains("\r\n..dangerous line", $msg);
    assert_false((bool)preg_match("/[^\r]\n/", $msg), 'all line endings are CRLF');
    assert_throws(InvalidArgumentException::class, fn() => smtp_send("victim@example.com>\r\nRCPT TO:<x@y.z", 's', 'b'));
});

test('SMTP delivery works against a local test server', function () {
    $port = 0;
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $log = getenv('BUILDTRACK_DATA_DIR') . '/smtp-received.txt';
    $server = <<<'PHP'
<?php
[$port, $log] = [(int)$argv[1], $argv[2]];
$srv = stream_socket_server("tcp://127.0.0.1:$port");
$c = stream_socket_accept($srv, 10);
$say = fn($s) => fwrite($c, $s . "\r\n");
$say('220 test ESMTP');
$data = false; $body = '';
while (($line = fgets($c)) !== false) {
    if ($data) { if (rtrim($line, "\r\n") === '.') { $data = false; $say('250 queued'); continue; } $body .= $line; continue; }
    $cmd = strtoupper(substr(trim($line), 0, 4));
    file_put_contents($log, trim($line) . "\n", FILE_APPEND);
    match (true) {
        $cmd === 'EHLO' => $say("250-test\r\n250 AUTH LOGIN"),
        $cmd === 'AUTH' => $say('334 VXNlcm5hbWU6'),
        $cmd === 'MAIL', $cmd === 'RCPT' => $say('250 ok'),
        $cmd === 'DATA' => [$data = true, $say('354 go')],
        $cmd === 'QUIT' => [$say('221 bye'), file_put_contents($log, "BODY:" . $body, FILE_APPEND), exit(0)],
        str_starts_with(trim($line), base64_encode('smtp-user')) => $say('334 UGFzc3dvcmQ6'),
        default => $say('235 ok'),
    };
}
PHP;
    $script = getenv('BUILDTRACK_DATA_DIR') . '/fake_smtp.php';
    file_put_contents($script, $server);
    $proc = proc_open([PHP_BINARY, $script, (string)$port, $log], [], $pipes);
    usleep(300000);
    fresh_db(['SMTP_HOST' => '127.0.0.1', 'SMTP_PORT' => (string)$port, 'SMTP_ENCRYPTION' => 'none', 'SMTP_USER' => 'smtp-user', 'SMTP_PASS' => 'smtp-pass', 'MAIL_FROM' => 'app@site.test']);
    $delivered = send_mail('dest@example.com', 'Reset', "Link: http://x/reset\n");
    proc_close($proc);
    $received = (string)@file_get_contents($log);
    assert_true($delivered, 'send_mail returned true; server saw: ' . $received);
    assert_contains('RCPT TO:<dest@example.com>', $received);
    assert_contains('MAIL FROM:<app@site.test>', $received);
    assert_contains('Link: http://x/reset', $received);
});
