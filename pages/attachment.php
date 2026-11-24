<?php
/**
 * Attachments: GET ?id=N downloads (after a visibility check); POST action=upload|delete.
 * @var PDO $pdo
 */

$returnTo = function (int $projectId, ?int $taskId): string {
    return post_str('return') === 'progress' && $taskId ? url('progress', ['task_id' => $taskId]) : url('project', ['id' => $projectId]);
};

if (is_post()) {
    verify_csrf();
    $action = post_str('action');
    if ($action === 'upload') {
        $projectId = (int)($_POST['project_id'] ?? 0);
        $taskId = post_str('task_id') !== '' ? (int)$_POST['task_id'] : null;
        $allowed = $taskId ? task_visible($pdo, $taskId) : project_visible($pdo, $projectId);
        if (!$projectId || !$allowed) {
            forbidden();
        }
        $file = $_FILES['file'] ?? null;
        try {
            if (!is_array($file) || is_array($file['error'])) {
                throw new UserError('Choose a single file to upload.');
            }
            if ($msg = upload_error_message((int)$file['error'])) {
                throw new UserError($msg);
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                throw new UserError('Upload rejected.');
            }
            $saved = attachment_store($pdo, $file['tmp_name'], (string)$file['name'], $projectId, $taskId, user_id());
            audit('attachment.uploaded', 'project', $projectId, ['file' => $saved['original_name'], 'task_id' => $taskId, 'attachment_id' => (int)$saved['id']]);
            flash('success', 'Uploaded ' . $saved['original_name'] . '.');
        } catch (UserError $ex) {
            flash('error', $ex->getMessage());
        }
        redirect($returnTo($projectId, $taskId));
    }
    if ($action === 'delete') {
        $row = db_one($pdo, 'SELECT * FROM attachments WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        if (!$row || !project_visible($pdo, (int)$row['project_id']) || ((int)$row['uploaded_by'] !== user_id() && !can('attachments.manage_any'))) {
            forbidden();
        }
        attachment_delete($pdo, (int)$row['id']);
        audit('attachment.deleted', 'project', (int)$row['project_id'], ['file' => $row['original_name']]);
        flash('success', 'File deleted.');
        redirect($returnTo((int)$row['project_id'], $row['task_id'] ? (int)$row['task_id'] : null));
    }
    forbidden();
}

$row = db_one($pdo, 'SELECT * FROM attachments WHERE id = ?', [(int)get_str('id', '0')]);
$visible = $row && project_visible($pdo, (int)$row['project_id']);
if (!$visible || !is_file(attachment_path($row))) {
    http_response_code(404);
    render_message_page('File not found', 'The file does not exist or you do not have access to it.');
}
$inline = in_array($row['mime_type'], ATTACHMENT_INLINE, true);
$safeName = str_replace(['"', '\\'], '', $row['original_name']);
header('Content-Type: ' . $row['mime_type']);
header('Content-Length: ' . filesize(attachment_path($row)));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $safeName) . '"; filename*=UTF-8\'\'' . rawurlencode($row['original_name']));
// Sandbox everything except PDFs (browser PDF viewers refuse to run inside a CSP sandbox).
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'" . ($row['mime_type'] === 'application/pdf' ? '' : '; sandbox'));
header('Cache-Control: private, max-age=3600');
readfile(attachment_path($row));
exit;
