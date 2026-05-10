<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';

require_role('employee');

$uid = (int) current_user_id();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'ไม่พบกิจกรรม');
    header('Location: ' . APP_URL . '/employee/my_activities.php');
    exit;
}

// ดึงข้อมูลกิจกรรม (scope = organization เท่านั้น)
$stmt = $pdo->prepare(
    'SELECT a.*, t.name AS type_name, t.color AS type_color, fy.name AS fiscal_name
     FROM activities a
     LEFT JOIN activity_types t  ON t.id = a.activity_type_id
     LEFT JOIN fiscal_years fy   ON fy.id = a.fiscal_year_id
     WHERE a.id = :id AND a.scope = "organization" LIMIT 1'
);
$stmt->execute([':id' => $id]);
$activity = $stmt->fetch();

if (!$activity) {
    flash_set('error', 'ไม่พบกิจกรรม');
    header('Location: ' . APP_URL . '/employee/my_activities.php');
    exit;
}

// ตรวจสิทธิ์: ต้องเข้าร่วมอยู่แล้ว หรือ กิจกรรมเปิดให้เข้าร่วม
$reg_check = $pdo->prepare(
    'SELECT id, status FROM activity_registrations
     WHERE activity_id = :a AND user_id = :u LIMIT 1'
);
$reg_check->execute([':a' => $id, ':u' => $uid]);
$my_reg = $reg_check->fetch();

if (!$my_reg && !(bool)$activity['is_open_registration']) {
    http_response_code(403);
    flash_set('error', 'คุณไม่มีสิทธิ์ดูกิจกรรมนี้');
    header('Location: ' . APP_URL . '/employee/my_activities.php');
    exit;
}

// ---------------------------------------------------------------------------
// POST — register / unregister
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        if ((bool)$activity['is_open_registration'] && !$my_reg) {
            if (strtotime((string)$activity['end_datetime']) < time()) {
                flash_set('error', 'กิจกรรมนี้สิ้นสุดแล้ว ไม่สามารถเข้าร่วมได้');
            } else {
                try {
                    $pdo->prepare(
                        'INSERT INTO activity_registrations (activity_id, user_id, status)
                         VALUES (:a, :u, "registered")'
                    )->execute([':a' => $id, ':u' => $uid]);
                    flash_set('success', 'เข้าร่วมกิจกรรมสำเร็จ');
                } catch (PDOException $e) {
                    flash_set('error', $e->getCode() === '23000' ? 'คุณได้เข้าร่วมแล้ว' : 'เกิดข้อผิดพลาด');
                }
            }
        }

    } elseif ($action === 'unregister') {
        if ($my_reg && (string)$my_reg['status'] === 'registered') {
            $pdo->prepare(
                'DELETE FROM activity_registrations
                 WHERE activity_id = :a AND user_id = :u AND status = "registered" LIMIT 1'
            )->execute([':a' => $id, ':u' => $uid]);
            flash_set('success', 'ยกเลิกการเข้าร่วมสำเร็จ');
        }
    }

    header('Location: ' . APP_URL . '/employee/activity_view.php?id=' . $id);
    exit;
}

// ---------------------------------------------------------------------------
// Fetch related data
// ---------------------------------------------------------------------------
$photos = $pdo->prepare(
    'SELECT id, filename, original_name FROM activity_photos
     WHERE activity_id = :id ORDER BY sort_order ASC, id ASC'
);
$photos->execute([':id' => $id]);
$photos = $photos->fetchAll();

$attachments = $pdo->prepare(
    'SELECT id, type, label, filename, url FROM activity_attachments
     WHERE activity_id = :id ORDER BY id ASC'
);
$attachments->execute([':id' => $id]);
$attachments = $attachments->fetchAll();

// certificate ของฉันในกิจกรรมนี้
$cert_check = $pdo->prepare(
    'SELECT id, original_name, created_at FROM certificates
     WHERE activity_id = :a AND user_id = :u LIMIT 1'
);
$cert_check->execute([':a' => $id, ':u' => $uid]);
$my_cert = $cert_check->fetch();

