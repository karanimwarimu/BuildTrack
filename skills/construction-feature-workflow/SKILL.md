---
name: construction-feature-workflow
description: Add a new construction module or entity to BuildTrack (e.g. inspections, purchase orders, subcontractors, snag lists) end to end — migration, permissions, validation, page/route, navigation, audit, dashboard/report hooks, tests and docs — following the existing conventions.
---

# Construction feature workflow

## When to use
A new kind of record or screen: inspections, RFIs, purchase orders, subcontractor register, snag/punch list, equipment maintenance, or similar. For small field additions to an existing entity, only steps 1, 4 and 8 apply.

## Reference implementation
`pages/expenses.php` and `pages/sitelogs.php` were built with this exact workflow. Copy their structure.

## Steps

### 1. Schema (skill: `sqlite-schema-and-migrations`)
Create `migrations/NNN_<feature>.sql`. Child records of a project use
`project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE` and `created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT`. Add indexes on FKs and filter columns, plus an `updated_at` trigger.

### 2. Constants & permissions (`src/helpers.php`)
- Enum lists: `const INSPECTION_RESULTS = ['pass', 'fail', 'conditional'];` These must match the CHECK constraint.
- Permissions: e.g. `'inspections.create' => [...]`, `'inspections.manage_any' => [...]`. See skill `auth-and-admin-rbac`.

### 3. Domain queries (optional, `src/construction.php`)
Put anything reused by more than one page (dashboard widgets, reports, tests) in a function taking `PDO $pdo`. Keep page-specific queries in the page.

### 4. Page controller + template: `pages/<feature>.php`
Follow this skeleton (Post/Redirect/Get):
```php
<?php
/** @var PDO $pdo */
$pageTitle = 'Inspections';
if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if (in_array($action, ['create', 'update'], true)) {
        require_permission('inspections.create');
        $data = [/* read with post_str(), filter_var(); same key order as the INSERT columns */];
        $error = match (true) {                      // validate everything; dates with valid_date()
            !project_visible($pdo, $data['project_id']) => 'Choose a project you have access to.',
            default => null,
        };
        if ($error) { flash('error', $error); redirect(url('inspections')); }
        db_exec($pdo, 'INSERT INTO inspections (...) VALUES (...)', [...array_values($data), user_id()]);
        audit('inspection.created', 'project', $data['project_id'], ['inspection_id' => (int)$pdo->lastInsertId()]);
        flash('success', 'Inspection recorded.');
        redirect(url('inspections', ['project_id' => $data['project_id']]));
    }
    // delete: load row, check ownership/permission, delete, audit, flash, redirect
}
// GET: filters from get_str(); visibility scope; db_all(); then:
require __DIR__ . '/../src/layout/header.php';
?>
<!-- page-toolbar, <details class="panel form-panel"> create form, filter-bar, table/empty-state -->
<?php require __DIR__ . '/../src/layout/footer.php'; ?>
```
Conventions:
- **Escape every output** with `e()`. Build links with `url('page', [...])`. Format with `date_label()`, `datetime_label()`, `money()`, `qty()`.
- **Visibility:** list queries use `current_project_access_sql()` / `current_task_access_sql()`; single records use `project_visible()` / `task_visible()`.
- **Multi-step writes** that must stay consistent use `db_transaction($pdo, fn(PDO $pdo) => ...)`.
- **Throw `UserError`** for messages safe to show; catch and `flash('error', ...)`.
- **Empty states:** `<div class="empty-state">` with a hint that depends on the user's permissions; errors as `alert alert-error`.
- **Filters:** `<form method="get" class="filter-bar">` with a hidden `page` input, plus a *Reset* link.
- **CSV export:** `if (get_str('export') === 'csv') send_csv(...)` *before* the header include, and `audit('export.csv', ...)`.

### 5. Route & navigation
- `public/index.php`: add `'inspections' => 'inspections.php'` to `PRIVATE_ROUTES`. Pretty URL `/inspections` then works automatically.
- `src/layout/header.php`: add `['inspections', 'Inspections', '✓', can('inspections.view') /* or true */]` to `$navItems`.

### 6. Hooks into existing screens (as relevant)
- Project page `pages/project.php`: a panel listing the latest records with a link to the module filtered by `project_id`.
- Dashboard `pages/dashboard.php` or alerts `pages/alerts.php`: counts or alerts (e.g. failed inspections).
- Admin CSV exports: add an entry to `$exports` in `pages/admin.php`.
- Health table counts in `system_health()` (`src/backup.php`).
- Deleting a project already cascades to child tables. If the feature stores files, reuse `attachments`, or follow `attachments_files_for()` + `attachments_unlink()`.

### 7. Demo data
Add a few realistic rows in `seeds/demo.php` (dates relative to today with `$d(-3)`).

### 8. Tests
- Unit-test domain functions in `tests/<feature>_test.php` with `fresh_db()`, `make_user()`, `act_as()`.
- Extend `tests/http_test.php` with one create-and-read round trip and one forbidden (403) check for a role without permission.

### 9. Docs
- Add a README feature line and update the ARCHITECTURE data-model diagram and permission table.
- Add rows to `docs/manual-test-plan.md`.

## Verification
```bash
make lint && make test
php bin/console seed --fresh && ./start.sh      # click through as admin, supervisor and worker
php bin/console audit --limit=10                # your audit actions appear
```
Checklist: CSRF on every form ✔ server-side permission checks ✔ worker sees only their projects ✔ invalid input shows a friendly error ✔ empty state ✔ mobile width (≤560px) still usable ✔ audit entries ✔ tests green.
