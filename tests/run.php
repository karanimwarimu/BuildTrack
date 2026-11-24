<?php
declare(strict_types=1);

/**
 * Dependency-free test runner:  php tests/run.php [filter]
 * Each tests/*_test.php file registers cases with test('name', fn() => ...).
 * Every case gets a fresh SQLite database in tests/tmp/ and isolated config.
 */

error_reporting(E_ALL);
set_error_handler(function (int $no, string $str, string $file, int $line) {
    if (!(error_reporting() & $no)) {
        return false; // respect the @ operator
    }
    throw new ErrorException($str, 0, $no, $file, $line); // warnings/notices fail tests
});

$tmpRoot = __DIR__ . '/tmp/run-' . getmypid();
@mkdir($tmpRoot, 0775, true);
putenv('BUILDTRACK_DATA_DIR=' . $tmpRoot . '/data');
putenv('BUILDTRACK_ENV_FILE=' . $tmpRoot . '/none.env');

require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/mysql_import.php';
require_once __DIR__ . '/../seeds/demo.php';

final class AssertionFailed extends Exception {}

$GLOBALS['__tests'] = [];
function test(string $name, callable $fn): void { $GLOBALS['__tests'][] = [$name, $fn]; }

function assert_true(mixed $cond, string $msg = 'Expected true'): void { if (!$cond) throw new AssertionFailed($msg); }
function assert_false(mixed $cond, string $msg = 'Expected false'): void { if ($cond) throw new AssertionFailed($msg); }
function assert_same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(($msg ? "$msg: " : '') . "'$needle' not found in '" . substr($haystack, 0, 300) . "'");
    }
}
/** Assert that $fn throws $class (optionally with a message containing $contains). */
function assert_throws(string $class, callable $fn, string $contains = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new AssertionFailed("Expected $class, got " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            throw new AssertionFailed("Exception message '{$e->getMessage()}' does not contain '$contains'");
        }
        return;
    }
    throw new AssertionFailed("Expected $class to be thrown");
}

/** Fresh migrated database file + default config for one test. */
function fresh_db(array $config = []): PDO
{
    static $n = 0;
    $n++;
    config_store($config + ['APP_ENV' => 'local']);
    $path = getenv('BUILDTRACK_DATA_DIR') . "/test-$n.sqlite";
    @mkdir(dirname($path), 0775, true);
    foreach (['', '-wal', '-shm'] as $s) {
        @unlink($path . $s);
    }
    putenv("BUILDTRACK_DB_PATH=$path");
    $pdo = db_connect($path);
    db_set($pdo);
    migrate($pdo);
    $_SESSION = [];
    forget_current_user();
    return $pdo;
}

function make_user(PDO $pdo, string $role = 'worker', ?string $email = null, string $password = 'correct-horse-battery'): array
{
    static $i = 0;
    $i++;
    return create_user($pdo, "User $i", $email ?? "user$i@example.com", $password, $role);
}

/** Pretend $user is signed in for code that calls user()/can(). */
function act_as(array $user): void
{
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['sv'] = (int)db_value(db(), 'SELECT session_version FROM users WHERE id = ?', [$user['id']]);
    forget_current_user();
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = "$dir/$f";
        is_dir($p) && !is_link($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = [];
$start = microtime(true);
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    try {
        $fn();
        $passed++;
        echo "\033[32m✔\033[0m $name\n";
    } catch (Throwable $e) {
        $failed[] = $name;
        echo "\033[31m✘ $name\033[0m\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    } finally {
        db_set(null);
    }
}
rrmdir($tmpRoot);
@rmdir(__DIR__ . '/tmp');
printf("\n%d passed, %d failed (%.1fs)\n", $passed, count($failed), microtime(true) - $start);
exit($failed ? 1 : 0);
