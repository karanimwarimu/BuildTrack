<?php
/** @var PDO $pdo */
$id = (int)get_str('id', '0');
if (!$id || !project_visible($pdo, $id)) {
    http_response_code(404);
    render_message_page('Project not found', 'The project does not exist or you are not assigned to it.', url('projects'), 'Back to projects');
}
$project = db_one($pdo, 'SELECT p.*, u.full_name AS manager_name, c.full_name AS creator_name FROM projects p LEFT JOIN users u ON u.id = p.manager_id JOIN users c ON c.id = p.created_by WHERE p.id = ?', [$id]);
$pageTitle = $project['name'];
$fin = project_financials($pdo, false, $id)[0];
[$taskScope, $taskParams] = current_task_access_sql();
$tasks = db_all($pdo, "SELECT t.*, u.full_name AS assignee_name FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.project_id = ? $taskScope
    ORDER BY CASE WHEN t.status = 'completed' THEN 2 WHEN t.due_date < app_today() THEN 0 ELSE 1 END, t.due_date IS NULL, t.due_date", array_merge([$id], $taskParams));
$expenses = can('expenses.view') ? db_all($pdo, 'SELECT x.*, u.full_name AS recorder FROM expenses x JOIN users u ON u.id = x.recorded_by WHERE x.project_id = ? ORDER BY x.spent_on DESC, x.id DESC LIMIT 6', [$id]) : [];
$breakdown = can('expenses.view') ? expense_breakdown($pdo, $id) : [];
$logs = db_all($pdo, 'SELECT s.*, u.full_name AS author FROM site_logs s JOIN users u ON u.id = s.created_by WHERE s.project_id = ? ORDER BY s.log_date DESC, s.id DESC LIMIT 5', [$id]);
$files = attachments_for($pdo, $id);
$history = can('audit.view') ? db_all($pdo, "SELECT a.*, u.full_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.entity_type = 'project' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 10", [$id]) : [];
$pct = $fin['budget_used_pct'];
require __DIR__ . '/../src/layout/header.php';
?>
<div class="page-toolbar">
  <div><a class="text-link" href="<?= e(url('projects')) ?>">← All projects</a>
    <p class="muted"><?= e($project['location'] ?: 'Location not set') ?> · Manager: <?= e($project['manager_name'] ?: 'Unassigned') ?> · <?= e(date_label($project['start_date'])) ?> → <?= e(date_label($project['end_date'])) ?></p></div>
  <div class="button-row"><span class="status status-<?= e($project['status']) ?>"><?= e(status_label($project['status'])) ?></span>
    <?php if (can('projects.manage')): ?><a class="btn btn-outline" href="<?= e(url('projects', ['edit' => $id])) ?>">Edit project</a><?php endif; ?></div>
</div>
<?php if ($project['description']): ?><section class="panel"><p><?= nl2br(e($project['description'])) ?></p></section><?php endif; ?>

<section class="stat-grid">
  <article class="stat-card"><div class="stat-top"><span>Tasks completed</span><span class="stat-icon icon-green">✓</span></div><div class="stat-number"><?= (int)$fin['task_done'] ?>/<?= (int)$fin['task_total'] ?></div><div class="stat-note">Average progress <?= (int)round((float)$fin['avg_progress']) ?>%</div></article>
  <article class="stat-card"><div class="stat-top"><span>Overdue tasks</span><span class="stat-icon icon-red">!</span></div><div class="stat-number <?= $fin['task_overdue'] ? 'text-danger' : '' ?>"><?= (int)$fin['task_overdue'] ?></div><div class="stat-note">Unfinished past due date</div></article>
  <?php if (can('expenses.view')): ?>
  <article class="stat-card"><div class="stat-top"><span>Spent</span><span class="stat-icon icon-indigo">¤</span></div><div class="stat-number stat-number-sm <?= $fin['over_budget'] ? 'text-danger' : '' ?>"><?= e(money($fin['spent'])) ?></div><div class="stat-note"><?= $project['budget'] !== null ? 'Budget ' . e(money($project['budget'])) : 'No budget set' ?></div></article>
  <article class="stat-card"><div class="stat-top"><span>Budget used</span><span class="stat-icon icon-amber">%</span></div><div class="stat-number"><?= $pct !== null ? (int)round($pct) . '%' : '—' ?></div><div class="progress-line <?= budget_bar_class($pct) ?>"><span style="width:<?= min(100, (int)round($pct ?? 0)) ?>%"></span></div></article>
  <?php endif; ?>
</section>

<div class="content-grid">
<section class="panel panel-main"><div class="panel-heading"><div><h3>Tasks</h3><p><?= can('projects.view_all') ? 'All tasks in this project' : 'Your tasks in this project' ?></p></div><a class="text-link" href="<?= e(url('tasks', ['project_id' => $id])) ?>">Manage tasks →</a></div>
<?php if (!$tasks): ?><div class="empty-inline">No tasks yet.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Task</th><th>Assignee</th><th>Due</th><th>Status</th><th>Progress</th></tr></thead><tbody>
<?php foreach ($tasks as $t): $late = $t['due_date'] && $t['due_date'] < today() && $t['status'] !== 'completed'; ?>
<tr><td><a class="row-link" href="<?= e(url('progress', ['task_id' => $t['id']])) ?>"><strong><?= e($t['title']) ?></strong></a></td><td><?= e($t['assignee_name'] ?: 'Unassigned') ?></td><td class="<?= $late ? 'text-danger' : '' ?>"><?= e(date_label($t['due_date'])) ?></td><td><span class="status status-<?= e($t['status']) ?>"><?= e(status_label($t['status'])) ?></span></td><td><div class="progress-line"><span style="width:<?= (int)$t['progress_percent'] ?>%"></span></div><small><?= (int)$t['progress_percent'] ?>%</small></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel"><div class="panel-heading"><div><h3>Site logs</h3><p>Latest daily reports</p></div><a class="text-link" href="<?= e(url('sitelogs', ['project_id' => $id])) ?>"><?= can('sitelogs.create') ? 'Add / view →' : 'View all →' ?></a></div>
<?php if (!$logs): ?><div class="empty-inline">No site logs yet.</div><?php else: ?><div class="timeline"><?php foreach ($logs as $s): ?><article class="timeline-item"><span class="timeline-dot"></span><div class="timeline-head"><strong><?= e(date_label($s['log_date'])) ?></strong><span><?= e($s['author']) ?> · crew <?= (int)$s['crew_count'] ?></span></div><p><?= e(str_limit($s['work_done'], 160)) ?></p><?php if ($s['issues']): ?><p class="text-danger small">Issue: <?= e(str_limit($s['issues'], 120)) ?></p><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?>
</section>
</div>

<?php if (can('expenses.view')): ?>
<div class="content-grid">
<section class="panel panel-main"><div class="panel-heading"><div><h3>Recent expenses</h3><p>Latest spending recorded against this project</p></div><a class="text-link" href="<?= e(url('expenses', ['project_id' => $id])) ?>"><?= can('expenses.manage') ? 'Record / view all →' : 'View all →' ?></a></div>
<?php if (!$expenses): ?><div class="empty-inline">No expenses recorded yet.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Amount</th></tr></thead><tbody>
<?php foreach ($expenses as $x): ?><tr><td><?= e(date_label($x['spent_on'])) ?></td><td><strong><?= e($x['description']) ?></strong><small class="cell-sub"><?= e($x['vendor'] ?: '—') ?></small></td><td><?= e(ucfirst($x['category'])) ?></td><td><?= e(money($x['amount'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<section class="panel"><div class="panel-heading"><div><h3>Spending by category</h3></div></div>
<?php if (!$breakdown): ?><div class="empty-inline">Nothing to show yet.</div><?php else: ?>
<ul class="mini-list"><?php foreach ($breakdown as $b): ?><li><span><?= e(ucfirst($b['category'])) ?> <small class="muted">(<?= (int)$b['n'] ?>)</small></span><strong><?= e(money($b['total'])) ?></strong></li><?php endforeach; ?></ul><?php endif; ?>
</section>
</div>
<?php endif; ?>

<section class="panel"><div class="panel-heading"><div><h3>Files & photos</h3><p>Drawings, permits, invoices and site photos (max <?= config_int('UPLOAD_MAX_MB', 10) ?> MB each)</p></div><span class="count-chip"><?= count($files) ?></span></div>
<form method="post" action="<?= e(url('attachment')) ?>" enctype="multipart/form-data" class="filter-bar upload-form"><?= csrf_field() ?>
  <input type="hidden" name="action" value="upload"><input type="hidden" name="project_id" value="<?= $id ?>"><input type="hidden" name="return" value="project">
  <input type="file" name="file" required accept=".<?= e(implode(',.', array_keys(ATTACHMENT_TYPES))) ?>">
  <select name="task_id"><option value="">Whole project</option><?php foreach ($tasks as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['title']) ?></option><?php endforeach; ?></select>
  <button class="btn btn-primary">Upload</button></form>
<?php if (!$files): ?><div class="empty-inline">No files uploaded yet.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>File</th><th>Task</th><th>Size</th><th>Uploaded</th><th>Action</th></tr></thead><tbody>
<?php foreach ($files as $f): ?><tr><td><a class="row-link" href="<?= e(url('attachment', ['id' => $f['id']])) ?>" target="_blank" rel="noopener"><strong><?= e($f['original_name']) ?></strong></a></td><td><?= e($f['task_title'] ?: '—') ?></td><td><?= e(human_bytes((int)$f['size_bytes'])) ?></td><td><?= e($f['uploader']) ?><small class="cell-sub"><?= e(datetime_label($f['created_at'])) ?></small></td>
<td class="actions"><?php if ((int)$f['uploaded_by'] === user_id() || can('attachments.manage_any')): ?><form method="post" action="<?= e(url('attachment')) ?>" onsubmit="return confirm('Delete this file?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="return" value="project"><button class="action-link danger-link">Delete</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>

<?php if ($history): ?>
<section class="panel"><div class="panel-heading"><div><h3>Change history</h3><p>From the audit log</p></div><a class="text-link" href="<?= e(url('admin', ['tab' => 'audit', 'q' => 'project'])) ?>">Full audit log →</a></div>
<div class="table-wrap"><table><tbody><?php foreach ($history as $h): ?><tr><td><?= e(datetime_label($h['created_at'])) ?></td><td><?= e($h['full_name'] ?? 'System') ?></td><td><code><?= e($h['action']) ?></code></td><td class="details-cell"><?= e($h['details'] ? str_limit($h['details'], 200) : '') ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
