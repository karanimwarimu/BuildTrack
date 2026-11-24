<?php
declare(strict_types=1);

/**
 * Demo data for local evaluation: one account per role, three projects, tasks with overdue/upcoming
 * dates relative to today, progress history, resources (one low on stock), expenses and site logs.
 * Run: php bin/console seed   (or: php bin/console seed --fresh to start from an empty database)
 */

const DEMO_PASSWORD = 'BuildTrack-demo-2026';

/** @return array<int,array{0:string,1:string}> [email, role] of demo accounts */
function seed_demo(PDO $pdo): array
{
    if ((int)db_value($pdo, 'SELECT COUNT(*) FROM projects') > 0) {
        throw new UserError('The database already has projects. Use: php bin/console seed --fresh (backs up and replaces the database).');
    }
    $d = fn(int $days) => date('Y-m-d', strtotime("$days days"));
    $accounts = [
        ['admin@buildtrack.local', 'Amina Otieno', 'admin'],
        ['pm@buildtrack.local', 'Brian Mwangi', 'project_manager'],
        ['supervisor@buildtrack.local', 'Grace Wanjiru', 'site_supervisor'],
        ['worker@buildtrack.local', 'Peter Kamau', 'worker'],
        ['worker2@buildtrack.local', 'Faith Achieng', 'worker'],
    ];

    return db_transaction($pdo, function (PDO $pdo) use ($accounts, $d) {
        $ids = [];
        $hash = hash_password(DEMO_PASSWORD);
        foreach ($accounts as [$email, $name, $role]) {
            $existing = find_user_by_email($pdo, $email);
            if ($existing) {
                $ids[$role === 'worker' && isset($ids['worker']) ? 'worker2' : $role] = (int)$existing['id'];
                continue;
            }
            db_exec($pdo, 'INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, ?)', [$name, $email, $hash, $role]);
            $ids[$role === 'worker' && isset($ids['worker']) ? 'worker2' : $role] = (int)$pdo->lastInsertId();
        }
        ['admin' => $admin, 'project_manager' => $pm, 'site_supervisor' => $sup, 'worker' => $w1, 'worker2' => $w2] = $ids;

        $project = function (array $p) use ($pdo, $admin): int {
            db_exec($pdo, 'INSERT INTO projects (name, description, location, start_date, end_date, budget, status, manager_id, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $admin]);
            return (int)$pdo->lastInsertId();
        };
        $riverside = $project(['Riverside Apartment Block', 'Six-storey residential block with 48 units and basement parking.', 'Kilimani, Nairobi', $d(-90), $d(180), 85000000, 'active', $pm]);
        $school = $project(['Mwangaza Primary School Extension', 'Four new classrooms, toilets block and rainwater harvesting.', 'Thika', $d(-40), $d(60), 12500000, 'active', $pm]);
        $warehouse = $project(['Industrial Area Warehouse Refit', 'Roof replacement and loading-bay upgrade.', 'Industrial Area, Nairobi', $d(20), $d(110), 6000000, 'planned', $admin]);

        $task = function (int $project, string $title, int $start, int $due, ?int $who, string $status, string $priority, int $progress, string $desc = '') use ($pdo, $d, $sup): int {
            db_exec($pdo, 'INSERT INTO tasks (project_id, title, description, start_date, due_date, assigned_to, status, priority, progress_percent, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$project, $title, $desc ?: null, $d($start), $d($due), $who, $status, $priority, $progress, $sup]);
            return (int)$pdo->lastInsertId();
        };
        $t1 = $task($riverside, 'Excavation and foundation', -90, -50, $w1, 'completed', 'high', 100, 'Strip foundation to engineer drawings S-01..S-04.');
        $t2 = $task($riverside, 'Ground floor slab', -50, -3, $w1, 'in_progress', 'urgent', 80, 'Includes DPM and BRC mesh A142.');
        $t3 = $task($riverside, 'First floor columns', -5, 6, $w2, 'in_progress', 'high', 25);
        $t4 = $task($riverside, 'Order lift equipment', -10, 2, $pm, 'blocked', 'medium', 10, 'Waiting for supplier quotation.');
        $t5 = $task($riverside, 'Site drainage', 10, 30, null, 'pending', 'low', 0);
        $t6 = $task($school, 'Classroom block walling', -30, 4, $w2, 'in_progress', 'high', 60);
        $t7 = $task($school, 'Roofing trusses', 0, 15, $w1, 'pending', 'medium', 0);
        $t8 = $task($school, 'Toilet block plumbing', -20, -1, $w2, 'in_progress', 'medium', 40);
        $task($warehouse, 'Structural survey of roof', 20, 30, $sup, 'pending', 'medium', 0);

        $progress = fn(int $t, int $u, int $pct, string $status, string $notes) => db_exec($pdo,
            'INSERT INTO progress_updates (task_id, user_id, progress_percentage, status, notes) VALUES (?,?,?,?,?)', [$t, $u, $pct, $status, $notes]);
        $progress($t1, $w1, 60, 'in_progress', 'Excavation complete, blinding done on grid A-C.');
        $progress($t1, $w1, 100, 'completed', 'Foundation walls complete, inspected by engineer.');
        $progress($t2, $w1, 50, 'in_progress', 'Hardcore and murram compacted.');
        $progress($t2, $w1, 80, 'in_progress', 'Half of slab poured; rain delayed second pour.');
        $progress($t4, $pm, 10, 'blocked', 'Supplier has not sent the revised quotation.');
        $progress($t6, $w2, 60, 'in_progress', 'Walling at window level on all four classrooms.');

        $resource = function (string $name, string $type, string $unit, float $qty, ?float $low) use ($pdo, $sup): int {
            db_exec($pdo, 'INSERT INTO resources (name, resource_type, unit, quantity_available, low_stock_threshold, created_by) VALUES (?,?,?,?,?,?)', [$name, $type, $unit, $qty, $low, $sup]);
            return (int)$pdo->lastInsertId();
        };
        $cement = $resource('Cement (50kg)', 'material', 'bags', 600, 100);
        $steel = $resource('Y12 reinforcement bar', 'material', 'pieces', 300, 50);
        $sand = $resource('River sand', 'material', 'tonnes', 40, 10);
        $mixer = $resource('Concrete mixer', 'equipment', 'units', 2, null);
        $masons = $resource('Masons', 'labour', 'people', 12, 2);
        $alloc = fn(int $t, int $r, float $q, string $n) => db_exec($pdo, 'INSERT INTO task_resources (task_id, resource_id, quantity_assigned, notes, assigned_by) VALUES (?,?,?,?,?)', [$t, $r, $q, $n, $sup]);
        $alloc($t2, $cement, 420, 'Slab pours');
        $alloc($t6, $cement, 120, 'Walling mortar');
        $alloc($t2, $steel, 120, 'Slab mesh supports');
        $alloc($t2, $sand, 33, 'Concrete');
        $alloc($t6, $masons, 6, '');
        $alloc($t2, $mixer, 1, '');

        $expense = fn(int $p, string $cat, string $desc, string $vendor, float $amt, int $day, int $who) => db_exec($pdo,
            'INSERT INTO expenses (project_id, category, description, vendor, amount, spent_on, recorded_by) VALUES (?,?,?,?,?,?,?)', [$p, $cat, $desc, $vendor, $amt, $d($day), $who]);
        $expense($riverside, 'materials', '600 bags cement', 'Bamburi Cement', 474000, -60, $pm);
        $expense($riverside, 'materials', 'Reinforcement steel', 'Devki Steel', 1980000, -55, $pm);
        $expense($riverside, 'labour', 'Excavation crew, 4 weeks', 'Site payroll', 1240000, -45, $pm);
        $expense($riverside, 'equipment', 'Excavator hire', 'Mantrac', 860000, -70, $pm);
        $expense($riverside, 'permits', 'NCA and county approvals', 'Nairobi County', 350000, -95, $admin);
        $expense($school, 'materials', 'Machine-cut stone, 4,000 pcs', 'Juja Quarry', 2600000, -25, $pm);
        $expense($school, 'labour', 'Masons and helpers, 3 weeks', 'Site payroll', 5400000, -10, $pm);
        $expense($school, 'transport', 'Material deliveries', 'Thika Haulage', 4890000, -8, $pm);

        $log = fn(int $p, int $day, string $weather, int $crew, string $work, ?string $issues, ?string $safety, int $who) => db_exec($pdo,
            'INSERT INTO site_logs (project_id, log_date, weather, crew_count, work_done, issues, safety_notes, created_by) VALUES (?,?,?,?,?,?,?,?)', [$p, $d($day), $weather, $crew, $work, $issues, $safety, $who]);
        $log($riverside, -2, 'Heavy rain from 11am', 14, 'Poured slab bay 3 before rain. Covered fresh concrete with polythene.', 'Afternoon work stopped due to rain; bay 4 pour moved to tomorrow.', null, $sup);
        $log($riverside, -1, 'Sunny, 27°C', 18, 'Poured slab bay 4. Started setting out first-floor columns on grid A.', null, 'Toolbox talk on working at height.', $sup);
        $log($school, -1, 'Cloudy', 11, 'Walling to ring-beam level on classrooms 1 and 2.', 'Stone delivery short by 300 pieces.', null, $sup);
        $log($school, 0, 'Sunny', 12, 'Plumbing first-fix in toilet block, 60% complete.', null, 'Near miss: unsecured ladder. Ladder tied off and crew briefed.', $sup);

        audit('seed.demo_loaded', null, null, ['projects' => 3], $admin);
        return array_map(fn($a) => [$a[0], $a[2]], $accounts);
    });
}
