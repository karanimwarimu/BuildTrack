<?php
/** @var PDO $pdo */
$pageTitle = 'Resources';
$canManage = can('resources.manage');

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'resource_delete') {
        require_permission('resources.delete');
        $before = db_one($pdo, 'SELECT * FROM resources WHERE id = ?', [$id]);
        db_exec($pdo, 'DELETE FROM resources WHERE id = ?', [$id]);
        if ($before) {
            audit('resource.deleted', 'resource', $id, ['name' => $before['name']]);
        }
        flash('success', 'Resource deleted.');
        redirect(url('resources'));
    }
    if (in_array($action, ['resource_create', 'resource_update'], true)) {
        require_permission('resources.manage');
        $qty = filter_var($_POST['quantity_available'] ?? null, FILTER_VALIDATE_FLOAT);
        $threshold = post_str('low_stock_threshold') === '' ? null : filter_var($_POST['low_stock_threshold'], FILTER_VALIDATE_FLOAT);
        $data = [
            'name' => post_str('name'),
            'resource_type' => post_str('resource_type', 'other'),
            'unit' => post_str('unit') ?: 'unit',
            'quantity_available' => $qty,
            'low_stock_threshold' => $threshold,
            'notes' => post_str('notes') ?: null,
        ];
        if ($data['name'] === '' || strlen($data['name']) > 160 || $qty === false || $qty < 0 || $threshold === false || ($threshold !== null && $threshold < 0)) {
            flash('error', 'Enter a resource name, a non-negative quantity and (optionally) a non-negative low-stock threshold.');
            redirect(url('resources', ['edit' => $id ?: null]));
        }
        if (!in_array($data['resource_type'], RESOURCE_TYPES, true)) {
            $data['resource_type'] = 'other';
        }
        if ($action === 'resource_create') {
            db_exec($pdo, 'INSERT INTO resources (name, resource_type, unit, quantity_available, low_stock_threshold, notes, created_by) VALUES (?,?,?,?,?,?,?)', [...array_values($data), user_id()]);
            audit('resource.created', 'resource', (int)$pdo->lastInsertId(), ['name' => $data['name'], 'quantity' => $qty]);
            flash('success', 'Resource recorded.');
        } else {
            $before = db_one($pdo, 'SELECT * FROM resources WHERE id = ?', [$id]);
            db_exec($pdo, 'UPDATE resources SET name=?, resource_type=?, unit=?, quantity_available=?, low_stock_threshold=?, notes=? WHERE id=?', [...array_values($data), $id]);
            audit('resource.updated', 'resource', $id, $before ? audit_diff($before, $data) : []);
            flash('success', 'Resource updated.');
        }
        redirect(url('resources'));
    }
    if ($action === 'allocate') {
        require_permission('resources.manage');
        $taskId = (int)($_POST['task_id'] ?? 0);
        $resourceId = (int)($_POST['resource_id'] ?? 0);
        $qty = filter_var($_POST['quantity_assigned'] ?? null, FILTER_VALIDATE_FLOAT);
        $notes = str_limit(post_str('allocation_notes'), 255);
        if ($qty === false || $qty <= 0) {
            flash('error', 'Allocation quantity must be greater than zero.');
            redirect(url('resources'));
        }
        try {
            // Check capacity and write in one transaction so two simultaneous allocations cannot overbook.
            db_transaction($pdo, function (PDO $pdo) use ($taskId, $resourceId, $qty, $notes) {
                $capacity = db_value($pdo, 'SELECT quantity_available FROM resources WHERE id = ?', [$resourceId]);
                if ($capacity === null || !db_value($pdo, 'SELECT id FROM tasks WHERE id = ?', [$taskId])) {
                    throw new UserError('Choose a valid resource and task.');
                }
                $used = (float)db_value($pdo, 'SELECT COALESCE(SUM(quantity_assigned), 0) FROM task_resources WHERE resource_id = ? AND task_id <> ?', [$resourceId, $taskId]);
                if ($used + $qty > (float)$capacity + 1e-9) {
                    throw new UserError('That allocation exceeds the quantity available after existing allocations (' . qty((float)$capacity - $used) . ' left).');
                }
                db_exec($pdo, 'INSERT INTO task_resources (task_id, resource_id, quantity_assigned, notes, assigned_by) VALUES (?,?,?,?,?)
                    ON CONFLICT (task_id, resource_id) DO UPDATE SET quantity_assigned = excluded.quantity_assigned, notes = excluded.notes, assigned_by = excluded.assigned_by',
                    [$taskId, $resourceId, $qty, $notes ?: null, user_id()]);
            });
            audit('resource.allocated', 'task', $taskId, ['resource_id' => $resourceId, 'quantity' => $qty]);
            flash('success', 'Resource allocated to the task.');
        } catch (UserError $ex) {
            flash('error', $ex->getMessage());
        }
        redirect(url('resources'));
    }
    if ($action === 'allocation_delete') {
        require_permission('resources.manage');
        $row = db_one($pdo, 'SELECT * FROM task_resources WHERE id = ?', [$id]);
        db_exec($pdo, 'DELETE FROM task_resources WHERE id = ?', [$id]);
        if ($row) {
            audit('resource.allocation_removed', 'task', (int)$row['task_id'], ['resource_id' => (int)$row['resource_id'], 'quantity' => $row['quantity_assigned']]);
        }
        flash('success', 'Resource allocation removed.');
        redirect(url('resources'));
    }
}

