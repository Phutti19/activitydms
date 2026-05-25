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

// stat: เข้าร่วมแล้ว
$s_at = $pdo->prepare('SELECT COUNT(*) FROM activity_registrations WHERE user_id = :u AND status = "attended"');
$s_at->execute([':u' => $uid]);
$attended_count = (int) $s_at->fetchColumn();

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

// แยก "วันนี้" ออกจาก "เร็วๆ นี้"
$today_str = date('Y-m-d');
$today_acts = [];
$later_acts = [];
foreach ($upcoming as $a) {
    if (date('Y-m-d', strtotime($a['start_datetime'])) === $today_str
        || (strtotime($a['start_datetime']) <= time() && strtotime($a['end_datetime']) >= time())) {
        $today_acts[] = $a;
    } else {
        $later_acts[] = $a;
    }
}
$today_count = count($today_acts);

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

$page_title  = 'หน้าหลัก';
$page_active = 'dashboard';
require __DIR__ . '/../includes/header.php';
$app_url_safe = h(APP_URL);

$today_label = th_weekday_date();
?>

<!-- Hero greeting -->
<div class="card mb-4 border-0 text-white"
     style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);">
    <div class="card-body p-4">
        <div class="row align-items-center g-3">
            <div class="col-12 col-md-8">
                <div class="small opacity-75 mb-1">
                    <i class="bi bi-calendar3 me-1"></i><?= h($today_label) ?>
                </div>
                <h1 class="h3 fw-bold mb-2">
                    สวัสดี, <?= h($_SESSION['display_name'] ?? '') ?>
                </h1>
                <p class="mb-0 opacity-90">
                    <?php if ($today_count > 0): ?>
                        <i class="bi bi-bell-fill me-1"></i>
                        วันนี้คุณมี <strong><?= $today_count ?></strong> กิจกรรม
                    <?php else: ?>
                        <i class="bi bi-cup-hot me-1"></i>
                        วันนี้ไม่มีกิจกรรมที่ต้องเข้าร่วม
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-12 col-md-4 text-md-end">
                <a href="<?= $app_url_safe ?>/employee/calendar.php"
                   class="btn btn-light fw-semibold">
                    <i class="bi bi-calendar-week me-1"></i>ดูปฏิทิน
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <a href="<?= $app_url_safe ?>/employee/my_activities.php" class="text-decoration-none text-reset">
            <div class="card card-hover text-center p-3 h-100">
                <div class="text-primary mb-1"><i class="bi bi-calendar-check fs-3"></i></div>
                <div class="fs-3 fw-bold text-primary"><?= $reg_count ?></div>
                <div class="small text-muted">กิจกรรมของฉัน</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="<?= $app_url_safe ?>/employee/my_reports.php" class="text-decoration-none text-reset">
            <div class="card card-hover text-center p-3 h-100">
                <div class="text-success mb-1"><i class="bi bi-check-circle fs-3"></i></div>
                <div class="fs-3 fw-bold text-success"><?= $attended_count ?></div>
                <div class="small text-muted">เข้าร่วมแล้ว</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="<?= $app_url_safe ?>/employee/personal_activities.php" class="text-decoration-none text-reset">
            <div class="card card-hover text-center p-3 h-100">
                <div class="text-secondary mb-1"><i class="bi bi-person-lines-fill fs-3"></i></div>
                <div class="fs-3 fw-bold text-secondary"><?= $personal_count ?></div>
                <div class="small text-muted">กิจกรรมส่วนตัว</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="<?= $app_url_safe ?>/employee/my_certificates.php" class="text-decoration-none text-reset">
            <div class="card card-hover text-center p-3 h-100">
                <div class="text-warning mb-1"><i class="bi bi-award fs-3"></i></div>
                <div class="fs-3 fw-bold text-warning"><?= $cert_count ?></div>
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
    <div class="p-4 text-center text-muted small">
        <i class="bi bi-calendar-x fs-3 d-block mb-2"></i>
        ไม่มีกิจกรรมที่รอดำเนินการ
    </div>
    <?php else: ?>

    <?php if (!empty($today_acts)): ?>
    <div class="px-3 pt-3 pb-1 small text-uppercase fw-semibold text-primary">
        <i class="bi bi-dot"></i>วันนี้ (<?= $today_count ?>)
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($today_acts as $a):
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#5F5E5A';
            $now   = time();
            $s     = strtotime((string)$a['start_datetime']);
            $e     = strtotime((string)$a['end_datetime']);
            $ongoing = ($s <= $now && $e >= $now);
        ?>
        <li class="list-group-item d-flex align-items-center gap-3 py-3">
            <div style="width:4px;height:40px;background:<?= h($color) ?>;border-radius:2px;flex-shrink:0;"></div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="fw-medium text-truncate">
                    <?= h($a['title']) ?>
                </div>
                <div class="small text-muted">
                    <i class="bi bi-clock me-1"></i><?= th_time($a['start_datetime']) ?>–<?= th_time($a['end_datetime']) ?>
                    <?php if (!empty($a['location'])): ?>
                    · <i class="bi bi-geo-alt me-1"></i><?= h($a['location']) ?>
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

    <?php if (!empty($later_acts)): ?>
    <div class="px-3 pt-3 pb-1 small text-uppercase fw-semibold text-muted">
        <i class="bi bi-dot"></i>เร็วๆ นี้
    </div>
    <ul class="list-group list-group-flush">
        <?php foreach ($later_acts as $a):
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#5F5E5A';
        ?>
        <li class="list-group-item d-flex align-items-center gap-3 py-3">
            <div style="width:4px;height:40px;background:<?= h($color) ?>;border-radius:2px;flex-shrink:0;"></div>
            <div class="flex-grow-1 overflow-hidden">
                <div class="fw-medium text-truncate">
                    <?= h($a['title']) ?>
                </div>
                <div class="small text-muted">
                    <i class="bi bi-calendar3 me-1"></i><?= h(th_date($a['start_datetime'])) ?>
                    · <?= th_time($a['start_datetime']) ?>
                    <?php if (!empty($a['location'])): ?>
                    · <i class="bi bi-geo-alt me-1"></i><?= h($a['location']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

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
            <span style="font-size:24px;">📜</span>
            <div class="flex-grow-1 overflow-hidden">
                <div class="fw-medium text-truncate">
                    <?= h($c['activity_title']) ?>
                </div>
                <?php $_cts = strtotime((string)$c['created_at']); ?>
                <div class="small text-muted">
                    <?= h(date('d/m/', $_cts) . (date('Y', $_cts) + 543)) ?>
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
