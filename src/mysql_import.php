<?php
declare(strict_types=1);

/**
 * Import data from a MariaDB/MySQL dump of the original BuildTrack (mysqldump or phpMyAdmin export).
 * Only INSERT statements for the six legacy tables are read; the schema comes from migrations/.
 *  - IDs are preserved, so foreign keys line up.
 *  - Password hashes (bcrypt $2y$) keep working; they are upgraded to Argon2id at next login.
 *  - TIMESTAMP values are converted from $sourceTz (the MySQL server's local time) to UTC.
 *  - Zero dates ('0000-00-00') become NULL.
 */

/** Column order of the legacy tables, for dumps whose INSERTs have no column list (mysqldump default). */
const LEGACY_COLUMNS = [
    'users' => ['id', 'full_name', 'email', 'password_hash', 'role', 'created_at'],
    'projects' => ['id', 'name', 'description', 'location', 'start_date', 'end_date', 'budget', 'status', 'manager_id', 'created_by', 'created_at', 'updated_at'],
    'tasks' => ['id', 'project_id', 'title', 'description', 'start_date', 'due_date', 'assigned_to', 'status', 'priority', 'progress_percent', 'created_by', 'created_at', 'updated_at'],
    'progress_updates' => ['id', 'task_id', 'user_id', 'progress_percentage', 'status', 'notes', 'created_at'],
    'resources' => ['id', 'name', 'resource_type', 'unit', 'quantity_available', 'notes', 'created_by', 'created_at', 'updated_at'],
    'task_resources' => ['id', 'task_id', 'resource_id', 'quantity_assigned', 'notes', 'assigned_by', 'created_at'],
];

/**
 * Parse every INSERT in a MySQL dump.
 * @return array<int,array{table:string,columns:?array,rows:array<int,array<int,?string>>}>
 */
function parse_mysql_inserts(string $sql): array
{
    $statements = [];
    $len = strlen($sql);
    $offset = 0;
    $pattern = '/\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*(?:\(([^)]*)\))?\s*VALUES\s*/Ai';
    while ($offset < $len) {
        $next = stripos($sql, 'INSERT', $offset);
        if ($next === false) {
            break;
        }
        if (!preg_match($pattern, $sql, $m, 0, $next)) {
            $offset = $next + 6;
            continue;
        }
        $table = strtolower($m[1]);
        $columns = isset($m[2]) && trim($m[2]) !== ''
            ? array_map(fn($c) => trim($c, " `\t\n\r"), explode(',', $m[2]))
            : null;
        $pos = $next + strlen($m[0]);
        $rows = [];
        while ($pos < $len) {
            while ($pos < $len && ctype_space($sql[$pos])) {
                $pos++;
            }
            if (($sql[$pos] ?? '') !== '(') {
                throw new RuntimeException("Unexpected character near byte $pos while reading INSERT INTO $table.");
            }
            $pos++;
            $row = [];
            while (true) {
                while ($pos < $len && ctype_space($sql[$pos])) {
                    $pos++;
                }
                if ($pos >= $len) {
                    throw new RuntimeException("Unexpected end of dump inside INSERT INTO $table.");
                }
                if ($sql[$pos] === "'") {
                    [$value, $pos] = mysql_read_string($sql, $pos + 1);
                    $row[] = $value;
                } else {
                    $start = $pos;
                    while ($pos < $len && $sql[$pos] !== ',' && $sql[$pos] !== ')') {
                        $pos++;
                    }
                    $raw = trim(substr($sql, $start, $pos - $start));
                    $row[] = strcasecmp($raw, 'NULL') === 0 ? null : $raw;
                }
                while ($pos < $len && ctype_space($sql[$pos])) {
                    $pos++;
                }
                if (($sql[$pos] ?? '') === ',') {
                    $pos++;
                    continue;
                }
                if (($sql[$pos] ?? '') === ')') {
                    $pos++;
                    break;
                }
                throw new RuntimeException("Malformed value list near byte $pos in INSERT INTO $table.");
            }
            $rows[] = $row;
            while ($pos < $len && ctype_space($sql[$pos])) {
                $pos++;
            }
            if ($pos < $len && $sql[$pos] === ',') {
                $pos++;
                continue;
            }
            break; // ';' or end
        }
        $statements[] = ['table' => $table, 'columns' => $columns, 'rows' => $rows];
        $offset = $pos;
    }
    return $statements;
}

