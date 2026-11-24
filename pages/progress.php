<?php
/** @var PDO $pdo */
$pageTitle = 'Progress updates';
$taskId = (int)($_GET['task_id'] ?? 0);

if (is_post()) {
    verify_csrf();
    $taskId = (int)($_POST['task_id'] ?? 0);
    $percent = filter_var($_POST['progress_percentage'] ?? null, FILTER_VALIDATE_INT);
    $status = post_str('status', 'in_progress');
    $notes = post_str('notes');
    if (!task_visible($pdo, $taskId)) {
        flash('error', 'You do not have access to update that task.');
        redirect(url('progress'));
    }
    if ($percent === false || $percent < 0 || $percent > 100) {
        flash('error', 'Progress must be a whole number between 0 and 100.');
        redirect(url('progress', ['task_id' => $taskId]));
    }
    if (!in_array($status, TASK_STATUSES, true)) {
        $status = 'in_progress';
    }
    if ($status === 'completed') {
        $percent = 100;
    }
    if ($percent === 100 && $status !== 'blocked') {
        $status = 'completed';
    }
    if ($status === 'blocked' && $notes === '') {
        flash('error', 'Explain what is blocking the task in the site notes.');
        redirect(url('progress', ['task_id' => $taskId]));
    }
    $before = db_one($pdo, 'SELECT status, progress_percent FROM tasks WHERE id = ?', [$taskId]);
    db_transaction($pdo, function (PDO $pdo) use ($taskId, $percent, $status, $notes) {
        db_exec($pdo, 'UPDATE tasks SET progress_percent = ?, status = ? WHERE id = ?', [$percent, $status, $taskId]);
        db_exec($pdo, 'INSERT INTO progress_updates (task_id, user_id, progress_percentage, status, notes) VALUES (?,?,?,?,?)', [$taskId, user_id(), $percent, $status, $notes ?: null]);
    });
    audit('task.progress_updated', 'task', $taskId, audit_diff($before, ['status' => $status, 'progress_percent' => $percent]));
    flash('success', 'Progress update saved.');
    redirect(url('progress', ['task_id' => $taskId]));
}

