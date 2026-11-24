<?php
/** @var PDO $pdo */
require_permission('admin.access');
$pageTitle = 'Administration';
$tabs = ['users' => 'Users & roles', 'health' => 'System health', 'audit' => 'Audit log', 'backups' => 'Backups & exports'];
$tab = get_str('tab', 'users');
if (!isset($tabs[$tab])) {
    $tab = 'users';
}
$back = fn(string $t = '') => url('admin', ['tab' => $t ?: $tab]);

/* ------------------------------------------------------------------ actions */
if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $targetId = (int)($_POST['id'] ?? 0);
    try {
        switch ($action) {
            case 'create_user':
                $generated = post_str('password') === '';
                $password = $generated ? generate_password() : (string)$_POST['password'];
                $role = post_str('role', 'worker');
                $created = create_user($pdo, post_str('full_name'), post_str('email'), $password, in_array($role, ROLES, true) ? $role : 'worker', isset($_POST['must_change']));
                audit('admin.user_created', 'user', (int)$created['id'], ['email' => $created['email'], 'role' => $created['role']]);
                flash('success', "Account created for {$created['email']}." . ($generated ? " Temporary password: $password — share it securely; it is not shown again." : ' Share the temporary password securely.'));
                break;
            case 'change_role':
                if ($targetId === user_id()) {
                    throw new UserError('You cannot change your own role. Ask another administrator.');
                }
                $new = post_str('role');
                $old = change_user_role($pdo, $targetId, $new);
                audit('admin.role_changed', 'user', $targetId, ['from' => $old, 'to' => $new]);
                flash('success', 'Role updated to ' . role_label($new) . '.');
                break;
            case 'toggle_active':
                if ($targetId === user_id()) {
                    throw new UserError('You cannot deactivate your own account.');
                }
                $activate = post_str('active') === '1';
                set_user_active($pdo, $targetId, $activate);
                audit($activate ? 'admin.user_activated' : 'admin.user_deactivated', 'user', $targetId);
                flash('success', $activate ? 'Account re-activated.' : 'Account deactivated. The user has been signed out.');
                break;
            case 'set_password':
                $password = post_str('new_password') === '' ? generate_password() : (string)$_POST['new_password'];
                set_user_password($pdo, $targetId, $password, true);
                audit('admin.password_set', 'user', $targetId);
                flash('success', "Temporary password set: $password — the user must change it at next sign-in. Share it securely.");
                break;
            case 'reset_link':
                $target = find_user($pdo, $targetId) ?? throw new UserError('User not found.');
                $link = reset_link(create_password_reset($pdo, $targetId, user_id(), client_ip()));
                audit('admin.reset_link_created', 'user', $targetId);
                flash('success', "Single-use reset link for {$target['email']} (valid " . config_int('RESET_TOKEN_TTL_MINUTES', 60) . " minutes): $link");
                break;
            case 'create_backup':
                $file = create_backup($pdo);
                $keep = (int)($_POST['keep'] ?? 0);
                $pruned = $keep > 0 ? prune_backups($keep) : [];
                audit('backup.created', null, null, ['file' => basename($file), 'pruned' => count($pruned)]);
                flash('success', 'Backup created: ' . basename($file) . ($pruned ? ' (' . count($pruned) . ' old backups removed)' : '') . '.');
                break;
            case 'delete_backup':
                $name = basename(post_str('file'));
                foreach (list_backups() as $b) {
                    if ($b['name'] === $name && @unlink($b['path'])) {
                        audit('backup.deleted', null, null, ['file' => $name]);
                        flash('success', "Deleted $name.");
                    }
                }
                break;
            default:
                throw new UserError('Unknown action.');
        }
    } catch (UserError $ex) {
        flash('error', $ex->getMessage());
    }
    redirect($back());
}

