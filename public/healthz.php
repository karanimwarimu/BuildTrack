<?php
// Health check for Render / Docker: returns "ok" when PHP and the SQLite database respond.
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
header('Content-Type: text/plain');
header('Cache-Control: no-store');
try {
    db()->query('SELECT 1')->fetchColumn();
    echo 'ok';
} catch (Throwable $e) {
    http_response_code(503);
    echo 'database unavailable';
}
