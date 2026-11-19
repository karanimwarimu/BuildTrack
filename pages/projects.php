<?php
$pageTitle = 'Projects';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action=(string)($_POST['action']??'');
    if ($action==='delete') {
        require_role(['admin','project_manager']);
        $id=(int)($_POST['id']??0);
        $stmt=$pdo->prepare('DELETE FROM projects WHERE id=?'); $stmt->execute([$id]);
        flash('success','Project deleted. Related tasks and progress records were also removed.'); redirect('index.php?page=projects');
    }
    if (in_array($action,['create','update'],true)) {
        require_role(['admin','project_manager']);
        $id=(int)($_POST['id']??0); $name=trim((string)($_POST['name']??''));
        $description=trim((string)($_POST['description']??'')); $location=trim((string)($_POST['location']??''));
        $start=trim((string)($_POST['start_date']??'')); $end=trim((string)($_POST['end_date']??''));
        $budget=trim((string)($_POST['budget']??'')); $status=(string)($_POST['status']??'planned'); $manager=($_POST['manager_id']??'')!==''?(int)$_POST['manager_id']:null;
        if ($name==='') { flash('error','Project name is required.'); redirect('index.php?page=projects'.($id?'&edit='.$id:'')); }
        if ($start!=='' && $end!=='' && $start>$end) { flash('error','Project end date must be on or after the start date.'); redirect('index.php?page=projects'.($id?'&edit='.$id:'')); }
        if (!in_array($status,['planned','active','on_hold','completed'],true)) $status='planned';
        if ($budget!=='' && (!is_numeric($budget) || (float)$budget<0)) { flash('error','Budget must be zero or a positive amount.'); redirect('index.php?page=projects'.($id?'&edit='.$id:'')); }
        if ($manager!==null) { $m=$pdo->prepare("SELECT id FROM users WHERE id=? AND role IN ('admin','project_manager')"); $m->execute([$manager]); if (!$m->fetchColumn()) $manager=null; }
        if ($action==='create') {
            $st=$pdo->prepare('INSERT INTO projects (name,description,location,start_date,end_date,budget,status,manager_id,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $st->execute([$name,$description?:null,$location?:null,$start?:null,$end?:null,$budget!==''?$budget:null,$status,$manager,(int)user()['id']]);
            flash('success','Project created successfully.');
        } else {
            $st=$pdo->prepare('UPDATE projects SET name=?,description=?,location=?,start_date=?,end_date=?,budget=?,status=?,manager_id=? WHERE id=?');
            $st->execute([$name,$description?:null,$location?:null,$start?:null,$end?:null,$budget!==''?$budget:null,$status,$manager,$id]);
            flash('success','Project details updated.');
        }
        redirect('index.php?page=projects');
    }
}
$edit=null;
if (isset($_GET['edit']) && can_manage_projects()) { $st=$pdo->prepare('SELECT * FROM projects WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit=$st->fetch()?:null; }
$managers=$pdo->query("SELECT id,full_name FROM users WHERE role IN ('admin','project_manager') ORDER BY full_name")->fetchAll();
$search=trim((string)($_GET['q']??'')); $statusFilter=(string)($_GET['status']??'');
$sql='SELECT p.*, u.full_name AS manager_name, (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.id) AS task_count FROM projects p LEFT JOIN users u ON u.id=p.manager_id WHERE 1=1'; $params=[];
if (!is_manager() && user()['role']!=='site_supervisor') { $sql.=' AND EXISTS (SELECT 1 FROM tasks tx WHERE tx.project_id=p.id AND tx.assigned_to=?)'; $params[]=(int)user()['id']; }
if ($search!=='') { $sql.=' AND (p.name LIKE ? OR p.location LIKE ?)'; $params[]='%'.$search.'%'; $params[]='%'.$search.'%'; }
if (in_array($statusFilter,['planned','active','on_hold','completed'],true)) { $sql.=' AND p.status=?'; $params[]=$statusFilter; }
$sql.=' ORDER BY p.updated_at DESC'; $st=$pdo->prepare($sql); $st->execute($params); $projects=$st->fetchAll();
require __DIR__ . '/../inc/header.php';
?>
<div class="page-toolbar"><div><p class="muted">Organise projects, ownership and target dates.</p></div><div class="toolbar-count"><strong><?= count($projects) ?></strong> project<?= count($projects)===1?'':'s' ?></div></div>
<?php if(can_manage_projects()): ?><section class="panel form-panel"><div class="panel-heading"><div><h3><?= $edit?'Edit project':'Create a project' ?></h3><p><?= $edit?'Update the project information below.':'Add a project to start scheduling tasks.' ?></p></div><?php if($edit): ?><a class="text-link" href="index.php?page=projects">Cancel edit</a><?php endif; ?></div><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit?'update':'create' ?>"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>">
<label class="span-2">Project name *<input required name="name" maxlength="180" value="<?= e($edit['name']??'') ?>" placeholder="e.g. Riverside Apartment Block"></label>
<label>Location<input name="location" maxlength="180" value="<?= e($edit['location']??'') ?>" placeholder="Site location"></label>
<label>Project manager<select name="manager_id"><option value="">Not assigned</option><?php foreach($managers as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (string)($edit['manager_id']??'')===(string)$m['id']?'selected':'' ?>><?= e($m['full_name']) ?></option><?php endforeach; ?></select></label>
<label>Start date<input type="date" name="start_date" value="<?= e($edit['start_date']??'') ?>"></label><label>Target end date<input type="date" name="end_date" value="<?= e($edit['end_date']??'') ?>"></label>
<label>Budget (KSh)<input type="number" min="0" step="0.01" name="budget" value="<?= e($edit['budget']??'') ?>" placeholder="Optional"></label><label>Status<select name="status"><?php foreach(['planned','active','on_hold','completed'] as $s): ?><option value="<?= e($s) ?>" <?= ($edit['status']??'planned')===$s?'selected':'' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select></label>
<label class="span-2">Description<textarea name="description" rows="2" placeholder="Project purpose and notes"><?= e($edit['description']??'') ?></textarea></label><div class="form-actions span-2"><button class="btn btn-primary" type="submit"><?= $edit?'Save changes':'Create project' ?></button><?php if($edit): ?><a class="btn btn-outline" href="index.php?page=projects">Cancel</a><?php endif; ?></div></form></section><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h3>Project register</h3><p>Search and review project status.</p></div></div><form method="get" class="filter-bar"><input type="hidden" name="page" value="projects"><input name="q" value="<?= e($search) ?>" placeholder="Search project or location"><select name="status"><option value="">All statuses</option><?php foreach(['planned','active','on_hold','completed'] as $s): ?><option value="<?= e($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= e(status_label($s)) ?></option><?php endforeach; ?></select><button class="btn btn-outline">Filter</button><a class="text-link" href="index.php?page=projects">Reset</a></form>
<?php if(!$projects): ?><div class="empty-state"><div class="empty-icon">▦</div><h3>No projects found</h3><p>Create a project or change the search filters.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Project</th><th>Manager</th><th>Schedule</th><th>Tasks</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($projects as $p): ?><tr><td><strong><?= e($p['name']) ?></strong><small class="cell-sub"><?= e($p['location']?:'Location not set') ?></small></td><td><?= e($p['manager_name']?:'Unassigned') ?></td><td><?= e(date_label($p['start_date'])) ?><small class="cell-sub">to <?= e(date_label($p['end_date'])) ?></small></td><td><span class="count-chip"><?= (int)$p['task_count'] ?></span></td><td><span class="status status-<?= e($p['status']) ?>"><?= e(status_label($p['status'])) ?></span></td><td class="actions"><a class="action-link" href="index.php?page=tasks&project_id=<?= (int)$p['id'] ?>">Tasks</a><?php if(can_manage_projects()): ?><a class="action-link" href="index.php?page=projects&edit=<?= (int)$p['id'] ?>">Edit</a><?php if(is_admin()): ?><form method="post" onsubmit="return confirm('Delete this project and all its tasks? This cannot be undone.');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="action-link danger-link" type="submit">Delete</button></form><?php endif; ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require __DIR__ . '/../inc/footer.php'; ?>