/* ------------------------------------------------------------------ downloads */
$download = get_str('download');
if ($download === 'snapshot') {
    $file = create_backup($pdo);
    audit('backup.downloaded', null, null, ['file' => basename($file)]);
    header('Content-Type: application/vnd.sqlite3');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;
}
if ($download === 'backup') {
    $name = basename(get_str('file'));
    foreach (list_backups() as $b) {
        if ($b['name'] === $name) {
            audit('backup.downloaded', null, null, ['file' => $name]);
            header('Content-Type: application/vnd.sqlite3');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . $b['size']);
            readfile($b['path']);
            exit;
        }
    }
    forbidden();
}
$exports = [
    'projects' => ['SELECT p.id, p.name, p.location, p.status, p.start_date, p.end_date, p.budget, u.full_name AS manager, p.created_at, p.updated_at FROM projects p LEFT JOIN users u ON u.id = p.manager_id ORDER BY p.id',
        ['ID', 'Name', 'Location', 'Status', 'Start', 'End', 'Budget', 'Manager', 'Created (UTC)', 'Updated (UTC)']],
    'tasks' => ['SELECT t.id, p.name, t.title, u.full_name, t.start_date, t.due_date, t.status, t.priority, t.progress_percent, t.updated_at FROM tasks t JOIN projects p ON p.id = t.project_id LEFT JOIN users u ON u.id = t.assigned_to ORDER BY t.id',
        ['ID', 'Project', 'Task', 'Assignee', 'Start', 'Due', 'Status', 'Priority', 'Progress %', 'Updated (UTC)']],
    'resources' => ['SELECT r.id, r.name, r.resource_type, r.unit, r.quantity_available, COALESCE((SELECT SUM(quantity_assigned) FROM task_resources tr WHERE tr.resource_id = r.id), 0), r.low_stock_threshold FROM resources r ORDER BY r.id',
        ['ID', 'Name', 'Type', 'Unit', 'Available', 'Allocated', 'Low-stock threshold']],
    'expenses' => ['SELECT x.id, p.name, x.spent_on, x.category, x.description, x.vendor, x.amount, u.full_name FROM expenses x JOIN projects p ON p.id = x.project_id JOIN users u ON u.id = x.recorded_by ORDER BY x.spent_on, x.id',
        ['ID', 'Project', 'Date', 'Category', 'Description', 'Vendor', 'Amount', 'Recorded by']],
    'site_logs' => ['SELECT s.id, p.name, s.log_date, s.weather, s.crew_count, s.work_done, s.issues, s.safety_notes, u.full_name FROM site_logs s JOIN projects p ON p.id = s.project_id JOIN users u ON u.id = s.created_by ORDER BY s.log_date, s.id',
        ['ID', 'Project', 'Date', 'Weather', 'Crew', 'Work done', 'Issues', 'Safety notes', 'Author']],
    'users' => ['SELECT id, full_name, email, role, CASE is_active WHEN 1 THEN \'active\' ELSE \'inactive\' END, last_login_at, created_at FROM users ORDER BY id',
        ['ID', 'Name', 'Email', 'Role', 'Status', 'Last login (UTC)', 'Created (UTC)']],
];

/* ------------------------------------------------------------------ audit filters (shared by view + CSV) */
$auditQ = get_str('q');
$auditUser = (int)get_str('user_id', '0');
$auditFrom = get_str('from');
$auditTo = get_str('to');
$auditWhere = ' WHERE 1=1';
$auditParams = [];
if ($auditQ !== '') {
    $auditWhere .= ' AND (a.action LIKE ? OR a.entity_type LIKE ? OR a.details LIKE ?)';
    array_push($auditParams, "%$auditQ%", "%$auditQ%", "%$auditQ%");
}
if ($auditUser > 0) {
    $auditWhere .= ' AND a.user_id = ?';
    $auditParams[] = $auditUser;
}
if ($auditFrom !== '' && valid_date($auditFrom)) {
    $auditWhere .= ' AND a.created_at >= ?';
    $auditParams[] = $auditFrom . ' 00:00:00';
}
if ($auditTo !== '' && valid_date($auditTo)) {
    $auditWhere .= ' AND a.created_at <= ?';
    $auditParams[] = $auditTo . ' 23:59:59';
}
$auditSql = "SELECT a.*, u.full_name, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id $auditWhere";

$export = get_str('export');
if ($export === 'audit') {
    $rows = db_all($pdo, "$auditSql ORDER BY a.id DESC LIMIT 50000", $auditParams);
    send_csv('buildtrack-audit-' . date('Y-m-d') . '.csv', ['ID', 'When (UTC)', 'User', 'Action', 'Entity', 'Entity ID', 'Details', 'IP'],
        array_map(fn($r) => [$r['id'], $r['created_at'], $r['email'] ?? 'system', $r['action'], $r['entity_type'], $r['entity_id'], $r['details'], $r['ip']], $rows));
}
if (isset($exports[$export])) {
    [$sql, $header] = $exports[$export];
    audit('export.csv', $export);
    send_csv("buildtrack-$export-" . date('Y-m-d') . '.csv', $header, $pdo->query($sql)->fetchAll(PDO::FETCH_NUM));
}

