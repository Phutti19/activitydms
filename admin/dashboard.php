<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fiscal_year.php';
require_role('admin');

$pdo = db();

// ปีงบประมาณ active (date-based auto-switch)
$fy = active_fiscal_year();
$fy_id   = $fy ? (int)$fy['id'] : 0;
$fy_name = $fy ? $fy['name'] : '—';

// แจ้งเตือน admin ให้ seed ปีถัดไปเมื่อใกล้หมด
$fy_days_left   = days_until_fy_end();
$fy_has_next    = next_fiscal_year_exists();
$fy_warn_seed   = $fy_days_left !== null && $fy_days_left <= 60 && !$fy_has_next;
$fy_warn_expired= $fy_days_left !== null && $fy_days_left < 0;

$fy_clause = $fy_id > 0 ? 'AND a.fiscal_year_id = :fy' : '';
$fy_params = $fy_id > 0 ? [':fy' => $fy_id] : [];

// กิจกรรมองค์กรปีนี้
$ta = $pdo->prepare(
    'SELECT COUNT(*) FROM activities a WHERE a.scope = "organization" ' . $fy_clause
);
$ta->execute($fy_params);
$total_activities = (int)$ta->fetchColumn();

// เข้าร่วม + breakdown
$reg = $pdo->prepare(
    'SELECT COUNT(*) AS reg_total,
            SUM(r.status = "attended")   AS attended,
            SUM(r.status = "absent")     AS absent,
            SUM(r.status = "registered") AS registered
     FROM activity_registrations r
     JOIN activities a ON a.id = r.activity_id
     WHERE a.scope = "organization" ' . $fy_clause
);
$reg->execute($fy_params);
$reg_row = $reg->fetch();
$total_reg        = (int)($reg_row['reg_total'] ?? 0);
$total_attended   = (int)($reg_row['attended']  ?? 0);
$total_absent     = (int)($reg_row['absent']    ?? 0);
$total_registered = (int)($reg_row['registered']?? 0);

// อัตราเข้าร่วม = attended / (attended + absent) — ไม่นับ registered ที่ยังไม่ถึงเวลา
$rate_base   = $total_attended + $total_absent;
$attend_rate = $rate_base > 0 ? (int) round(($total_attended / $rate_base) * 100) : 0;

// เกียรติบัตร
$tc = $pdo->prepare(
    'SELECT COUNT(*) FROM certificates c
     JOIN activities a ON a.id = c.activity_id
     WHERE a.scope = "organization" ' . $fy_clause
);
$tc->execute($fy_params);
$total_certs = (int)$tc->fetchColumn();

// บุคลากร active
$te = $pdo->prepare(
    "SELECT
        SUM(role = 'employee') AS employees,
        SUM(role = 'admin')    AS admins,
        SUM(role = 'director') AS directors,
        COUNT(*)               AS total_users
     FROM users WHERE is_active = 1"
);
$te->execute();
$user_stats = $te->fetch();

// queue email pending
$eq = $pdo->prepare(
    "SELECT
        SUM(status = 'pending') AS pending,
        SUM(status = 'failed')  AS failed
     FROM email_queue"
);
$eq->execute();
$email_stats = $eq->fetch();

// กิจกรรมวันนี้
$td_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM activities
     WHERE scope = 'organization'
       AND DATE(start_datetime) = CURDATE()"
);
$td_stmt->execute();
$today_count = (int)$td_stmt->fetchColumn();

// กิจกรรมที่กำลังจะมา (5 รายการ)
$upcoming_stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location,
            a.is_open_registration,
            t.name AS type_name, t.color AS type_color,
            COUNT(r.id) AS reg_count
     FROM activities a
     LEFT JOIN activity_types t ON t.id = a.activity_type_id
     LEFT JOIN activity_registrations r ON r.activity_id = a.id
     WHERE a.scope = "organization" AND a.end_datetime >= NOW()
     GROUP BY a.id
     ORDER BY a.start_datetime ASC
     LIMIT 5'
);
$upcoming_stmt->execute();
$upcoming = $upcoming_stmt->fetchAll();

