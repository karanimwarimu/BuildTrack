<?php
declare(strict_types=1);

/**
 * Authentication, registration, password resets and admin-safety rules.
 * Functions take a PDO so they can be unit tested against a throwaway database.
 * Skill: skills/auth-and-admin-rbac/SKILL.md
 */

const PASSWORD_MIN_LENGTH = 10;
const PASSWORD_MAX_LENGTH = 512;
const RESET_REQUESTS_PER_WINDOW = 3;   // per account
const RESET_REQUEST_WINDOW = '-15 minutes';

function password_algo(): string|int|null
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function hash_password(string $password): string
{
    return password_hash($password, password_algo());
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/** @return string|null error message, or null when acceptable */
function password_policy_error(string $password, ?string $confirm = null): ?string
{
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Choose a password with at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (strlen($password) > PASSWORD_MAX_LENGTH) {
        return 'That password is too long.';
    }
    if ($confirm !== null && !hash_equals($password, $confirm)) {
        return 'The password confirmation does not match.';
    }
    return null;
}

function find_user_by_email(PDO $pdo, string $email): ?array
{
    return db_one($pdo, 'SELECT * FROM users WHERE email = ?', [normalize_email($email)]);
}

function find_user(PDO $pdo, int $id): ?array
{
    return db_one($pdo, 'SELECT * FROM users WHERE id = ?', [$id]);
}

/* ------------------------------------------------------------------ login */

/** Minutes the account/IP must wait, or 0 if a login attempt is allowed. */
function login_lockout_minutes(PDO $pdo, string $email, string $ip): int
{
    $max = max(1, config_int('LOGIN_MAX_ATTEMPTS', 5));
    $window = max(1, config_int('LOGIN_LOCKOUT_MINUTES', 15));
    $since = utc_now("-$window minutes");
    $byEmail = (int)db_value($pdo, 'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND success = 0 AND created_at > ?', [normalize_email($email), $since]);
    $byIp = (int)db_value($pdo, 'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > ?', [$ip, $since]);
    return ($byEmail >= $max || $byIp >= $max * 4) ? $window : 0;
}

/**
 * Verify credentials with throttling. Returns the user row on success, throws UserError otherwise.
 * Error messages never reveal whether the email exists.
 */
function attempt_login(PDO $pdo, string $email, string $password, string $ip): array
{
    $email = normalize_email($email);
    if ($wait = login_lockout_minutes($pdo, $email, $ip)) {
        throw new UserError("Too many failed sign-in attempts. Try again in $wait minutes, or reset your password.");
    }
    $user = find_user_by_email($pdo, $email);
    // Verify against a dummy hash when the user is unknown, so timing does not reveal valid emails.
    static $dummyHash = null;
    $hash = $user['password_hash'] ?? ($dummyHash ??= hash_password(random_bytes(16)));
    $valid = password_verify($password, $hash) && $user !== null;

    if (!$valid || (int)$user['is_active'] !== 1) {
        db_exec($pdo, 'INSERT INTO login_attempts (email, ip, success) VALUES (?, ?, 0)', [$email, $ip]);
        audit('auth.login_failed', 'user', $user ? (int)$user['id'] : null, ['email' => $email], $user ? (int)$user['id'] : null);
        if ($valid && (int)$user['is_active'] !== 1) {
            throw new UserError('This account has been deactivated. Contact your administrator.');
        }
        throw new UserError('The email address or password is incorrect.');
    }

    if (password_needs_rehash($user['password_hash'], password_algo())) {
        db_exec($pdo, 'UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $user['id']]);
    }
    db_exec($pdo, 'DELETE FROM login_attempts WHERE email = ? AND success = 0', [$email]);
    db_exec($pdo, 'INSERT INTO login_attempts (email, ip, success) VALUES (?, ?, 1)', [$email, $ip]);
    db_exec($pdo, 'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?', [$user['id']]);
    audit('auth.login', 'user', (int)$user['id'], [], (int)$user['id']);
    return $user;
}

function start_user_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['sv'] = (int)$user['session_version'];
    unset($_SESSION['_csrf']);
    forget_current_user();
}

function end_user_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
    forget_current_user();
}

