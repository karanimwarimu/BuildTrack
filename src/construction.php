<?php
declare(strict_types=1);

/**
 * Reusable construction-domain queries (dashboard, project page, reports, tests).
 * Skill: skills/construction-feature-workflow/SKILL.md
 */

/** Resources whose remaining (available − allocated) quantity is at or below their low-stock threshold. */
function low_stock_resources(PDO $pdo): array
{
    return db_all($pdo, 'SELECT r.*, COALESCE(a.allocated, 0) AS allocated,
            r.quantity_available - COALESCE(a.allocated, 0) AS remaining
        FROM resources r
        LEFT JOIN (SELECT resource_id, SUM(quantity_assigned) AS allocated FROM task_resources GROUP BY resource_id) a ON a.resource_id = r.id
        WHERE r.low_stock_threshold IS NOT NULL
          AND r.quantity_available - COALESCE(a.allocated, 0) <= r.low_stock_threshold
        ORDER BY remaining - r.low_stock_threshold, r.name');
}

/**
 * Budget vs actual per project, plus task completion.
 * @param bool $openOnly limit to planned/active/on_hold projects
 */
function project_financials(PDO $pdo, bool $openOnly = true, ?int $projectId = null): array
{
    $sql = "SELECT p.id, p.name, p.status, p.budget, p.end_date, u.full_name AS manager_name,
            COALESCE(x.spent, 0) AS spent,
            COALESCE(t.total, 0) AS task_total, COALESCE(t.done, 0) AS task_done, COALESCE(t.overdue, 0) AS task_overdue,
            COALESCE(t.avg_progress, 0) AS avg_progress
        FROM projects p
        LEFT JOIN users u ON u.id = p.manager_id
        LEFT JOIN (SELECT project_id, SUM(amount) AS spent FROM expenses GROUP BY project_id) x ON x.project_id = p.id
        LEFT JOIN (SELECT project_id, COUNT(*) AS total,
                          SUM(status = 'completed') AS done,
                          SUM(status <> 'completed' AND due_date < app_today()) AS overdue,
                          AVG(progress_percent) AS avg_progress
                   FROM tasks GROUP BY project_id) t ON t.project_id = p.id
        WHERE 1=1";
    $params = [];
    if ($openOnly) {
        $sql .= " AND p.status IN ('planned', 'active', 'on_hold')";
    }
    if ($projectId !== null) {
        $sql .= ' AND p.id = ?';
        $params[] = $projectId;
    }
    $rows = db_all($pdo, $sql . " ORDER BY CASE p.status WHEN 'active' THEN 0 WHEN 'on_hold' THEN 1 WHEN 'planned' THEN 2 ELSE 3 END, p.name", $params);
    foreach ($rows as &$r) {
        $r['budget_used_pct'] = ($r['budget'] !== null && (float)$r['budget'] > 0) ? (float)$r['spent'] / (float)$r['budget'] * 100 : null;
        $r['over_budget'] = $r['budget'] !== null && (float)$r['spent'] > (float)$r['budget'];
    }
    return $rows;
}

/** Expense totals by category for one project (or all). */
function expense_breakdown(PDO $pdo, ?int $projectId = null): array
{
    $sql = 'SELECT category, SUM(amount) AS total, COUNT(*) AS n FROM expenses';
    $params = [];
    if ($projectId !== null) {
        $sql .= ' WHERE project_id = ?';
        $params[] = $projectId;
    }
    return db_all($pdo, $sql . ' GROUP BY category ORDER BY total DESC', $params);
}

function budget_bar_class(?float $pct): string
{
    return match (true) {
        $pct === null => '',
        $pct > 100 => 'bar-danger',
        $pct >= 85 => 'bar-warning',
        default => 'bar-ok',
    };
}