// กิจกรรมที่เพิ่งสร้าง (5 รายการ)
$recent_stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.start_datetime, a.created_at,
            t.name AS type_name, t.color AS type_color,
            TRIM(CONCAT_WS(" ", u.prefix_name, u.first_name, u.last_name)) AS creator_name
     FROM activities a
     LEFT JOIN activity_types t ON t.id = a.activity_type_id
     LEFT JOIN users u ON u.id = a.created_by
     WHERE a.scope = "organization"
     ORDER BY a.created_at DESC
     LIMIT 5'
);
$recent_stmt->execute();
$recent = $recent_stmt->fetchAll();

function admin_dash_relative(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)        return 'เมื่อสักครู่';
    if ($diff < 3600)      return (int)floor($diff/60)    . ' นาทีก่อน';
    if ($diff < 86400)     return (int)floor($diff/3600)  . ' ชั่วโมงก่อน';
    if ($diff < 86400 * 7) return (int)floor($diff/86400) . ' วันก่อน';
    $ts = strtotime($dt);
    $m  = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1];
}

$page_title  = 'หน้าหลัก';
$page_active = 'dashboard';
require __DIR__ . '/../includes/header.php';
$app_url_safe = h(APP_URL);

$today_label = th_weekday_date();
?>

<?php if ($fy_warn_expired): ?>
<div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
    <i class="bi bi-exclamation-octagon-fill fs-4"></i>
    <div class="flex-grow-1">
        <div class="fw-semibold">ปีงบประมาณ <?= h($fy_name) ?> หมดอายุแล้ว</div>
        <div class="small">
            ระบบกำลังใช้ปีนี้เป็นค่าเริ่มต้น (fallback) เนื่องจากไม่มีปีงบประมาณที่ครอบคลุมวันนี้
            กรุณาเพิ่มปีงบประมาณถัดไปทันที
        </div>
    </div>
    <a href="<?= h(APP_URL) ?>/admin/manage_fiscal_year.php"
       class="btn btn-sm btn-danger flex-shrink-0">เพิ่มปีงบประมาณ</a>
</div>
<?php elseif ($fy_warn_seed): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
    <div class="flex-grow-1">
        <div class="fw-semibold">
            ปีงบประมาณ <?= h($fy_name) ?>
            จะหมดในอีก <?= (int)$fy_days_left ?> วัน
        </div>
        <div class="small">
            ยังไม่มีปีงบประมาณถัดไปในระบบ — กรุณาเพิ่มล่วงหน้าเพื่อให้ระบบ auto-switch เมื่อขึ้นปีใหม่
        </div>
    </div>
    <a href="<?= h(APP_URL) ?>/admin/manage_fiscal_year.php"
       class="btn btn-sm btn-warning flex-shrink-0">เพิ่มปีงบประมาณ</a>
</div>
<?php endif; ?>

<!-- Hero greeting -->
<div class="card mb-4 border-0 text-white"
     style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);">
    <div class="card-body p-4">
        <div class="row align-items-center g-3">
            <div class="col-12 col-md-8">
                <div class="small opacity-75 mb-1">
                    <i class="bi bi-calendar3 me-1"></i><?= h($today_label) ?>
                    · ปีงบประมาณ <?= h($fy_name) ?>
                </div>
                <h1 class="h3 fw-bold mb-2">
                    สวัสดี, <?= h($_SESSION['display_name'] ?? '') ?>
                </h1>
                <p class="mb-0 opacity-90">
                    <?php if ($today_count > 0): ?>
                        <i class="bi bi-bell-fill me-1"></i>
                        วันนี้มี <strong><?= $today_count ?></strong> กิจกรรมองค์กร
                    <?php else: ?>
                        <i class="bi bi-cup-hot me-1"></i>
                        วันนี้ไม่มีกิจกรรมองค์กร
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-12 col-md-4 text-md-end">
                <a href="<?= $app_url_safe ?>/admin/activity_form.php?action=create"
                   class="btn btn-light fw-semibold">
                    <i class="bi bi-plus-lg me-1"></i>เพิ่มกิจกรรม
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <a href="<?= $app_url_safe ?>/admin/manage_activities.php" class="text-decoration-none text-reset">
            <div class="card card-hover text-center p-3 h-100">
                <div class="text-primary mb-1"><i class="bi bi-calendar-event fs-3"></i></div>
                <div class="fs-3 fw-bold text-primary"><?= number_format($total_activities) ?></div>
                <div class="small text-muted">กิจกรรมองค์กร</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 h-100">
            <div class="text-info mb-1"><i class="bi bi-people fs-3"></i></div>
            <div class="fs-3 fw-bold text-info"><?= number_format($total_reg) ?></div>
            <div class="small text-muted">รายการเข้าร่วม</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 h-100">
            <div class="text-success mb-1"><i class="bi bi-check2-circle fs-3"></i></div>
            <div class="fs-3 fw-bold text-success"><?= $attend_rate ?>%</div>
            <div class="small text-muted">
                อัตราเข้าร่วม
                <?php if ($rate_base > 0): ?>
                    <span class="d-block">(<?= $total_attended ?>/<?= $rate_base ?>)</span>
                <?php else: ?>
                    <span class="d-block">— ยังไม่มีข้อมูล</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <a href="<?= $app_url_safe ?>/admin/manage_certificates.php" class="text-decoration-none text-reset">
            <div class="card card-hover text-center p-3 h-100">
                <div class="text-warning mb-1"><i class="bi bi-award fs-3"></i></div>
                <div class="fs-3 fw-bold text-warning"><?= number_format($total_certs) ?></div>
                <div class="small text-muted">เกียรติบัตรที่ออก</div>
            </div>
        </a>
    </div>
