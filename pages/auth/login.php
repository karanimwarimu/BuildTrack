<?php
/** @var PDO $pdo */
require_once __DIR__ . '/../../src/layout/auth.php';
require_once __DIR__ . '/../../seeds/demo.php'; // DEMO_PASSWORD for the demo banner

if (logged_in()) {
    redirect('index.php');
}
$error = '';
$email = post_str('email');
if (is_post()) {
    verify_csrf();
    try {
        $found = attempt_login($pdo, $email, (string)($_POST['password'] ?? ''), client_ip());
        start_user_session($found);
        flash('success', 'Welcome back, ' . $found['full_name'] . '.');
        redirect('index.php');
    } catch (UserError $ex) {
        $error = $ex->getMessage();
    }
}
$noUsers = user_count($pdo) === 0;

auth_page_start('Sign in', 'WELCOME BACK', 'Sign in to your workspace', 'Use your registered project account to continue.');
?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if ($noUsers): ?>
  <div class="alert alert-info"><strong>New installation.</strong> No accounts exist yet.
    <a href="<?= e(url('register')) ?>">Create the first account</a><?= config_bool('FIRST_USER_IS_ADMIN') ? ' — it becomes the administrator.' : '.' ?></div>
<?php endif; ?>
<?php if (is_demo_mode()): ?>
  <div class="alert alert-info demo-accounts"><strong>Public demo.</strong> Sign in with any account below (password <code><?= e(DEMO_PASSWORD) ?></code>). Data resets automatically.
    <ul>
      <?php foreach (['admin@buildtrack.local' => 'Administrator', 'pm@buildtrack.local' => 'Project Manager', 'supervisor@buildtrack.local' => 'Site Supervisor', 'worker@buildtrack.local' => 'Worker'] as $demoEmail => $demoRole): ?>
        <li><button type="button" class="action-link" data-demo-login="<?= e($demoEmail) ?>"><?= e($demoRole) ?></button> <code><?= e($demoEmail) ?></code></li>
      <?php endforeach; ?>
    </ul></div>
<?php endif; ?>
<form method="post" action="<?= e(url('login')) ?>">
  <?= csrf_field() ?>
  <label>Email address<input type="email" name="email" required autocomplete="username" autofocus value="<?= e($email) ?>"></label>
  <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn btn-primary btn-full" type="submit">Sign in <span>→</span></button>
</form>
<p class="auth-small"><a href="<?= e(url('forgot')) ?>">Forgot your password?</a></p>
<?php if (registration_open($pdo)): ?>
  <p class="auth-small">New here? <a href="<?= e(url('register')) ?>">Create an account</a></p>
<?php endif; ?>
<?php auth_page_end();