/* ------------------------------------------------------------------ view data */
$userQ = get_str('uq');
$roleFilter = get_str('role');
$users = [];
$allUsers = db_all($pdo, 'SELECT id, full_name FROM users ORDER BY full_name');
if ($tab === 'users') {
    $sql = "SELECT u.*, (SELECT COUNT(*) FROM tasks t WHERE t.assigned_to = u.id AND t.status <> 'completed') AS open_tasks FROM users u WHERE 1=1";
    $params = [];
    if ($userQ !== '') {
        $sql .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
        array_push($params, "%$userQ%", "%$userQ%");
    }
    if (in_array($roleFilter, ROLES, true)) {
        $sql .= ' AND u.role = ?';
        $params[] = $roleFilter;
    }
    $sql .= " ORDER BY u.is_active DESC, CASE u.role WHEN 'admin' THEN 0 WHEN 'project_manager' THEN 1 WHEN 'site_supervisor' THEN 2 ELSE 3 END, u.full_name";
    $users = db_all($pdo, $sql, $params);
}
$health = $tab === 'health' ? system_health($pdo, get_str('full') === '1') : null;
$auditPage = max(1, (int)get_str('p', '1'));
$auditRows = [];
$auditTotal = 0;
if ($tab === 'audit') {
    $auditTotal = (int)db_value($pdo, "SELECT COUNT(*) FROM audit_log a $auditWhere", $auditParams);
    $auditRows = db_all($pdo, "$auditSql ORDER BY a.id DESC LIMIT 50 OFFSET " . (($auditPage - 1) * 50), $auditParams);
}
$backups = $tab === 'backups' ? list_backups() : [];

require __DIR__ . '/../src/layout/header.php';
?>
<?php foreach (config_warnings() as $w): ?><div class="alert alert-error"><strong>Configuration warning:</strong> <?= e($w) ?></div><?php endforeach; ?>
<nav class="tabs" aria-label="Administration sections">
  <?php foreach ($tabs as $key => $label): ?><a class="tab <?= $tab === $key ? 'selected' : '' ?>" href="<?= e(url('admin', ['tab' => $key])) ?>"><?= e($label) ?></a><?php endforeach; ?>
</nav>

<?php if ($tab === 'users'): ?>
<section class="panel form-panel">
  <div class="panel-heading"><div><h3>Create a user account</h3><p>Leave the password blank to generate one. Give each person only the access their role needs.</p></div></div>
  <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create_user">
    <label>Full name *<input name="full_name" maxlength="120" required></label>
    <label>Email address *<input type="email" name="email" maxlength="190" required></label>
    <label>Temporary password<input type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" placeholder="Blank = generate"></label>
    <label>Role<select name="role"><?php foreach (ROLES as $r): ?><option value="<?= e($r) ?>" <?= $r === 'worker' ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?></select></label>
    <label class="checkbox span-2"><input type="checkbox" name="must_change" value="1" checked> Require a password change at first sign-in</label>
    <div class="form-actions span-2"><button class="btn btn-primary">Create account</button></div>
  </form>
