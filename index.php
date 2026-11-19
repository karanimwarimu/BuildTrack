<?php
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';

$page = preg_replace('/[^a-z_]/', '', (string)($_GET['page'] ?? 'dashboard')) ?: 'dashboard';

if ($page === 'login') {
    if (logged_in()) redirect('index.php');
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id,full_name,email,password_hash,role FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $found = $stmt->fetch();
        if ($found && password_verify($password, $found['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id'=>(int)$found['id'],'full_name'=>$found['full_name'],'email'=>$found['email'],'role'=>$found['role']];
            flash('success', 'Welcome back, ' . $found['full_name'] . '.');
            redirect('index.php');
        }
        $error = 'The email address or password is incorrect.';
    }
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in | BuildTrack</title><link rel="stylesheet" href="assets/app.css"></head><body class="login-bg"><main class="auth-wrap"><section class="auth-intro"><div class="brand brand-light"><span class="brand-mark">B</span><span>BuildTrack<small>Construction workspace</small></span></div><div class="auth-message"><span class="pill pill-blue">PROJECT CONTROL, SIMPLIFIED</span><h1>Keep every project<br>moving forward.</h1><p>Manage work schedules, track site progress, coordinate resources and stay ahead of deadlines in one place.</p></div><div class="auth-foot">Construction Project Management and Scheduling System</div></section><section class="auth-panel"><div class="auth-card"><div class="mobile-brand">BUIL​​DTRACK</div><div class="eyebrow">WELCOME BACK</div><h2>Sign in to your workspace</h2><p class="muted">Use your registered project account to continue.</p><?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?><?php if ($count === 0): ?><div class="alert alert-info">This installation has no user accounts yet. <a href="setup.php">Create the first administrator</a>.</div><?php endif; ?><form method="post" action="index.php?page=login"><?= csrf_field() ?><label>Email address<input type="email" name="email" required autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="btn btn-primary btn-full" type="submit">Sign in <span>→</span></button></form><p class="auth-small">Forgot your password? Contact your system administrator.</p></div></section></main></body></html><?php
    exit;
}

if (!logged_in()) redirect('index.php?page=login');

$routes = [
    'dashboard'=>'dashboard.php', 'projects'=>'projects.php', 'tasks'=>'tasks.php',
    'progress'=>'progress.php', 'resources'=>'resources.php', 'alerts'=>'alerts.php',
    'reports'=>'reports.php', 'users'=>'users.php'
];
if (!isset($routes[$page])) $page = 'dashboard';
require __DIR__ . '/pages/' . $routes[$page];
