<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in web server (used by ./start.sh):
 *   php -S 127.0.0.1:8080 -t public bin/router.php
 * - Serves real files under public/ (CSS, JS) directly.
 * - Maps pretty URLs like /admin or /projects to index.php?page=admin.
 * - Returns 404 for everything else, so nothing outside public/ is ever reachable.
 */

$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$publicDir = realpath(__DIR__ . '/../public');

if ($path !== '/' && str_ends_with($path, '/')) {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . rtrim($path, '/') . ($query !== '' ? '?' . $query : ''), true, 301);
    return true;
}

$file = realpath($publicDir . $path);
if ($file !== false && is_file($file) && str_starts_with($file, $publicDir . DIRECTORY_SEPARATOR) && !str_ends_with($file, '.php')) {
    return false; // let the built-in server send the static file
}

if ($path === '/healthz.php') {
    require $publicDir . '/healthz.php';
    return true;
}
if (preg_match('#^/([a-z_]+)$#', $path, $m) && $m[1] !== 'index') {
    $_GET['page'] = $_GET['page'] ?? $m[1];
} elseif ($path !== '/' && $path !== '/index.php') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    return true;
}

chdir($publicDir);
require $publicDir . '/index.php';