/* ------------------------------------------------------------------ registration */

function user_count(PDO $pdo): int
{
    return (int)db_value($pdo, 'SELECT COUNT(*) FROM users');
}

/** The very first account can always be registered; afterwards ALLOW_REGISTRATION decides. */
function registration_open(PDO $pdo): bool
{
    return config_bool('ALLOW_REGISTRATION') || user_count($pdo) === 0;
}

function validate_user_fields(string $name, string $email): void
{
    if ($name === '' || strlen($name) > 120) {
        throw new UserError('Enter your full name (up to 120 characters).');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new UserError('Enter a valid email address.');
    }
}

/**
 * Create a user account. Role rules:
 *  - $role given (admin/CLI creation): used as-is.
 *  - self-registration: first user becomes admin when FIRST_USER_IS_ADMIN=true, everyone else is a worker.
 * The count + insert run in one write transaction, so two simultaneous "first" sign-ups cannot both become admin.
 * @return array the created user row
 */
function create_user(PDO $pdo, string $name, string $email, string $password, ?string $role = null, bool $mustChange = false): array
{
    $name = trim($name);
    $email = normalize_email($email);
    validate_user_fields($name, $email);
    if ($err = password_policy_error($password)) {
        throw new UserError($err);
    }
    if ($role !== null && !in_array($role, ROLES, true)) {
        throw new UserError('Unknown role.');
    }
    $hash = hash_password($password);
    return db_transaction($pdo, function (PDO $pdo) use ($name, $email, $hash, $role, $mustChange) {
        if (find_user_by_email($pdo, $email)) {
            throw new UserError('An account with that email address already exists.');
        }
        if ($role === null) {
            $role = (user_count($pdo) === 0 && config_bool('FIRST_USER_IS_ADMIN')) ? 'admin' : 'worker';
        }
        db_exec($pdo, 'INSERT INTO users (full_name, email, password_hash, role, must_change_password) VALUES (?, ?, ?, ?, ?)',
            [$name, $email, $hash, $role, $mustChange ? 1 : 0]);
        return find_user($pdo, (int)$pdo->lastInsertId());
    });
}

/** Set a new password, sign out other sessions (session_version++), optionally force a change at next login. */
function set_user_password(PDO $pdo, int $userId, string $password, bool $mustChange = false): void
{
    guard_demo_account($pdo, $userId);
    if ($err = password_policy_error($password)) {
        throw new UserError($err);
    }
    db_exec($pdo, 'UPDATE users SET password_hash = ?, must_change_password = ?, session_version = session_version + 1 WHERE id = ?',
        [hash_password($password), $mustChange ? 1 : 0, $userId]);
    db_exec($pdo, 'UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL', [$userId]);
}

function generate_password(int $length = 16): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

/* ------------------------------------------------------------------ password reset */