</div>

<!-- Chart + ทางลัด -->
<div class="row g-3 mb-4">
    <?php if ($total_reg > 0): ?>
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="bi bi-pie-chart me-1"></i>สรุปสถานะการเข้าร่วม (ปีงบ <?= h($fy_name) ?>)
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-md-5">
                        <div style="max-width: 220px; margin: 0 auto;">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                    <div class="col-12 col-md-7">
                        <ul class="list-unstyled mb-0">
                            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <span><span class="d-inline-block rounded-circle me-2" style="width:.75rem;height:.75rem;background:#198754;"></span>เข้าร่วมแล้ว</span>
                                <strong><?= $total_attended ?></strong>
                            </li>
                            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <span><span class="d-inline-block rounded-circle me-2" style="width:.75rem;height:.75rem;background:#dc3545;"></span>ไม่เข้าร่วม</span>
                                <strong><?= $total_absent ?></strong>
                            </li>
                            <li class="d-flex justify-content-between align-items-center py-2">
                                <span><span class="d-inline-block rounded-circle me-2" style="width:.75rem;height:.75rem;background:#6c757d;"></span>ยืนยันเข้าร่วม (รอ)</span>
                                <strong><?= $total_registered ?></strong>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12 <?= $total_reg > 0 ? 'col-lg-5' : '' ?>">
        <div class="row g-3 h-100">
            <div class="col-6">
                <a href="<?= $app_url_safe ?>/admin/manage_users.php" class="text-decoration-none text-reset">
                    <div class="card card-hover p-3 h-100">
                        <div class="small text-muted mb-1">
                            <i class="bi bi-person-badge me-1"></i>บัญชีทั้งหมด
                        </div>
                        <div class="fs-4 fw-bold"><?= (int)($user_stats['total_users'] ?? 0) ?></div>
                        <div class="small text-muted">
                            <?= (int)($user_stats['employees'] ?? 0) ?> พนักงาน ·
                            <?= (int)($user_stats['admins'] ?? 0) ?> admin ·
                            <?= (int)($user_stats['directors'] ?? 0) ?> director
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-6">
                <a href="<?= $app_url_safe ?>/admin/notification_settings.php" class="text-decoration-none text-reset">
                    <div class="card card-hover p-3 h-100">
                        <div class="small text-muted mb-1">
                            <i class="bi bi-envelope me-1"></i>คิวอีเมล
                        </div>
                        <div class="fs-4 fw-bold"><?= (int)($email_stats['pending'] ?? 0) ?>
                            <span class="small text-muted fw-normal">รอส่ง</span>
                        </div>
                        <?php if ((int)($email_stats['failed'] ?? 0) > 0): ?>
                        <div class="small text-danger fw-semibold">
                            <i class="bi bi-exclamation-triangle me-1"></i><?= (int)$email_stats['failed'] ?> ล้มเหลว
                        </div>
                        <?php else: ?>
                        <div class="small text-muted">ไม่มีรายการล้มเหลว</div>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
            <div class="col-12">
                <div class="card p-3 h-100">
                    <div class="small text-muted mb-2">
                        <i class="bi bi-lightning-charge me-1"></i>ทางลัด
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?= $app_url_safe ?>/admin/calendar.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-calendar3 me-1"></i>ปฏิทิน
                        </a>
                        <a href="<?= $app_url_safe ?>/admin/reports.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-graph-up me-1"></i>รายงาน
                        </a>
                        <a href="<?= $app_url_safe ?>/admin/manage_documents.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-folder me-1"></i>เอกสาร
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Upcoming activities -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">
                    <i class="bi bi-calendar-event me-1"></i>กิจกรรมที่กำลังจะมาถึง
                </span>
                <a href="<?= $app_url_safe ?>/admin/manage_activities.php"
                   class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
            </div>
            <?php if (empty($upcoming)): ?>
            <div class="p-4 text-center text-muted small">
                <i class="bi bi-calendar-x fs-3 d-block mb-2"></i>
                ยังไม่มีกิจกรรมที่กำลังจะมาถึง
            </div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($upcoming as $a):
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#185FA5';
                    $is_ongoing = strtotime($a['start_datetime']) <= time() && strtotime($a['end_datetime']) >= time();
                ?>
                <li class="list-group-item d-flex align-items-center gap-3 py-3">
                    <div style="width:4px;height:40px;background:<?= h($color) ?>;border-radius:2px;flex-shrink:0;"></div>
                    <div class="flex-grow-1 overflow-hidden">
                        <a href="<?= $app_url_safe ?>/admin/activity_view.php?id=<?= (int)$a['id'] ?>"
                           class="fw-medium text-decoration-none text-truncate d-block">
                            <?= h($a['title']) ?>
                        </a>
                        <div class="small text-muted">
                            <i class="bi bi-clock me-1"></i><?= h(th_datetime($a['start_datetime'], ' ')) ?>
                            <?php if (!empty($a['location'])): ?>
                            · <i class="bi bi-geo-alt me-1"></i><?= h($a['location']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end flex-shrink-0">
                        <?php if ($is_ongoing): ?>
                        <span class="badge" style="background:#FEF3C7;color:#92400E;">กำลังดำเนินอยู่</span>
                        <?php else: ?>
                        <span class="badge bg-secondary"><?= (int)$a['reg_count'] ?> คน</span>
                        <?php endif; ?>
                        <?php if ((int)$a['is_open_registration'] === 1): ?>
                        <div class="small mt-1">
                            <span class="badge bg-info">เปิดรับสมัคร</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent activities -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="bi bi-clock-history me-1"></i>กิจกรรมที่เพิ่งสร้าง
            </div>
            <?php if (empty($recent)): ?>
            <div class="p-4 text-center text-muted small">
                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                ยังไม่มีกิจกรรม
            </div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($recent as $a):
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#185FA5';
                ?>
                <li class="list-group-item py-3">
                    <div class="d-flex align-items-start gap-2">
                        <span class="badge flex-shrink-0"
                              style="background:<?= h($color) ?>;">
                            <?= h($a['type_name'] ?? '—') ?>
                        </span>
                        <div class="flex-grow-1 overflow-hidden">
                            <a href="<?= $app_url_safe ?>/admin/activity_view.php?id=<?= (int)$a['id'] ?>"
                               class="fw-medium text-decoration-none text-truncate d-block">
                                <?= h($a['title']) ?>
                            </a>
                            <div class="small text-muted mt-1">
                                <i class="bi bi-person me-1"></i><?= h($a['creator_name'] ?? '—') ?>
                                · <?= h(admin_dash_relative($a['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($total_reg > 0): ?>
<script src="<?= h(APP_URL) ?>/assets/vendor/chartjs/chart.umd.min.js"></script>
<script>
(function () {
    const ctx = document.getElementById('statusChart');
    if (!ctx || typeof Chart === 'undefined') return;
    Chart.defaults.font.family = "'Kanit', system-ui, sans-serif";
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['เข้าร่วมแล้ว', 'ไม่เข้าร่วม', 'ยืนยันเข้าร่วม'],
            datasets: [{
                data: [<?= $total_attended ?>, <?= $total_absent ?>, <?= $total_registered ?>],
                backgroundColor: ['#198754', '#dc3545', '#6c757d'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '65%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (c) => ' ' + c.label + ': ' + c.parsed
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
