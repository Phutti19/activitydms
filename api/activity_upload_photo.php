<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json; charset=utf-8');

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Manual CSRF check (return JSON instead of die HTML)
$submitted = $_POST[CSRF_TOKEN_NAME] ?? '';
$expected  = $_SESSION[CSRF_TOKEN_NAME] ?? '';
if (!is_string($submitted) || $expected === '' || !hash_equals($expected, $submitted)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$activity_id = (int)($_POST['activity_id'] ?? 0);
if ($activity_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing activity_id']);
    exit;
}

$pdo = db();

// Verify activity exists + organization scope (Admin หน้านี้ห้ามแตะ personal)
$check = $pdo->prepare(
    'SELECT id FROM activities WHERE id = :id AND scope = "organization" LIMIT 1'
);
$check->execute([':id' => $activity_id]);
if (!$check->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'ไม่พบกิจกรรม']);
    exit;
}

// Pre-check (สเปค §4.2: max 5)
$count = $pdo->prepare('SELECT COUNT(*) FROM activity_photos WHERE activity_id = :id');
$count->execute([':id' => $activity_id]);
if ((int)$count->fetchColumn() >= 5) {
    echo json_encode(['ok' => false, 'error' => 'ครบ 5 ภาพแล้ว']);
    exit;
}

$file = $_FILES['photo'] ?? null;
if (!$file) {
    echo json_encode(['ok' => false, 'error' => 'ไม่ได้แนบไฟล์']);
    exit;
}

$max_image = mb_to_bytes((int)($_ENV['UPLOAD_MAX_IMAGE_MB'] ?? 5));
$result = validate_uploaded_file($file, UPLOAD_IMAGE_MIMES, $max_image);
if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

$original_name = (string)($file['name'] ?? '');
$original_name = basename($original_name);
if ($original_name === '' || mb_strlen($original_name) > 255) {
    $original_name = 'photo.' . $result['ext'];
}

$filename = null;
$pdo->beginTransaction();
try {
    // Race-safe re-check ภายใน transaction
    $count2 = $pdo->prepare('SELECT COUNT(*) FROM activity_photos WHERE activity_id = :id FOR UPDATE');
    $count2->execute([':id' => $activity_id]);
    $current = (int)$count2->fetchColumn();
    if ($current >= 5) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'ครบ 5 ภาพแล้ว']);
        exit;
    }

    $filename = move_uploaded_with_uuid($file, 'activities', $result['ext']);

    $next_order = $current + 1;
    $stmt = $pdo->prepare(
        'INSERT INTO activity_photos (activity_id, filename, original_name, sort_order)
         VALUES (:a, :f, :o, :s)'
    );
    $stmt->execute([
        ':a' => $activity_id,
        ':f' => $filename,
        ':o' => $original_name,
        ':s' => $next_order,
    ]);
    $photo_id = (int)$pdo->lastInsertId();

    audit_log('upload_photo', 'activity_photos', $photo_id, null, [
        'activity_id'   => $activity_id,
        'filename'      => $filename,
        'original_name' => $original_name,
        'mime'          => $result['mime'],
        'size'          => $result['size'],
        'sort_order'    => $next_order,
    ]);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'photo' => [
            'id'            => $photo_id,
            'filename'      => $filename,
            'original_name' => $original_name,
            'sort_order'    => $next_order,
        ],
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    if ($filename !== null) {
        safe_unlink_upload('activities', $filename);
    }
    error_log('[upload_photo] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'เกิดข้อผิดพลาดภายใน']);
}
