<?php
/** @var PDO $pdo */
$pageTitle = 'Projects';

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'delete') {
        require_permission('projects.delete');
        $before = db_one($pdo, 'SELECT * FROM projects WHERE id = ?', [$id]);
        if ($before) {
            $files = attachments_files_for($pdo, 'project', $id);
            db_exec($pdo, 'DELETE FROM projects WHERE id = ?', [$id]);
            attachments_unlink($files);
            audit('project.deleted', 'project', $id, ['name' => $before['name']]);
        }
        flash('success', 'Project deleted. Related tasks, expenses, site logs and files were also removed.');
        redirect(url('projects'));
    }
    if (in_array($action, ['create', 'update'], true)) {
        require_permission('projects.manage');
        $back = url('projects', ['edit' => $id ?: null]);
        $data = [
            'name' => post_str('name'),
            'description' => post_str('description') ?: null,
            'location' => post_str('location') ?: null,
            'start_date' => post_str('start_date') ?: null,
            'end_date' => post_str('end_date') ?: null,
            'budget' => post_str('budget') !== '' ? post_str('budget') : null,
            'status' => post_str('status', 'planned'),
            'manager_id' => post_str('manager_id') !== '' ? (int)$_POST['manager_id'] : null,
        ];
        $error = match (true) {
            $data['name'] === '' || strlen($data['name']) > 180 => 'Project name is required (up to 180 characters).',
            ($data['start_date'] && !valid_date($data['start_date'])) || ($data['end_date'] && !valid_date($data['end_date'])) => 'Enter valid dates.',
            $data['start_date'] && $data['end_date'] && $data['start_date'] > $data['end_date'] => 'Project end date must be on or after the start date.',
            $data['budget'] !== null && (!is_numeric($data['budget']) || (float)$data['budget'] < 0) => 'Budget must be zero or a positive amount.',
            !in_array($data['status'], PROJECT_STATUSES, true) => 'Choose a valid status.',
            default => null,
        };
        if ($error) {
            flash('error', $error);
            redirect($back);
        }
        if ($data['budget'] !== null) {
            $data['budget'] = round((float)$data['budget'], 2);
        }
        if ($data['manager_id'] !== null && !db_value($pdo, "SELECT id FROM users WHERE id = ? AND role IN ('admin','project_manager') AND is_active = 1", [$data['manager_id']])) {
            $data['manager_id'] = null;
        }
        if ($action === 'create') {
            db_exec($pdo, 'INSERT INTO projects (name, description, location, start_date, end_date, budget, status, manager_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [...array_values($data), user_id()]);
            $newId = (int)$pdo->lastInsertId();
            audit('project.created', 'project', $newId, ['name' => $data['name']]);
            flash('success', 'Project created. Add tasks, expenses and site logs from the project page.');
            redirect(url('project', ['id' => $newId]));
        }
        $before = db_one($pdo, 'SELECT * FROM projects WHERE id = ?', [$id]);
        if (!$before) {
            flash('error', 'Project not found.');
            redirect(url('projects'));
        }
        db_exec($pdo, 'UPDATE projects SET name=?, description=?, location=?, start_date=?, end_date=?, budget=?, status=?, manager_id=? WHERE id=?', [...array_values($data), $id]);
        audit('project.updated', 'project', $id, audit_diff($before, $data));
        flash('success', 'Project details updated.');
        redirect(url('project', ['id' => $id]));
    }
}

$edit = null;
if (isset($_GET['edit']) && can('projects.manage')) {
    $edit = db_one($pdo, 'SELECT * FROM projects WHERE id = ?', [(int)$_GET['edit']]);
}
$managers = db_all($pdo, "SELECT id, full_name FROM users WHERE role IN ('admin','project_manager') AND is_active = 1 ORDER BY full_name");
$search = get_str('q');
$statusFilter = get_str('status');
$managerFilter = (int)get_str('manager_id', '0');
[$scope, $params] = current_project_access_sql();
$sql = "SELECT p.*, u.full_name AS manager_name,
        (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) AS task_count,
        (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.status = 'completed') AS task_done,
        (SELECT COALESCE(SUM(amount), 0) FROM expenses x WHERE x.project_id = p.id) AS spent
    FROM projects p LEFT JOIN users u ON u.id = p.manager_id WHERE 1=1 $scope";
