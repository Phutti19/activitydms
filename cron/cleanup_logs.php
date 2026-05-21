<?php
declare(strict_types=1);
/**
 * cron/cleanup_logs.php — ล้างข้อมูล log/queue เก่าเพื่อกัน DB โตไม่จำกัด
 *
 * Crontab (รันวันละ 1 ครั้ง ตี 3):
 *   0 3 * * * /usr/bin/php /path/to/activitydms/cron/cleanup_logs.php >> /var/log/activitydms-cleanup.log 2>&1
 *
 * Retention policy (ปรับได้ผ่าน constant ด้านล่าง):
 *  - email_queue.status='sent'   เก็บ  90 วัน (email_logs CASCADE DELETE ตามไปด้วย)
 *  - email_queue.status='failed' เก็บ 180 วัน (เก็บไว้ debug นานกว่า)
 *  - notifications.is_read=1     เก็บ 180 วัน
 *  - audit_logs                  เก็บ 3 ปี (compliance / ตรวจสอบย้อนหลัง)
 *
 * Dry-run (นับอย่างเดียว ไม่ลบ):
 *   php cron/cleanup_logs.php --dry-run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Retention (วัน)
const RETAIN_SENT_EMAILS   = 90;
const RETAIN_FAILED_EMAILS = 180;
const RETAIN_READ_NOTIFS   = 180;
const RETAIN_AUDIT_LOGS    = 1095; // 3 ปี

// Safety cap ต่อรอบ — กัน lock table นาน ถ้ามี backlog เยอะ
const DELETE_BATCH_SIZE = 5000;

$dry_run = in_array('--dry-run', $argv ?? [], true);

$pdo = db();

$log = function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
};

$log(($dry_run ? '[DRY-RUN] ' : '') . 'cleanup_logs.php started');

/**
 * นับและลบ records ตามเงื่อนไข (batch deletion)
 * คืนจำนวนที่ลบจริง
 */
$cleanup = function (string $table, string $where, array $params, int $days, string $label)
    use ($pdo, $log, $dry_run): int
{
    // นับก่อน
    $countSql = "SELECT COUNT(*) FROM `$table` WHERE $where";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $count = (int)$stmt->fetchColumn();

    if ($count === 0) {
        $log("  $label: 0 records ที่เก่ากว่า $days วัน — ข้าม");
        return 0;
    }

    $log("  $label: พบ $count records ที่เก่ากว่า $days วัน" . ($dry_run ? ' (dry-run)' : ''));

    if ($dry_run) return 0;

    // ลบเป็น batch ป้องกัน long-running lock
    $deleted = 0;
    $delSql = "DELETE FROM `$table` WHERE $where LIMIT " . DELETE_BATCH_SIZE;
    while (true) {
        $del = $pdo->prepare($delSql);
        $del->execute($params);
        $rows = $del->rowCount();
        $deleted += $rows;
        if ($rows < DELETE_BATCH_SIZE) break;
    }

    $log("  $label: ลบสำเร็จ $deleted records");
    return $deleted;
};

try {
    $total = 0;

    // 1. email_queue: sent เก่า → CASCADE ลบ email_logs ตามไปด้วย
    $total += $cleanup(
        'email_queue',
        "status = 'sent' AND sent_at < (NOW() - INTERVAL :d DAY)",
        [':d' => RETAIN_SENT_EMAILS],
        RETAIN_SENT_EMAILS,
        'email_queue (sent)'
    );

    // 2. email_queue: failed เก่า
    $total += $cleanup(
        'email_queue',
        "status = 'failed' AND created_at < (NOW() - INTERVAL :d DAY)",
        [':d' => RETAIN_FAILED_EMAILS],
        RETAIN_FAILED_EMAILS,
        'email_queue (failed)'
    );

    // 3. notifications: อ่านแล้วเก่า
    $total += $cleanup(
        'notifications',
        "is_read = 1 AND read_at IS NOT NULL AND read_at < (NOW() - INTERVAL :d DAY)",
        [':d' => RETAIN_READ_NOTIFS],
        RETAIN_READ_NOTIFS,
        'notifications (read)'
    );

    // 4. audit_logs: เกิน 3 ปี
    $total += $cleanup(
        'audit_logs',
        "created_at < (NOW() - INTERVAL :d DAY)",
        [':d' => RETAIN_AUDIT_LOGS],
        RETAIN_AUDIT_LOGS,
        'audit_logs'
    );

    $log(($dry_run ? '[DRY-RUN] ' : '') . "เสร็จสิ้น — รวมลบ $total records");
    exit(0);

} catch (Throwable $e) {
    $log('ERROR: ' . $e->getMessage());
    exit(1);
}
