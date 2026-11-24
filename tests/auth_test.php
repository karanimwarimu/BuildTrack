<?php
declare(strict_types=1);

test('first registered user becomes admin, later users are workers', function () {
    $pdo = fresh_db(['FIRST_USER_IS_ADMIN' => 'true']);
    assert_same('admin', create_user($pdo, 'First', 'first@example.com', 'long-enough-pw')['role']);
    assert_same('worker', create_user($pdo, 'Second', 'second@example.com', 'long-enough-pw')['role']);
});

test('FIRST_USER_IS_ADMIN=false makes the first user a worker', function () {
    $pdo = fresh_db(['FIRST_USER_IS_ADMIN' => 'false']);
    assert_same('worker', create_user($pdo, 'First', 'first@example.com', 'long-enough-pw')['role']);
});

test('registration validates input and rejects duplicate emails case-insensitively', function () {
    $pdo = fresh_db();
    create_user($pdo, 'A', 'Person@Example.com', 'long-enough-pw');
    assert_throws(UserError::class, fn() => create_user($pdo, 'B', 'person@EXAMPLE.com', 'long-enough-pw'), 'already exists');
    assert_throws(UserError::class, fn() => create_user($pdo, 'B', 'not-an-email', 'long-enough-pw'), 'valid email');
    assert_throws(UserError::class, fn() => create_user($pdo, '', 'b@example.com', 'long-enough-pw'), 'full name');
    assert_throws(UserError::class, fn() => create_user($pdo, 'B', 'b@example.com', 'short'), 'at least');
    assert_same('The password confirmation does not match.', password_policy_error('long-enough-pw', 'different-pw!'));
});

test('registration_open: always for the first account, then follows ALLOW_REGISTRATION', function () {
    $pdo = fresh_db(['ALLOW_REGISTRATION' => 'false']);
    assert_true(registration_open($pdo));
    make_user($pdo, 'admin');
    assert_false(registration_open($pdo));
});

test('passwords are hashed with Argon2id (or bcrypt fallback), never stored in plain text', function () {
    $pdo = fresh_db();
    $u = make_user($pdo, 'worker', 'hash@example.com', 'my-secret-password');
    assert_false(str_contains($u['password_hash'], 'my-secret-password'));
    assert_true(password_verify('my-secret-password', $u['password_hash']));
    if (defined('PASSWORD_ARGON2ID')) {
        assert_true(str_starts_with($u['password_hash'], '$argon2id$'));
    }
});

test('login succeeds with the right password and fails generically otherwise', function () {
    $pdo = fresh_db();
    make_user($pdo, 'worker', 'login@example.com', 'right-password-1');
    assert_same('login@example.com', attempt_login($pdo, ' LOGIN@example.com ', 'right-password-1', '10.0.0.1')['email']);
    assert_throws(UserError::class, fn() => attempt_login($pdo, 'login@example.com', 'wrong', '10.0.0.1'), 'incorrect');
    assert_throws(UserError::class, fn() => attempt_login($pdo, 'nobody@example.com', 'wrong', '10.0.0.1'), 'incorrect');
    assert_true(db_value($pdo, 'SELECT last_login_at FROM users WHERE email = ?', ['login@example.com']) !== null);
});

test('login is throttled after LOGIN_MAX_ATTEMPTS failures, even with the right password', function () {
    $pdo = fresh_db(['LOGIN_MAX_ATTEMPTS' => '3', 'LOGIN_LOCKOUT_MINUTES' => '15']);
    make_user($pdo, 'worker', 'lock@example.com', 'right-password-1');
    for ($i = 0; $i < 3; $i++) {
        assert_throws(UserError::class, fn() => attempt_login($pdo, 'lock@example.com', 'nope', '10.0.0.2'));
    }
    assert_throws(UserError::class, fn() => attempt_login($pdo, 'lock@example.com', 'right-password-1', '10.0.0.2'), 'Too many');
    db_exec($pdo, "UPDATE login_attempts SET created_at = datetime('now', '-16 minutes')");
    assert_same('lock@example.com', attempt_login($pdo, 'lock@example.com', 'right-password-1', '10.0.0.2')['email'], 'unlocked after window');
});

test('deactivated accounts cannot sign in', function () {
    $pdo = fresh_db();
    make_user($pdo, 'admin');
    $u = make_user($pdo, 'worker', 'off@example.com', 'right-password-1');
    set_user_active($pdo, (int)$u['id'], false);
    assert_throws(UserError::class, fn() => attempt_login($pdo, 'off@example.com', 'right-password-1', '10.0.0.3'), 'deactivated');
});

