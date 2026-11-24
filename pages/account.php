<?php
/** @var PDO $pdo */
$pageTitle = 'My account';
$me = user();

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    try {
        if ($action === 'profile') {
            $name = post_str('full_name');
            if ($name === '' || strlen($name) > 120) {
                throw new UserError('Enter your full name (up to 120 characters).');
            }
            db_exec($pdo, 'UPDATE users SET full_name = ? WHERE id = ?', [$name, $me['id']]);
            audit('user.profile_updated', 'user', $me['id'], audit_diff(['full_name' => $me['full_name']], ['full_name' => $name]));
            flash('success', 'Profile updated.');
        } elseif ($action === 'password') {
            $row = find_user($pdo, $me['id']);
            if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$row['password_hash'])) {
                throw new UserError('Your current password is incorrect.');
            }
            $new = (string)($_POST['password'] ?? '');
            if ($err = password_policy_error($new, (string)($_POST['confirm_password'] ?? ''))) {
                throw new UserError($err);
            }
            if (password_verify($new, (string)$row['password_hash'])) {
                throw new UserError('Choose a password different from your current one.');
            }
            set_user_password($pdo, $me['id'], $new, false);
            audit('user.password_changed', 'user', $me['id']);
            // Keep this browser signed in with the new session version; other sessions are signed out.
            start_user_session(find_user($pdo, $me['id']));
            flash('success', 'Password changed. Other devices have been signed out.');
        } elseif ($action === 'dev_elevate') {
            if (!dev_elevation_allowed()) {
                audit('admin.dev_elevation_denied', 'user', $me['id']);
                forbidden();
            }
            change_user_role($pdo, $me['id'], 'admin');
            audit('admin.dev_elevation', 'user', $me['id'], ['from' => $me['role'], 'ip' => client_ip()]);
            flash('success', 'Development shortcut used: you are now an administrator. This was recorded in the audit log.');
        }
    } catch (UserError $ex) {
        flash('error', $ex->getMessage());
    }
    redirect(url('account'));
}

$account = find_user($pdo, $me['id']);
$recent = db_all($pdo, 'SELECT action, ip, created_at FROM audit_log WHERE user_id = ? AND action LIKE ? ORDER BY id DESC LIMIT 8', [$me['id'], 'auth.%']);
require __DIR__ . '/../src/layout/header.php';
?>
<?php if ((int)$account['must_change_password'] === 1): ?>
  <div class="alert alert-error"><strong>Password change required.</strong> Your password was set by an administrator. Choose a new private password to continue.</div>
<?php endif; ?>
<div class="content-grid">
<section class="panel">
  <div class="panel-heading"><div><h3>Profile</h3><p>Your details as shown to your team.</p></div><span class="role-pill role-<?= e($account['role']) ?>"><?= e(role_label($account['role'])) ?></span></div>
  <form method="post" class="stack-form"><?= csrf_field() ?><input type="hidden" name="action" value="profile">
    <label>Full name<input name="full_name" required maxlength="120" value="<?= e($account['full_name']) ?>"></label>
    <label>Email address<input type="email" value="<?= e($account['email']) ?>" readonly class="readonly-input"></label>
    <p class="muted small">Email changes are made by an administrator. Last sign-in: <?= e(datetime_label($account['last_login_at'])) ?>.</p>
    <button class="btn btn-primary" type="submit">Save profile</button>
  </form>
</section>
<section class="panel">
  <div class="panel-heading"><div><h3>Change password</h3><p>At least <?= PASSWORD_MIN_LENGTH ?> characters. Other devices will be signed out.</p></div></div>
  <form method="post" class="stack-form"><?= csrf_field() ?><input type="hidden" name="action" value="password">
    <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>New password<input type="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password"></label>
    <label>Confirm new password<input type="password" name="confirm_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password"></label>
    <button class="btn btn-primary" type="submit">Change password</button>
  </form>
</section>
</div>
<?php if ($account['role'] !== 'admin' && dev_elevation_allowed()): ?>
<section class="panel dev-panel">
  <div class="panel-heading"><div><h3>Development shortcut: become administrator</h3>
    <p>Shown only because <code>ALLOW_DEV_ADMIN_ELEVATION=true</code>, <code>APP_ENV=local</code> and you are browsing from this machine. Every use is audited.</p></div></div>
  <form method="post" onsubmit="return confirm('Promote your own account to administrator?');"><?= csrf_field() ?><input type="hidden" name="action" value="dev_elevate">
    <button class="btn btn-outline" type="submit">Make me an administrator</button></form>
</section>
<?php endif; ?>
<section class="panel">
  <div class="panel-heading"><div><h3>Recent sign-in activity</h3><p>Review this list for anything you do not recognise.</p></div></div>
  <?php if (!$recent): ?><div class="empty-inline">No activity recorded yet.</div><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Event</th><th>IP address</th><th>When</th></tr></thead><tbody>
  <?php foreach ($recent as $r): ?><tr><td><?= e(audit_action_label($r['action'])) ?></td><td><?= e($r['ip']) ?></td><td><?= e(datetime_label($r['created_at'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</section>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
