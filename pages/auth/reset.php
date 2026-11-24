<?php
/** @var PDO $pdo */
require_once __DIR__ . '/../../src/layout/auth.php';

$token = is_post() ? post_str('token') : get_str('token');
$error = '';
if (is_post()) {
    verify_csrf();
    try {
        complete_password_reset($pdo, $token, (string)($_POST['password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
        if (logged_in()) {
            end_user_session();
            session_start();
        }
        flash('success', 'Your password has been changed. Sign in with the new password.');
        redirect(url('login'));
    } catch (UserError $ex) {
        $error = $ex->getMessage();
    }
}
$reset = find_valid_reset($pdo, $token);

auth_page_start('Choose a new password', 'ACCOUNT RECOVERY', 'Choose a new password', $reset ? 'Resetting the password for ' . $reset['email'] . '.' : 'Reset links are single-use and expire.');
?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (!$reset): ?>
  <div class="alert alert-error">This reset link is invalid, expired or has already been used.</div>
  <a class="btn btn-primary btn-full" href="<?= e(url('forgot')) ?>">Request a new link</a>
<?php else: ?>
  <form method="post" action="<?= e(url('reset')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label>New password (minimum <?= PASSWORD_MIN_LENGTH ?> characters)<input type="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" autofocus></label>
    <label>Confirm new password<input type="password" name="confirm_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password"></label>
    <button class="btn btn-primary btn-full" type="submit">Save new password</button>
  </form>
<?php endif; ?>
<p class="auth-small"><a href="<?= e(url('login')) ?>">Back to sign in</a></p>
<?php auth_page_end();
