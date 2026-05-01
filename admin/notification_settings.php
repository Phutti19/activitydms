<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';

require_role('admin');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    // ดึง keys ทั้งหมดจาก DB แล้ว update ทีละ key
    $keys_stmt = $pdo->query('SELECT setting_key FROM notification_settings');
    $all_keys  = $keys_stmt->fetchAll(PDO::FETCH_COLUMN);

    $upd = $pdo->prepare(
        'UPDATE notification_settings SET setting_value = :v WHERE setting_key = :k'
    );

    $changes = [];
    foreach ($all_keys as $key) {
        // checkbox ที่ไม่ถูกติ๊กจะไม่ส่งมาใน POST → ถือว่า '0'
        $new_val = isset($_POST['settings'][$key]) ? '1' : '0';
        $upd->execute([':v' => $new_val, ':k' => $key]);
        $changes[$key] = $new_val;
    }

    audit_log('update_notification_settings', 'notification_settings', null, null, $changes);
    flash_set('success', 'บันทึกการตั้งค่าการแจ้งเตือนสำเร็จ');
    header('Location: ' . APP_URL . '/admin/notification_settings.php');
    exit;
}

// ดึงค่าทั้งหมด
$rows = $pdo->query(
    'SELECT setting_key, setting_value, label FROM notification_settings ORDER BY id'
)->fetchAll();

// ดึงสถิติ queue
$queue_stats = $pdo->query(
    "SELECT status, COUNT(*) AS cnt FROM email_queue GROUP BY status"
)->fetchAll();
$stats = array_column($queue_stats, 'cnt', 'status');

// 50 อีเมลล่าสุดจาก email_queue (แสดงประวัติ)
$recent_stmt = $pdo->prepare(
    "SELECT q.id, q.to_email, q.to_name, q.subject, q.status,
            q.retry_count, q.sent_at, q.created_at,
            (SELECT COUNT(*) FROM email_logs el WHERE el.queue_id = q.id) AS attempt_count
     FROM email_queue q
     ORDER BY q.id DESC
     LIMIT 50"
);
$recent_stmt->execute();
$recent_queue = $recent_stmt->fetchAll();

$page_title  = 'ตั้งค่าการแจ้งเตือน';
$page_active = 'notification_settings';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">ตั้งค่าการแจ้งเตือนอีเมล</h1>
</div>

<div class="row g-3 mb-4">
    <?php
    $stat_items = [
        ['label'=>'รอส่ง',     'key'=>'pending', 'color'=>'warning'],
        ['label'=>'กำลังส่ง',  'key'=>'sending', 'color'=>'info'],
        ['label'=>'ส่งแล้ว',   'key'=>'sent',    'color'=>'success'],
        ['label'=>'ส่งไม่สำเร็จ','key'=>'failed', 'color'=>'danger'],
    ];
    foreach ($stat_items as $s):
        $cnt = (int)($stats[$s['key']] ?? 0);
    ?>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3">
            <div class="fs-2 fw-bold text-<?= $s['color'] ?>"><?= number_format($cnt) ?></div>
            <div class="small text-muted"><?= htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card p-4 mb-4">
    <h5 class="mb-3">การแจ้งเตือนอีเมล</h5>
    <form method="POST">
        <?= csrf_field() ?>
        <?php foreach ($rows as $row): ?>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch"
                   id="chk_<?= htmlspecialchars($row['setting_key'], ENT_QUOTES, 'UTF-8') ?>"
                   name="settings[<?= htmlspecialchars($row['setting_key'], ENT_QUOTES, 'UTF-8') ?>]"
                   value="1"
                   <?= $row['setting_value'] === '1' ? 'checked' : '' ?>>
            <label class="form-check-label"
                   for="chk_<?= htmlspecialchars($row['setting_key'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') ?>
            </label>
        </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i> บันทึก
        </button>
    </form>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-envelope-open"></i>
        <span class="fw-semibold">ประวัติอีเมล (50 ล่าสุด)</span>
    </div>
    <?php if (empty($recent_queue)): ?>
    <div class="p-4 text-center text-muted">ยังไม่มีอีเมลในคิว</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>#</th>
                    <th>ผู้รับ</th>
                    <th>หัวเรื่อง</th>
                    <th>สถานะ</th>
                    <th>retry</th>
                    <th>วันที่สร้าง</th>
                    <th>ส่งเมื่อ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_queue as $q):
                    $status_badge = match($q['status']) {
                        'sent'    => 'success',
                        'pending' => 'warning',
                        'sending' => 'info',
                        'failed'  => 'danger',
                        default   => 'secondary',
                    };
                    $status_label = match($q['status']) {
                        'sent'    => 'ส่งแล้ว',
                        'pending' => 'รอส่ง',
                        'sending' => 'กำลังส่ง',
                        'failed'  => 'ล้มเหลว',
                        default   => $q['status'],
                    };
                ?>
                <tr>
                    <td data-label="#" class="text-muted small"><?= (int)$q['id'] ?></td>
                    <td data-label="ผู้รับ">
                        <div class="small fw-medium text-truncate" style="max-width:180px;">
                            <?= htmlspecialchars($q['to_name'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="small text-muted text-truncate" style="max-width:180px;">
                            <?= htmlspecialchars($q['to_email'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </td>
                    <td data-label="หัวเรื่อง" class="small text-truncate" style="max-width:220px;">
                        <?= htmlspecialchars($q['subject'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td data-label="สถานะ">
                        <span class="badge bg-<?= $status_badge ?>"><?= $status_label ?></span>
                    </td>
                    <td data-label="retry" class="small text-center"><?= (int)$q['retry_count'] ?>/3</td>
                    <td data-label="วันที่สร้าง" class="small text-muted text-nowrap">
                        <?= htmlspecialchars(
                            date('d/m/Y H:i', strtotime((string)$q['created_at'])),
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>
                    <td data-label="ส่งเมื่อ" class="small text-muted text-nowrap">
                        <?= $q['sent_at']
                            ? htmlspecialchars(date('d/m/Y H:i', strtotime((string)$q['sent_at'])), ENT_QUOTES, 'UTF-8')
                            : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
