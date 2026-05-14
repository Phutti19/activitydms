<?php
declare(strict_types=1);

// In-app notification helper (มติประชุม 2026-05-14)
// ทำงานคู่กับ email_queue เคารพ notification_settings เดียวกัน
// (notify_new_activity / notify_new_certificate)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php'; // ใช้ notification_enabled()

/**
 * INSERT notification เข้าตาราง notifications
 * ใช้ภายใน — ส่วน caller ควรเรียก notify_new_activity / notify_new_certificate
 *
 * @return int  notification.id ที่สร้าง (0 = ไม่ได้สร้าง)
 */
function enqueue_notification(
    int $user_id,
    string $type,
    string $title,
    string $message = '',
    ?string $link_url = null,
    ?string $ref_type = null,
    ?int $ref_id = null
): int {
    if (!in_array($type, ['new_activity', 'new_certificate', 'system'], true)) {
        return 0;
    }
    if ($user_id <= 0 || $title === '') {
        return 0;
    }

    $stmt = db()->prepare(
        'INSERT INTO notifications
            (user_id, type, title, message, link_url, ref_type, ref_id)
         VALUES (:u, :t, :title, :msg, :url, :rt, :rid)'
    );
    $stmt->execute([
        ':u'     => $user_id,
        ':t'     => $type,
        ':title' => mb_substr($title, 0, 255),
        ':msg'   => $message !== '' ? $message : null,
        ':url'   => $link_url,
        ':rt'    => $ref_type,
        ':rid'   => $ref_id,
    ]);
    return (int) db()->lastInsertId();
}

/**
 * Trigger 1: Admin เพิ่ม user เข้า activity_registrations
 * เคารพ key เดียวกับ email (notify_new_activity)
 */
function notify_new_activity(int $user_id, int $activity_id): int
{
    if (!notification_enabled('notify_new_activity')) return 0;

    $stmt = db()->prepare('SELECT title FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $activity_id]);
    $title = $stmt->fetchColumn();
    if (!$title) return 0;

    return enqueue_notification(
        $user_id,
        'new_activity',
        'กิจกรรมใหม่: ' . $title,
        'คุณถูกเพิ่มเข้าร่วมกิจกรรม "' . $title . '"',
        '/employee/my_activities.php',
        'activity',
        $activity_id
    );
}

/**
 * Trigger 2: Admin upload certificate
 * เคารพ key เดียวกับ email (notify_new_certificate)
 */
function notify_new_certificate(int $user_id, int $activity_id): int
{
    if (!notification_enabled('notify_new_certificate')) return 0;

    $stmt = db()->prepare('SELECT title FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $activity_id]);
    $title = $stmt->fetchColumn();
    if (!$title) return 0;

    return enqueue_notification(
        $user_id,
        'new_certificate',
        'เกียรติบัตรใหม่: ' . $title,
        'มีเกียรติบัตรของคุณจากกิจกรรม "' . $title . '" — ดาวน์โหลดได้ที่หน้าเกียรติบัตรของฉัน',
        '/employee/my_certificates.php',
        'certificate',
        $activity_id
    );
}

/**
 * จำนวน unread ของ user
 */
function unread_notification_count(int $user_id): int
{
    if ($user_id <= 0) return 0;
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0');
    $stmt->execute([':u' => $user_id]);
    return (int) $stmt->fetchColumn();
}