/** Read a single-quoted MySQL string starting after the opening quote. Returns [value, position after closing quote]. */
function mysql_read_string(string $sql, int $pos): array
{
    $out = '';
    $len = strlen($sql);
    $map = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A", '\\' => '\\', "'" => "'", '"' => '"'];
    while ($pos < $len) {
        $c = $sql[$pos];
        if ($c === '\\' && $pos + 1 < $len) {
            $n = $sql[$pos + 1];
            $out .= $map[$n] ?? ('\\' . $n); // \% and \_ keep their backslash, as MySQL does
            $pos += 2;
            continue;
        }
        if ($c === "'") {
            if (($sql[$pos + 1] ?? '') === "'") { // '' escape
                $out .= "'";
                $pos += 2;
                continue;
            }
            return [$out, $pos + 1];
        }
        $out .= $c;
        $pos++;
    }
    throw new RuntimeException('Unterminated string in dump.');
}

function mysql_convert_value(string $column, ?string $value, DateTimeZone $sourceTz): ?string
{
    if ($value === null) {
        return null;
    }
    if (str_starts_with($value, '0000-00-00')) {
        return null;
    }
    if (str_ends_with($column, '_at')) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), $sourceTz);
        return $dt ? $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : $value;
    }
    return $value;
}

/**
 * Wipe the legacy tables and load the dump. Runs in one transaction with foreign keys checked at the end.
 * @return array<string,int> rows imported per table
 */
function import_mysql_dump(PDO $pdo, string $dump, string $sourceTz = 'UTC'): array
{
    $tz = new DateTimeZone($sourceTz);
    $statements = parse_mysql_inserts($dump);
    if (!$statements) {
        throw new UserError('No INSERT statements found. Export the old database with data (mysqldump or phpMyAdmin → Export → SQL).');
    }
    $counts = array_fill_keys(array_keys(LEGACY_COLUMNS), 0);
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $wipe = ['attachments', 'site_logs', 'expenses', 'audit_log', 'password_resets', 'login_attempts', 'task_resources', 'progress_updates', 'tasks', 'resources', 'projects', 'users'];
        foreach ($wipe as $t) {
            $pdo->exec("DELETE FROM $t");
        }
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('" . implode("','", $wipe) . "')");
        foreach ($statements as $st) {
            if (!isset(LEGACY_COLUMNS[$st['table']])) {
                continue; // unknown table (e.g. phpMyAdmin metadata) — ignored
            }
            $cols = $st['columns'] ?? LEGACY_COLUMNS[$st['table']];
            $unknown = array_diff($cols, LEGACY_COLUMNS[$st['table']]);
            if ($unknown) {
                throw new UserError("Table {$st['table']} has unexpected columns: " . implode(', ', $unknown));
            }
            $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $st['table'], implode(', ', $cols), implode(', ', array_fill(0, count($cols), '?')));
            $insert = $pdo->prepare($sql);
            foreach ($st['rows'] as $row) {
                if (count($row) !== count($cols)) {
                    throw new UserError("Row in {$st['table']} has " . count($row) . ' values but ' . count($cols) . ' columns were expected.');
                }
                $values = [];
                foreach ($cols as $i => $col) {
                    $values[] = mysql_convert_value($col, $row[$i], $tz);
                }
                // NOT NULL timestamps that were zero-dates fall back to "now".
                foreach ($cols as $i => $col) {
                    if (str_ends_with($col, '_at') && $values[$i] === null && $col !== 'last_login_at') {
                        $values[$i] = utc_now();
                    }
                }
                $insert->execute($values);
                $counts[$st['table']]++;
            }
        }
        $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
        if ($violations) {
            throw new UserError('The dump has rows pointing at missing records (first: ' . json_encode($violations[0]) . '). Nothing was imported.');
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        $pdo->exec('PRAGMA foreign_keys = ON');
        throw $e;
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $counts;
}