</section>
<section class="panel">
  <div class="panel-heading"><div><h3>Accounts</h3><p>Roles: Administrator (everything) · Project Manager (projects, budgets) · Site Supervisor (tasks, resources, site logs) · Worker (own tasks).</p></div><span class="count-chip"><?= count($users) ?></span></div>
  <form method="get" class="filter-bar"><input type="hidden" name="page" value="admin"><input type="hidden" name="tab" value="users">
    <input name="uq" value="<?= e($userQ) ?>" placeholder="Search name or email">
    <select name="role"><option value="">All roles</option><?php foreach (ROLES as $r): ?><option value="<?= e($r) ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?></select>
    <button class="btn btn-outline">Filter</button><a class="text-link" href="<?= e(url('admin')) ?>">Reset</a></form>
  <div class="table-wrap"><table><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Open tasks</th><th>Last sign-in</th><th>Password</th></tr></thead><tbody>
  <?php foreach ($users as $u): $self = (int)$u['id'] === user_id(); ?>
    <tr class="<?= (int)$u['is_active'] ? '' : 'row-muted' ?>">
      <td><strong><?= e($u['full_name']) ?></strong><small class="cell-sub"><?= e($u['email']) ?></small></td>
      <td><?php if ($self): ?><span class="role-pill role-<?= e($u['role']) ?>"><?= e(role_label($u['role'])) ?></span><small class="cell-sub">You</small>
        <?php else: ?><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="change_role"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <select name="role" aria-label="Role for <?= e($u['full_name']) ?>"><?php foreach (ROLES as $r): ?><option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?></select>
          <button class="action-link">Save</button></form><?php endif; ?></td>
      <td><?php if ((int)$u['is_active']): ?><span class="status status-completed">Active</span><?php else: ?><span class="status status-blocked">Deactivated</span><?php endif; ?>
        <?php if ((int)$u['must_change_password']): ?><small class="cell-sub">Must change password</small><?php endif; ?>
        <?php if (!$self): ?><form method="post" class="inline-form" onsubmit="return confirm('<?= (int)$u['is_active'] ? 'Deactivate this account? The user is signed out immediately.' : 'Re-activate this account?' ?>');"><?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="active" value="<?= (int)$u['is_active'] ? '0' : '1' ?>">
          <button class="action-link <?= (int)$u['is_active'] ? 'danger-link' : '' ?>"><?= (int)$u['is_active'] ? 'Deactivate' : 'Activate' ?></button></form><?php endif; ?></td>
      <td><?= (int)$u['open_tasks'] ?></td>
      <td><?= e(datetime_label($u['last_login_at'])) ?></td>
      <td><?php if ($self): ?><a class="action-link" href="<?= e(url('account')) ?>">Change in My account</a><?php else: ?>
        <form method="post" class="inline-form" onsubmit="return confirm('Set a temporary password for this user?');"><?= csrf_field() ?><input type="hidden" name="action" value="set_password"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input type="password" name="new_password" minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="Blank = generate" aria-label="Temporary password" autocomplete="new-password"><button class="action-link">Set temporary</button></form>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="reset_link"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="action-link">Create reset link</button></form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
<div class="alert alert-info"><strong>Admin access, the easy way:</strong> the first registered account becomes admin; <code>ADMIN_EMAIL</code>/<code>ADMIN_PASSWORD</code> in <code>.env</code> create one at startup; and on the server <code>php bin/console make-admin user@example.com</code> promotes anyone. The last active admin can never be demoted or deactivated.</div>

<?php elseif ($tab === 'health'): ?>
<div class="content-grid">
<section class="panel">
  <div class="panel-heading"><div><h3>Checks</h3><p>Quick check runs on every visit; run the full integrity check occasionally.</p></div><a class="btn btn-outline" href="<?= e(url('admin', ['tab' => 'health', 'full' => '1'])) ?>">Run full integrity check</a></div>
  <div class="table-wrap"><table><tbody>
  <?php foreach ($health['checks'] as [$label, $value, $ok]): ?>
    <tr><td><span class="health-dot <?= $ok ? 'ok' : 'bad' ?>"></span><?= e($label) ?></td><td><?= e($value) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
