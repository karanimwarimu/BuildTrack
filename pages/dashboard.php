<?php
$pageTitle = 'Dashboard';
$uid = (int)user()['id'];
[$taskScope, $scopeParams] = current_task_access_sql();
$taskCount = $pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE 1=1 $taskScope"); $taskCount->execute($scopeParams); $totalTasks=(int)$taskCount->fetchColumn();
$completed = $pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE t.status='completed' $taskScope"); $completed->execute($scopeParams); $completedTasks=(int)$completed->fetchColumn();
$overdue = $pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE t.due_date < CURDATE() AND t.status <> 'completed' $taskScope"); $overdue->execute($scopeParams); $overdueTasks=(int)$overdue->fetchColumn();
$upcoming = $pdo->prepare("SELECT COUNT(*) FROM tasks t WHERE t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND t.status <> 'completed' $taskScope"); $upcoming->execute($scopeParams); $upcomingTasks=(int)$upcoming->fetchColumn();
if (is_manager() || user()['role']==='site_supervisor') {
    $projectCount=(int)$pdo->query("SELECT COUNT(*) FROM projects WHERE status IN ('planned','active','on_hold')")->fetchColumn();
    $recent=$pdo->query("SELECT p.*, u.full_name AS manager_name FROM projects p LEFT JOIN users u ON u.id=p.manager_id ORDER BY p.updated_at DESC LIMIT 6")->fetchAll();
} else {
    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT p.id) FROM projects p JOIN tasks t ON t.project_id=p.id WHERE t.assigned_to=? AND p.status IN ('planned','active','on_hold')"); $stmt->execute([$uid]); $projectCount=(int)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT DISTINCT p.*, u.full_name AS manager_name FROM projects p JOIN tasks t ON t.project_id=p.id LEFT JOIN users u ON u.id=p.manager_id WHERE t.assigned_to=? ORDER BY p.updated_at DESC LIMIT 6"); $stmt->execute([$uid]); $recent=$stmt->fetchAll();
}
$taskStmt=$pdo->prepare("SELECT t.*, p.name AS project_name, u.full_name AS assignee_name FROM tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.assigned_to WHERE 1=1 $taskScope ORDER BY CASE WHEN t.status='completed' THEN 2 WHEN t.due_date < CURDATE() THEN 0 ELSE 1 END, t.due_date ASC LIMIT 7"); $taskStmt->execute($scopeParams); $taskRows=$taskStmt->fetchAll();
require __DIR__ . '/../inc/header.php';
?>
<section class="welcome-strip"><div><div class="eyebrow">YOUR PROJECT OVERVIEW</div><h2>Good to see you, <?= e(explode(' ', user()['full_name'])[0]) ?>.</h2><p>Here is the latest status of work across your construction projects.</p></div><div class="welcome-icon">▦</div></section>
<section class="stat-grid">
  <article class="stat-card"><div class="stat-top"><span>Active projects</span><span class="stat-icon icon-indigo">▦</span></div><div class="stat-number"><?= $projectCount ?></div><div class="stat-note">Planned, active or on hold</div></article>
  <article class="stat-card"><div class="stat-top"><span>Total tasks</span><span class="stat-icon icon-blue">☷</span></div><div class="stat-number"><?= $totalTasks ?></div><div class="stat-note">Tasks available to your role</div></article>
  <article class="stat-card"><div class="stat-top"><span>Completed tasks</span><span class="stat-icon icon-green">✓</span></div><div class="stat-number"><?= $completedTasks ?></div><div class="stat-note"><?= $totalTasks ? (int)round($completedTasks/$totalTasks*100) : 0 ?>% of visible tasks</div></article>
  <article class="stat-card"><div class="stat-top"><span>Overdue tasks</span><span class="stat-icon icon-red">!</span></div><div class="stat-number"><?= $overdueTasks ?></div><div class="stat-note"><a href="index.php?page=alerts">Review deadline alerts →</a></div></article>
</section>
<div class="content-grid">
<section class="panel panel-main"><div class="panel-heading"><div><h3>Task schedule</h3><p>Tasks ordered by urgency and due date</p></div><a class="text-link" href="index.php?page=tasks">View all →</a></div>
<?php if (!$taskRows): ?><div class="empty-state"><div class="empty-icon">☷</div><h3>No tasks yet</h3><p>Tasks will appear here when they are added to a project.</p><?php if (can_manage_tasks()): ?><a class="btn btn-primary" href="index.php?page=tasks">Create a task</a><?php endif; ?></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Task / project</th><th>Assignee</th><th>Due date</th><th>Status</th><th>Progress</th></tr></thead><tbody><?php foreach($taskRows as $t): $late=$t['due_date'] && $t['due_date']<date('Y-m-d') && $t['status']!=='completed'; ?><tr><td><strong><?= e($t['title']) ?></strong><small class="cell-sub"><?= e($t['project_name']) ?></small></td><td><?= e($t['assignee_name'] ?: 'Unassigned') ?></td><td><span class="<?= $late?'text-danger':'' ?>"><?= e(date_label($t['due_date'])) ?></span></td><td><span class="status status-<?= e($t['status']) ?>"><?= e(status_label($t['status'])) ?></span></td><td><div class="progress-line"><span style="width:<?= (int)$t['progress_percent'] ?>%"></span></div><small><?= (int)$t['progress_percent'] ?>%</small></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="panel"><div class="panel-heading"><div><h3>Deadline watch</h3><p>Next seven days</p></div><span class="stat-icon icon-amber">◷</span></div><div class="deadline-summary"><div><span class="bullet bullet-red"></span><span>Overdue</span><strong><?= $overdueTasks ?></strong></div><div><span class="bullet bullet-amber"></span><span>Due soon</span><strong><?= $upcomingTasks ?></strong></div><div><span class="bullet bullet-green"></span><span>Completed</span><strong><?= $completedTasks ?></strong></div></div><a class="btn btn-outline btn-full" href="index.php?page=alerts">Open deadline alerts</a><div class="panel-divider"></div><div class="mini-note"><strong>Reminder</strong><p>Keep task progress current so project reports reflect the latest site updates.</p></div></section>
</div>
<section class="panel"><div class="panel-heading"><div><h3>Recently updated projects</h3><p>Projects with the latest changes</p></div><a class="text-link" href="index.php?page=projects">View projects →</a></div>
<?php if (!$recent): ?><div class="empty-inline">No projects available yet.</div><?php else: ?><div class="project-cards"><?php foreach($recent as $p): ?><a class="project-card" href="index.php?page=tasks&project_id=<?= (int)$p['id'] ?>"><div class="project-card-top"><span class="project-mark"><?= e(strtoupper(substr($p['name'],0,1))) ?></span><span class="status status-<?= e($p['status']) ?>"><?= e(status_label($p['status'])) ?></span></div><h4><?= e($p['name']) ?></h4><p><?= e($p['location'] ?: 'Location not set') ?></p><div class="project-card-bottom"><span>Ends <?= e(date_label($p['end_date'])) ?></span><span>Open project →</span></div></a><?php endforeach; ?></div><?php endif; ?></section>
<?php require __DIR__ . '/../inc/footer.php'; ?>
