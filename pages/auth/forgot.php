<?php
/** @var PDO $pdo */
require_once __DIR__ . '/../../src/layout/auth.php';

$sent = false;
$devLink = null;
if (is_post()) {
    verify_csrf();
    $email = post_str('email');
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $result = request_password_reset($pdo, $email, client_ip());
        // Showing the link on screen is ONLY acceptable in local mode without SMTP: in production it would
        // let anyone reset any account. In production the link goes by email or into data/logs/mail.log.
        if ($result['link'] && !$result['delivered'] && is_local_env()) {
            $devLink = $result['link'];
        }
    }
    $sent = true;
}

auth_page_start('Forgot password', 'ACCOUNT RECOVERY', 'Reset your password', 'Enter your account email and we will send you a link to choose a new password.');
?>
<?php if ($sent): ?>
  <div class="alert alert-success">If an active account exists for that address, a reset link has been sent. The link expires in <?= config_int('RESET_TOKEN_TTL_MINUTES', 60) ?> minutes.</div>
  <?php if ($devLink): ?>
    <div class="alert alert-info dev-link"><strong>Local mode — email is not configured.</strong> Here is the reset link (also written to <code>data/logs/mail.log</code>):<br>
      <a href="<?= e($devLink) ?>"><?= e($devLink) ?></a></div>
  <?php endif; ?>
<?php endif; ?>
<form method="post" action="<?= e(url('forgot')) ?>">
  <?= csrf_field() ?>
  <label>Email address<input type="email" name="email" required autocomplete="email" value="<?= e(post_str('email')) ?>"></label>
  <button class="btn btn-primary btn-full" type="submit">Send reset link</button>
</form>
<p class="auth-small">No email access? Ask an administrator to reset your password from the Administration page, or the server owner can run <code>php bin/console reset-password you@example.com</code>.</p>
<p class="auth-small"><a href="<?= e(url('login')) ?>">Back to sign in</a></p>
<?php auth_page_end();
