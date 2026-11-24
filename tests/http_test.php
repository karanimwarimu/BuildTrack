<?php
declare(strict_types=1);

/**
 * End-to-end tests against the real router + front controller running in PHP's built-in server,
 * with an isolated data directory. Exercises the auth gate, registration, RBAC and core writes.
 */

final class HttpClient
{
    public array $cookies = [];
    public int $status = 0;
    public array $headers = [];
    public string $body = '';

    public function __construct(private string $base) {}

    public function get(string $path): self
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $fields, bool $withCsrfFrom = true): self
    {
        if ($withCsrfFrom && !isset($fields['_csrf'])) {
            $fields['_csrf'] = $this->csrf($path);
        }
        return $this->request('POST', $path, http_build_query($fields));
    }

    /** Fetch a page and extract its CSRF token (forms post back to the page they are on). */
    public function csrf(string $path): string
    {
        $this->get($path);
        preg_match('/name="_csrf" value="([a-f0-9]+)"/', $this->body, $m);
        return $m[1] ?? '';
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $h) {
            if (stripos($h, $name . ':') === 0) {
                return trim(substr($h, strlen($name) + 1));
            }
        }
        return null;
    }

    private function request(string $method, string $path, ?string $body = null): self
    {
        $headers = ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies))];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '',
            'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 10,
        ]]);
        $this->body = (string)@file_get_contents($this->base . $path, false, $ctx);
        $this->headers = $http_response_header ?? [];
        preg_match('#HTTP/\S+ (\d+)#', $this->headers[0] ?? '', $m);
        $this->status = (int)($m[1] ?? 0);
        foreach ($this->headers as $h) {
            if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $c)) {
                $this->cookies[$c[1]] = $c[2];
            }
        }
        return $this;
    }
}

