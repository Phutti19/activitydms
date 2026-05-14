<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_login();

$uid = (int) current_user_id();

// Director ห้ามแก้ไขใด ๆ — POST จะถูก middleware require_role บล็อก แต่ที่นี่ไม่ได้ require_role
// เพราะ bell ใช้ได้ทั้ง 3 roles ดังนั้น manual block POST สำหรับ director
if ($_SESSION['role'] === 'director' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Director is read-only']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $unread_only = !empty($_GET['unread_only']);
    $limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));

    $sql = 'SELECT id, type, title, message, link_url, is_read, created_at
            FROM notifications
            WHERE user_id = :u' . ($unread_only ? ' AND is_read = 0' : '') . '
            ORDER BY id DESC
            LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([':u' => $uid]);
    $items = $stmt->fetchAll();

    echo json_encode([
        'ok'           => true,
        'items'        => $items,
        'unread_count' => unread_notification_count($uid),
    ]);
    exit;
}

if ($action === 'count' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok'           => true,
        'unread_count' => unread_notification_count($uid),
    ]);
    exit;
}

// POST endpoints — ต้อง CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$submitted = $_POST[CSRF_TOKEN_NAME] ?? '';
$expected  = $_SESSION[CSRF_TOKEN_NAME] ?? '';
if (!is_string($submitted) || $expected === '' || !hash_equals($expected, $submitted)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Missing id']);
        exit;
    }
    $stmt = db()->prepare(
        'UPDATE notifications
         SET is_read = 1, read_at = NOW()
         WHERE id = :id AND user_id = :u AND is_read = 0'
    );
    $stmt->execute([':id' => $id, ':u' => $uid]);
    echo json_encode([
        'ok'           => true,
        'unread_count' => unread_notification_count($uid),
    ]);
    exit;
}

if ($action === 'mark_all_read') {
    $stmt = db()->prepare(
        'UPDATE notifications
         SET is_read = 1, read_at = NOW()
         WHERE user_id = :u AND is_read = 0'
    );
    $stmt->execute([':u' => $uid]);
    echo json_encode([
        'ok'           => true,
        'unread_count' => 0,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
