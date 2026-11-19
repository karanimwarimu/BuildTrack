<?php
declare(strict_types=1);
require __DIR__ . '/inc/boot.php';
if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) redirect('index.php?page=login');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string)($_POST['full_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a full name and valid email address.';
    elseif (strlen($password) < 10) $error = 'Choose a password with at least 10 characters.';
    elseif ($password !== $confirm) $error = 'The password confirmation does not match.';
    else {
        $stmt = $pdo->prepare('INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,\'admin\')');
        $stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
        flash('success', 'Administrator account created. Sign in to continue.');
        redirect('index.php?page=login');
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Initial setup | BuildTrack</title><link rel="stylesheet" href="assets/app.css"></head><body class="setup-bg"><main class="auth-card setup-card"><div class="brand"><span class="brand-mark">B</span><span>BuildTrack<small>Initial setup</small></span></div><div class="eyebrow">ONE-TIME CONFIGURATION</div><h1>Create administrator</h1><p class="muted">Create the first account. This setup form becomes unavailable as soon as an account exists.</p><?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?><form method="post"><?= csrf_field() ?><label>Full name<input name="full_name" required maxlength="120" value="<?= e($_POST['full_name'] ?? '') ?>"></label><label>Email address<input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></label><label>Password (minimum 10 characters)<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><label>Confirm password<input type="password" name="confirm_password" required minlength="10" autocomplete="new-password"></label><button class="btn btn-primary btn-full">Create administrator</button></form><p class="auth-small"><a href="index.php?page=login">Return to sign in</a></p></main></body></html>
