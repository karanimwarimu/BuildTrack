<?php
/** @var PDO $pdo */
$pageTitle = 'Tasks & schedule';

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'delete') {
        require_permission('tasks.delete');
        $before = db_one($pdo, 'SELECT * FROM tasks WHERE id = ?', [$id]);
        if ($before) {
            $files = attachments_files_for($pdo, 'task', $id);
            db_exec($pdo, 'DELETE FROM tasks WHERE id = ?', [$id]);
            attachments_unlink($files);
            audit('task.deleted', 'task', $id, ['title' => $before['title'], 'project_id' => (int)$before['project_id']]);
        }
        flash('success', 'Task deleted.');
        redirect(url('tasks'));
    }
    if (in_array($action, ['create', 'update'], true)) {
        require_permission('tasks.manage');
        $back = url('tasks', ['edit' => $id ?: null]);
        $data = [
            'project_id' => (int)($_POST['project_id'] ?? 0),
            'title' => post_str('title'),
            'description' => post_str('description') ?: null,
            'start_date' => post_str('start_date') ?: null,
            'due_date' => post_str('due_date') ?: null,
            'assigned_to' => post_str('assigned_to') !== '' ? (int)$_POST['assigned_to'] : null,
            'status' => post_str('status', 'pending'),
            'priority' => post_str('priority', 'medium'),
        ];
        $error = match (true) {
            $data['title'] === '' || strlen($data['title']) > 180 || $data['project_id'] < 1 => 'Choose a project and enter a task title (up to 180 characters).',
            ($data['start_date'] && !valid_date($data['start_date'])) || ($data['due_date'] && !valid_date($data['due_date'])) => 'Enter valid dates.',
            $data['start_date'] && $data['due_date'] && $data['start_date'] > $data['due_date'] => 'Task due date must be on or after the start date.',
            !db_value($pdo, 'SELECT id FROM projects WHERE id = ?', [$data['project_id']]) => 'Selected project was not found.',
            default => null,
        };
        if ($error) {
            flash('error', $error);
            redirect($back);
        }
        if ($data['assigned_to'] !== null && !db_value($pdo, 'SELECT id FROM users WHERE id = ? AND is_active = 1', [$data['assigned_to']])) {
            $data['assigned_to'] = null;
        }
        if (!in_array($data['status'], TASK_STATUSES, true)) {
            $data['status'] = 'pending';
        }
        if (!in_array($data['priority'], TASK_PRIORITIES, true)) {
            $data['priority'] = 'medium';
        }
        if ($action === 'create') {
            db_exec($pdo, 'INSERT INTO tasks (project_id, title, description, start_date, due_date, assigned_to, status, priority, progress_percent, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [...array_values($data), $data['status'] === 'completed' ? 100 : 0, user_id()]);
            $newId = (int)$pdo->lastInsertId();
            audit('task.created', 'task', $newId, ['title' => $data['title'], 'project_id' => $data['project_id'], 'assigned_to' => $data['assigned_to']]);
            flash('success', 'Task added to the schedule.');
        } else {
            $before = db_one($pdo, 'SELECT * FROM tasks WHERE id = ?', [$id]);
            if (!$before) {
                flash('error', 'Task not found.');
                redirect(url('tasks'));
            }
            db_exec($pdo, "UPDATE tasks SET project_id=?, title=?, description=?, start_date=?, due_date=?, assigned_to=?, status=?, priority=?,
                progress_percent = CASE WHEN ? = 'completed' THEN 100 ELSE progress_percent END WHERE id=?", [...array_values($data), $data['status'], $id]);
            audit('task.updated', 'task', $id, audit_diff($before, $data));
            flash('success', 'Task details updated.');
        }
        redirect(url('tasks', ['project_id' => $data['project_id']]));
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    require_permission('tasks.manage');
    $edit = db_one($pdo, 'SELECT * FROM tasks WHERE id = ?', [(int)$_GET['edit']]);
}
$projects = visible_projects($pdo);
$users = db_all($pdo, 'SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name');

$projectFilter = (int)get_str('project_id', '0');
$statusFilter = get_str('status');
$priorityFilter = get_str('priority');
$assigneeFilter = get_str('assignee');
$overdueOnly = get_str('overdue') === '1';
$q = get_str('q');
[$scope, $params] = current_task_access_sql();
$sql = "SELECT t.*, p.name AS project_name, u.full_name AS assignee_name FROM tasks t JOIN projects p ON p.id = t.project_id LEFT JOIN users u ON u.id = t.assigned_to WHERE 1=1 $scope";
if ($projectFilter > 0) {
    $sql .= ' AND t.project_id = ?';
    $params[] = $projectFilter;
}
if (in_array($statusFilter, TASK_STATUSES, true)) {
    $sql .= ' AND t.status = ?';
    $params[] = $statusFilter;
} elseif ($statusFilter === 'open') {
    $sql .= " AND t.status <> 'completed'";
}
if (in_array($priorityFilter, TASK_PRIORITIES, true)) {
    $sql .= ' AND t.priority = ?';
    $params[] = $priorityFilter;
}
if ($assigneeFilter === 'none') {
    $sql .= ' AND t.assigned_to IS NULL';
} elseif ($assigneeFilter === 'me') {
    $sql .= ' AND t.assigned_to = ?';
    $params[] = user_id();
} elseif ((int)$assigneeFilter > 0) {
    $sql .= ' AND t.assigned_to = ?';
    $params[] = (int)$assigneeFilter;
}
if ($overdueOnly) {
    $sql .= " AND t.due_date < app_today() AND t.status <> 'completed'";
}
if ($q !== '') {
    $sql .= ' AND (t.title LIKE ? OR t.description LIKE ? OR p.name LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
$sql .= " ORDER BY CASE WHEN t.status = 'completed' THEN 2 WHEN t.due_date < app_today() THEN 0 ELSE 1 END, t.due_date IS NULL, t.due_date ASC,
          CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END";
$tasks = db_all($pdo, $sql, $params);
$filtered = $projectFilter || $statusFilter || $priorityFilter || $assigneeFilter || $overdueOnly || $q !== '';
require __DIR__ . '/../src/layout/header.php';
?>
<div class="page-toolbar"><p class="muted">Plan work, assign responsibility and monitor each task against its deadline.</p><div class="toolbar-count"><strong><?= count($tasks) ?></strong> task<?= count($tasks) === 1 ? '' : 's' ?></div></div>
<?php if (can_manage_tasks()): ?>
<details class="panel form-panel" <?= $edit ? 'open' : '' ?>>
<summary class="panel-heading"><div><h3><?= $edit ? 'Edit task: ' . e($edit['title']) : '+ Add a scheduled task' ?></h3><p>Use a clear title, responsible person and due date.</p></div></summary>
<?php if (!$projects): ?><div class="alert alert-info">Create a project before adding tasks. <a href="<?= e(url('projects')) ?>">Open projects</a>.</div><?php else: ?>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<label class="span-2">Task title *<input name="title" required maxlength="180" value="<?= e($edit['title'] ?? '') ?>" placeholder="e.g. Complete foundation excavation"></label>
<label>Project *<select name="project_id" required><option value="">Select project</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (string)($edit['project_id'] ?? $projectFilter) === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
<label>Assigned to<select name="assigned_to"><option value="">Unassigned</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)($edit['assigned_to'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name'] . ' (' . role_label($u['role']) . ')') ?></option><?php endforeach; ?></select></label>
<label>Start date<input type="date" name="start_date" value="<?= e($edit['start_date'] ?? '') ?>"></label><label>Due date<input type="date" name="due_date" value="<?= e($edit['due_date'] ?? '') ?>"></label>
<label>Priority<select name="priority"><?php foreach (TASK_PRIORITIES as $p): ?><option value="<?= e($p) ?>" <?= ($edit['priority'] ?? 'medium') === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option><?php endforeach; ?></select></label>
<label>Status<select name="status"><?php foreach (TASK_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= ($edit['status'] ?? 'pending') === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></label>
<label class="span-2">Description<textarea rows="2" name="description" placeholder="Instructions, dependencies or site notes"><?= e($edit['description'] ?? '') ?></textarea></label>
<div class="form-actions span-2"><button class="btn btn-primary"><?= $edit ? 'Save task' : 'Add task' ?></button><?php if ($edit): ?><a class="btn btn-outline" href="<?= e(url('tasks')) ?>">Cancel</a><?php endif; ?></div></form><?php endif; ?></details>
<?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h3>Task register</h3><p>Overdue first, then by due date and priority.</p></div></div>
<form method="get" class="filter-bar"><input type="hidden" name="page" value="tasks"><input name="q" value="<?= e($q) ?>" placeholder="Search task, description or project">
<select name="project_id"><option value="0">All projects</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $projectFilter === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
<select name="status"><option value="">All statuses</option><option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open (not completed)</option><?php foreach (TASK_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select>
<select name="priority"><option value="">Any priority</option><?php foreach (TASK_PRIORITIES as $p): ?><option value="<?= e($p) ?>" <?= $priorityFilter === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option><?php endforeach; ?></select>
<?php if (can('projects.view_all')): ?><select name="assignee"><option value="">Anyone</option><option value="me" <?= $assigneeFilter === 'me' ? 'selected' : '' ?>>Me</option><option value="none" <?= $assigneeFilter === 'none' ? 'selected' : '' ?>>Unassigned</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $assigneeFilter === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
<label class="checkbox"><input type="checkbox" name="overdue" value="1" <?= $overdueOnly ? 'checked' : '' ?>> Overdue only</label>
<button class="btn btn-outline">Filter</button><a class="text-link" href="<?= e(url('tasks')) ?>">Reset</a></form>
<?php if (!$tasks): ?><div class="empty-state"><div class="empty-icon">☷</div><h3>No tasks found</h3><p><?= $filtered ? 'Try a different filter.' : (can_manage_tasks() ? 'Add a scheduled task above.' : 'Tasks assigned to you will appear here.') ?></p></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Task</th><th>Project</th><th>Assignee</th><th>Dates</th><th>Priority</th><th>Status</th><th>Progress</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($tasks as $t): $late = $t['due_date'] && $t['due_date'] < today() && $t['status'] !== 'completed'; ?>
<tr><td><strong><?= e($t['title']) ?></strong><small class="cell-sub"><?= e($t['description'] ? str_limit($t['description'], 120) : 'No description') ?></small></td><td><a class="row-link" href="<?= e(url('project', ['id' => $t['project_id']])) ?>"><?= e($t['project_name']) ?></a></td><td><?= e($t['assignee_name'] ?: 'Unassigned') ?></td>
<td><small>Start: <?= e(date_label($t['start_date'])) ?></small><small class="cell-sub <?= $late ? 'text-danger' : '' ?>">Due: <?= e(date_label($t['due_date'])) ?><?= $late ? ' · overdue' : '' ?></small></td>
<td><span class="priority priority-<?= e($t['priority']) ?>"><?= e(ucfirst($t['priority'])) ?></span></td><td><span class="status status-<?= e($t['status']) ?>"><?= e(status_label($t['status'])) ?></span></td>
<td><div class="progress-line"><span style="width:<?= (int)$t['progress_percent'] ?>%"></span></div><small><?= (int)$t['progress_percent'] ?>%</small></td>
<td class="actions"><a class="action-link" href="<?= e(url('progress', ['task_id' => $t['id']])) ?>">Update</a><?php if (can_manage_tasks()): ?><a class="action-link" href="<?= e(url('tasks', ['edit' => $t['id']])) ?>">Edit</a><?php endif; ?><?php if (can('tasks.delete')): ?><form method="post" onsubmit="return confirm('Delete this task, its progress history and files?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="action-link danger-link">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
