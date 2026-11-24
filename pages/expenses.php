<?php
/** @var PDO $pdo */
require_permission('expenses.view');
$pageTitle = 'Expenses & budget';

if (is_post()) {
    verify_csrf();
    require_permission('expenses.manage');
    $action = post_str('action');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'delete') {
        $before = db_one($pdo, 'SELECT * FROM expenses WHERE id = ?', [$id]);
        db_exec($pdo, 'DELETE FROM expenses WHERE id = ?', [$id]);
        if ($before) {
            audit('expense.deleted', 'project', (int)$before['project_id'], ['expense_id' => $id, 'description' => $before['description'], 'amount' => $before['amount']]);
        }
        flash('success', 'Expense deleted.');
        redirect(url('expenses', ['project_id' => $before['project_id'] ?? null]));
    }
    if (in_array($action, ['create', 'update'], true)) {
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $data = [
            'project_id' => (int)($_POST['project_id'] ?? 0),
            'category' => post_str('category', 'other'),
            'description' => post_str('description'),
            'vendor' => post_str('vendor') ?: null,
            'amount' => $amount === false ? null : round($amount, 2),
            'spent_on' => post_str('spent_on'),
        ];
        $error = match (true) {
            !db_value($pdo, 'SELECT id FROM projects WHERE id = ?', [$data['project_id']]) => 'Choose a project.',
            $data['description'] === '' || strlen($data['description']) > 255 => 'Enter a description (up to 255 characters).',
            $data['amount'] === null || $data['amount'] < 0 => 'Amount must be zero or a positive number.',
            !valid_date($data['spent_on']) => 'Enter the date the money was spent.',
            !in_array($data['category'], EXPENSE_CATEGORIES, true) => 'Choose a valid category.',
            default => null,
        };
        if ($error) {
            flash('error', $error);
            redirect(url('expenses', ['edit' => $id ?: null, 'project_id' => $data['project_id'] ?: null]));
        }
        if ($action === 'create') {
            db_exec($pdo, 'INSERT INTO expenses (project_id, category, description, vendor, amount, spent_on, recorded_by) VALUES (?,?,?,?,?,?,?)', [...array_values($data), user_id()]);
            audit('expense.created', 'project', $data['project_id'], ['expense_id' => (int)$pdo->lastInsertId(), 'amount' => $data['amount'], 'description' => $data['description']]);
            $fin = project_financials($pdo, false, $data['project_id'])[0] ?? null;
            flash('success', 'Expense recorded.' . ($fin && $fin['over_budget'] ? ' Warning: this project is now over budget (' . money($fin['spent']) . ' of ' . money($fin['budget']) . ').' : ''));
        } else {
            $before = db_one($pdo, 'SELECT * FROM expenses WHERE id = ?', [$id]);
            db_exec($pdo, 'UPDATE expenses SET project_id=?, category=?, description=?, vendor=?, amount=?, spent_on=? WHERE id=?', [...array_values($data), $id]);
            audit('expense.updated', 'project', $data['project_id'], ['expense_id' => $id] + ($before ? audit_diff($before, $data) : []));
            flash('success', 'Expense updated.');
        }
        redirect(url('expenses', ['project_id' => $data['project_id']]));
    }
}

$edit = isset($_GET['edit']) && can('expenses.manage') ? db_one($pdo, 'SELECT * FROM expenses WHERE id = ?', [(int)$_GET['edit']]) : null;
$projects = visible_projects($pdo);
$projectFilter = (int)get_str('project_id', '0');
$categoryFilter = get_str('category');
$from = get_str('from');
$to = get_str('to');
$q = get_str('q');
$sql = 'SELECT x.*, p.name AS project_name, u.full_name AS recorder FROM expenses x JOIN projects p ON p.id = x.project_id JOIN users u ON u.id = x.recorded_by WHERE 1=1';
$params = [];
if ($projectFilter > 0) {
    $sql .= ' AND x.project_id = ?';
    $params[] = $projectFilter;
}
if (in_array($categoryFilter, EXPENSE_CATEGORIES, true)) {
    $sql .= ' AND x.category = ?';
    $params[] = $categoryFilter;
}
if (valid_date($from)) {
    $sql .= ' AND x.spent_on >= ?';
    $params[] = $from;
}
if (valid_date($to)) {
    $sql .= ' AND x.spent_on <= ?';
    $params[] = $to;
}
if ($q !== '') {
    $sql .= ' AND (x.description LIKE ? OR x.vendor LIKE ?)';
    array_push($params, "%$q%", "%$q%");
}
$rows = db_all($pdo, $sql . ' ORDER BY x.spent_on DESC, x.id DESC', $params);
$total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));

if (get_str('export') === 'csv') {
    audit('export.csv', 'expenses', null, ['rows' => count($rows)]);
    send_csv('buildtrack-expenses-' . date('Y-m-d') . '.csv', ['Date', 'Project', 'Category', 'Description', 'Vendor', 'Amount', 'Recorded by'],
        array_map(fn($r) => [$r['spent_on'], $r['project_name'], $r['category'], $r['description'], $r['vendor'], number_format((float)$r['amount'], 2, '.', ''), $r['recorder']], $rows));
}

