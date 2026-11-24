<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ output & http */

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Build an internal URL: url('tasks', ['project_id' => 3]) -> index.php?page=tasks&project_id=3 */
function url(string $page, array $params = []): string
{
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return 'index.php?' . http_build_query(['page' => $page] + $params);
}

function post_str(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get_str(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/**
 * The visitor's IP address (used for login throttling and the audit log).
 * With TRUST_PROXY=true the CF-Connecting-IP header is used: on Render every request passes through
 * Cloudflare, which overwrites that header, so clients cannot forge it. X-Forwarded-For is NOT used
 * because Render appends to whatever the client sent, making the left-most entry spoofable.
 */
function client_ip(): string
{
    if (config_bool('TRUST_PROXY')) {
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

/** True when the browser connection is HTTPS (directly, or via a trusted proxy that terminated TLS). */
function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return config_bool('TRUST_PROXY') && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/* ------------------------------------------------------------------ csrf & flash */

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    $expected = (string)($_SESSION['_csrf'] ?? '');
    // An empty session token must never match an empty/missing form token.
    if ($expected === '' || !is_string($token) || !hash_equals($expected, $token)) {
        http_response_code(419);
        render_message_page('Form expired', 'Your form session expired. Go back, refresh the page, and submit again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int,array{type:string,message:string}> */
function pull_flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($items) ? $items : [];
}

/** A minimal standalone page for errors/forbidden/expired states. */
function render_message_page(string $title, string $message, string $linkHref = 'index.php', string $linkText = 'Return to dashboard'): never
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . ' | BuildTrack</title><link rel="stylesheet" href="assets/app.css"></head>'
        . '<body class="setup-bg"><main class="auth-card setup-card"><div class="brand"><span class="brand-mark">B</span><span>BuildTrack<small>Construction workspace</small></span></div>'
        . '<h1>' . e($title) . '</h1><p>' . e($message) . '</p><a class="btn btn-primary" href="' . e($linkHref) . '">' . e($linkText) . '</a></main></body></html>';
    exit;
}

/* ------------------------------------------------------------------ labels & formatting */

const ROLES = ['admin', 'project_manager', 'site_supervisor', 'worker'];
const PROJECT_STATUSES = ['planned', 'active', 'on_hold', 'completed'];
const TASK_STATUSES = ['pending', 'in_progress', 'blocked', 'completed'];
const TASK_PRIORITIES = ['low', 'medium', 'high', 'urgent'];
const RESOURCE_TYPES = ['labour', 'equipment', 'material', 'other'];
const EXPENSE_CATEGORIES = ['materials', 'labour', 'equipment', 'subcontractor', 'permits', 'transport', 'other'];

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Administrator', 'project_manager' => 'Project Manager',
        'site_supervisor' => 'Site Supervisor', 'worker' => 'Worker', default => ucfirst($role),
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'in_progress' => 'In progress', 'on_hold' => 'On hold', default => ucwords(str_replace('_', ' ', $status)),
    };
}

function date_label(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $stamp = strtotime($value);
    return $stamp ? date('d M Y', $stamp) : '—';
}

/** Database timestamps are UTC; show them in APP_TIMEZONE. */
function datetime_label(?string $utc, string $format = 'd M Y, H:i'): string
{
    if (!$utc) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format($format);
    } catch (Exception) {
        return '—';
    }
}

/** Current UTC timestamp in the format SQLite's CURRENT_TIMESTAMP uses. */
function utc_now(string $modify = ''): string
{
    $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return ($modify !== '' ? $dt->modify($modify) : $dt)->format('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function valid_date(string $value): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

function money(float|int|string|null $amount): string
{
    return config('CURRENCY', 'KSh') . ' ' . number_format((float)$amount, 2);
}

function qty(float|int|string|null $value): string
{
    return rtrim(rtrim(number_format((float)$value, 2, '.', ','), '0'), '.');
}

function human_bytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
}

function first_letter(string $text): string
{
    return strtoupper(substr($text, 0, 1));
}

/* ------------------------------------------------------------------ current user & permissions */

/**
 * The signed-in user, re-read from the database once per request so that role changes,
 * deactivation and password changes take effect immediately.
 */
function user(): ?array
{
    if (!array_key_exists('__user', $GLOBALS)) {
        $GLOBALS['__user'] = null;
        $id = (int)($_SESSION['uid'] ?? 0);
        if ($id > 0) {
            $row = db_one(db(), 'SELECT id, full_name, email, role, is_active, must_change_password, session_version FROM users WHERE id = ?', [$id]);
            if ($row && (int)$row['is_active'] === 1 && (int)$row['session_version'] === (int)($_SESSION['sv'] ?? -1)) {
                $row['id'] = (int)$row['id'];
                $GLOBALS['__user'] = $row;
            } else {
                unset($_SESSION['uid'], $_SESSION['sv']); // deactivated, deleted or password changed elsewhere
            }
        }
    }
    return $GLOBALS['__user'];
}

function forget_current_user(): void
{
    unset($GLOBALS['__user']);
}

function user_id(): int
{
    return (int)(user()['id'] ?? 0);
}

function user_role(): string
{
    return (string)(user()['role'] ?? '');
}

function logged_in(): bool
{
    return user() !== null;
}

/**
 * Central permission map. Add new permissions here instead of scattering role lists.
 * See skills/auth-and-admin-rbac/SKILL.md.
 */
const PERMISSIONS = [
    'admin.access'        => ['admin'],
    'users.manage'        => ['admin'],
    'audit.view'          => ['admin'],
    'backup.manage'       => ['admin'],
    'projects.manage'     => ['admin', 'project_manager'],
    'projects.delete'     => ['admin', 'project_manager'],
    'projects.view_all'   => ['admin', 'project_manager', 'site_supervisor'],
    'tasks.manage'        => ['admin', 'project_manager', 'site_supervisor'],
    'tasks.delete'        => ['admin', 'project_manager'],
    'resources.manage'    => ['admin', 'project_manager', 'site_supervisor'],
    'resources.delete'    => ['admin', 'project_manager'],
    'expenses.view'       => ['admin', 'project_manager', 'site_supervisor'],
    'expenses.manage'     => ['admin', 'project_manager'],
    'sitelogs.create'     => ['admin', 'project_manager', 'site_supervisor'],
    'sitelogs.manage_any' => ['admin', 'project_manager'],
    'attachments.manage_any' => ['admin', 'project_manager'],
];

function role_can(string $role, string $permission): bool
{
    return in_array($role, PERMISSIONS[$permission] ?? [], true);
}

function can(string $permission): bool
{
    return role_can(user_role(), $permission);
}

/** Legacy helpers kept for readability in templates. */
function is_admin(): bool { return user_role() === 'admin'; }
function is_manager(): bool { return can('projects.manage'); }
function can_manage_projects(): bool { return can('projects.manage'); }
function can_manage_tasks(): bool { return can('tasks.manage'); }

function forbidden(): never
{
    http_response_code(403);
    render_message_page('Access denied', 'Your role does not have permission to access this page or perform this action.');
}

function require_permission(string $permission): void
{
    if (!can($permission)) {
        forbidden();
    }
}

function require_role(array $roles): void
{
    if (!in_array(user_role(), $roles, true)) {
        forbidden();
    }
}

/** Workers only see tasks assigned to them; supervisors and above see all. Returns [sql, params] for alias "t". */
function current_task_access_sql(): array
{
    if (can('projects.view_all')) {
        return ['', []];
    }
    return [' AND t.assigned_to = ?', [user_id()]];
}

/** Same rule for project-level queries (alias "p"). */
function current_project_access_sql(): array
{
    if (can('projects.view_all')) {
        return ['', []];
    }
    return [' AND EXISTS (SELECT 1 FROM tasks tx WHERE tx.project_id = p.id AND tx.assigned_to = ?)', [user_id()]];
}

function task_visible(PDO $pdo, int $taskId): bool
{
    [$scope, $params] = current_task_access_sql();
    return (bool)db_value($pdo, "SELECT t.id FROM tasks t WHERE t.id = ? $scope", array_merge([$taskId], $params));
}

function project_visible(PDO $pdo, int $projectId): bool
{
    [$scope, $params] = current_project_access_sql();
    return (bool)db_value($pdo, "SELECT p.id FROM projects p WHERE p.id = ? $scope", array_merge([$projectId], $params));
}

/** Projects the current user can see, for <select> menus. */
function visible_projects(PDO $pdo, bool $includeCompleted = true): array
{
    [$scope, $params] = current_project_access_sql();
    $status = $includeCompleted ? '' : " AND p.status <> 'completed'";
    return db_all($pdo, "SELECT p.id, p.name, p.status FROM projects p WHERE 1=1 $scope $status ORDER BY p.name", $params);
}

/* ------------------------------------------------------------------ audit trail */

/**
 * Record an action in audit_log. Never throws: auditing must not break the user's request.
 * Use dotted action names: <entity>.<verb>, e.g. task.updated, auth.login_failed, admin.role_changed.
 */
function audit(string $action, ?string $entityType = null, ?int $entityId = null, array $details = [], ?int $actorId = null): void
{
    try {
        db_exec(db(), 'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip) VALUES (?, ?, ?, ?, ?, ?)', [
            $actorId ?? (user_id() ?: null),
            $action,
            $entityType,
            $entityId,
            $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            PHP_SAPI === 'cli' ? 'cli' : client_ip(),
        ]);
    } catch (Throwable $e) {
        app_log('error', 'audit write failed: ' . $e->getMessage());
    }
}

function audit_action_label(string $action): string
{
    return match ($action) {
        'auth.login' => 'Signed in',
        'auth.logout' => 'Signed out',
        'auth.login_failed' => 'Failed sign-in attempt',
        'auth.registered' => 'Account registered',
        'auth.password_reset_requested' => 'Password reset requested',
        'auth.password_reset_completed' => 'Password reset completed',
        default => ucfirst(str_replace(['.', '_'], [': ', ' '], $action)),
    };
}

/** Return only the fields that differ between two rows (for update audit entries). */
function audit_diff(array $before, array $after): array
{
    $diff = [];
    foreach ($after as $k => $v) {
        if (array_key_exists($k, $before) && (string)$before[$k] !== (string)$v) {
            $diff[$k] = ['from' => $before[$k], 'to' => $v];
        }
    }
    return $diff;
}

/* ------------------------------------------------------------------ logging & csv */

function app_log(string $level, string $message, string $file = 'app.log'): void
{
    $dir = data_path('logs');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
    @file_put_contents($dir . '/' . $file, $line, FILE_APPEND | LOCK_EX);
    if (config_bool('LOG_STDERR')) {
        @file_put_contents('php://stderr', "[buildtrack/$file] $line");
    }
}

/** Neutralise spreadsheet formula injection (=, +, -, @ prefixes) in exported CSV cells. */
function csv_safe(mixed $value): string
{
    $v = (string)($value ?? '');
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

/** Stream rows as a CSV download and stop. */
function send_csv(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel detects the encoding
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe', array_values($row)));
    }
    fclose($out);
    exit;
}

/** Keep a string within a byte length without splitting a UTF-8 character (mbstring is optional). */
function str_limit(string $value, int $maxBytes): string
{
    if (strlen($value) <= $maxBytes) {
        return $value;
    }
    $cut = substr($value, 0, $maxBytes);
    return preg_replace('/[\x80-\xBF]*[\xC0-\xFF]?$/', '', $cut) ?? $cut;
}
