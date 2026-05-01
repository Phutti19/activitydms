<?php
declare(strict_types=1);
/**
 * cron/send_emails.php — Email sender worker
 *
 * Crontab (ทุก 5 นาที):
 *   * /5 * * * * /usr/bin/php /path/to/activitydms/cron/send_emails.php >> /var/log/activitydms-mail.log 2>&1
 *
 * Flow:
 *  1. SELECT pending (retry < 3, scheduled_at <= NOW()), LIMIT 50 ต่อรอบ
 *  2. UPDATE status = 'sending' ก่อนส่ง (lock optimistic)
 *  3. ส่งผ่าน PHPMailer SMTP
 *  4. SUCCESS → status = 'sent', sent_at = NOW()
 *     FAILED   → retry_count++; retry < 3 → status = 'pending', retry >= 3 → 'failed'
 *  5. INSERT email_logs ทุก attempt
 */

// Guard: ต้องรันจาก CLI เท่านั้น
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

// Bootstrap — config, db, PHPMailer
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

define('MAIL_BATCH_SIZE', 50);  // จำนวนสูงสุดต่อรอบ (rate limit)
define('MAIL_MAX_RETRY', 3);

$pdo = db();
$now = date('Y-m-d H:i:s');

$log = function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
};

// ---------------------------------------------------------------------------
// 1. ดึง pending emails
// ---------------------------------------------------------------------------
$fetch = $pdo->prepare(
    'SELECT id, to_email, to_name, subject, body_html, body_text, retry_count
     FROM email_queue
     WHERE status = "pending"
       AND retry_count < :max_retry
       AND scheduled_at <= NOW()
     ORDER BY id ASC
     LIMIT :batch'
);
$fetch->bindValue(':max_retry', MAIL_MAX_RETRY, PDO::PARAM_INT);
$fetch->bindValue(':batch',     MAIL_BATCH_SIZE, PDO::PARAM_INT);
$fetch->execute();
$emails = $fetch->fetchAll();

if (empty($emails)) {
    $log('No pending emails.');
    exit(0);
}

$log('Found ' . count($emails) . ' pending email(s).');

// ---------------------------------------------------------------------------
// 2. สร้าง PHPMailer instance (ใช้ร่วมกันทุก email ในรอบนี้)
// ---------------------------------------------------------------------------
function make_mailer(): PHPMailer
{
    $mail             = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_HOST'];
    $mail->Port       = (int)($_ENV['MAIL_PORT'] ?? 587);
    $mail->SMTPAuth   = filter_var($_ENV['MAIL_SMTP_AUTH']   ?? 'true',  FILTER_VALIDATE_BOOLEAN);
    $mail->SMTPSecure = match(strtolower($_ENV['MAIL_ENCRYPTION'] ?? 'tls')) {
        'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
        default => PHPMailer::ENCRYPTION_STARTTLS,
    };
    $mail->Username   = $_ENV['MAIL_USERNAME']     ?? '';
    $mail->Password   = $_ENV['MAIL_PASSWORD']     ?? '';
    $mail->setFrom(
        $_ENV['MAIL_FROM_ADDRESS'],
        $_ENV['MAIL_FROM_NAME'] ?? (APP_NAME)
    );
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 15; // วินาที
    return $mail;
}

$mailer = make_mailer();

// ---------------------------------------------------------------------------
// 3. ส่งทีละฉบับ
// ---------------------------------------------------------------------------
$sent  = 0;
$failed = 0;

foreach ($emails as $email) {
    $qid = (int) $email['id'];

    // Mark as 'sending' (optimistic lock — ป้องกัน cron ซ้อนกัน)
    $pdo->prepare(
        'UPDATE email_queue SET status = "sending" WHERE id = :id AND status = "pending"'
    )->execute([':id' => $qid]);

    $error_msg = null;

    try {
        $mailer->clearAddresses();
        $mailer->clearReplyTos();

        $mailer->addAddress((string)$email['to_email'], (string)$email['to_name']);
        $mailer->Subject = (string)$email['subject'];
        $mailer->isHTML(true);
        $mailer->Body    = (string)$email['body_html'];
        $mailer->AltBody = $email['body_text'] !== ''
            ? (string)$email['body_text']
            : strip_tags((string)$email['body_html']);

        $mailer->send();

        // SUCCESS
        $pdo->prepare(
            'UPDATE email_queue SET status = "sent", sent_at = NOW() WHERE id = :id'
        )->execute([':id' => $qid]);

        $pdo->prepare(
            'INSERT INTO email_logs (queue_id, status) VALUES (:q, "success")'
        )->execute([':q' => $qid]);

        $log("SENT  #{$qid} → {$email['to_email']}");
        $sent++;

    } catch (MailerException $e) {
        $error_msg = $e->getMessage();
    } catch (Throwable $e) {
        $error_msg = $e->getMessage();
    }

    if ($error_msg !== null) {
        $new_retry = (int)$email['retry_count'] + 1;
        $new_status = $new_retry >= MAIL_MAX_RETRY ? 'failed' : 'pending';

        $pdo->prepare(
            'UPDATE email_queue
             SET status = :s, retry_count = :r
             WHERE id = :id'
        )->execute([':s' => $new_status, ':r' => $new_retry, ':id' => $qid]);

        $pdo->prepare(
            'INSERT INTO email_logs (queue_id, status, error_message)
             VALUES (:q, "failed", :e)'
        )->execute([':q' => $qid, ':e' => mb_substr($error_msg, 0, 1000)]);

        $log("FAIL  #{$qid} → {$email['to_email']} (retry {$new_retry}/{MAIL_MAX_RETRY}): {$error_msg}");
        $failed++;
    }
}

$log("Done. sent={$sent}, failed={$failed}");
exit(0);
