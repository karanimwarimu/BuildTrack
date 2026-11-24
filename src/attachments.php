<?php
declare(strict_types=1);

/**
 * Project/task file attachments stored under data/uploads/ (outside the web root).
 * Files are served only through index.php?page=attachment&id=N after a permission check.
 */

const ATTACHMENT_TYPES = [
    'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
    'gif' => ['image/gif'], 'webp' => ['image/webp'],
    'pdf' => ['application/pdf'],
    'txt' => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'],
    'doc' => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
];
/** Types safe to display inline in the browser; everything else downloads. */
const ATTACHMENT_INLINE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

function uploads_dir(): string
{
    $dir = data_path('uploads');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function upload_max_bytes(): int
{
    return max(1, config_int('UPLOAD_MAX_MB', 10)) * 1024 * 1024;
}

function detect_mime(string $path): string
{
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $f ? finfo_file($f, $path) : false;
        if ($f) {
            finfo_close($f);
        }
        if ($mime) {
            return $mime;
        }
    }
    return 'application/octet-stream';
}

/**
 * Validate and store a file, then insert the attachments row.
 * $move is the function that relocates the temp file (move_uploaded_file on the web, copy in tests).
 */
function attachment_store(PDO $pdo, string $tmpPath, string $originalName, int $projectId, ?int $taskId, int $userId, ?callable $move = null): array
{
    $size = (int)@filesize($tmpPath);
    if ($size <= 0) {
        throw new UserError('The uploaded file is empty.');
    }
    if ($size > upload_max_bytes()) {
        throw new UserError('The file is larger than the ' . config_int('UPLOAD_MAX_MB', 10) . ' MB limit.');
    }
    $original = str_limit(trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '_', basename($originalName)) ?? 'file'), 180);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!isset(ATTACHMENT_TYPES[$ext])) {
        throw new UserError('File type not allowed. Allowed: ' . implode(', ', array_keys(ATTACHMENT_TYPES)) . '.');
    }
    $mime = detect_mime($tmpPath);
    if (!in_array($mime, ATTACHMENT_TYPES[$ext], true)) {
        throw new UserError("The file content ($mime) does not match its .$ext extension.");
    }
    if ($taskId !== null && (int)db_value($pdo, 'SELECT project_id FROM tasks WHERE id = ?', [$taskId]) !== $projectId) {
        throw new UserError('That task does not belong to the selected project.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = uploads_dir() . '/' . $stored;
    $move ??= 'move_uploaded_file';
    if (!$move($tmpPath, $dest)) {
        throw new RuntimeException('Could not save the uploaded file. Check that data/uploads is writable.');
    }
    try {
        db_exec($pdo, 'INSERT INTO attachments (project_id, task_id, original_name, stored_name, mime_type, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$projectId, $taskId, $original, $stored, $mime, $size, $userId]);
    } catch (Throwable $e) {
        @unlink($dest);
        throw $e;
    }
    return db_one($pdo, 'SELECT * FROM attachments WHERE id = ?', [(int)$pdo->lastInsertId()]);
}

/** Map a PHP upload error code to a friendly message (null = OK). */
function upload_error_message(int $code): ?string
{
    return match ($code) {
        UPLOAD_ERR_OK => null,
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large for the server upload limit.',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
        UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
        default => 'The server could not accept the upload (error ' . $code . ').',
    };
}

function attachment_path(array $attachment): string
{
    return uploads_dir() . '/' . basename((string)$attachment['stored_name']);
}

function attachment_delete(PDO $pdo, int $id): void
{
    $row = db_one($pdo, 'SELECT * FROM attachments WHERE id = ?', [$id]);
    if ($row) {
        db_exec($pdo, 'DELETE FROM attachments WHERE id = ?', [$id]);
        @unlink(attachment_path($row));
    }
}

/**
 * Collect the files that belong to a project or task. Call before deleting the parent row
 * (the rows cascade in SQLite), then pass the result to attachments_unlink() after the delete succeeds.
 */
function attachments_files_for(PDO $pdo, string $type, int $id): array
{
    $column = $type === 'project' ? 'project_id' : 'task_id';
    return array_map(fn($r) => attachment_path($r), db_all($pdo, "SELECT stored_name FROM attachments WHERE $column = ?", [$id]));
}

function attachments_unlink(array $paths): void
{
    foreach ($paths as $p) {
        @unlink($p);
    }
}

function attachments_for(PDO $pdo, int $projectId, ?int $taskId = null): array
{
    $sql = 'SELECT a.*, u.full_name AS uploader, t.title AS task_title FROM attachments a
            JOIN users u ON u.id = a.uploaded_by LEFT JOIN tasks t ON t.id = a.task_id WHERE a.project_id = ?';
    $params = [$projectId];
    if ($taskId !== null) {
        $sql .= ' AND a.task_id = ?';
        $params[] = $taskId;
    }
    return db_all($pdo, $sql . ' ORDER BY a.created_at DESC', $params);
}
