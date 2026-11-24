<?php
/** @var PDO $pdo */
$pageTitle = 'Daily site logs';
$canEdit = fn(array $log) => can('sitelogs.manage_any') || ((int)$log['created_by'] === user_id() && can('sitelogs.create'));

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'delete') {
        $log = db_one($pdo, 'SELECT * FROM site_logs WHERE id = ?', [$id]);
        if (!$log || !$canEdit($log)) {
            forbidden();
        }
        db_exec($pdo, 'DELETE FROM site_logs WHERE id = ?', [$id]);
        audit('sitelog.deleted', 'project', (int)$log['project_id'], ['log_id' => $id, 'log_date' => $log['log_date']]);
        flash('success', 'Site log deleted.');
        redirect(url('sitelogs', ['project_id' => $log['project_id']]));
    }
    if (in_array($action, ['create', 'update'], true)) {
        require_permission('sitelogs.create');
        $data = [
            'project_id' => (int)($_POST['project_id'] ?? 0),
            'log_date' => post_str('log_date'),
            'weather' => str_limit(post_str('weather'), 80) ?: null,
            'crew_count' => filter_var($_POST['crew_count'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]),
            'work_done' => post_str('work_done'),
            'issues' => post_str('issues') ?: null,
            'safety_notes' => post_str('safety_notes') ?: null,
        ];
        $error = match (true) {
            !project_visible($pdo, $data['project_id']) => 'Choose a project you have access to.',
            !valid_date($data['log_date']) || $data['log_date'] > today() => 'Enter a valid log date (not in the future).',
            $data['crew_count'] === false => 'Crew count must be a whole number of 0 or more.',
            $data['work_done'] === '' => 'Describe the work done on site.',
            default => null,
        };
        if ($error) {
            flash('error', $error);
            redirect(url('sitelogs', ['edit' => $id ?: null, 'project_id' => $data['project_id'] ?: null]));
        }
        if ($action === 'create') {
            db_exec($pdo, 'INSERT INTO site_logs (project_id, log_date, weather, crew_count, work_done, issues, safety_notes, created_by) VALUES (?,?,?,?,?,?,?,?)', [...array_values($data), user_id()]);
            audit('sitelog.created', 'project', $data['project_id'], ['log_id' => (int)$pdo->lastInsertId(), 'log_date' => $data['log_date']]);
            flash('success', 'Site log saved.');
        } else {
            $before = db_one($pdo, 'SELECT * FROM site_logs WHERE id = ?', [$id]);
            if (!$before || !$canEdit($before)) {
                forbidden();
            }
            db_exec($pdo, 'UPDATE site_logs SET project_id=?, log_date=?, weather=?, crew_count=?, work_done=?, issues=?, safety_notes=? WHERE id=?', [...array_values($data), $id]);
            audit('sitelog.updated', 'project', $data['project_id'], ['log_id' => $id] + audit_diff($before, $data));
            flash('success', 'Site log updated.');
        }
        redirect(url('sitelogs', ['project_id' => $data['project_id']]));
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $edit = db_one($pdo, 'SELECT * FROM site_logs WHERE id = ?', [(int)$_GET['edit']]);
    if ($edit && !$canEdit($edit)) {
        $edit = null;
    }
}
$projects = visible_projects($pdo);
$projectFilter = (int)get_str('project_id', '0');
$from = get_str('from');
$to = get_str('to');
$q = get_str('q');
$issuesOnly = get_str('issues') === '1';
[$scope, $params] = current_project_access_sql();
$sql = "SELECT s.*, p.name AS project_name, u.full_name AS author FROM site_logs s JOIN projects p ON p.id = s.project_id JOIN users u ON u.id = s.created_by WHERE 1=1 $scope";
if ($projectFilter > 0) {
    $sql .= ' AND s.project_id = ?';
    $params[] = $projectFilter;
}
if (valid_date($from)) {
    $sql .= ' AND s.log_date >= ?';
    $params[] = $from;
}
if (valid_date($to)) {
    $sql .= ' AND s.log_date <= ?';
    $params[] = $to;
}
if ($q !== '') {
    $sql .= ' AND (s.work_done LIKE ? OR s.issues LIKE ? OR s.safety_notes LIKE ? OR s.weather LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($issuesOnly) {
    $sql .= " AND (COALESCE(s.issues, '') <> '' OR COALESCE(s.safety_notes, '') <> '')";
}
$logs = db_all($pdo, $sql . ' ORDER BY s.log_date DESC, s.id DESC LIMIT 200', $params);
$openProjects = array_values(array_filter($projects, fn($p) => $p['status'] !== 'completed'));
require __DIR__ . '/../src/layout/header.php';
?>
<div class="page-toolbar"><p class="muted">A dated record of what happened on site: weather, crew, work completed, problems and safety observations.</p><div class="toolbar-count"><strong><?= count($logs) ?></strong> log<?= count($logs) === 1 ? '' : 's' ?></div></div>
<?php if (can('sitelogs.create')): ?>
<details class="panel form-panel" <?= $edit || $projectFilter ? 'open' : '' ?>><summary class="panel-heading"><div><h3><?= $edit ? 'Edit site log' : '+ Write today\'s site log' ?></h3><p>One entry per visit or shift is fine; multiple supervisors can log the same day.</p></div></summary>
<?php if (!$openProjects && !$edit): ?><div class="alert alert-info">There are no open projects to log against.</div><?php else: ?>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<label>Project *<select name="project_id" required><?php foreach ($edit ? $projects : $openProjects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($edit['project_id'] ?? $projectFilter) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
<label>Date *<input type="date" name="log_date" required max="<?= e(today()) ?>" value="<?= e($edit['log_date'] ?? today()) ?>"></label>
<label>Weather<input name="weather" maxlength="80" value="<?= e($edit['weather'] ?? '') ?>" placeholder="e.g. Sunny, 26°C; rain after 3pm"></label>
<label>Crew on site<input type="number" name="crew_count" min="0" step="1" value="<?= e($edit['crew_count'] ?? '0') ?>"></label>
<label class="span-2">Work done *<textarea name="work_done" rows="3" required placeholder="What was completed today?"><?= e($edit['work_done'] ?? '') ?></textarea></label>
<label>Issues / delays<textarea name="issues" rows="2" placeholder="Material shortages, breakdowns, inspections failed…"><?= e($edit['issues'] ?? '') ?></textarea></label>
<label>Safety notes<textarea name="safety_notes" rows="2" placeholder="Incidents, near misses, toolbox talks…"><?= e($edit['safety_notes'] ?? '') ?></textarea></label>
<div class="form-actions span-2"><button class="btn btn-primary"><?= $edit ? 'Save log' : 'Save site log' ?></button><?php if ($edit): ?><a class="btn btn-outline" href="<?= e(url('sitelogs')) ?>">Cancel</a><?php endif; ?></div></form><?php endif; ?></details>
<?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h3>Site diary</h3><p>Newest first (up to 200 entries; narrow with filters).</p></div></div>
<form method="get" class="filter-bar"><input type="hidden" name="page" value="sitelogs"><input name="q" value="<?= e($q) ?>" placeholder="Search work, issues, weather">
<select name="project_id"><option value="0">All projects</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $projectFilter === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
<input type="date" name="from" value="<?= e($from) ?>" aria-label="From date"><input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
<label class="checkbox"><input type="checkbox" name="issues" value="1" <?= $issuesOnly ? 'checked' : '' ?>> With issues/safety notes</label>
<button class="btn btn-outline">Filter</button><a class="text-link" href="<?= e(url('sitelogs')) ?>">Reset</a></form>
<?php if (!$logs): ?><div class="empty-state"><div class="empty-icon">✎</div><h3>No site logs found</h3><p><?= can('sitelogs.create') ? 'Write the first daily log with the form above.' : 'Supervisors\' daily logs for your projects appear here.' ?></p></div><?php else: ?>
<div class="log-list"><?php foreach ($logs as $s): ?>
<article class="log-card"><header><div><strong><?= e(date_label($s['log_date'])) ?></strong> · <a class="row-link" href="<?= e(url('project', ['id' => $s['project_id']])) ?>"><?= e($s['project_name']) ?></a></div>
<div class="muted small"><?= e($s['weather'] ?: 'Weather not noted') ?> · crew <?= (int)$s['crew_count'] ?> · by <?= e($s['author']) ?></div></header>
<p><?= nl2br(e($s['work_done'])) ?></p>
<?php if ($s['issues']): ?><p class="log-issue"><strong>Issues:</strong> <?= nl2br(e($s['issues'])) ?></p><?php endif; ?>
<?php if ($s['safety_notes']): ?><p class="log-safety"><strong>Safety:</strong> <?= nl2br(e($s['safety_notes'])) ?></p><?php endif; ?>
<?php if ($canEdit($s)): ?><div class="actions"><a class="action-link" href="<?= e(url('sitelogs', ['edit' => $s['id']])) ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this site log?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="action-link danger-link">Delete</button></form></div><?php endif; ?>
</article><?php endforeach; ?></div><?php endif; ?></section>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
