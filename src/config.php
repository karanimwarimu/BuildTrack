<?php
declare(strict_types=1);

/**
 * Configuration: reads `.env` (KEY=value lines) once, real environment variables win.
 * Access values with config('KEY', default) / config_bool('KEY').
 */

const APP_ROOT = __DIR__ . '/..';

function env_file_path(): string
{
    return getenv('BUILDTRACK_ENV_FILE') ?: APP_ROOT . '/.env';
}

/** Parse a .env file into an array. Supports comments, blank lines and optional quotes. */
function parse_env_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (preg_match('/^(["\'])(.*)\1$/s', $value, $m)) {
            $value = $m[2];
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value); // strip trailing inline comment
        }
        $values[$key] = $value;
    }
    return $values;
}

function config_defaults(): array
{
    return [
        'APP_NAME' => 'BuildTrack',
        'APP_ENV' => 'local',               // local | production
        'APP_HOST' => '127.0.0.1',
        'APP_PORT' => '8080',
        'APP_URL' => '',                    // required in production (used in reset links)
        'APP_TIMEZONE' => 'Africa/Nairobi',
        'CURRENCY' => 'KSh',
        'DB_PATH' => 'data/buildtrack.sqlite',
        'ALLOW_REGISTRATION' => 'true',
        'FIRST_USER_IS_ADMIN' => 'true',
        'ADMIN_EMAIL' => '',
        'ADMIN_PASSWORD' => '',
        'ADMIN_NAME' => 'Administrator',
        'ALLOW_DEV_ADMIN_ELEVATION' => 'false',
        'SMTP_HOST' => '',
        'SMTP_PORT' => '587',
        'SMTP_USER' => '',
        'SMTP_PASS' => '',
        'SMTP_ENCRYPTION' => 'tls',         // tls (STARTTLS) | ssl | none
        'MAIL_FROM' => 'no-reply@buildtrack.local',
        'MAIL_FROM_NAME' => 'BuildTrack',
        'RESET_TOKEN_TTL_MINUTES' => '60',
        'LOGIN_MAX_ATTEMPTS' => '5',
        'LOGIN_LOCKOUT_MINUTES' => '15',
        'UPLOAD_MAX_MB' => '10',
        // Hosting behind a reverse proxy (Render, Cloudflare, Nginx): trust its client-IP / HTTPS headers.
        'TRUST_PROXY' => 'false',
        // Public showcase: demo accounts are locked, banner + credentials on the login page.
        'DEMO_MODE' => 'false',
        // Also send log lines to stderr (container platforms show stderr in their log viewer).
        'LOG_STDERR' => 'false',
    ];
}

/** @internal Holds the merged config. Pass an array to override (used by tests). */
function config_store(?array $override = null): array
{
    static $config = null;
    if ($override !== null) {
        $config = array_merge(config_defaults(), $override);
    }
    if ($config === null) {
        $config = array_merge(config_defaults(), parse_env_file(env_file_path()));
        foreach (array_keys($config) as $key) {
            $real = getenv($key);
            if ($real !== false) {
                $config[$key] = $real;
            }
        }
    }
    return $config;
}

function config(string $key, ?string $default = null): ?string
{
    $value = config_store()[$key] ?? null;
    return ($value === null || $value === '') ? $default : (string)$value;
}

function config_bool(string $key): bool
{
    return in_array(strtolower((string)config($key, 'false')), ['1', 'true', 'yes', 'on'], true);
}

function config_int(string $key, int $default): int
{
    $v = config($key);
    return ($v !== null && is_numeric($v)) ? (int)$v : $default;
}

function is_local_env(): bool
{
    return config('APP_ENV', 'local') === 'local';
}

/** Resolve a path relative to the project root unless it is already absolute. */
function app_path(string $path): string
{
    return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
        ? $path
        : realpath(APP_ROOT) . '/' . $path;
}

function data_path(string $sub = ''): string
{
    $base = getenv('BUILDTRACK_DATA_DIR') ?: app_path('data');
    return rtrim($base, '/') . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
}

function is_demo_mode(): bool
{
    return config_bool('DEMO_MODE');
}

/** Warnings about risky configuration, shown on startup and on the admin health page. */
function config_warnings(): array
{
    $w = [];
    $prod = !is_local_env();
    if (config_bool('ALLOW_DEV_ADMIN_ELEVATION')) {
        $w[] = $prod
            ? 'ALLOW_DEV_ADMIN_ELEVATION=true is IGNORED because APP_ENV is not "local". Remove it from .env.'
            : 'ALLOW_DEV_ADMIN_ELEVATION=true: localhost users can promote themselves to admin. Never use this on a shared server.';
    }
    if (config('ADMIN_PASSWORD') !== null) {
        $w[] = 'ADMIN_PASSWORD is set in .env. After the admin account exists, remove it and change the password in the app.';
    }
    if ($prod && config('APP_URL') === null && !getenv('RENDER_EXTERNAL_URL')) {
        $w[] = 'APP_URL is empty in production. Password reset links need it (e.g. https://buildtrack.example.com).';
    }
    if ($prod && config('SMTP_HOST') === null && !config_bool('DEMO_MODE')) {
        $w[] = 'SMTP is not configured: reset links are only written to data/logs/mail.log. Use admin-assisted resets.';
    }
    if ($prod && config('APP_HOST') === '0.0.0.0' && !str_starts_with((string)config('APP_URL'), 'https://')) {
        $w[] = 'The app listens on all interfaces without an https APP_URL. Put it behind a TLS reverse proxy.';
    }
    return $w;
}
