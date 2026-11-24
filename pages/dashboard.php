<?php
/** @var PDO $pdo */
$pageTitle = 'Dashboard';
$me = user();
$seesAll = can('projects.view_all');
[$taskScope, $scopeParams] = current_task_access_sql();
[$projectScope, $projectParams] = current_project_access_sql();

$count = fn(string $where) => (int)db_value($pdo, "SELECT COUNT(*) FROM tasks t WHERE $where $taskScope", $scopeParams);
$totalTasks = $count('1=1');
$completedTasks = $count("t.status = 'completed'");
$overdueTasks = $count("t.due_date < app_today() AND t.status <> 'completed'");
$upcomingTasks = $count("t.due_date BETWEEN app_today() AND date(app_today(), '+7 days') AND t.status <> 'completed'");
$blockedTasks = $count("t.status = 'blocked'");

$projectCount = (int)db_value($pdo, "SELECT COUNT(*) FROM projects p WHERE p.status IN ('planned','active','on_hold') $projectScope", $projectParams);
$recent = db_all($pdo, "SELECT p.*, u.full_name AS manager_name FROM projects p LEFT JOIN users u ON u.id = p.manager_id WHERE 1=1 $projectScope ORDER BY p.updated_at DESC LIMIT 6", $projectParams);
$taskRows = db_all($pdo, "SELECT t.*, p.name AS project_name, u.full_name AS assignee_name FROM tasks t JOIN projects p ON p.id = t.project_id LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.status <> 'completed' $taskScope
    ORDER BY CASE WHEN t.due_date < app_today() THEN 0 WHEN t.status = 'blocked' THEN 1 ELSE 2 END, t.due_date IS NULL, t.due_date ASC LIMIT 8", $scopeParams);

$financials = can('expenses.view') ? project_financials($pdo, true) : [];
$totalBudget = array_sum(array_map(fn($r) => (float)$r['budget'], $financials));
$totalSpent = array_sum(array_map(fn($r) => (float)$r['spent'], $financials));
$overBudget = count(array_filter($financials, fn($r) => $r['over_budget']));
$lowStock = $seesAll ? low_stock_resources($pdo) : [];
$siteLogs = db_all($pdo, "SELECT s.*, p.name AS project_name, u.full_name AS author FROM site_logs s JOIN projects p ON p.id = s.project_id JOIN users u ON u.id = s.created_by WHERE 1=1 $projectScope ORDER BY s.log_date DESC, s.id DESC LIMIT 4", $projectParams);

require __DIR__ . '/../src/layout/header.php';
?>
<section class="welcome-strip"><div><div class="eyebrow">YOUR PROJECT OVERVIEW</div><h2>Good to see you, <?= e(explode(' ', $me['full_name'])[0]) ?>.</h2><p>Here is the latest status of work across <?= $seesAll ? 'your construction projects' : 'the projects you are assigned to' ?>.</p></div><div class="welcome-icon">▦</div></section>

<section class="stat-grid">
  <article class="stat-card"><div class="stat-top"><span>Active projects</span><span class="stat-icon icon-indigo">▦</span></div><div class="stat-number"><?= $projectCount ?></div><div class="stat-note">Planned, active or on hold</div></article>
  <article class="stat-card"><div class="stat-top"><span>Open tasks</span><span class="stat-icon icon-blue">☷</span></div><div class="stat-number"><?= $totalTasks - $completedTasks ?></div><div class="stat-note"><?= $totalTasks ? (int)round($completedTasks / $totalTasks * 100) : 0 ?>% of <?= $totalTasks ?> tasks completed<?= $blockedTasks ? " · $blockedTasks blocked" : '' ?></div></article>
  <article class="stat-card"><div class="stat-top"><span>Overdue tasks</span><span class="stat-icon icon-red">!</span></div><div class="stat-number <?= $overdueTasks ? 'text-danger' : '' ?>"><?= $overdueTasks ?></div><div class="stat-note"><a href="<?= e(url('alerts', ['view' => 'overdue'])) ?>">Review deadline alerts →</a></div></article>
  <?php if (can('expenses.view')): ?>
  <article class="stat-card"><div class="stat-top"><span>Spent vs budget</span><span class="stat-icon icon-green">¤</span></div><div class="stat-number stat-number-sm"><?= $totalBudget > 0 ? (int)round($totalSpent / $totalBudget * 100) . '%' : '—' ?></div><div class="stat-note"><?= e(money($totalSpent)) ?> of <?= e(money($totalBudget)) ?><?= $overBudget ? " · <span class=\"text-danger\">$overBudget over budget</span>" : '' ?></div></article>
  <?php elseif ($seesAll): ?>
  <article class="stat-card"><div class="stat-top"><span>Low stock</span><span class="stat-icon icon-amber">▤</span></div><div class="stat-number"><?= count($lowStock) ?></div><div class="stat-note"><a href="<?= e(url('resources', ['low' => '1'])) ?>">View resources →</a></div></article>
  <?php else: ?>
  <article class="stat-card"><div class="stat-top"><span>Due this week</span><span class="stat-icon icon-amber">◷</span></div><div class="stat-number"><?= $upcomingTasks ?></div><div class="stat-note">Unfinished, due in 7 days</div></article>
  <?php endif; ?>
</section>

<div class="content-grid">
<section class="panel panel-main"><div class="panel-heading"><div><h3>Needs attention</h3><p>Open tasks: overdue first, then blocked, then by due date</p></div><a class="text-link" href="<?= e(url('tasks')) ?>">View all →</a></div>
<?php if (!$taskRows): ?><div class="empty-state"><div class="empty-icon">✓</div><h3>Nothing open</h3><p>Open tasks will appear here when they are added to a project.</p><?php if (can_manage_tasks()): ?><a class="btn btn-primary" href="<?= e(url('tasks')) ?>">Create a task</a><?php endif; ?></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Task / project</th><th>Assignee</th><th>Due date</th><th>Status</th><th>Progress</th></tr></thead><tbody>
<?php foreach ($taskRows as $t): $late = $t['due_date'] && $t['due_date'] < today(); ?>
<tr><td><a class="row-link" href="<?= e(url('progress', ['task_id' => $t['id']])) ?>"><strong><?= e($t['title']) ?></strong></a><small class="cell-sub"><?= e($t['project_name']) ?></small></td><td><?= e($t['assignee_name'] ?: 'Unassigned') ?></td><td><span class="<?= $late ? 'text-danger' : '' ?>"><?= e(date_label($t['due_date'])) ?><?= $late ? ' · overdue' : '' ?></span></td><td><span class="status status-<?= e($t['status']) ?>"><?= e(status_label($t['status'])) ?></span></td><td><div class="progress-line"><span style="width:<?= (int)$t['progress_percent'] ?>%"></span></div><small><?= (int)$t['progress_percent'] ?>%</small></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<section class="panel"><div class="panel-heading"><div><h3>Deadline watch</h3><p>Next seven days</p></div><span class="stat-icon icon-amber">◷</span></div>
  <div class="deadline-summary"><div><span class="bullet bullet-red"></span><span>Overdue</span><strong><?= $overdueTasks ?></strong></div><div><span class="bullet bullet-amber"></span><span>Due soon</span><strong><?= $upcomingTasks ?></strong></div><div><span class="bullet bullet-green"></span><span>Completed</span><strong><?= $completedTasks ?></strong></div></div>
  <a class="btn btn-outline btn-full" href="<?= e(url('alerts')) ?>">Open deadline alerts</a>
  <?php if ($seesAll): ?>
  <div class="panel-divider"></div>
  <div class="panel-heading compact-heading"><div><h3>Low stock</h3><p>At or below threshold</p></div><a class="text-link" href="<?= e(url('resources', ['low' => '1'])) ?>">Manage →</a></div>
  <?php if (!$lowStock): ?><div class="empty-inline">All tracked resources are above their thresholds.</div><?php else: ?>
  <ul class="mini-list"><?php foreach (array_slice($lowStock, 0, 5) as $r): ?><li><span><?= e($r['name']) ?></span><strong class="<?= (float)$r['remaining'] <= 0 ? 'text-danger' : 'text-warning' ?>"><?= e(qty($r['remaining'])) ?> <?= e($r['unit']) ?></strong></li><?php endforeach; ?></ul>
  <?php endif; endif; ?>
</section>
</div>

<?php if ($financials): ?>
<section class="panel"><div class="panel-heading"><div><h3>Budget vs actual</h3><p>Open projects — recorded expenses against budget</p></div><a class="text-link" href="<?= e(url('expenses')) ?>">Expenses →</a></div>
<div class="table-wrap"><table><thead><tr><th>Project</th><th>Status</th><th>Budget</th><th>Spent</th><th>Used</th><th>Tasks done</th></tr></thead><tbody>
<?php foreach ($financials as $f): $pct = $f['budget_used_pct']; ?>
<tr><td><a class="row-link" href="<?= e(url('project', ['id' => $f['id']])) ?>"><strong><?= e($f['name']) ?></strong></a><small class="cell-sub"><?= e($f['manager_name'] ?: 'No manager') ?></small></td>
  <td><span class="status status-<?= e($f['status']) ?>"><?= e(status_label($f['status'])) ?></span></td>
  <td><?= $f['budget'] !== null ? e(money($f['budget'])) : '<span class="muted">Not set</span>' ?></td>
  <td class="<?= $f['over_budget'] ? 'text-danger' : '' ?>"><?= e(money($f['spent'])) ?></td>
  <td><?php if ($pct !== null): ?><div class="progress-line <?= budget_bar_class($pct) ?>"><span style="width:<?= min(100, (int)round($pct)) ?>%"></span></div><small><?= (int)round($pct) ?>%</small><?php else: ?>—<?php endif; ?></td>
  <td><?= (int)$f['task_done'] ?>/<?= (int)$f['task_total'] ?><?= $f['task_overdue'] ? ' <small class="text-danger">(' . (int)$f['task_overdue'] . ' overdue)</small>' : '' ?></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>

<div class="content-grid">
<section class="panel panel-main"><div class="panel-heading"><div><h3>Recently updated projects</h3><p>Projects with the latest changes</p></div><a class="text-link" href="<?= e(url('projects')) ?>">View projects →</a></div>
<?php if (!$recent): ?><div class="empty-inline">No projects available yet.</div><?php else: ?><div class="project-cards"><?php foreach ($recent as $p): ?><a class="project-card" href="<?= e(url('project', ['id' => $p['id']])) ?>"><div class="project-card-top"><span class="project-mark"><?= e(first_letter($p['name'])) ?></span><span class="status status-<?= e($p['status']) ?>"><?= e(status_label($p['status'])) ?></span></div><h4><?= e($p['name']) ?></h4><p><?= e($p['location'] ?: 'Location not set') ?></p><div class="project-card-bottom"><span>Ends <?= e(date_label($p['end_date'])) ?></span><span>Open →</span></div></a><?php endforeach; ?></div><?php endif; ?></section>
<section class="panel"><div class="panel-heading"><div><h3>Latest site logs</h3><p>Daily reports from site</p></div><a class="text-link" href="<?= e(url('sitelogs')) ?>">All logs →</a></div>
<?php if (!$siteLogs): ?><div class="empty-inline">No site logs recorded yet.</div><?php else: ?><div class="timeline"><?php foreach ($siteLogs as $s): ?><article class="timeline-item"><span class="timeline-dot"></span><div class="timeline-head"><strong><?= e($s['project_name']) ?></strong><span><?= e(date_label($s['log_date'])) ?></span></div><p><?= e(str_limit($s['work_done'], 140)) ?><?= strlen($s['work_done']) > 140 ? '…' : '' ?></p><?php if ($s['issues']): ?><p class="text-danger small">Issue: <?= e(str_limit($s['issues'], 100)) ?></p><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?>
</section>
</div>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
