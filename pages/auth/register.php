<?php
/** @var PDO $pdo */
require_once __DIR__ . '/../../src/layout/auth.php';

if (logged_in()) {
    redirect('index.php');
}
if (!registration_open($pdo)) {
    http_response_code(403);
    render_message_page('Registration is closed', 'New accounts are created by an administrator on this installation. Ask your administrator for access.', url('login'), 'Back to sign in');
}

$firstUser = user_count($pdo) === 0;
$error = '';
if (is_post()) {
    verify_csrf();
    $password = (string)($_POST['password'] ?? '');
    try {
        if ($err = password_policy_error($password, (string)($_POST['confirm_password'] ?? ''))) {
            throw new UserError($err);
        }
        $created = create_user($pdo, post_str('full_name'), post_str('email'), $password);
        audit('auth.registered', 'user', (int)$created['id'], ['role' => $created['role']], (int)$created['id']);
        start_user_session($created);
        flash('success', $created['role'] === 'admin'
            ? 'Account created. As the first user you are the administrator — invite your team from Administration.'
            : 'Account created. An administrator can give you more access if you need it.');
        redirect('index.php');
    } catch (UserError $ex) {
        $error = $ex->getMessage();
    }
}

auth_page_start('Create account', $firstUser ? 'FIRST-TIME SETUP' : 'JOIN YOUR TEAM', $firstUser ? 'Create the administrator account' : 'Create your account',
    $firstUser && config_bool('FIRST_USER_IS_ADMIN')
        ? 'This is the first account on this installation, so it will become the administrator.'
        : 'New accounts start with the Worker role. An administrator can change your role.');
?>
<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('register')) ?>">
  <?= csrf_field() ?>
  <label>Full name<input name="full_name" required maxlength="120" autocomplete="name" value="<?= e(post_str('full_name')) ?>"></label>
  <label>Email address<input type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e(post_str('email')) ?>"></label>
  <label>Password (minimum <?= PASSWORD_MIN_LENGTH ?> characters)<input type="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password"></label>
  <label>Confirm password<input type="password" name="confirm_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password"></label>
  <button class="btn btn-primary btn-full" type="submit">Create account <span>→</span></button>
</form>
<p class="auth-small">Already registered? <a href="<?= e(url('login')) ?>">Sign in</a></p>
<?php auth_page_end();
