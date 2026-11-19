<?php
declare(strict_types=1);
date_default_timezone_set('Africa/Nairobi');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// Update these values if your local MySQL settings differ.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'construction_project_system');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $ex) {
    http_response_code(500);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Database connection</title><link rel="stylesheet" href="assets/app.css"></head><body class="setup-bg"><main class="auth-card"><h1>Database connection failed</h1><p>Check that MySQL is running in XAMPP, import <code>database.sql</code>, and confirm the database settings in <code>inc/boot.php</code>.</p><p class="muted">Technical message: ' . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></main></body></html>';
    exit;
}

require_once __DIR__ . '/functions.php';