[$scope, $scopeParams] = current_task_access_sql();
$tasks = db_all($pdo, "SELECT t.id, t.title, t.project_id, t.status, t.progress_percent, t.due_date, p.name AS project_name, u.full_name AS assignee_name
    FROM tasks t JOIN projects p ON p.id = t.project_id LEFT JOIN users u ON u.id = t.assigned_to WHERE 1=1 $scope
    ORDER BY t.status = 'completed', t.due_date IS NULL, t.due_date", $scopeParams);
$selected = null;
foreach ($tasks as $t) {
    if ((int)$t['id'] === $taskId) {
        $selected = $t;
        break;
    }
}
if (!$selected && $tasks && $taskId === 0) {
    $selected = $tasks[0];
}
$history = $selected ? db_all($pdo, 'SELECT pu.*, u.full_name FROM progress_updates pu JOIN users u ON u.id = pu.user_id WHERE pu.task_id = ? ORDER BY pu.created_at DESC, pu.id DESC LIMIT 30', [(int)$selected['id']]) : [];
$files = $selected ? attachments_for($pdo, (int)$selected['project_id'], (int)$selected['id']) : [];
require __DIR__ . '/../src/layout/header.php';
?>
<div class="page-toolbar"><p class="muted">Record site updates and keep the task schedule aligned with actual progress.</p></div>
<div class="content-grid progress-grid"><section class="panel"><div class="panel-heading"><div><h3>Record progress</h3><p>Select a task and provide the latest status.</p></div></div>
<?php if (!$tasks): ?><div class="empty-state"><div class="empty-icon">↗</div><h3>No available tasks</h3><p>Tasks assigned to you will appear here.</p></div>
<?php elseif ($taskId && !$selected): ?><div class="alert alert-error">That task was not found or is not assigned to you.</div><a class="btn btn-outline" href="<?= e(url('progress')) ?>">Choose another task</a>
<?php else: ?>
<form method="get" class="stack-form"><input type="hidden" name="page" value="progress"><label>Task<select name="task_id" data-autosubmit><?php foreach ($tasks as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$selected['id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['title'] . ' — ' . $t['project_name']) ?><?= $t['status'] === 'completed' ? ' ✓' : '' ?></option><?php endforeach; ?></select></label><noscript><button class="btn btn-outline">Open task</button></noscript></form>
<form method="post" class="stack-form"><?= csrf_field() ?><input type="hidden" name="task_id" value="<?= (int)$selected['id'] ?>">
<div class="progress-edit"><div><span><?= e($selected['project_name']) ?> · due <?= e(date_label($selected['due_date'])) ?> · current progress</span><strong><?= (int)$selected['progress_percent'] ?>%</strong></div><div class="progress-line progress-large"><span style="width:<?= (int)$selected['progress_percent'] ?>%"></span></div></div>
<label>Updated progress (%)<input type="number" name="progress_percentage" min="0" max="100" step="1" value="<?= (int)$selected['progress_percent'] ?>" required></label>
<label>Task status<select name="status"><?php foreach (TASK_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $selected['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></label>
<label>Site notes / work completed<textarea name="notes" rows="4" maxlength="3000" placeholder="Describe what was completed, issues encountered, or what remains. Required when marking a task Blocked."></textarea></label>
<button class="btn btn-primary" type="submit">Save progress update</button><p class="muted small">A permanent history entry is saved each time you submit this form.</p></form>
<div class="panel-divider"></div>
<div class="panel-heading compact-heading"><div><h3>Task photos & files</h3><p>Attach site photos or documents for this task.</p></div></div>
<form method="post" action="<?= e(url('attachment')) ?>" enctype="multipart/form-data" class="filter-bar upload-form"><?= csrf_field() ?><input type="hidden" name="action" value="upload"><input type="hidden" name="project_id" value="<?= (int)$selected['project_id'] ?>"><input type="hidden" name="task_id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="return" value="progress"><input type="file" name="file" required accept=".<?= e(implode(',.', array_keys(ATTACHMENT_TYPES))) ?>"><button class="btn btn-outline">Upload</button></form>
<?php if ($files): ?><ul class="mini-list"><?php foreach ($files as $f): ?><li><a href="<?= e(url('attachment', ['id' => $f['id']])) ?>" target="_blank" rel="noopener"><?= e($f['original_name']) ?></a><small class="muted"><?= e($f['uploader']) ?> · <?= e(human_bytes((int)$f['size_bytes'])) ?></small></li><?php endforeach; ?></ul><?php endif; ?>
<?php endif; ?></section>
<section class="panel"><div class="panel-heading"><div><h3>Update history</h3><p><?= $selected ? e($selected['title']) : 'Select a task to review updates' ?></p></div></div><?php if (!$selected): ?><div class="empty-inline">No task selected.</div><?php elseif (!$history): ?><div class="empty-state compact"><div class="empty-icon">◷</div><h3>No history yet</h3><p>The first update will appear here.</p></div><?php else: ?><div class="timeline"><?php foreach ($history as $h): ?><article class="timeline-item"><span class="timeline-dot"></span><div class="timeline-head"><strong><?= e($h['full_name']) ?></strong><span><?= e(datetime_label($h['created_at'])) ?></span></div><div class="timeline-meta"><span class="status status-<?= e($h['status']) ?>"><?= e(status_label($h['status'])) ?></span><strong><?= (int)$h['progress_percentage'] ?>%</strong></div><div class="progress-line"><span style="width:<?= (int)$h['progress_percentage'] ?>%"></span></div><?php if ($h['notes']): ?><p><?= nl2br(e($h['notes'])) ?></p><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></section></div>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
