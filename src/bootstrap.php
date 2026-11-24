<?php
declare(strict_types=1);

/**
 * Loads the application for both the web front controller and bin/console.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/construction.php';

/** A validation / business-rule error whose message is safe to show to the user. */
final class UserError extends RuntimeException {}

$tz = (string)config('APP_TIMEZONE', 'Africa/Nairobi');
if (!@date_default_timezone_set($tz)) {
    date_default_timezone_set('UTC');
}

/**
 * Web-only setup: hardened session, security headers, database connection with
 * automatic migration on first run, and friendly error pages.
 */
function bootstrap_web(): PDO
{
    set_exception_handler('handle_web_exception');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");

    if (session_status() !== PHP_SESSION_ACTIVE) {
        $sessionDir = data_path('sessions');
        if (!is_dir($sessionDir)) {
            @mkdir($sessionDir, 0770, true);
        }
        if (is_dir($sessionDir) && is_writable($sessionDir)) {
            session_save_path($sessionDir);
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
            ini_set('session.gc_maxlifetime', '28800'); // 8 hours
        }
        ini_set('session.use_strict_mode', '1');
        session_name('buildtrack_session');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => is_https(),
            'path' => '/',
        ]);
        session_start();
    }

    try {
        $pdo = db();
        if (migrations_pending($pdo)) {
            migrate($pdo);
            ensure_env_admin($pdo);
        }
    } catch (Throwable $e) {
        app_log('error', 'Startup failed: ' . $e->getMessage());
        http_response_code(500);
        render_message_page(
            'Setup problem',
            'BuildTrack could not open or prepare its SQLite database at ' . db_path() . '. '
            . 'Make sure the data/ folder exists and is writable by PHP, then reload. Details: ' . $e->getMessage(),
            'index.php',
            'Try again'
        );
    }
    return $pdo;
}

function handle_web_exception(Throwable $e): void
{
    $id = bin2hex(random_bytes(4));
    app_log('error', "[$id] " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (!headers_sent()) {
        http_response_code(500);
    }
    $msg = is_local_env()
        ? get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
        : "Something went wrong. The error was logged with reference $id.";
    render_message_page('Unexpected error', $msg);
}
