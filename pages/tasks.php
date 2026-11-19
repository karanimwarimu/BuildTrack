<?php
$pageTitle = 'Tasks & schedule';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action=(string)($_POST['action']??'');
    if ($action==='delete') {
        require_role(['admin','project_manager']);
        $id=(int)($_POST['id']??0); $st=$pdo->prepare('DELETE FROM tasks WHERE id=?'); $st->execute([$id]);
        flash('success','Task deleted.'); redirect('index.php?page=tasks');
    }
    if (in_array($action,['create','update'],true)) {
        require_role(['admin','project_manager','site_supervisor']);
        $id=(int)($_POST['id']??0); $projectId=(int)($_POST['project_id']??0); $title=trim((string)($_POST['title']??''));
        $description=trim((string)($_POST['description']??'')); $start=trim((string)($_POST['start_date']??'')); $due=trim((string)($_POST['due_date']??''));
        $assignee=($_POST['assigned_to']??'')!==''?(int)$_POST['assigned_to']:null; $status=(string)($_POST['status']??'pending'); $priority=(string)($_POST['priority']??'medium');
        if ($title==='' || $projectId<1) { flash('error','Choose a project and enter a task title.'); redirect('index.php?page=tasks'.($id?'&edit='.$id:'')); }
        if ($start!=='' && $due!=='' && $start>$due) { flash('error','Task due date must be on or after the start date.'); redirect('index.php?page=tasks'.($id?'&edit='.$id:'')); }
        $check=$pdo->prepare('SELECT id FROM projects WHERE id=?'); $check->execute([$projectId]);
        if (!$check->fetchColumn()) { flash('error','Selected project was not found.'); redirect('index.php?page=tasks'); }
        if ($assignee!==null) { $check=$pdo->prepare('SELECT id FROM users WHERE id=?'); $check->execute([$assignee]); if (!$check->fetchColumn()) $assignee=null; }
        if (!in_array($status,['pending','in_progress','blocked','completed'],true)) $status='pending';
        if (!in_array($priority,['low','medium','high','urgent'],true)) $priority='medium';
        if ($action==='create') {
            $progress=$status==='completed'?100:0;
            $st=$pdo->prepare('INSERT INTO tasks (project_id,title,description,start_date,due_date,assigned_to,status,priority,progress_percent,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$projectId,$title,$description?:null,$start?:null,$due?:null,$assignee,$status,$priority,$progress,(int)user()['id']]);
            flash('success','Task added to the schedule.');
        } else {
            $st=$pdo->prepare('UPDATE tasks SET project_id=?,title=?,description=?,start_date=?,due_date=?,assigned_to=?,status=?,priority=?,progress_percent=CASE WHEN ?=\'completed\' THEN 100 ELSE progress_percent END WHERE id=?');
            $st->execute([$projectId,$title,$description?:null,$start?:null,$due?:null,$assignee,$status,$priority,$status,$id]);
            flash('success','Task details updated.');
        }
        redirect('index.php?page=tasks');
    }
}
$edit=null;
if (isset($_GET['edit'])) {
    require_role(['admin','project_manager','site_supervisor']);
    $st=$pdo->prepare('SELECT * FROM tasks WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit=$st->fetch()?:null;
}
if (is_manager() || user()['role']==='site_supervisor') $projects=$pdo->query("SELECT id,name FROM projects WHERE status<>'completed' OR id IN (SELECT project_id FROM tasks) ORDER BY name")->fetchAll();
else { $st=$pdo->prepare('SELECT DISTINCT p.id,p.name FROM projects p JOIN tasks t ON t.project_id=p.id WHERE t.assigned_to=? ORDER BY p.name'); $st->execute([(int)user()['id']]); $projects=$st->fetchAll(); }
$users=$pdo->query('SELECT id,full_name FROM users ORDER BY full_name')->fetchAll();
$projectFilter=(int)($_GET['project_id']??0); $statusFilter=(string)($_GET['status']??''); $q=trim((string)($_GET['q']??''));
$sql='SELECT t.*, p.name AS project_name, u.full_name AS assignee_name FROM tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.assigned_to WHERE 1=1'; $params=[];
if (!is_manager() && user()['role']!=='site_supervisor') { $sql.=' AND t.assigned_to=?'; $params[]=(int)user()['id']; }
if ($projectFilter>0) { $sql.=' AND t.project_id=?'; $params[]=$projectFilter; }
if (in_array($statusFilter,['pending','in_progress','blocked','completed'],true)) { $sql.=' AND t.status=?'; $params[]=$statusFilter; }
if ($q!=='') { $sql.=' AND (t.title LIKE ? OR p.name LIKE ?)'; $params[]='%'.$q.'%'; $params[]='%'.$q.'%'; }
$sql.=" ORDER BY CASE WHEN t.status='completed' THEN 2 WHEN t.due_date < CURDATE() THEN 0 ELSE 1 END, t.due_date IS NULL, t.due_date ASC";
$st=$pdo->prepare($sql); $st->execute($params); $tasks=$st->fetchAll();
require __DIR__ . '/../inc/header.php';
?>
<div class="page-toolbar"><p class="muted">Plan work, assign responsibility and monitor each task against its deadline.</p><div class="toolbar-count"><strong><?= count($tasks) ?></strong> task<?= count($tasks)===1?'':'s' ?></div></div>
<?php if(can_manage_tasks()): ?><section class="panel form-panel"><div class="panel-heading"><div><h3><?= $edit?'Edit task':'Add a scheduled task' ?></h3><p>Use a clear title, responsible person and due date.</p></div><?php if($edit): ?><a class="text-link" href="index.php?page=tasks">Cancel edit</a><?php endif; ?></div><?php if(!$projects): ?><div class="alert alert-info">Create a project before adding tasks. <a href="index.php?page=projects">Open projects</a>.</div><?php else: ?><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit?'update':'create' ?>"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>">
<label class="span-2">Task title *<input name="title" required maxlength="180" value="<?= e($edit['title']??'') ?>" placeholder="e.g. Complete foundation excavation"></label><label>Project *<select name="project_id" required><option value="">Select project</option><?php foreach($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (string)($edit['project_id']??$projectFilter)===(string)$p['id']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></label><label>Assigned to<select name="assigned_to"><option value="">Unassigned</option><?php foreach($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)($edit['assigned_to']??'')===(string)$u['id']?'selected':'' ?>><?= e($u['full_name']) ?></option><?php endforeach; ?></select></label>
<label>Start date<input type="date" name="start_date" value="<?= e($edit['start_date']??'') ?>"></label><label>Due date<input type="date" name="due_date" value="<?= e($edit['due_date']??'') ?>"></label><label>Priority<select name="priority"><?php foreach(['low','medium','high','urgent'] as $p): ?><option value="<?= e($p) ?>" <?= ($edit['priority']??'medium')===$p?'selected':'' ?>><?= e(ucfirst($p)) ?></option><?php endforeach; ?></select></label><label>Status<select name="status"><?php foreach(['pending','in_progress','blocked','completed'] as $s): ?><option value="<?= e($s) ?>" <?= ($edit['status']??'pending')===$s?'selected':'' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></label><label class="span-2">Description<textarea rows="2" name="description" placeholder="Instructions, dependencies or site notes"><?= e($edit['description']??'') ?></textarea></label><div class="form-actions span-2"><button class="btn btn-primary"><?= $edit?'Save task':'Add task' ?></button><?php if($edit): ?><a class="btn btn-outline" href="index.php?page=tasks">Cancel</a><?php endif; ?></div></form><?php endif; ?></section><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h3>Task register</h3><p>Tasks are ordered by urgency.</p></div></div><form method="get" class="filter-bar"><input type="hidden" name="page" value="tasks"><input name="q" value="<?= e($q) ?>" placeholder="Search task or project"><select name="project_id"><option value="0">All projects</option><?php foreach($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $projectFilter===(int)$p['id']?'selected':'' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select><select name="status"><option value="">All statuses</option><?php foreach(['pending','in_progress','blocked','completed'] as $s): ?><option value="<?= e($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select><button class="btn btn-outline">Filter</button><a class="text-link" href="index.php?page=tasks">Reset</a></form>
<?php if(!$tasks): ?><div class="empty-state"><div class="empty-icon">☷</div><h3>No tasks found</h3><p>Try a different filter or add a scheduled task.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Task</th><th>Project</th><th>Assignee</th><th>Dates</th><th>Priority</th><th>Status</th><th>Progress</th><th>Actions</th></tr></thead><tbody><?php foreach($tasks as $t): $late=$t['due_date'] && $t['due_date']<date('Y-m-d') && $t['status']!=='completed'; ?><tr><td><strong><?= e($t['title']) ?></strong><small class="cell-sub"><?= e($t['description']?:'No description') ?></small></td><td><?= e($t['project_name']) ?></td><td><?= e($t['assignee_name']?:'Unassigned') ?></td><td><small>Start: <?= e(date_label($t['start_date'])) ?></small><small class="cell-sub <?= $late?'text-danger':'' ?>">Due: <?= e(date_label($t['due_date'])) ?><?= $late?' · overdue':'' ?></small></td><td><span class="priority priority-<?= e($t['priority']) ?>"><?= e(ucfirst($t['priority'])) ?></span></td><td><span class="status status-<?= e($t['status']) ?>"><?= e(status_label($t['status'])) ?></span></td><td><div class="progress-line"><span style="width:<?= (int)$t['progress_percent'] ?>%"></span></div><small><?= (int)$t['progress_percent'] ?>%</small></td><td class="actions"><a class="action-link" href="index.php?page=progress&task_id=<?= (int)$t['id'] ?>">Update</a><?php if(can_manage_tasks()): ?><a class="action-link" href="index.php?page=tasks&edit=<?= (int)$t['id'] ?>">Edit</a><?php endif; ?><?php if(is_manager()): ?><form method="post" onsubmit="return confirm('Delete this task and its progress history?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="action-link danger-link">Delete</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require __DIR__ . '/../inc/footer.php'; ?>