$financials = project_financials($pdo, false, $projectFilter ?: null);
$exportParams = ['export' => 'csv', 'project_id' => $projectFilter ?: null, 'category' => $categoryFilter, 'from' => $from, 'to' => $to, 'q' => $q];
require __DIR__ . '/../src/layout/header.php';
?>
<div class="page-toolbar"><p class="muted">Record spending against each project and compare it with the budget.</p><a class="btn btn-outline" href="<?= e(url('expenses', $exportParams)) ?>">↓ Export CSV</a></div>
<?php if (can('expenses.manage')): ?>
<details class="panel form-panel" <?= $edit ? 'open' : '' ?>><summary class="panel-heading"><div><h3><?= $edit ? 'Edit expense' : '+ Record an expense' ?></h3><p>Materials, labour, equipment hire, permits and other costs.</p></div></summary>
<?php if (!$projects): ?><div class="alert alert-info">Create a project first.</div><?php else: ?>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<label>Project *<select name="project_id" required><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($edit['project_id'] ?? $projectFilter) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
<label>Date *<input type="date" name="spent_on" required value="<?= e($edit['spent_on'] ?? today()) ?>"></label>
<label class="span-2">Description *<input name="description" required maxlength="255" value="<?= e($edit['description'] ?? '') ?>" placeholder="e.g. 200 bags cement"></label>
<label>Category<select name="category"><?php foreach (EXPENSE_CATEGORIES as $c): ?><option value="<?= e($c) ?>" <?= ($edit['category'] ?? 'materials') === $c ? 'selected' : '' ?>><?= e(ucfirst($c)) ?></option><?php endforeach; ?></select></label>
<label>Amount (<?= e(config('CURRENCY', 'KSh')) ?>) *<input type="number" name="amount" min="0" step="0.01" required value="<?= e($edit['amount'] ?? '') ?>"></label>
<label class="span-2">Vendor / supplier<input name="vendor" maxlength="160" value="<?= e($edit['vendor'] ?? '') ?>"></label>
<div class="form-actions span-2"><button class="btn btn-primary"><?= $edit ? 'Save expense' : 'Record expense' ?></button><?php if ($edit): ?><a class="btn btn-outline" href="<?= e(url('expenses')) ?>">Cancel</a><?php endif; ?></div></form><?php endif; ?></details>
<?php endif; ?>

<section class="panel"><div class="panel-heading"><div><h3>Budget vs actual</h3><p><?= $projectFilter ? 'Selected project' : 'All projects' ?></p></div></div>
<?php if (!$financials): ?><div class="empty-inline">No projects yet.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Project</th><th>Budget</th><th>Spent</th><th>Remaining</th><th>Used</th></tr></thead><tbody>
<?php foreach ($financials as $f): $pct = $f['budget_used_pct']; ?><tr><td><a class="row-link" href="<?= e(url('expenses', ['project_id' => $f['id']])) ?>"><strong><?= e($f['name']) ?></strong></a><small class="cell-sub"><?= e(status_label($f['status'])) ?></small></td><td><?= $f['budget'] !== null ? e(money($f['budget'])) : '<span class="muted">Not set</span>' ?></td><td><?= e(money($f['spent'])) ?></td><td class="<?= $f['over_budget'] ? 'text-danger' : '' ?>"><?= $f['budget'] !== null ? e(money((float)$f['budget'] - (float)$f['spent'])) : '—' ?></td><td><?php if ($pct !== null): ?><div class="progress-line <?= budget_bar_class($pct) ?>"><span style="width:<?= min(100, (int)round($pct)) ?>%"></span></div><small><?= (int)round($pct) ?>%</small><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>

<section class="panel"><div class="panel-heading"><div><h3>Expense ledger</h3><p><?= count($rows) ?> entries · total <?= e(money($total)) ?></p></div></div>
<form method="get" class="filter-bar"><input type="hidden" name="page" value="expenses"><input name="q" value="<?= e($q) ?>" placeholder="Search description or vendor">
<select name="project_id"><option value="0">All projects</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $projectFilter === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
<select name="category"><option value="">All categories</option><?php foreach (EXPENSE_CATEGORIES as $c): ?><option value="<?= e($c) ?>" <?= $categoryFilter === $c ? 'selected' : '' ?>><?= e(ucfirst($c)) ?></option><?php endforeach; ?></select>
<input type="date" name="from" value="<?= e($from) ?>" aria-label="From date"><input type="date" name="to" value="<?= e($to) ?>" aria-label="To date">
<button class="btn btn-outline">Filter</button><a class="text-link" href="<?= e(url('expenses')) ?>">Reset</a></form>
<?php if (!$rows): ?><div class="empty-state"><div class="empty-icon">¤</div><h3>No expenses found</h3><p><?= can('expenses.manage') ? 'Record spending with the form above.' : 'Expenses recorded by project managers appear here.' ?></p></div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Date</th><th>Project</th><th>Description</th><th>Category</th><th>Amount</th><th>Recorded by</th><?php if (can('expenses.manage')): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $x): ?><tr><td><?= e(date_label($x['spent_on'])) ?></td><td><?= e($x['project_name']) ?></td><td><strong><?= e($x['description']) ?></strong><small class="cell-sub"><?= e($x['vendor'] ?: '—') ?></small></td><td><?= e(ucfirst($x['category'])) ?></td><td><strong><?= e(money($x['amount'])) ?></strong></td><td><?= e($x['recorder']) ?></td>
<?php if (can('expenses.manage')): ?><td class="actions"><a class="action-link" href="<?= e(url('expenses', ['edit' => $x['id']])) ?>">Edit</a><form method="post" onsubmit="return confirm('Delete this expense?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="action-link danger-link">Delete</button></form></td><?php endif; ?></tr><?php endforeach; ?>
</tbody><tfoot><tr><th colspan="4">Total</th><th><?= e(money($total)) ?></th><th colspan="<?= can('expenses.manage') ? 2 : 1 ?>"></th></tr></tfoot></table></div><?php endif; ?></section>
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
