<?php
/** @var PDO $pdo */
$pageTitle = 'Reports';
$projectId = (int)get_str('project_id', '0');
$statusFilter = get_str('status');
$projects = visible_projects($pdo);
[$scope, $params] = current_task_access_sql();
$where = " WHERE 1=1 $scope";
if ($projectId > 0) {
    $where .= ' AND t.project_id = ?';
    $params[] = $projectId;
}
if (in_array($statusFilter, TASK_STATUSES, true)) {
    $where .= ' AND t.status = ?';
    $params[] = $statusFilter;
}
$rows = db_all($pdo, "SELECT t.id, t.title, t.start_date, t.due_date, t.status, t.priority, t.progress_percent, p.name AS project_name, u.full_name AS assignee_name
    FROM tasks t JOIN projects p ON p.id = t.project_id LEFT JOIN users u ON u.id = t.assigned_to $where ORDER BY p.name, t.due_date IS NULL, t.due_date", $params);

if (get_str('export') === 'csv') {
    audit('export.csv', 'task_report', null, ['project_id' => $projectId ?: null, 'status' => $statusFilter ?: null]);
    send_csv('buildtrack-task-report-' . date('Y-m-d') . '.csv',
        ['Project', 'Task', 'Assigned To', 'Start Date', 'Due Date', 'Status', 'Priority', 'Progress (%)'],
        array_map(fn($r) => [$r['project_name'], $r['title'], $r['assignee_name'] ?? 'Unassigned', $r['start_date'] ?? '', $r['due_date'] ?? '', status_label($r['status']), ucfirst($r['priority']), (int)$r['progress_percent']], $rows));
}

$tot = count($rows);
$complete = count(array_filter($rows, fn($r) => $r['status'] === 'completed'));
$overdue = count(array_filter($rows, fn($r) => $r['due_date'] && $r['due_date'] < today() && $r['status'] !== 'completed'));
$average = $tot ? (int)round(array_sum(array_column($rows, 'progress_percent')) / $tot) : 0;
$financials = can('expenses.view') ? project_financials($pdo, false, $projectId ?: null) : [];
require __DIR__ . '/../src/layout/header.php';
?>
<div class="page-toolbar"><p class="muted">Summaries for planning, monitoring and project review. Export the current filter as a CSV file.</p><a class="btn btn-primary" href="<?= e(url('reports', ['export' => 'csv', 'project_id' => $projectId ?: null, 'status' => $statusFilter])) ?>">↓ Export CSV</a></div>
<section class="stat-grid">
  <article class="stat-card"><div class="stat-top"><span>Tasks in report</span><span class="stat-icon icon-blue">☷</span></div><div class="stat-number"><?= $tot ?></div><div class="stat-note">Matches selected filters</div></article>
  <article class="stat-card"><div class="stat-top"><span>Completed</span><span class="stat-icon icon-green">✓</span></div><div class="stat-number"><?= $complete ?></div><div class="stat-note"><?= $tot ? (int)round($complete / $tot * 100) : 0 ?>% of report tasks</div></article>
  <article class="stat-card"><div class="stat-top"><span>Overdue</span><span class="stat-icon icon-red">!</span></div><div class="stat-number"><?= $overdue ?></div><div class="stat-note">Unfinished past due date</div></article>
  <article class="stat-card"><div class="stat-top"><span>Average progress</span><span class="stat-icon icon-indigo">↗</span></div><div class="stat-number"><?= $average ?>%</div><div class="stat-note">Average of listed task progress</div></article>
</section>
<section class="panel"><div class="panel-heading"><div><h3>Task performance report</h3><p>Use filters to focus on a project or status.</p></div></div>
<form method="get" class="filter-bar"><input type="hidden" name="page" value="reports"><select name="project_id"><option value="0">All accessible projects</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $projectId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select><select name="status"><option value="">All statuses</option><?php foreach (TASK_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select><button class="btn btn-outline">Apply</button><a class="text-link" href="<?= e(url('reports')) ?>">Reset</a></form>
<?php if (!$rows): ?><div class="empty-inline">No tasks match the selected filters.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Project</th><th>Task</th><th>Assignee</th><th>Due date</th><th>Status</th><th>Priority</th><th>Progress</th></tr></thead><tbody>
<?php foreach ($rows as $r): $late = $r['due_date'] && $r['due_date'] < today() && $r['status'] !== 'completed'; ?>
<tr><td><?= e($r['project_name']) ?></td><td><strong><?= e($r['title']) ?></strong></td><td><?= e($r['assignee_name'] ?: 'Unassigned') ?></td><td class="<?= $late ? 'text-danger' : '' ?>"><?= e(date_label($r['due_date'])) ?></td><td><span class="status status-<?= e($r['status']) ?>"><?= e(status_label($r['status'])) ?></span></td><td><span class="priority priority-<?= e($r['priority']) ?>"><?= e(ucfirst($r['priority'])) ?></span></td><td><div class="progress-line"><span style="width:<?= (int)$r['progress_percent'] ?>%"></span></div><small><?= (int)$r['progress_percent'] ?>%</small></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php if ($financials): ?>
<section class="panel"><div class="panel-heading"><div><h3>Budget report</h3><p>Budget vs actual for <?= $projectId ? 'the selected project' : 'all projects' ?>.</p></div><?php if (can('admin.access')): ?><a class="text-link" href="<?= e(url('admin', ['tab' => 'backups', 'export' => 'expenses'])) ?>">↓ Expenses CSV</a><?php endif; ?></div>
<div class="table-wrap"><table><thead><tr><th>Project</th><th>Status</th><th>Budget</th><th>Spent</th><th>Remaining</th><th>Used</th></tr></thead><tbody>
<?php foreach ($financials as $f): ?><tr><td><?= e($f['name']) ?></td><td><span class="status status-<?= e($f['status']) ?>"><?= e(status_label($f['status'])) ?></span></td><td><?= $f['budget'] !== null ? e(money($f['budget'])) : '—' ?></td><td><?= e(money($f['spent'])) ?></td><td class="<?= $f['over_budget'] ? 'text-danger' : '' ?>"><?= $f['budget'] !== null ? e(money((float)$f['budget'] - (float)$f['spent'])) : '—' ?></td><td><?= $f['budget_used_pct'] !== null ? (int)round($f['budget_used_pct']) . '%' : '—' ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<div class="alert alert-info">Report totals are calculated from the tasks visible to your user role and the filters above. CSV export contains the same task-level data.</div>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