function reset_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Base URL used in reset links. APP_URL wins; in local mode we fall back to the request host. */
function app_base_url(): string
{
    if ($u = config('APP_URL') ?? (getenv('RENDER_EXTERNAL_URL') ?: null)) { // Render sets RENDER_EXTERNAL_URL
        return rtrim($u, '/');
    }
    if (PHP_SAPI !== 'cli' && is_local_env() && !empty($_SERVER['HTTP_HOST'])) {
        return (is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }
    $host = config('APP_HOST', '127.0.0.1');
    return 'http://' . (in_array($host, ['0.0.0.0', '127.0.0.1'], true) ? 'localhost' : $host) . ':' . config('APP_PORT', '8080');
}

function reset_link(string $token): string
{
    return app_base_url() . '/index.php?page=reset&token=' . rawurlencode($token);
}

/** Create a single-use reset token (previous unused tokens for the user are invalidated). Returns the raw token. */
function create_password_reset(PDO $pdo, int $userId, ?int $createdBy = null, ?string $ip = null): string
{
    $token = bin2hex(random_bytes(32));
    $ttl = max(5, config_int('RESET_TOKEN_TTL_MINUTES', 60));
    db_exec($pdo, 'UPDATE password_resets SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL', [$userId]);
    db_exec($pdo, 'INSERT INTO password_resets (user_id, token_hash, expires_at, created_by, requested_ip) VALUES (?, ?, ?, ?, ?)',
        [$userId, reset_token_hash($token), utc_now("+$ttl minutes"), $createdBy, $ip]);
    return $token;
}

/** @return array|null reset row joined with the user, when the token is valid, unused, unexpired and the user active */
function find_valid_reset(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    return db_one($pdo, 'SELECT pr.id AS reset_id, pr.expires_at, u.id AS user_id, u.email, u.full_name
        FROM password_resets pr JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > ? AND u.is_active = 1',
        [reset_token_hash($token), utc_now()]);
}

function complete_password_reset(PDO $pdo, string $token, string $password, ?string $confirm = null): array
{
    if ($err = password_policy_error($password, $confirm)) {
        throw new UserError($err);
    }
    return db_transaction($pdo, function (PDO $pdo) use ($token, $password) {
        $reset = find_valid_reset($pdo, $token);
        if (!$reset) {
            throw new UserError('This reset link is invalid, expired or has already been used. Request a new one.');
        }
        set_user_password($pdo, (int)$reset['user_id'], $password, false); // also marks the token used
        db_exec($pdo, 'DELETE FROM login_attempts WHERE email = ? AND success = 0', [$reset['email']]);
        audit('auth.password_reset_completed', 'user', (int)$reset['user_id'], [], (int)$reset['user_id']);
        return $reset;
    });
}

/**
 * Handle a "forgot password" request. Always behaves the same for unknown emails.
 * @return array{link: ?string, delivered: bool} link is returned so local/dev mode can display it
 */
function request_password_reset(PDO $pdo, string $email, string $ip): array
{
    $user = find_user_by_email($pdo, $email);
    if ($user && is_demo_account($user)) {
        return ['link' => null, 'delivered' => false]; // public demo accounts cannot be taken over
    }
    if (!$user || (int)$user['is_active'] !== 1) {
        audit('auth.password_reset_unknown', null, null, ['email' => normalize_email($email)]);
        return ['link' => null, 'delivered' => false];
    }
    $recent = (int)db_value($pdo, 'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_by IS NULL AND created_at > ?',
        [$user['id'], utc_now(RESET_REQUEST_WINDOW)]);
    if ($recent >= RESET_REQUESTS_PER_WINDOW) {
        audit('auth.password_reset_throttled', 'user', (int)$user['id'], [], (int)$user['id']);
        return ['link' => null, 'delivered' => false];
    }
    $link = reset_link(create_password_reset($pdo, (int)$user['id'], null, $ip));
    $ttl = config_int('RESET_TOKEN_TTL_MINUTES', 60);
    $delivered = send_mail(
        $user['email'],
        'Reset your BuildTrack password',
        "Hello {$user['full_name']},\n\nSomeone (hopefully you) asked to reset your BuildTrack password.\n"
        . "Open this link within $ttl minutes to choose a new password:\n\n$link\n\n"
        . "If you did not request this, you can ignore this email; your password will not change.\n"
    );
    audit('auth.password_reset_requested', 'user', (int)$user['id'], ['delivered' => $delivered], (int)$user['id']);
    return ['link' => $link, 'delivered' => $delivered];
}

/* ------------------------------------------------------------------ admin safety */

/** In DEMO_MODE the seeded @buildtrack.local accounts are shared publicly, so nobody may lock others out of them. */
function is_demo_account(array $user): bool
{
    return is_demo_mode() && str_ends_with(strtolower((string)$user['email']), '@buildtrack.local');
}

function guard_demo_account(PDO $pdo, int $userId): void
{
    $user = find_user($pdo, $userId);
    if ($user && is_demo_account($user)) {
        throw new UserError('This is a shared demo account: its password, role and status cannot be changed in the public demo.');
    }
}

function active_admin_count(PDO $pdo): int
{
    return (int)db_value($pdo, "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1");
}

/** Change a role; refuses to remove the last active admin. Returns the previous role. */
function change_user_role(PDO $pdo, int $userId, string $role): string
{
    if (!in_array($role, ROLES, true)) {
        throw new UserError('Unknown role.');
    }
    return db_transaction($pdo, function (PDO $pdo) use ($userId, $role) {
        $target = find_user($pdo, $userId) ?? throw new UserError('User not found.');
        guard_demo_account($pdo, $userId);
        if ($target['role'] === 'admin' && $role !== 'admin' && (int)$target['is_active'] === 1 && active_admin_count($pdo) <= 1) {
            throw new UserError('You cannot demote the last active administrator. Promote someone else first.');
        }
        db_exec($pdo, 'UPDATE users SET role = ? WHERE id = ?', [$role, $userId]);
        return (string)$target['role'];
    });
}

function set_user_active(PDO $pdo, int $userId, bool $active): void
{
    db_transaction($pdo, function (PDO $pdo) use ($userId, $active) {
        $target = find_user($pdo, $userId) ?? throw new UserError('User not found.');
        guard_demo_account($pdo, $userId);
        if (!$active && $target['role'] === 'admin' && (int)$target['is_active'] === 1 && active_admin_count($pdo) <= 1) {
            throw new UserError('You cannot deactivate the last active administrator.');
        }
        // Deactivation also bumps session_version so the user is signed out immediately.
        db_exec($pdo, 'UPDATE users SET is_active = ?, session_version = session_version + ? WHERE id = ?', [$active ? 1 : 0, $active ? 0 : 1, $userId]);
    });
}

/**
 * The dev-only "make me admin" shortcut. ALL three conditions must hold:
 * ALLOW_DEV_ADMIN_ELEVATION=true, APP_ENV=local, and the request comes from the loopback interface.
 */
function dev_elevation_allowed(?string $remoteAddr = null): bool
{
    // Uses the raw socket address (never proxy headers), and is always off behind a proxy,
    // where the proxy itself may connect from 127.0.0.1.
    $ip = $remoteAddr ?? (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return config_bool('ALLOW_DEV_ADMIN_ELEVATION')
        && is_local_env()
        && !config_bool('TRUST_PROXY')
        && in_array($ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
}

/**
 * Ensure the ADMIN_EMAIL account from .env exists and is an active admin.
 * Creates it with ADMIN_PASSWORD (must_change_password=1) or promotes an existing account.
 * Runs from `bin/console migrate` (and therefore ./start.sh). Returns a human-readable note or null.
 */
function ensure_env_admin(PDO $pdo): ?string
{
    $email = config('ADMIN_EMAIL');
    if ($email === null) {
        return null;
    }
    $email = normalize_email($email);
    $existing = find_user_by_email($pdo, $email);
    if ($existing) {
        if ($existing['role'] === 'admin' && (int)$existing['is_active'] === 1) {
            return null;
        }
        db_exec($pdo, "UPDATE users SET role = 'admin', is_active = 1 WHERE id = ?", [$existing['id']]);
        audit('admin.env_promoted', 'user', (int)$existing['id'], ['email' => $email], null);
        return "Promoted $email to administrator (from ADMIN_EMAIL).";
    }
    $password = config('ADMIN_PASSWORD');
    if ($password === null) {
        return "ADMIN_EMAIL=$email has no account yet. Set ADMIN_PASSWORD too, or register with that email and run: php bin/console make-admin $email";
    }
    $user = create_user($pdo, (string)config('ADMIN_NAME', 'Administrator'), $email, $password, 'admin', true);
    audit('admin.env_created', 'user', (int)$user['id'], ['email' => $email], null);
    return "Created administrator $email from ADMIN_EMAIL/ADMIN_PASSWORD (password change required at first sign-in).";
}
