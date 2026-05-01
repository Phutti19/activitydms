<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Rate limit ต่อ trigger ตามสเปค (≤ 50 emails/ชั่วโมง/trigger)
 * นับเฉพาะ record ที่ enqueue ใน 60 นาทีล่าสุด ไม่ว่าจะส่งสำเร็จหรือไม่
 */
const EMAIL_RATE_LIMIT_PER_HOUR = 50;

function rate_limit_exceeded(string $trigger_key): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM email_queue
         WHERE trigger_key = :k
           AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $stmt->execute([':k' => $trigger_key]);
    return (int)$stmt->fetchColumn() >= EMAIL_RATE_LIMIT_PER_HOUR;
}

/**
 * Enqueue helper — INSERT into email_queue (ไม่ส่งทันที)
 * Cron cron/send_emails.php จะดึงไปส่งทุก 5 นาที
 * คืน true ถ้า enqueue สำเร็จ / false ถ้าโดน rate limit
 */
function enqueue_email(
    string $to_email,
    string $to_name,
    string $subject,
    string $body_html,
    string $body_text = '',
    ?string $trigger_key = null
): bool {
    if ($trigger_key !== null && rate_limit_exceeded($trigger_key)) {
        error_log("[mailer] rate limit exceeded for trigger '{$trigger_key}' — skip enqueue to {$to_email}");
        return false;
    }

    $stmt = db()->prepare(
        'INSERT INTO email_queue
            (to_email, to_name, subject, body_html, body_text, trigger_key, status, scheduled_at)
         VALUES (:email, :name, :subj, :html, :text, :tkey, "pending", NOW())'
    );
    $stmt->execute([
        ':email' => $to_email,
        ':name'  => $to_name,
        ':subj'  => $subject,
        ':html'  => $body_html,
        ':text'  => $body_text,
        ':tkey'  => $trigger_key,
    ]);
    return true;
}

/**
 * ตรวจว่า notification key นี้เปิดอยู่ไหม
 */
function notification_enabled(string $key): bool
{
    $stmt = db()->prepare(
        'SELECT setting_value FROM notification_settings WHERE setting_key = :k LIMIT 1'
    );
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();
    return $row && $row['setting_value'] === '1';
}

/**
 * Render PHP template เป็น HTML string
 * @param string $template  path ใต้ templates/emails/ (ไม่รวม .php)
 * @param array  $vars      ตัวแปรที่จะ extract ใน template
 */
function render_email_template(string $template, array $vars): string
{
    $path = __DIR__ . '/../templates/emails/' . $template . '.php';
    if (!is_file($path)) {
        return '';
    }
    // Inject APP_NAME constant ให้ template ใช้ได้
    $vars['app_url'] = APP_URL;
    extract($vars, EXTR_SKIP);
    ob_start();
    include $path;
    return (string) ob_get_clean();
}

/**
 * Trigger 1: Admin เพิ่ม user เข้า activity_registrations
 * ส่งเฉพาะถ้า notify_new_activity = 1 และไม่เกิน rate limit
 * คืน true ถ้า enqueue สำเร็จ / false ถ้าไม่ได้ส่ง (ปิดอยู่ / ไม่พบข้อมูล / rate limit)
 */
function enqueue_new_activity_email(int $user_id, int $activity_id): bool
{
    if (!notification_enabled('notify_new_activity')) {
        return false;
    }

    $pdo = db();

    $usr = $pdo->prepare(
        "SELECT TRIM(CONCAT_WS(' ', prefix_name, first_name, last_name)) AS fullname, email
         FROM users WHERE id = :id AND is_active = 1 LIMIT 1"
    );
    $usr->execute([':id' => $user_id]);
    $user = $usr->fetch();
    if (!$user || empty($user['email'])) {
        return false;
    }

    $act = $pdo->prepare(
        "SELECT a.title, a.start_datetime, a.end_datetime, a.location,
                at.name AS type_name
         FROM activities a
         LEFT JOIN activity_types at ON at.id = a.activity_type_id
         WHERE a.id = :id LIMIT 1"
    );
    $act->execute([':id' => $activity_id]);
    $activity = $act->fetch();
    if (!$activity) {
        return false;
    }

    $html = render_email_template('new_activity', [
        'name'           => $user['fullname'],
        'activity_title' => $activity['title'],
        'start'          => $activity['start_datetime'],
        'end'            => $activity['end_datetime'],
        'location'       => $activity['location'] ?? '',
        'type_name'      => $activity['type_name'] ?? '',
    ]);
    if ($html === '') {
        return false;
    }

    $subject = 'มีกิจกรรมใหม่: ' . $activity['title'];
    return enqueue_email(
        $user['email'], $user['fullname'], $subject, $html,
        '', 'notify_new_activity'
    );
}

/**
 * Trigger 2: Admin upload certificate
 * ส่งเฉพาะถ้า notify_new_certificate = 1 และไม่เกิน rate limit
 */
function enqueue_new_certificate_email(int $user_id, int $activity_id): bool
{
    if (!notification_enabled('notify_new_certificate')) {
        return false;
    }

    $pdo = db();

    $usr = $pdo->prepare(
        "SELECT TRIM(CONCAT_WS(' ', prefix_name, first_name, last_name)) AS fullname, email
         FROM users WHERE id = :id AND is_active = 1 LIMIT 1"
    );
    $usr->execute([':id' => $user_id]);
    $user = $usr->fetch();
    if (!$user || empty($user['email'])) {
        return false;
    }

    $act = $pdo->prepare('SELECT title FROM activities WHERE id = :id LIMIT 1');
    $act->execute([':id' => $activity_id]);
    $activity = $act->fetch();
    if (!$activity) {
        return false;
    }

    $html = render_email_template('new_certificate', [
        'name'           => $user['fullname'],
        'activity_title' => $activity['title'],
    ]);
    if ($html === '') {
        return false;
    }

    $subject = 'ออกเกียรติบัตรใหม่: ' . $activity['title'];
    return enqueue_email(
        $user['email'], $user['fullname'], $subject, $html,
        '', 'notify_new_certificate'
    );
}