test('HTTP: auth gate, registration, RBAC, projects/tasks/progress, admin backup + export', function () {
    $dir = getenv('BUILDTRACK_DATA_DIR') . '/http';
    @mkdir($dir, 0775, true);
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $root = realpath(__DIR__ . '/..');
    $env = ['BUILDTRACK_DATA_DIR' => $dir, 'BUILDTRACK_DB_PATH' => "$dir/app.sqlite", 'BUILDTRACK_ENV_FILE' => "$dir/none.env", 'APP_ENV' => 'local', 'PATH' => getenv('PATH')];
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$root/public", "$root/bin/router.php"], [1 => ['file', "$dir/server.log", 'a'], 2 => ['file', "$dir/server.log", 'a']], $pipes, $root, $env);
    try {
        $base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }

        $anon = new HttpClient($base);
        assert_same(302, $anon->get('/')->status);
        assert_contains('page=login', (string)$anon->header('Location'));
        assert_same(302, $anon->get('/admin')->status, '/admin is gated');
        assert_same('DENY', $anon->get('/index.php?page=login')->header('X-Frame-Options'));
        foreach (['/data/app.sqlite', '/.env', '/src/config.php', '/pages/admin.php', '/migrations/001_initial_schema.sql'] as $secret) {
            assert_same(404, $anon->get($secret)->status, "$secret must not be served");
        }
        assert_contains('Create the first account', $anon->get('/index.php?page=login')->body);
        assert_same(419, $anon->post('/index.php?page=login', ['email' => 'x@y.z', 'password' => 'x'], false)->status, 'POST without CSRF rejected');

        $admin = new HttpClient($base);
        $admin->post('/index.php?page=register', ['full_name' => 'Ada Admin', 'email' => 'ada@example.com', 'password' => 'admin-password-1', 'confirm_password' => 'admin-password-1']);
        assert_same(302, $admin->status, 'register redirects');
        assert_contains('role-admin', $admin->get('/index.php?page=account')->body, 'first user is admin');
        assert_same(200, $admin->get('/admin')->status, 'pretty /admin URL works');
        assert_contains('Users &amp; roles', $admin->body);

        $worker = new HttpClient($base);
        $worker->post('/index.php?page=register', ['full_name' => 'Wes Worker', 'email' => 'wes@example.com', 'password' => 'worker-password-1', 'confirm_password' => 'worker-password-1']);
        assert_contains('role-worker', $worker->get('/index.php?page=account')->body);
        assert_same(403, $worker->get('/index.php?page=admin')->status, 'worker blocked from admin');
        assert_same(403, $worker->get('/index.php?page=expenses')->status, 'worker blocked from expenses');
        $worker->post('/index.php?page=projects', ['action' => 'create', 'name' => 'Sneaky']);
        assert_same(403, $worker->status, 'worker cannot create projects');

        $admin->post('/index.php?page=projects', ['action' => 'create', 'name' => 'Harbour Wall', 'budget' => '5000', 'status' => 'active']);
        assert_same(302, $admin->status);
        assert_contains('page=project&id=1', (string)$admin->header('Location'));
        assert_contains('Harbour Wall', $admin->get('/index.php?page=project&id=1')->body);
        assert_same(404, $worker->get('/index.php?page=project&id=1')->status, 'worker cannot see unassigned project');

        $admin->post('/index.php?page=tasks', ['action' => 'create', 'project_id' => '1', 'title' => 'Pour footings', 'assigned_to' => '2', 'due_date' => date('Y-m-d', strtotime('-1 day')), 'status' => 'pending', 'priority' => 'high']);
        assert_contains('Pour footings', $worker->get('/index.php?page=tasks')->body, 'worker sees assigned task');
        assert_same(200, $worker->get('/index.php?page=project&id=1')->status, 'worker can now open the project');
        $worker->post('/index.php?page=progress', ['task_id' => '1', 'progress_percentage' => '100', 'status' => 'in_progress', 'notes' => 'Done']);
        assert_contains('Progress update saved', $worker->get('/index.php?page=progress&task_id=1')->body);
        assert_contains('status-completed', $worker->body, '100% marks the task completed');

        $admin->post('/index.php?page=expenses', ['action' => 'create', 'project_id' => '1', 'description' => 'Rebar', 'amount' => '6000', 'spent_on' => date('Y-m-d'), 'category' => 'materials']);
        assert_contains('over budget', $admin->get('/index.php?page=expenses&project_id=1')->body);

        $admin->post('/index.php?page=admin&tab=users', ['action' => 'change_role', 'id' => '1', 'role' => 'worker']);
        assert_contains('cannot change your own role', $admin->get('/index.php?page=admin')->body);

        $admin->post('/index.php?page=admin&tab=backups', ['action' => 'create_backup', 'keep' => '5']);
        assert_contains('Backup created', $admin->get('/index.php?page=admin&tab=backups')->body);
        $admin->get('/index.php?page=admin&tab=backups&download=snapshot');
        assert_same("SQLite format 3\0", substr($admin->body, 0, 16), 'snapshot download is a SQLite file');
        $admin->get('/index.php?page=admin&tab=backups&export=tasks');
        assert_contains('text/csv', (string)$admin->header('Content-Type'));
        assert_contains('Pour footings', $admin->body);

        $anon2 = new HttpClient($base);
        $anon2->post('/index.php?page=forgot', ['email' => 'wes@example.com']);
        assert_contains('page=reset&amp;token=', $anon2->body, 'local mode shows reset link');

        $worker->post('/index.php?page=logout', ['_csrf' => $worker->csrf('/index.php?page=account')]);
        assert_same(302, $worker->get('/index.php?page=dashboard')->status, 'signed out');

        $audit = $admin->get('/index.php?page=admin&tab=audit')->body;
        foreach (['auth.registered', 'project.created', 'task.created', 'task.progress_updated', 'expense.created', 'backup.created'] as $action) {
            assert_contains($action, $audit, "audit has $action");
        }
        $log = (string)@file_get_contents("$dir/server.log");
        assert_false((bool)preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/', $log), 'no PHP warnings in server log: ' . substr($log, 0, 500));
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
});
