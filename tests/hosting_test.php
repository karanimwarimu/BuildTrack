<?php
declare(strict_types=1);

/** Behaviour when hosted behind a proxy (Render) and in public DEMO_MODE. */

test('client_ip ignores proxy headers unless TRUST_PROXY=true, and never trusts X-Forwarded-For', function () {
    $saved = $_SERVER;
    try {
        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 10.1.2.3';
        fresh_db(['TRUST_PROXY' => 'false']);
        assert_same('10.1.2.3', client_ip(), 'proxy headers ignored by default');
        fresh_db(['TRUST_PROXY' => 'true']);
        assert_same('203.0.113.9', client_ip(), 'CF-Connecting-IP used behind a trusted proxy');
        $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
        assert_same('10.1.2.3', client_ip(), 'invalid header falls back to the socket address');
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        assert_same('10.1.2.3', client_ip(), 'X-Forwarded-For is never trusted');
    } finally {
        $_SERVER = $saved;
    }
});

test('is_https honours X-Forwarded-Proto only behind a trusted proxy', function () {
    $saved = $_SERVER;
    try {
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        fresh_db(['TRUST_PROXY' => 'false']);
        assert_false(is_https());
        fresh_db(['TRUST_PROXY' => 'true']);
        assert_true(is_https());
    } finally {
        $_SERVER = $saved;
    }
});

test('dev elevation is always off behind a proxy, even from 127.0.0.1', function () {
    fresh_db(['ALLOW_DEV_ADMIN_ELEVATION' => 'true', 'APP_ENV' => 'local', 'TRUST_PROXY' => 'true']);
    assert_false(dev_elevation_allowed('127.0.0.1'));
});

test('reset links use RENDER_EXTERNAL_URL when APP_URL is not set', function () {
    fresh_db(['APP_ENV' => 'production']);
    putenv('RENDER_EXTERNAL_URL=https://buildtrack-demo.onrender.com');
    try {
        assert_same('https://buildtrack-demo.onrender.com/index.php?page=reset&token=abc', reset_link('abc'));
    } finally {
        putenv('RENDER_EXTERNAL_URL');
    }
});

test('DEMO_MODE locks the shared demo accounts but not other accounts', function () {
    $pdo = fresh_db(['DEMO_MODE' => 'true']);
    seed_demo($pdo);
    $demoAdmin = find_user_by_email($pdo, 'admin@buildtrack.local');
    $demoWorker = find_user_by_email($pdo, 'worker@buildtrack.local');
    assert_throws(UserError::class, fn() => set_user_password($pdo, (int)$demoAdmin['id'], 'hijacked-password'), 'shared demo account');
    assert_throws(UserError::class, fn() => change_user_role($pdo, (int)$demoWorker['id'], 'admin'), 'shared demo account');
    assert_throws(UserError::class, fn() => set_user_active($pdo, (int)$demoAdmin['id'], false), 'shared demo account');
    assert_same(['link' => null, 'delivered' => false], request_password_reset($pdo, 'admin@buildtrack.local', '1.2.3.4'));
    assert_same('admin@buildtrack.local', attempt_login($pdo, 'admin@buildtrack.local', DEMO_PASSWORD, '1.2.3.4')['email'], 'demo password still works');

    $visitor = make_user($pdo, 'worker', 'someone@example.com');
    set_user_password($pdo, (int)$visitor['id'], 'their-own-password');
    assert_same('project_manager', change_user_role($pdo, (int)$visitor['id'], 'project_manager') === 'worker' ? 'project_manager' : 'x');

    fresh_db(['DEMO_MODE' => 'false']);
    $pdo = db();
    seed_demo($pdo);
    $admin = find_user_by_email($pdo, 'admin@buildtrack.local');
    set_user_password($pdo, (int)$admin['id'], 'changed-outside-demo'); // no lock when DEMO_MODE is off
});