$now      = time();
$s        = strtotime((string)$activity['start_datetime']);
$e_ts     = strtotime((string)$activity['end_datetime']);
$ended    = $e_ts < $now;
$ongoing  = ($s <= $now && $e_ts >= $now);

$months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$fmt = function(string $dt) use ($months): string {
    $ts = strtotime($dt);
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . (date('Y', $ts)+543) . ', ' . date('H:i', $ts) . ' น.';
};

$color  = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$activity['type_color']) ? $activity['type_color'] : '#5F5E5A';
$ts_label = $ended ? 'เสร็จสิ้น' : ($ongoing ? 'กำลังดำเนินอยู่' : 'กำลังจะมาถึง');
$ts_bg    = $ended ? '#D1FAE5' : ($ongoing ? '#FEF3C7' : '#DBEAFE');
$ts_fg    = $ended ? '#065F46' : ($ongoing ? '#92400E' : '#1E40AF');

$reg_label = ['registered'=>'ยืนยันเข้าร่วม','attended'=>'เข้าร่วมแล้ว','absent'=>'ไม่เข้าร่วม'];
$reg_badge = ['registered'=>'secondary','attended'=>'success','absent'=>'danger'];

$page_title  = htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8');
$page_active = 'my_activities';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>

<div class="page-header flex-wrap gap-2">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <a href="javascript:history.back()" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i>
            </a>
            <h1 class="page-title mb-0">
                <?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?>
            </h1>
        </div>
        <div class="d-flex align-items-center gap-2 ms-1 flex-wrap">
            <span class="badge" style="background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
                <?= htmlspecialchars($activity['type_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span class="badge" style="background:<?= $ts_bg ?>;color:<?= $ts_fg ?>;"><?= $ts_label ?></span>
            <?php if ($my_reg): ?>
            <span class="badge bg-<?= $reg_badge[$my_reg['status']] ?? 'secondary' ?>">
                <?= $reg_label[$my_reg['status']] ?? '' ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- Info card -->
    <div class="col-12 col-lg-8">
        <div class="card p-4 h-100">
            <div class="row g-3">
                <div class="col-12 col-sm-6">
                    <div class="small text-muted mb-1"><i class="bi bi-clock me-1"></i>วันเวลาเริ่ม</div>
                    <div class="fw-medium"><?= htmlspecialchars($fmt($activity['start_datetime']), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="col-12 col-sm-6">
                    <div class="small text-muted mb-1"><i class="bi bi-clock me-1"></i>วันเวลาสิ้นสุด</div>
                    <div class="fw-medium"><?= htmlspecialchars($fmt($activity['end_datetime']), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php if (!empty($activity['location'])): ?>
                <div class="col-12">
                    <div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1"></i>สถานที่</div>
                    <div><?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($activity['fiscal_name'])): ?>
                <div class="col-12 col-sm-6">
                    <div class="small text-muted mb-1"><i class="bi bi-calendar-range me-1"></i>ปีงบประมาณ</div>
                    <div><?= htmlspecialchars($activity['fiscal_name'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($activity['description'])): ?>
                <div class="col-12">
                    <div class="small text-muted mb-1">รายละเอียด</div>
                    <div class="text-body" style="white-space:pre-wrap;word-break:break-word;">
                        <?= htmlspecialchars($activity['description'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($activity['external_url'])): ?>
                <div class="col-12">
                    <a href="<?= htmlspecialchars($activity['external_url'], ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener">
                        <i class="bi bi-box-arrow-up-right me-1"></i>ลิงก์ภายนอก
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Action card -->
    <div class="col-12 col-lg-4">
        <?php if (!empty($my_cert)): ?>
        <div class="card p-4 mb-3 border-warning">
            <div class="text-center mb-3" style="font-size:40px;">🏆</div>
            <h6 class="fw-semibold text-center mb-2">เกียรติบัตรของคุณ</h6>
            <p class="small text-muted text-center mb-3">
                <?= htmlspecialchars($my_cert['original_name'], ENT_QUOTES, 'UTF-8') ?>
            </p>
            <a href="<?= $app_url_safe ?>/api/download.php?type=cert&id=<?= (int)$my_cert['id'] ?>"
               class="btn btn-warning w-100" target="_blank">
                <i class="bi bi-download me-1"></i>ดาวน์โหลดเกียรติบัตร
            </a>
        </div>
        <?php endif; ?>

        <?php if ((bool)$activity['is_open_registration']): ?>
        <div class="card p-4">
            <h6 class="fw-semibold mb-3"><i class="bi bi-megaphone me-1"></i>การเข้าร่วม</h6>
            <?php if (!$my_reg && !$ended): ?>
            <p class="small text-muted mb-3">กิจกรรมนี้เข้าร่วมได้ด้วยตนเอง</p>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="register">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-person-plus me-1"></i>เข้าร่วม
                </button>
            </form>

            <?php elseif ($my_reg && (string)$my_reg['status'] === 'registered' && !$ended): ?>
            <p class="small text-success mb-3"><i class="bi bi-check-circle me-1"></i>คุณยืนยันเข้าร่วมแล้ว</p>
            <form method="POST"
                  onsubmit="return confirm('ยืนยันการยกเลิกเข้าร่วมกิจกรรมนี้?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unregister">
                <button type="submit" class="btn btn-outline-danger w-100">
                    <i class="bi bi-person-dash me-1"></i>ยกเลิกการเข้าร่วม
                </button>
            </form>

            <?php elseif ($my_reg): ?>
            <div class="alert alert-success small mb-0">
                <i class="bi bi-check-circle me-1"></i>
                คุณเป็นผู้เข้าร่วมกิจกรรมนี้
            </div>

            <?php elseif ($ended): ?>
            <div class="alert alert-secondary small mb-0">
                <i class="bi bi-lock me-1"></i>กิจกรรมสิ้นสุดแล้ว
            </div>
            <?php endif; ?>
        </div>
        <?php elseif ($my_reg): ?>
        <div class="card p-4">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-person-check text-success fs-5"></i>
                <div>
                    <h6 class="fw-semibold mb-1">การเข้าร่วม</h6>
                    <p class="small text-muted mb-0">Admin เพิ่มคุณเข้าร่วมกิจกรรมนี้</p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($photos)): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold">
        <i class="bi bi-images me-1"></i>ภาพกิจกรรม (<?= count($photos) ?>)
    </div>
    <div class="card-body">
        <div class="row g-2">
            <?php foreach ($photos as $ph): ?>
            <div class="col-6 col-md-4 col-lg-3">
                <a href="<?= $app_url_safe ?>/api/download.php?type=photo&id=<?= (int)$ph['id'] ?>"
                   target="_blank" class="d-block rounded overflow-hidden"
                   style="aspect-ratio:4/3;background:#f1f5f9;">
                    <img src="<?= $app_url_safe ?>/api/download.php?type=photo&id=<?= (int)$ph['id'] ?>"
                         alt="<?= htmlspecialchars($ph['original_name'], ENT_QUOTES, 'UTF-8') ?>"
                         class="w-100 h-100" style="object-fit:cover;"
                         loading="lazy">
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($attachments)): ?>
<div class="card">
    <div class="card-header fw-semibold">
        <i class="bi bi-paperclip me-1"></i>ไฟล์แนบและลิงก์ (<?= count($attachments) ?>)
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($attachments as $att): ?>
        <li class="list-group-item d-flex align-items-center gap-3 py-3">
            <?php if ($att['type'] === 'url'): ?>
            <i class="bi bi-link-45deg text-primary fs-5"></i>
            <div class="flex-grow-1 overflow-hidden">
                <div class="fw-medium text-truncate">
                    <?= htmlspecialchars($att['label'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <a href="<?= htmlspecialchars($att['url'], ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
            <?php else: ?>
            <i class="bi bi-file-earmark-text text-secondary fs-5"></i>
            <div class="flex-grow-1 overflow-hidden">
                <div class="fw-medium text-truncate">
                    <?= htmlspecialchars($att['label'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <a href="<?= $app_url_safe ?>/api/download.php?type=attachment&id=<?= (int)$att['id'] ?>"
               class="btn btn-sm btn-outline-secondary" target="_blank">
                <i class="bi bi-download"></i>
            </a>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
