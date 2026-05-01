<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';

require_login();

$type = $_GET['type'] ?? '';
$id   = (int)($_GET['id'] ?? 0);

// Phase 5: photo + attachment + document + cert
if ($id <= 0 || !in_array($type, ['photo', 'attachment', 'document', 'cert'], true)) {
    http_response_code(400);
    exit('Invalid request');
}

$role = current_role();
$uid  = (int) current_user_id();

/**
 * Permission check (CLAUDE.md ข้อ 1 + §6)
 *  - personal: เจ้าของเท่านั้น (Admin/Director ห้ามเห็น)
 *  - organization: Admin/Director เห็น, Employee เห็นเฉพาะที่ registered
 */
function can_access_activity(array $row, string $role, int $uid): bool
{
    if ($row['scope'] === 'personal') {
        return (int)$row['created_by'] === $uid;
    }
    if ($role === 'admin' || $role === 'director') {
        return true;
    }
    $check = db()->prepare(
        'SELECT 1 FROM activity_registrations
         WHERE activity_id = :a AND user_id = :u LIMIT 1'
    );
    $check->execute([':a' => (int)$row['activity_id'], ':u' => $uid]);
    return (bool) $check->fetch();
}

$row = null;
$original_name = '';

if ($type === 'photo') {
    $stmt = db()->prepare(
        'SELECT p.id, p.activity_id, p.filename, p.original_name,
                a.scope, a.created_by
         FROM activity_photos p
         JOIN activities a ON a.id = p.activity_id
         WHERE p.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); exit('Not found'); }
    if (!can_access_activity($row, $role, $uid)) { http_response_code(403); exit('Forbidden'); }
    $original_name = $row['original_name'];

} elseif ($type === 'attachment') {
    $stmt = db()->prepare(
        'SELECT att.id, att.activity_id, att.type, att.filename, att.label,
                a.scope, a.created_by
         FROM activity_attachments att
         JOIN activities a ON a.id = att.activity_id
         WHERE att.id = :id AND att.type = "file" LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); exit('Not found'); }
    if (!can_access_activity($row, $role, $uid)) { http_response_code(403); exit('Forbidden'); }

    $ext  = pathinfo((string)$row['filename'], PATHINFO_EXTENSION);
    $base = trim((string)$row['label']) !== '' ? (string)$row['label'] : 'attachment';
    $original_name = $base . ($ext !== '' ? '.' . $ext : '');

} elseif ($type === 'document') {
    $stmt = db()->prepare(
        'SELECT id, activity_id, filename, original_name
         FROM documents
         WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); exit('Not found'); }
    // Only general org documents (activity_id IS NULL) — all authenticated users may download
    if ($row['activity_id'] !== null) { http_response_code(404); exit('Not found'); }
    $original_name = $row['original_name'];

} elseif ($type === 'cert') {
    $stmt = db()->prepare(
        'SELECT c.id, c.activity_id, c.user_id, c.filename, c.original_name
         FROM certificates c
         WHERE c.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); exit('Not found'); }
    // Employee: own cert only; Admin/Director: all
    if ($role === 'employee' && (int)$row['user_id'] !== $uid) {
        http_response_code(403); exit('Forbidden');
    }
    $original_name = $row['original_name'];
}

$subdir_map = [
    'photo'      => 'activities',
    'attachment' => 'activities',
    'document'   => 'documents',
    'cert'       => 'certificates',
];
$subdir        = $subdir_map[$type];
$filename_safe = basename((string)$row['filename']);
$base_dir      = realpath(UPLOAD_PATH . '/' . $subdir);
$real          = realpath(UPLOAD_PATH . '/' . $subdir . '/' . $filename_safe);

if ($base_dir === false || $real === false
    || (strpos($real, $base_dir . DIRECTORY_SEPARATOR) !== 0 && $real !== $base_dir)) {
    http_response_code(404);
    exit('File missing');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($real) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

$dispo       = sanitize_download_filename($original_name);
$dispo_ascii = preg_replace('/[^\x20-\x7E]/', '_', $dispo);
header('Content-Disposition: inline'
    . '; filename="' . str_replace('"', '', $dispo_ascii) . '"'
    . '; filename*=UTF-8\'\'' . rawurlencode($dispo));

readfile($real);
exit;