if ($search !== '') {
    $sql .= ' AND (p.name LIKE ? OR p.location LIKE ? OR p.description LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
if (in_array($statusFilter, PROJECT_STATUSES, true)) {
    $sql .= ' AND p.status = ?';
    $params[] = $statusFilter;
}
if ($managerFilter > 0) {
    $sql .= ' AND p.manager_id = ?';
    $params[] = $managerFilter;
}
$projects = db_all($pdo, $sql . ' ORDER BY p.updated_at DESC', $params);
$showMoney = can('expenses.view');
require __DIR__ . '/../src/layout/header.php';
?>
<div class="page-toolbar"><div><p class="muted">Organise projects, ownership, budgets and target dates.</p></div><div class="toolbar-count"><strong><?= count($projects) ?></strong> project<?= count($projects) === 1 ? '' : 's' ?></div></div>
<?php if (can('projects.manage')): ?>
<details class="panel form-panel" <?= $edit || !$projects ? 'open' : '' ?>>
<summary class="panel-heading"><div><h3><?= $edit ? 'Edit project: ' . e($edit['name']) : '+ Create a project' ?></h3><p><?= $edit ? 'Update the project information below.' : 'Add a project to start scheduling tasks.' ?></p></div></summary>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<label class="span-2">Project name *<input required name="name" maxlength="180" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. Riverside Apartment Block"></label>
<label>Location<input name="location" maxlength="180" value="<?= e($edit['location'] ?? '') ?>" placeholder="Site location"></label>
<label>Project manager<select name="manager_id"><option value="">Not assigned</option><?php foreach ($managers as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (string)($edit['manager_id'] ?? '') === (string)$m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option><?php endforeach; ?></select></label>
<label>Start date<input type="date" name="start_date" value="<?= e($edit['start_date'] ?? '') ?>"></label><label>Target end date<input type="date" name="end_date" value="<?= e($edit['end_date'] ?? '') ?>"></label>
<label>Budget (<?= e(config('CURRENCY', 'KSh')) ?>)<input type="number" min="0" step="0.01" name="budget" value="<?= e($edit['budget'] ?? '') ?>" placeholder="Optional"></label><label>Status<select name="status"><?php foreach (PROJECT_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= ($edit['status'] ?? 'planned') === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></label>
<label class="span-2">Description<textarea name="description" rows="2" placeholder="Project purpose and notes"><?= e($edit['description'] ?? '') ?></textarea></label><div class="form-actions span-2"><button class="btn btn-primary" type="submit"><?= $edit ? 'Save changes' : 'Create project' ?></button><?php if ($edit): ?><a class="btn btn-outline" href="<?= e(url('projects')) ?>">Cancel</a><?php endif; ?></div></form></details>
<?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h3>Project register</h3><p>Search and review project status.</p></div></div>
<form method="get" class="filter-bar"><input type="hidden" name="page" value="projects"><input name="q" value="<?= e($search) ?>" placeholder="Search name, location or description">
<select name="status"><option value="">All statuses</option><?php foreach (PROJECT_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select>
<select name="manager_id"><option value="0">All managers</option><?php foreach ($managers as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $managerFilter === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['full_name']) ?></option><?php endforeach; ?></select>
<button class="btn btn-outline">Filter</button><a class="text-link" href="<?= e(url('projects')) ?>">Reset</a></form>
<?php if (!$projects): ?><div class="empty-state"><div class="empty-icon">▦</div><h3>No projects found</h3><p><?= $search || $statusFilter || $managerFilter ? 'Change the search filters.' : (can('projects.manage') ? 'Create your first project above.' : 'Projects appear here once you are assigned tasks.') ?></p></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Project</th><th>Manager</th><th>Schedule</th><th>Tasks</th><?php if ($showMoney): ?><th>Spent / budget</th><?php endif; ?><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($projects as $p): $pct = ($p['budget'] !== null && (float)$p['budget'] > 0) ? (float)$p['spent'] / (float)$p['budget'] * 100 : null; ?>
<tr><td><a class="row-link" href="<?= e(url('project', ['id' => $p['id']])) ?>"><strong><?= e($p['name']) ?></strong></a><small class="cell-sub"><?= e($p['location'] ?: 'Location not set') ?></small></td><td><?= e($p['manager_name'] ?: 'Unassigned') ?></td><td><?= e(date_label($p['start_date'])) ?><small class="cell-sub">to <?= e(date_label($p['end_date'])) ?></small></td>
<td><span class="count-chip"><?= (int)$p['task_done'] ?>/<?= (int)$p['task_count'] ?></span></td>
<?php if ($showMoney): ?><td><span class="<?= $pct !== null && $pct > 100 ? 'text-danger' : '' ?>"><?= e(money($p['spent'])) ?></span><small class="cell-sub"><?= $p['budget'] !== null ? 'of ' . e(money($p['budget'])) . ($pct !== null ? ' · ' . (int)round($pct) . '%' : '') : 'No budget set' ?></small></td><?php endif; ?>
<td><span class="status status-<?= e($p['status']) ?>"><?= e(status_label($p['status'])) ?></span></td>
<td class="actions"><a class="action-link" href="<?= e(url('project', ['id' => $p['id']])) ?>">Open</a><a class="action-link" href="<?= e(url('tasks', ['project_id' => $p['id']])) ?>">Tasks</a><?php if (can('projects.manage')): ?><a class="action-link" href="<?= e(url('projects', ['edit' => $p['id']])) ?>">Edit</a><?php endif; ?><?php if (can('projects.delete')): ?><form method="post" onsubmit="return confirm('Delete this project and ALL its tasks, expenses, site logs and files? This cannot be undone.');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="action-link danger-link" type="submit">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
