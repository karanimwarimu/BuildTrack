<?php
/** @var PDO $pdo */
$pageTitle = 'Deadline alerts';
[$scope, $scopeParams] = current_task_access_sql();
$view = get_str('view', 'all');
$sql = "SELECT t.*, p.name AS project_name, u.full_name AS assignee_name,
        CAST(julianday(t.due_date) - julianday(app_today()) AS INTEGER) AS days_left
    FROM tasks t JOIN projects p ON p.id = t.project_id LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.due_date IS NOT NULL AND t.status <> 'completed' AND t.due_date <= date(app_today(), '+7 days') $scope";
if ($view === 'overdue') {
    $sql .= ' AND t.due_date < app_today()';
} elseif ($view === 'upcoming') {
    $sql .= ' AND t.due_date >= app_today()';
}
$alerts = db_all($pdo, $sql . ' ORDER BY t.due_date ASC', $scopeParams);
$countWhere = fn(string $where) => (int)db_value($pdo, "SELECT COUNT(*) FROM tasks t WHERE $where AND t.status <> 'completed' $scope", $scopeParams);
$overdue = $countWhere('t.due_date < app_today()');
$dueToday = $countWhere('t.due_date = app_today()');
$dueWeek = $countWhere("t.due_date BETWEEN date(app_today(), '+1 day') AND date(app_today(), '+7 days')");
$overdueInView = count(array_filter($alerts, fn($a) => (int)$a['days_left'] < 0));
$lowStock = can('resources.manage') ? low_stock_resources($pdo) : [];
require __DIR__ . '/../src/layout/header.php';
?>
<div class="page-toolbar"><p class="muted">Tasks due within the next seven days, including overdue work. Alerts are generated from current due dates.</p><span class="status <?= $overdueInView ? 'status-blocked' : 'status-completed' ?>"><?= $overdueInView ?> overdue in this view</span></div>
<section class="stat-grid stat-grid-3">
  <article class="stat-card"><div class="stat-top"><span>Overdue</span><span class="stat-icon icon-red">!</span></div><div class="stat-number"><?= $overdue ?></div><div class="stat-note">Needs review</div></article>
  <article class="stat-card"><div class="stat-top"><span>Due today</span><span class="stat-icon icon-amber">◷</span></div><div class="stat-number"><?= $dueToday ?></div><div class="stat-note">Deadline is today</div></article>
  <article class="stat-card"><div class="stat-top"><span>Due in 7 days</span><span class="stat-icon icon-blue">↗</span></div><div class="stat-number"><?= $dueWeek ?></div><div class="stat-note">Plan next actions early</div></article>
</section>
<section class="panel"><div class="panel-heading"><div><h3>Deadline watchlist</h3><p>Open the task update page to record progress or explain a delay.</p></div></div>
<div class="filter-bar"><?php foreach (['all' => 'All alerts', 'overdue' => 'Overdue', 'upcoming' => 'Upcoming'] as $k => $label): ?><a class="filter-chip <?= $view === $k ? 'selected' : '' ?>" href="<?= e(url('alerts', ['view' => $k])) ?>"><?= e($label) ?></a><?php endforeach; ?></div>
<?php if (!$alerts): ?><div class="empty-state"><div class="empty-icon">✓</div><h3>No active deadline alerts</h3><p>No unfinished tasks in this date range match this view.</p></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Task / project</th><th>Assigned to</th><th>Due date</th><th>Timing</th><th>Status</th><th>Progress</th><th>Action</th></tr></thead><tbody>
<?php foreach ($alerts as $a): $d = (int)$a['days_left']; ?>
<tr><td><strong><?= e($a['title']) ?></strong><small class="cell-sub"><?= e($a['project_name']) ?></small></td><td><?= e($a['assignee_name'] ?: 'Unassigned') ?></td><td><?= e(date_label($a['due_date'])) ?></td>
<td><?php if ($d < 0): ?><span class="priority priority-urgent"><?= abs($d) ?> day<?= abs($d) === 1 ? '' : 's' ?> overdue</span><?php elseif ($d === 0): ?><span class="priority priority-high">Due today</span><?php else: ?><span class="priority priority-medium">In <?= $d ?> day<?= $d === 1 ? '' : 's' ?></span><?php endif; ?></td>
<td><span class="status status-<?= e($a['status']) ?>"><?= e(status_label($a['status'])) ?></span></td><td><?= (int)$a['progress_percent'] ?>%</td><td><a class="action-link" href="<?= e(url('progress', ['task_id' => $a['id']])) ?>">Update task</a></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php if ($lowStock): ?>
<section class="panel"><div class="panel-heading"><div><h3>Low-stock alerts</h3><p>Resources at or below their alert threshold.</p></div><a class="text-link" href="<?= e(url('resources', ['low' => '1'])) ?>">Manage resources →</a></div>
<div class="table-wrap"><table><thead><tr><th>Resource</th><th>Remaining</th><th>Alert at</th></tr></thead><tbody><?php foreach ($lowStock as $r): ?><tr><td><?= e($r['name']) ?></td><td class="<?= (float)$r['remaining'] <= 0 ? 'text-danger' : 'text-warning' ?>"><strong><?= e(qty($r['remaining'])) ?> <?= e($r['unit']) ?></strong></td><td><?= e(qty($r['low_stock_threshold'])) ?> <?= e($r['unit']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
<div class="alert alert-info">These are in-app alerts based on due dates and stock thresholds stored in the database. Email/SMS notifications are not sent.</div>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
