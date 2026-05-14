<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fiscal_year.php';
require_role('employee');

$uid = (int) current_user_id();
$pdo = db();

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$f_fiscal = (int)($_GET['fiscal'] ?? 0);
$f_status = $_GET['status'] ?? '';

$years_stmt = $pdo->prepare('SELECT id, name FROM fiscal_years ORDER BY start_year DESC');
$years_stmt->execute();
$years = $years_stmt->fetchAll();

// Default to active fiscal year (date-based auto-switch)
if ($f_fiscal === 0 && !isset($_GET['fiscal'])) {
    $f_fiscal = active_fiscal_year_id() ?? 0;
}

// ---------------------------------------------------------------------------
// My org activities
// ---------------------------------------------------------------------------
$where  = ['r.user_id = :uid', 'a.scope = "organization"'];
$params = [':uid' => $uid];
if ($f_fiscal > 0) { $where[] = 'a.fiscal_year_id = :fy'; $params[':fy'] = $f_fiscal; }
if (in_array($f_status, ['registered','attended','absent'], true)) {
    $where[] = 'r.status = :rs';
    $params[':rs'] = $f_status;
}

$org_stmt = $pdo->prepare(
    "SELECT a.id, a.title, a.start_datetime, a.end_datetime,
            t.name AS type_name, t.color AS type_color,
            r.status AS reg_status
     FROM activity_registrations r
     JOIN activities a ON a.id = r.activity_id
     LEFT JOIN activity_types t ON t.id = a.activity_type_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY a.start_datetime DESC"
);
$org_stmt->execute($params);
$org_acts = $org_stmt->fetchAll();

$total_reg      = count($org_acts);
$total_attended = count(array_filter($org_acts, fn($r) => $r['reg_status'] === 'attended'));
$total_absent   = count(array_filter($org_acts, fn($r) => $r['reg_status'] === 'absent'));
$total_pending  = count(array_filter($org_acts, fn($r) => $r['reg_status'] === 'registered'));

// Total hours from attended activities
$total_hours = 0.0;
foreach ($org_acts as $a) {
    if ($a['reg_status'] !== 'attended') continue;
    $s = strtotime($a['start_datetime']);
    $e = strtotime($a['end_datetime']);
    if ($s && $e && $e > $s) $total_hours += ($e - $s) / 3600;
}

// ---------------------------------------------------------------------------
// Personal activities (list — not just count)
// ---------------------------------------------------------------------------
$pers_sql = 'SELECT a.id, a.title, a.start_datetime, a.end_datetime,
                    t.name AS type_name, t.color AS type_color
             FROM activities a
             LEFT JOIN activity_types t ON t.id = a.activity_type_id
             WHERE a.scope = "personal" AND a.created_by = :u'
            . ($f_fiscal > 0 ? ' AND a.fiscal_year_id = :fy' : '')
            . ' ORDER BY a.start_datetime DESC';
$pers_stmt = $pdo->prepare($pers_sql);
$pers_params = [':u' => $uid];
if ($f_fiscal > 0) $pers_params[':fy'] = $f_fiscal;
$pers_stmt->execute($pers_params);
$pers_acts = $pers_stmt->fetchAll();
$personal_count = count($pers_acts);

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function emprpt_fmt(string $dt): string {
    $ts = strtotime($dt);
    $m  = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543);
}

$reg_label = ['registered'=>'ยืนยันเข้าร่วม','attended'=>'เข้าร่วมแล้ว','absent'=>'ไม่เข้าร่วม'];
$reg_badge = ['registered'=>'secondary','attended'=>'success','absent'=>'danger'];

// Filter summary for PDF header
$filter_parts = [];
if ($f_fiscal > 0) {
    foreach ($years as $y) {
        if ((int)$y['id'] === $f_fiscal) { $filter_parts[] = 'ปีงบประมาณ: ' . $y['name']; break; }
    }
}
if (in_array($f_status, ['registered','attended','absent'], true)) {
    $filter_parts[] = 'สถานะ: ' . ($reg_label[$f_status] ?? $f_status);
}
$filter_summary = empty($filter_parts) ? 'ทั้งหมด' : implode(' · ', $filter_parts);

$gen_dt = emprpt_fmt(date('Y-m-d H:i:s')) . ' เวลา ' . date('H:i') . ' น.';

$me_name = $_SESSION['display_name'] ?? '';

$page_title  = 'รายงานของฉัน';
$page_active = 'my_reports';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h1 class="page-title mb-0">
        <i class="bi bi-graph-up-arrow text-primary me-2"></i>รายงานของฉัน
    </h1>
    <div class="d-flex align-items-center gap-3">
        <span class="text-muted small d-print-none">
            <i class="bi bi-clock-history me-1"></i>
            ชั่วโมงเข้าร่วมรวม: <strong class="text-dark"><?= number_format($total_hours, 1) ?></strong> ชม.
        </span>
        <button type="button" class="btn btn-outline-primary d-print-none" onclick="window.print()">
            <i class="bi bi-file-earmark-pdf me-1"></i>ส่งออก PDF
        </button>
    </div>
</div>