$edit = null;
if (isset($_GET['edit']) && $canManage) {
    $edit = db_one($pdo, 'SELECT * FROM resources WHERE id = ?', [(int)$_GET['edit']]);
}
$q = get_str('q');
$typeFilter = get_str('type');
$lowOnly = get_str('low') === '1';
$sql = 'SELECT * FROM (SELECT r.*, COALESCE((SELECT SUM(tr.quantity_assigned) FROM task_resources tr WHERE tr.resource_id = r.id), 0) AS allocated FROM resources r) r WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (r.name LIKE ? OR r.notes LIKE ?)';
    array_push($params, "%$q%", "%$q%");
}
if (in_array($typeFilter, RESOURCE_TYPES, true)) {
    $sql .= ' AND r.resource_type = ?';
    $params[] = $typeFilter;
}
if ($lowOnly) {
    $sql .= ' AND r.low_stock_threshold IS NOT NULL AND r.quantity_available - r.allocated <= r.low_stock_threshold';
}
$resources = db_all($pdo, $sql . ' ORDER BY r.name', $params);
$allResources = $canManage ? db_all($pdo, 'SELECT r.id, r.name, r.unit, r.quantity_available - COALESCE((SELECT SUM(quantity_assigned) FROM task_resources WHERE resource_id = r.id), 0) AS remaining FROM resources r ORDER BY r.name') : [];
[$scope, $scopeParams] = current_task_access_sql();
$tasks = db_all($pdo, "SELECT t.id, t.title, p.name AS project_name FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.status <> 'completed' $scope ORDER BY p.name, t.title", $scopeParams);
$allocations = db_all($pdo, "SELECT tr.*, r.name AS resource_name, r.unit, t.title AS task_title, p.name AS project_name, u.full_name AS assigned_by_name
    FROM task_resources tr JOIN resources r ON r.id = tr.resource_id JOIN tasks t ON t.id = tr.task_id JOIN projects p ON p.id = t.project_id JOIN users u ON u.id = tr.assigned_by
    WHERE 1=1 $scope ORDER BY tr.created_at DESC, tr.id DESC", $scopeParams);
require __DIR__ . '/../src/layout/header.php';
?>
<div class="page-toolbar"><p class="muted">Maintain a resource register, set low-stock alerts and allocate materials, equipment or labour to scheduled tasks.</p></div>
<?php if ($canManage): ?>
<details class="panel form-panel" <?= $edit ? 'open' : '' ?>><summary class="panel-heading"><div><h3><?= $edit ? 'Edit resource: ' . e($edit['name']) : '+ Add a resource' ?></h3><p>Record available quantities before allocating them to tasks.</p></div></summary>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'resource_update' : 'resource_create' ?>"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<label>Resource name *<input name="name" maxlength="160" required value="<?= e($edit['name'] ?? '') ?>" placeholder="Cement, excavator, mason"></label>
<label>Type<select name="resource_type"><?php foreach (RESOURCE_TYPES as $t): ?><option value="<?= e($t) ?>" <?= ($edit['resource_type'] ?? 'other') === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option><?php endforeach; ?></select></label>
<label>Quantity available *<input type="number" step="0.01" min="0" required name="quantity_available" value="<?= e($edit['quantity_available'] ?? '0') ?>"></label>
<label>Unit<input name="unit" maxlength="40" value="<?= e($edit['unit'] ?? 'unit') ?>" placeholder="bags, hours, people"></label>
<label>Low-stock alert at (remaining)<input type="number" step="0.01" min="0" name="low_stock_threshold" value="<?= e($edit['low_stock_threshold'] ?? '') ?>" placeholder="Optional, e.g. 20"></label>
<label>Notes<input name="notes" value="<?= e($edit['notes'] ?? '') ?>" maxlength="500"></label>
<div class="form-actions span-2"><button class="btn btn-primary"><?= $edit ? 'Save resource' : 'Add resource' ?></button><?php if ($edit): ?><a class="btn btn-outline" href="<?= e(url('resources')) ?>">Cancel</a><?php endif; ?></div></form></details>
<?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h3>Resource register</h3><p>Available quantity compared with current task allocations.</p></div><span class="count-chip"><?= count($resources) ?> items</span></div>
<form method="get" class="filter-bar"><input type="hidden" name="page" value="resources"><input name="q" value="<?= e($q) ?>" placeholder="Search name or notes">
<select name="type"><option value="">All types</option><?php foreach (RESOURCE_TYPES as $t): ?><option value="<?= e($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>><?= e(ucfirst($t)) ?></option><?php endforeach; ?></select>
<label class="checkbox"><input type="checkbox" name="low" value="1" <?= $lowOnly ? 'checked' : '' ?>> Low stock only</label>
<button class="btn btn-outline">Filter</button><a class="text-link" href="<?= e(url('resources')) ?>">Reset</a></form>
<?php if (!$resources): ?><div class="empty-state"><div class="empty-icon">▤</div><h3><?= $q || $typeFilter || $lowOnly ? 'No matching resources' : 'No resources recorded' ?></h3><p><?= $lowOnly ? 'Nothing is at or below its low-stock threshold.' : 'Add materials, equipment or labour resources to begin tracking.' ?></p></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Resource</th><th>Type</th><th>Total available</th><th>Allocated</th><th>Remaining</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($resources as $r): $remaining = (float)$r['quantity_available'] - (float)$r['allocated']; $low = $r['low_stock_threshold'] !== null && $remaining <= (float)$r['low_stock_threshold']; ?>
<tr><td><strong><?= e($r['name']) ?></strong><?php if ($r['notes']): ?><small class="cell-sub"><?= e($r['notes']) ?></small><?php endif; ?></td><td><?= e(ucfirst($r['resource_type'])) ?></td>
<td><?= e(qty($r['quantity_available'])) ?> <?= e($r['unit']) ?></td><td><?= e(qty($r['allocated'])) ?> <?= e($r['unit']) ?></td>
<td><strong class="<?= $remaining <= 0 ? 'text-danger' : ($low ? 'text-warning' : '') ?>"><?= e(qty($remaining)) ?> <?= e($r['unit']) ?></strong><?php if ($low): ?><small class="cell-sub"><span class="priority priority-high">Low stock</span> alert at <?= e(qty($r['low_stock_threshold'])) ?></small><?php elseif ($r['low_stock_threshold'] !== null): ?><small class="cell-sub">Alert at <?= e(qty($r['low_stock_threshold'])) ?></small><?php endif; ?></td>
<?php if ($canManage): ?><td class="actions"><a class="action-link" href="<?= e(url('resources', ['edit' => $r['id']])) ?>">Edit</a><?php if (can('resources.delete')): ?><form method="post" onsubmit="return confirm('Delete resource and its allocations?');"><?= csrf_field() ?><input type="hidden" name="action" value="resource_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="action-link danger-link">Delete</button></form><?php endif; ?></td><?php endif; ?></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php if ($canManage): ?><section class="panel form-panel"><div class="panel-heading"><div><h3>Allocate a resource</h3><p>Assign quantities to a task; allocations are checked against the available quantity. Re-allocating to the same task replaces the earlier quantity.</p></div></div>
<?php if (!$allResources || !$tasks): ?><div class="alert alert-info">You need at least one resource and one unfinished task before making an allocation.</div><?php else: ?>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="allocate">
<label class="span-2">Task *<select name="task_id" required><?php foreach ($tasks as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['project_name'] . ' — ' . $t['title']) ?></option><?php endforeach; ?></select></label>
<label>Resource *<select name="resource_id" required><?php foreach ($allResources as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name'] . ' (remaining ' . qty($r['remaining']) . ' ' . $r['unit'] . ')') ?></option><?php endforeach; ?></select></label>
<label>Quantity to allocate *<input type="number" name="quantity_assigned" step="0.01" min="0.01" required></label>
<label class="span-2">Allocation notes<input name="allocation_notes" maxlength="255" placeholder="Optional usage notes"></label><div class="form-actions span-2"><button class="btn btn-primary">Save allocation</button></div></form><?php endif; ?></section><?php endif; ?>
<section class="panel"><div class="panel-heading"><div><h3>Task resource allocations</h3><p>Current resource assignments.</p></div></div>
<?php if (!$allocations): ?><div class="empty-inline">No resource allocations have been recorded.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Task / project</th><th>Resource</th><th>Quantity</th><th>Notes</th><th>Assigned by</th><th>Action</th></tr></thead><tbody>
<?php foreach ($allocations as $a): ?><tr><td><strong><?= e($a['task_title']) ?></strong><small class="cell-sub"><?= e($a['project_name']) ?></small></td><td><?= e($a['resource_name']) ?></td><td><?= e(qty($a['quantity_assigned'])) ?> <?= e($a['unit']) ?></td><td><?= e($a['notes'] ?: '—') ?></td><td><?= e($a['assigned_by_name']) ?><small class="cell-sub"><?= e(datetime_label($a['created_at'])) ?></small></td>
<td class="actions"><?php if ($canManage): ?><form method="post" onsubmit="return confirm('Remove this allocation?');"><?= csrf_field() ?><input type="hidden" name="action" value="allocation_delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="action-link danger-link">Remove</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