test('legacy bcrypt hashes from MariaDB still work and are upgraded on login', function () {
    $pdo = fresh_db();
    db_exec($pdo, "INSERT INTO users (full_name, email, password_hash, role) VALUES ('Old', 'old@example.com', ?, 'worker')", [password_hash('legacy-password', PASSWORD_BCRYPT)]);
    attempt_login($pdo, 'old@example.com', 'legacy-password', '10.0.0.4');
    $hash = (string)db_value($pdo, "SELECT password_hash FROM users WHERE email = 'old@example.com'");
    assert_false(password_needs_rehash($hash, password_algo()), 'rehashed to current algorithm');
});

test('session is invalidated when the password changes or the account is deactivated', function () {
    $pdo = fresh_db();
    make_user($pdo, 'admin');
    $u = make_user($pdo, 'worker');
    act_as($u);
    assert_same($u['id'], user_id());
    set_user_password($pdo, (int)$u['id'], 'a-new-password-1');
    forget_current_user();
    assert_same(null, user(), 'old session rejected after password change');
    act_as($u);
    set_user_active($pdo, (int)$u['id'], false);
    forget_current_user();
    assert_same(null, user(), 'deactivated user signed out');
});

test('password reset tokens: hashed at rest, single use, expire', function () {
    $pdo = fresh_db(['RESET_TOKEN_TTL_MINUTES' => '60']);
    $u = make_user($pdo, 'worker', 'reset@example.com');
    $token = create_password_reset($pdo, (int)$u['id']);
    assert_same(64, strlen($token));
    assert_same(0, (int)db_value($pdo, 'SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [$token]), 'raw token not stored');
    assert_true(find_valid_reset($pdo, $token) !== null);
    assert_throws(UserError::class, fn() => complete_password_reset($pdo, $token, 'short'), 'at least');
    complete_password_reset($pdo, $token, 'fresh-password-9', 'fresh-password-9');
    assert_same('reset@example.com', attempt_login($pdo, 'reset@example.com', 'fresh-password-9', '10.0.0.5')['email']);
    assert_throws(UserError::class, fn() => complete_password_reset($pdo, $token, 'another-password-9'), 'already been used');

    $expired = create_password_reset($pdo, (int)$u['id']);
    db_exec($pdo, "UPDATE password_resets SET expires_at = datetime('now', '-1 minute') WHERE token_hash = ?", [reset_token_hash($expired)]);
    assert_same(null, find_valid_reset($pdo, $expired));
    assert_same(null, find_valid_reset($pdo, 'not-a-token'));
});

test('a new reset link invalidates older unused links', function () {
    $pdo = fresh_db();
    $u = make_user($pdo);
    $first = create_password_reset($pdo, (int)$u['id']);
    $second = create_password_reset($pdo, (int)$u['id']);
    assert_same(null, find_valid_reset($pdo, $first));
    assert_true(find_valid_reset($pdo, $second) !== null);
});

test('forgot-password: unknown emails reveal nothing, requests are throttled, link goes to mail.log', function () {
    $pdo = fresh_db(['SMTP_HOST' => '']);
    make_user($pdo, 'worker', 'forgot@example.com');
    assert_same(['link' => null, 'delivered' => false], request_password_reset($pdo, 'ghost@example.com', '10.0.0.6'));
    $r = request_password_reset($pdo, 'forgot@example.com', '10.0.0.6');
    assert_contains('page=reset&token=', (string)$r['link']);
    assert_false($r['delivered']);
    assert_contains((string)$r['link'], (string)file_get_contents(data_path('logs/mail.log')));
    request_password_reset($pdo, 'forgot@example.com', '10.0.0.6');
    request_password_reset($pdo, 'forgot@example.com', '10.0.0.6');
    assert_same(null, request_password_reset($pdo, 'forgot@example.com', '10.0.0.6')['link'], '4th request in 15 min throttled');
});

test('the last active admin cannot be demoted or deactivated', function () {
    $pdo = fresh_db();
    $a = make_user($pdo, 'admin');
    assert_throws(UserError::class, fn() => change_user_role($pdo, (int)$a['id'], 'worker'), 'last active administrator');
    assert_throws(UserError::class, fn() => set_user_active($pdo, (int)$a['id'], false), 'last active administrator');
    $b = make_user($pdo, 'admin');
    assert_same('admin', change_user_role($pdo, (int)$a['id'], 'project_manager'));
    assert_throws(UserError::class, fn() => change_user_role($pdo, (int)$b['id'], 'worker'));
    assert_throws(UserError::class, fn() => change_user_role($pdo, (int)$b['id'], 'emperor'), 'Unknown role');
});

test('dev admin elevation requires the flag, APP_ENV=local and a loopback address', function () {
    fresh_db(['ALLOW_DEV_ADMIN_ELEVATION' => 'true', 'APP_ENV' => 'local']);
    assert_true(dev_elevation_allowed('127.0.0.1'));
    assert_true(dev_elevation_allowed('::1'));
    assert_false(dev_elevation_allowed('192.168.1.20'), 'LAN address');
    fresh_db(['ALLOW_DEV_ADMIN_ELEVATION' => 'true', 'APP_ENV' => 'production']);
    assert_false(dev_elevation_allowed('127.0.0.1'), 'production');
    fresh_db(['ALLOW_DEV_ADMIN_ELEVATION' => 'false', 'APP_ENV' => 'local']);
    assert_false(dev_elevation_allowed('127.0.0.1'), 'flag off');
});

test('ADMIN_EMAIL/ADMIN_PASSWORD bootstrap creates or promotes an admin', function () {
    $pdo = fresh_db(['ADMIN_EMAIL' => 'Boss@Example.com', 'ADMIN_PASSWORD' => 'bootstrap-pass-1']);
    assert_contains('Created administrator', (string)ensure_env_admin($pdo));
    $boss = find_user_by_email($pdo, 'boss@example.com');
    assert_same('admin', $boss['role']);
    assert_same(1, (int)$boss['must_change_password'], 'forced password change');
    assert_same(null, ensure_env_admin($pdo), 'idempotent');

    $pdo = fresh_db(['ADMIN_EMAIL' => 'promote@example.com']);
    make_user($pdo, 'worker', 'promote@example.com');
    assert_contains('Promoted', (string)ensure_env_admin($pdo));
    assert_same('admin', find_user_by_email($pdo, 'promote@example.com')['role']);

    $pdo = fresh_db(['ADMIN_EMAIL' => 'missing@example.com']);
    assert_contains('make-admin', (string)ensure_env_admin($pdo));
});

test('permission map matches the role design', function () {
    fresh_db();
    $expect = [
        'admin' => ['admin.access' => true, 'expenses.manage' => true, 'tasks.manage' => true],
        'project_manager' => ['admin.access' => false, 'expenses.manage' => true, 'projects.delete' => true, 'users.manage' => false],
        'site_supervisor' => ['expenses.view' => true, 'expenses.manage' => false, 'tasks.manage' => true, 'tasks.delete' => false, 'sitelogs.create' => true],
        'worker' => ['expenses.view' => false, 'tasks.manage' => false, 'projects.view_all' => false, 'sitelogs.create' => false, 'admin.access' => false],
    ];
    foreach ($expect as $role => $perms) {
        foreach ($perms as $perm => $allowed) {
            assert_same($allowed, role_can($role, $perm), "$role / $perm");
        }
    }
    assert_false(role_can('admin', 'no.such.permission'), 'unknown permissions are denied');
});

test('workers only see projects and tasks assigned to them', function () {
    $pdo = fresh_db();
    $admin = make_user($pdo, 'admin');
    $w1 = make_user($pdo, 'worker');
    $w2 = make_user($pdo, 'worker');
    db_exec($pdo, "INSERT INTO projects (name, created_by) VALUES ('Mine', ?), ('Theirs', ?)", [$admin['id'], $admin['id']]);
    db_exec($pdo, "INSERT INTO tasks (project_id, title, assigned_to, created_by) VALUES (1, 'a', ?, ?), (2, 'b', ?, ?)", [$w1['id'], $admin['id'], $w2['id'], $admin['id']]);
    act_as($w1);
    assert_true(project_visible($pdo, 1));
    assert_false(project_visible($pdo, 2));
    assert_true(task_visible($pdo, 1));
    assert_false(task_visible($pdo, 2));
    assert_same(['Mine'], array_column(visible_projects($pdo), 'name'));
    act_as($admin);
    assert_same(2, count(visible_projects($pdo)));
});

test('audit() records actor, action and JSON details', function () {
    $pdo = fresh_db();
    $u = make_user($pdo, 'admin');
    act_as($u);
    audit('project.updated', 'project', 7, ['name' => ['from' => 'A', 'to' => 'B']]);
    $row = db_one($pdo, 'SELECT * FROM audit_log ORDER BY id DESC LIMIT 1');
    assert_same((int)$u['id'], (int)$row['user_id']);
    assert_same('project.updated', $row['action']);
    assert_same(7, (int)$row['entity_id']);
    assert_same('B', json_decode($row['details'], true)['name']['to']);
    assert_same(['name' => ['from' => 'A', 'to' => 'B']], audit_diff(['name' => 'A', 'x' => 1], ['name' => 'B', 'x' => 1]));
});