<section class="panel">
  <div class="panel-heading"><div><h3>Environment</h3><p>Runtime facts.</p></div></div>
  <dl class="facts"><?php foreach ($health['facts'] as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd><?php endforeach; ?></dl>
</section>
</div>
<section class="panel"><div class="panel-heading"><div><h3>Records per table</h3></div></div>
  <div class="facts-grid"><?php foreach ($health['tables'] as $t => $n): ?><div class="fact-tile"><span><?= e(str_replace('_', ' ', $t)) ?></span><strong><?= $n ?></strong></div><?php endforeach; ?></div>
</section>

<?php elseif ($tab === 'audit'): ?>
<section class="panel">
  <div class="panel-heading"><div><h3>Audit log</h3><p>Who changed what, and when. Times shown in <?= e(date_default_timezone_get()) ?>.</p></div>
    <a class="btn btn-outline" href="<?= e(url('admin', ['tab' => 'audit', 'export' => 'audit', 'q' => $auditQ, 'user_id' => $auditUser ?: null, 'from' => $auditFrom, 'to' => $auditTo])) ?>">↓ Export CSV</a></div>
  <form method="get" class="filter-bar"><input type="hidden" name="page" value="admin"><input type="hidden" name="tab" value="audit">
    <input name="q" value="<?= e($auditQ) ?>" placeholder="Action, entity or detail (e.g. auth.login_failed)">
    <select name="user_id"><option value="0">All users</option><?php foreach ($allUsers as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $auditUser === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e($auditFrom) ?>" aria-label="From date"><input type="date" name="to" value="<?= e($auditTo) ?>" aria-label="To date">
    <button class="btn btn-outline">Filter</button><a class="text-link" href="<?= e(url('admin', ['tab' => 'audit'])) ?>">Reset</a></form>
  <?php if (!$auditRows): ?><div class="empty-inline">No audit entries match.</div><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($auditRows as $a): ?>
    <tr><td><?= e(datetime_label($a['created_at'])) ?></td><td><?= e($a['full_name'] ?? 'System') ?></td><td><code><?= e($a['action']) ?></code></td>
      <td><?= e(($a['entity_type'] ?? '') . ($a['entity_id'] ? ' #' . $a['entity_id'] : '')) ?: '—' ?></td>
      <td class="details-cell"><?= e($a['details'] ? str_limit((string)$a['details'], 300) : '—') ?></td><td><?= e($a['ip']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="pager"><span><?= $auditTotal ?> entries</span>
    <?php if ($auditPage > 1): ?><a class="text-link" href="<?= e(url('admin', ['tab' => 'audit', 'q' => $auditQ, 'user_id' => $auditUser ?: null, 'from' => $auditFrom, 'to' => $auditTo, 'p' => $auditPage - 1])) ?>">← Newer</a><?php endif; ?>
    <?php if ($auditPage * 50 < $auditTotal): ?><a class="text-link" href="<?= e(url('admin', ['tab' => 'audit', 'q' => $auditQ, 'user_id' => $auditUser ?: null, 'from' => $auditFrom, 'to' => $auditTo, 'p' => $auditPage + 1])) ?>">Older →</a><?php endif; ?>
  </div><?php endif; ?>
</section>

<?php else: ?>
<div class="content-grid">
<section class="panel">
  <div class="panel-heading"><div><h3>Database backups</h3><p>Consistent SQLite snapshots (<code>VACUUM INTO</code>) saved in <code>data/backups/</code>. Safe while the app is running.</p></div></div>
  <div class="button-row">
    <a class="btn btn-primary" href="<?= e(url('admin', ['tab' => 'backups', 'download' => 'snapshot'])) ?>">↓ Download a fresh backup</a>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="create_backup">
      <label class="inline-label">Keep newest<input type="number" name="keep" min="0" value="14" class="narrow-input"></label><button class="btn btn-outline">Save backup on server</button></form>
  </div>
  <?php if (!$backups): ?><div class="empty-inline">No backups stored on the server yet.</div><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>File</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($backups as $b): ?><tr><td><?= e($b['name']) ?></td><td><?= e(human_bytes($b['size'])) ?></td><td><?= e(date('d M Y, H:i', $b['mtime'])) ?></td>
    <td class="actions"><a class="action-link" href="<?= e(url('admin', ['tab' => 'backups', 'download' => 'backup', 'file' => $b['name']])) ?>">Download</a>
      <form method="post" onsubmit="return confirm('Delete this backup file?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_backup"><input type="hidden" name="file" value="<?= e($b['name']) ?>"><button class="action-link danger-link">Delete</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <p class="muted small">To restore, stop the app and run <code>php bin/console restore data/backups/&lt;file&gt;.sqlite</code>. The current database is backed up automatically first.</p>
</section>
<section class="panel">
  <div class="panel-heading"><div><h3>CSV exports</h3><p>Open in Excel, LibreOffice or Google Sheets.</p></div></div>
  <div class="export-list"><?php foreach (array_keys($exports) as $x): ?><a class="btn btn-outline" href="<?= e(url('admin', ['tab' => 'backups', 'export' => $x])) ?>">↓ <?= e(ucwords(str_replace('_', ' ', $x))) ?></a><?php endforeach; ?></div>
</section>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