<!-- Print-only header -->
<div class="print-only print-header">
    <div class="org-name">สำนักวิทยบริการและเทคโนโลยีสารสนเทศ (ARIT)</div>
    <div class="report-title">รายงานของฉัน — <?= htmlspecialchars($me_name, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="meta">
        ตัวกรอง: <?= htmlspecialchars($filter_summary, ENT_QUOTES, 'UTF-8') ?>
        <br>ชั่วโมงเข้าร่วมรวม: <?= number_format($total_hours, 1) ?> ชม.
        · วันที่ออกรายงาน: <?= htmlspecialchars($gen_dt, ENT_QUOTES, 'UTF-8') ?>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="card p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-4">
            <label class="form-label small mb-1">ปีงบประมาณ</label>
            <select name="fiscal" class="form-select">
                <option value="0">ทุกปี</option>
                <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y['id'] ?>" <?= $f_fiscal===(int)$y['id']?'selected':'' ?>>
                    <?= htmlspecialchars($y['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label small mb-1">สถานะ</label>
            <select name="status" class="form-select">
                <option value="">ทั้งหมด</option>
                <option value="registered" <?= $f_status==='registered'?'selected':'' ?>>ยืนยันเข้าร่วม</option>
                <option value="attended"   <?= $f_status==='attended'  ?'selected':'' ?>>เข้าร่วมแล้ว</option>
                <option value="absent"     <?= $f_status==='absent'    ?'selected':'' ?>>ไม่เข้าร่วม</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <button type="submit" class="btn btn-primary w-100 mt-3">
                <i class="bi bi-funnel me-1"></i>กรอง
            </button>
        </div>
        <?php if ($f_fiscal || $f_status): ?>
        <div class="col-6 col-md-1">
            <a href="<?= $app_url_safe ?>/employee/my_reports.php"
               class="btn btn-outline-secondary w-100 mt-3" title="ล้าง">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>
        <?php endif; ?>
    </div>
</form>

<!-- Summary cards -->
<div class="row g-3 mb-4 summary-print-grid">
    <div class="col-6 col-md-3">
        <a href="<?= $app_url_safe ?>/employee/my_activities.php" class="text-decoration-none text-reset">
            <div class="card card-hover text-center p-3 h-100">
                <div class="text-primary mb-1"><i class="bi bi-calendar-check fs-3"></i></div>
                <div class="fs-3 fw-bold text-primary"><?= $total_reg ?></div>
                <div class="small text-muted">กิจกรรมที่เข้าร่วม</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 h-100">
            <div class="text-success mb-1"><i class="bi bi-check-circle fs-3"></i></div>
            <div class="fs-3 fw-bold text-success"><?= $total_attended ?></div>
            <div class="small text-muted">เข้าร่วมแล้ว</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 h-100">
            <div class="text-danger mb-1"><i class="bi bi-x-circle fs-3"></i></div>
            <div class="fs-3 fw-bold text-danger"><?= $total_absent ?></div>
            <div class="small text-muted">ไม่เข้าร่วม</div>
        </div>
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
</div>

<!-- Chart + breakdown -->
<?php if ($total_reg > 0): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-pie-chart me-1"></i>สรุปสถานะการเข้าร่วม
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-5">
                <div style="max-width: 260px; margin: 0 auto;">
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
                        <strong><?= $total_pending ?></strong>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Org activity table -->
<div class="card mb-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-building me-1"></i>กิจกรรมองค์กรของฉัน (<?= count($org_acts) ?>)
    </div>
    <?php if (empty($org_acts)): ?>
    <div class="p-5 text-center text-muted">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
        ไม่พบกิจกรรมตามเงื่อนไข
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>กิจกรรม</th>
                    <th>วันที่</th>
                    <th class="text-center">สถานะ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($org_acts as $a):
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#5F5E5A';
                    $rs    = (string)$a['reg_status'];
                ?>
                <tr>
                    <td data-label="กิจกรรม">
                        <a href="<?= $app_url_safe ?>/employee/activity_view.php?id=<?= (int)$a['id'] ?>"
                           class="fw-medium text-decoration-none">
                            <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <?php if (!empty($a['type_name'])): ?>
                        <span class="badge ms-1"
                              style="background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
                            <?= htmlspecialchars($a['type_name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td data-label="วันที่" class="small text-muted text-nowrap">
                        <?= htmlspecialchars(emprpt_fmt($a['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td data-label="สถานะ" class="text-center">
                        <span class="badge bg-<?= $reg_badge[$rs] ?? 'secondary' ?>">
                            <?= $reg_label[$rs] ?? $rs ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Personal activity table -->
<div class="card mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-lines-fill me-1"></i>กิจกรรมส่วนตัวของฉัน (<?= $personal_count ?>)</span>
        <a href="<?= $app_url_safe ?>/employee/personal_activities.php"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>จัดการ
        </a>
    </div>
    <?php if (empty($pers_acts)): ?>
    <div class="p-5 text-center text-muted">
        <i class="bi bi-journal-plus fs-1 d-block mb-2"></i>
        ยังไม่มีกิจกรรมส่วนตัวในช่วงนี้
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>กิจกรรม</th>
                    <th>วันที่</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pers_acts as $a):
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#5F5E5A';
                ?>
                <tr>
                    <td data-label="กิจกรรม">
                        <span class="fw-medium"><?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($a['type_name'])): ?>
                        <span class="badge ms-1"
                              style="background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
                            <?= htmlspecialchars($a['type_name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td data-label="วันที่" class="small text-muted text-nowrap">
                        <?= htmlspecialchars(emprpt_fmt($a['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($total_reg > 0): ?>
<script src="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/assets/vendor/chartjs/chart.umd.min.js"></script>
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
                data: [<?= $total_attended ?>, <?= $total_absent ?>, <?= $total_pending ?>],
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
                        label: (c) => ' ' + c.label + ': ' + c.parsed + ' กิจกรรม'
                    }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
