<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('employee');

$uid = (int) current_user_id();
$pdo = db();

// stat: กิจกรรมที่เข้าร่วม (org)
$s1 = $pdo->prepare('SELECT COUNT(*) FROM activity_registrations WHERE user_id = :u');
$s1->execute([':u' => $uid]);
$reg_count = (int) $s1->fetchColumn();

$s2 = $pdo->prepare('SELECT COUNT(*) FROM activities WHERE scope = "personal" AND created_by = :u');
$s2->execute([':u' => $uid]);
$personal_count = (int) $s2->fetchColumn();

$s3 = $pdo->prepare('SELECT COUNT(*) FROM certificates WHERE user_id = :u');
$s3->execute([':u' => $uid]);
$cert_count = (int) $s3->fetchColumn();

// upcoming activities (org, registered, not ended)
$upcoming_stmt = $pdo->prepare(
    "SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location,
            t.name AS type_name, t.color AS type_color,
            r.status AS reg_status
     FROM activity_registrations r
     JOIN activities a ON a.id = r.activity_id
     LEFT JOIN activity_types t ON t.id = a.activity_type_id
     WHERE r.user_id = :u AND a.scope = 'organization' AND a.end_datetime >= NOW()
     ORDER BY a.start_datetime ASC
     LIMIT 5"
);
$upcoming_stmt->execute([':u' => $uid]);
$upcoming = $upcoming_stmt->fetchAll();

// recent certs
$cert_stmt = $pdo->prepare(
    "SELECT c.id, c.original_name, c.created_at, a.title AS activity_title
     FROM certificates c
     JOIN activities a ON a.id = c.activity_id
     WHERE c.user_id = :u
     ORDER BY c.created_at DESC
     LIMIT 3"
);
$cert_stmt->execute([':u' => $uid]);
$recent_certs = $cert_stmt->fetchAll();

function dash_fmt_date(string $dt): string {
    $ts = strtotime($dt);
    $m  = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543);
}

$page_title  = 'หน้าหลัก';
$page_active = 'dashboard';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">สวัสดี, <?= htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-muted small mb-0"><?= date('j') ?> <?= ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'][(int)date('n')] ?> <?= (int)date('Y')+543 ?></p>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <a href="<?= $app_url_safe ?>/employee/my_activities.php" class="text-decoration-none">
            <div class="card text-center p-3 h-100">
                <div class="fs-2 fw-bold text-primary"><?= $reg_count ?></div>
                <div class="small text-muted">กิจกรรมของฉัน</div>
            </div>
        </a>
    </div>
    <div class="col-4">
        <a href="<?= $app_url_safe ?>/employee/personal_activities.php" class="text-decoration-none">
            <div class="card text-center p-3 h-100">
                <div class="fs-2 fw-bold text-secondary"><?= $personal_count ?></div>
                <div class="small text-muted">กิจกรรมส่วนตัว</div>
            </div>
        </a>
    </div>
    <div class="col-4">
        <a href="<?= $app_url_safe ?>/employee/my_certificates.php" class="text-decoration-none">
            <div class="card text-center p-3 h-100">
                <div class="fs-2 fw-bold text-warning"><?= $cert_count ?></div>
                <div class="small text-muted">เกียรติบัตร</div>
            </div>
        </a>
    </div>
</div>

<!-- Upcoming activities -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-calendar-event me-1"></i>กิจกรรมที่กำลังจะมาถึง</span>
        <a href="<?= $app_url_safe ?>/employee/my_activities.php" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
    </div>
    <?php if (empty($upcoming)): ?>
    <div class="p-4 text-center text-muted small">ไม่มีกิจกรรมที่รอดำเนินการ</div>
    <?php else: ?>
    <ul class="list-group list-group-flush">
        <?php foreach ($upcoming as $a):
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#5F5E5A';
            $now   = time();
            $s     = strtotime((string)$a['start_datetime']);
            $e     = strtotime((string)$a['end_datetime']);
            $ongoing = ($s <= $now && $e >= $now);
        ?>
        <li class="list-group-item d-flex align-items-center gap-3 py-3">
            <div style="width:4px;height:40px;background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;border-radius:2px;flex-shrink:0;"></div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="fw-medium text-truncate">
                    <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="small text-muted">
                    <?= htmlspecialchars(dash_fmt_date($a['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
                    <?php if (!empty($a['location'])): ?>
                    · <?= htmlspecialchars($a['location'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($ongoing): ?>
            <span class="badge" style="background:#FEF3C7;color:#92400E;">กำลังดำเนินอยู่</span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<!-- Recent certs -->
<?php if (!empty($recent_certs)): ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-award me-1"></i>เกียรติบัตรล่าสุด</span>
        <a href="<?= $app_url_safe ?>/employee/my_certificates.php" class="btn btn-sm btn-outline-warning">ดูทั้งหมด</a>
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($recent_certs as $c): ?>
        <li class="list-group-item d-flex align-items-center gap-3 py-3">
            <span style="font-size:24px;">🏆</span>
            <div class="flex-grow-1 overflow-hidden">
                <div class="fw-medium text-truncate">
                    <?= htmlspecialchars($c['activity_title'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php $_cts = strtotime((string)$c['created_at']); ?>
                <div class="small text-muted">
                    <?= htmlspecialchars(date('d/m/', $_cts) . (date('Y', $_cts) + 543), ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <a href="<?= $app_url_safe ?>/api/download.php?type=cert&id=<?= (int)$c['id'] ?>"
               class="btn btn-sm btn-outline-warning" target="_blank">
                <i class="bi bi-download"></i>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
