<?php
declare(strict_types=1);

function e(mixed $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function verify_csrf(): void {
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals((string)($_SESSION['_csrf'] ?? ''), $token)) {
        http_response_code(419);
        exit('Your form session expired. Go back, refresh the page, and submit again.');
    }
}
function flash(string $type, string $message): void {
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}
function pull_flash(): ?array {
    $item = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return is_array($item) ? $item : null;
}
function user(): ?array { return $_SESSION['user'] ?? null; }
function logged_in(): bool { return user() !== null; }
function is_admin(): bool { return (user()['role'] ?? '') === 'admin'; }
function is_manager(): bool { return in_array((user()['role'] ?? ''), ['admin','project_manager'], true); }
function can_manage_projects(): bool { return is_manager(); }
function can_manage_tasks(): bool { return in_array((user()['role'] ?? ''), ['admin','project_manager','site_supervisor'], true); }
function require_login(): void { if (!logged_in()) redirect('index.php?page=login'); }
function require_role(array $roles): void {
    if (!in_array(user()['role'] ?? '', $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/forbidden.php';
        exit;
    }
}
function role_label(string $role): string {
    return match ($role) {
        'admin' => 'Administrator', 'project_manager' => 'Project Manager',
        'site_supervisor' => 'Site Supervisor', 'worker' => 'Worker', default => ucfirst($role)
    };
}
function status_label(string $status): string {
    return match ($status) {
        'in_progress' => 'In progress', 'on_hold' => 'On hold', default => ucwords(str_replace('_', ' ', $status))
    };
}
function date_label(?string $value): string {
    if (!$value) return '—';
    $stamp = strtotime($value);
    return $stamp ? date('d M Y', $stamp) : '—';
}
function current_task_access_sql(): array {
    if (is_manager() || (user()['role'] ?? '') === 'site_supervisor') return ['', []];
    return [' AND t.assigned_to = ?', [(int)user()['id']]];
}
function task_visible(PDO $pdo, int $taskId): bool {
    if (is_manager() || (user()['role'] ?? '') === 'site_supervisor') {
        $stmt = $pdo->prepare('SELECT id FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM tasks WHERE id = ? AND assigned_to = ?');
        $stmt->execute([$taskId, (int)user()['id']]);
    }
    return (bool)$stmt->fetchColumn();
}
