<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fiscal_year.php';
require_role('director');

$pdo = db();

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$f_fiscal = (int)($_GET['fiscal'] ?? 0);
$f_type   = (int)($_GET['type']   ?? 0);
$f_time   = $_GET['time'] ?? '';

$years_stmt = $pdo->prepare('SELECT id, name FROM fiscal_years ORDER BY start_year DESC');
$years_stmt->execute();
$years = $years_stmt->fetchAll();

$types_stmt = $pdo->prepare('SELECT id, name, color FROM activity_types WHERE is_active = 1 ORDER BY id');
$types_stmt->execute();
$types = $types_stmt->fetchAll();

// Active fiscal year default (date-based auto-switch)
if ($f_fiscal === 0 && !isset($_GET['fiscal'])) {
    $f_fiscal = active_fiscal_year_id() ?? 0;
}

// ---------------------------------------------------------------------------
// Build WHERE for activities
// ---------------------------------------------------------------------------
$act_where  = ['a.scope = "organization"'];
$act_params = [];

if ($f_fiscal > 0) { $act_where[] = 'a.fiscal_year_id = :fy'; $act_params[':fy'] = $f_fiscal; }
if ($f_type   > 0) { $act_where[] = 'a.activity_type_id = :t'; $act_params[':t'] = $f_type; }
if ($f_time === 'upcoming')  $act_where[] = 'a.start_datetime > NOW()';
if ($f_time === 'ongoing')   $act_where[] = 'a.start_datetime <= NOW() AND a.end_datetime >= NOW()';
if ($f_time === 'completed') $act_where[] = 'a.end_datetime < NOW()';

$act_sql = '
    SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location,
           t.name AS type_name, t.color AS type_color,
           COUNT(r.id)                     AS reg_total,
           SUM(r.status = "attended")      AS attended,
           SUM(r.status = "absent")        AS absent,
           SUM(r.status = "registered")    AS pending
    FROM activities a
    LEFT JOIN activity_types t         ON t.id = a.activity_type_id
    LEFT JOIN activity_registrations r ON r.activity_id = a.id
    WHERE ' . implode(' AND ', $act_where) . '
    GROUP BY a.id
    ORDER BY a.start_datetime DESC
';
$act_stmt = $pdo->prepare($act_sql);
$act_stmt->execute($act_params);
$activities = $act_stmt->fetchAll();

// Summary totals
$total_activities  = count($activities);
$total_reg         = array_sum(array_column($activities, 'reg_total'));
$total_attended    = array_sum(array_column($activities, 'attended'));
$attend_rate = $total_reg > 0
    ? round(($total_attended / $total_reg) * 100, 1)
    : 0;

// ---------------------------------------------------------------------------
// Department participation breakdown
// ---------------------------------------------------------------------------
$dept_where  = ['a.scope = "organization"'];
$dept_params = [];
if ($f_fiscal > 0) { $dept_where[] = 'a.fiscal_year_id = :fy2'; $dept_params[':fy2'] = $f_fiscal; }
if ($f_type   > 0) { $dept_where[] = 'a.activity_type_id = :t2'; $dept_params[':t2'] = $f_type; }

$dept_sql = '
    SELECT d.name AS dept_name,
           COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN r.id END)        AS reg_count,
           SUM(CASE WHEN a.id IS NOT NULL AND r.status = "attended" THEN 1 ELSE 0 END) AS attended,
           SUM(CASE WHEN a.id IS NOT NULL AND r.status = "absent"   THEN 1 ELSE 0 END) AS absent,
           COUNT(DISTINCT u.id)        AS member_count
    FROM departments d
    LEFT JOIN users u ON u.department_id = d.id AND u.is_active = 1 AND u.role = "employee"
    LEFT JOIN activity_registrations r ON r.user_id = u.id
    LEFT JOIN activities a ON a.id = r.activity_id
        AND ' . implode(' AND ', $dept_where) . '
    GROUP BY d.id
    ORDER BY d.id
';
$dept_stmt = $pdo->prepare($dept_sql);
$dept_stmt->execute($dept_params);
$dept_rows = $dept_stmt->fetchAll();

// ---------------------------------------------------------------------------
// Monthly breakdown
// ---------------------------------------------------------------------------
$monthly_where  = ['a.scope = "organization"'];
$monthly_params = [];
if ($f_fiscal > 0) { $monthly_where[] = 'a.fiscal_year_id = :fy3'; $monthly_params[':fy3'] = $f_fiscal; }

$monthly_sql = '
    SELECT DATE_FORMAT(a.start_datetime, "%Y-%m") AS ym,
           COUNT(DISTINCT a.id)                   AS act_count,
           COUNT(r.id)                            AS reg_count,
           SUM(r.status = "attended")             AS attended
    FROM activities a
    LEFT JOIN activity_registrations r ON r.activity_id = a.id
    WHERE ' . implode(' AND ', $monthly_where) . '
    GROUP BY ym
    ORDER BY ym
';
$monthly_stmt = $pdo->prepare($monthly_sql);
$monthly_stmt->execute($monthly_params);
$monthly_rows = $monthly_stmt->fetchAll();

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function dir_rpt_fmt(string $dt): string {
    $ts = strtotime($dt);
    $m  = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543);
}

function dir_rpt_ym_th(string $ym): string {
    [$y, $m] = explode('-', $ym);
    $months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return $months[(int)$m] . ' ' . ((int)$y + 543);
}

// Filter summary for PDF header
$filter_parts = [];
if ($f_fiscal > 0) {
    foreach ($years as $y) {
        if ((int)$y['id'] === $f_fiscal) { $filter_parts[] = 'ปีงบประมาณ: ' . $y['name']; break; }
    }
}
if ($f_type > 0) {
    foreach ($types as $t) {
        if ((int)$t['id'] === $f_type) { $filter_parts[] = 'ประเภท: ' . $t['name']; break; }
    }
}
$time_label = ['upcoming'=>'กำลังจะมาถึง','ongoing'=>'กำลังดำเนินอยู่','completed'=>'เสร็จสิ้น'][$f_time] ?? '';
if ($time_label !== '') $filter_parts[] = 'สถานะ: ' . $time_label;
$filter_summary = empty($filter_parts) ? 'ทั้งหมด' : implode(' · ', $filter_parts);

$gen_dt = dir_rpt_fmt(date('Y-m-d H:i:s')) . ' เวลา ' . date('H:i') . ' น.';

$page_title  = 'รายงานสรุป';
$page_active = 'reports';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div class="d-flex align-items-center gap-2">
        <h1 class="page-title mb-0">รายงานสรุปกิจกรรม</h1>
        <span class="badge bg-secondary d-print-none">อ่านอย่างเดียว</span>
    </div>
    <button type="button" class="btn btn-outline-primary d-print-none" onclick="window.print()">
        <i class="bi bi-file-earmark-pdf me-1"></i>ส่งออก PDF
    </button>
</div>

<!-- Print-only header -->
<div class="print-only print-header">
    <div class="org-name">สำนักวิทยบริการและเทคโนโลยีสารสนเทศ (ARIT)</div>
    <div class="report-title">รายงานสรุปกิจกรรม</div>
    <div class="meta">
        ตัวกรอง: <?= htmlspecialchars($filter_summary, ENT_QUOTES, 'UTF-8') ?>
        <br>วันที่ออกรายงาน: <?= htmlspecialchars($gen_dt, ENT_QUOTES, 'UTF-8') ?>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="card p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
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
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">ประเภท</label>
            <select name="type" class="form-select">
                <option value="0">ทุกประเภท</option>
                <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $f_type===(int)$t['id']?'selected':'' ?>>
                    <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">สถานะ</label>
            <select name="time" class="form-select">
                <option value="">ทั้งหมด</option>
                <option value="upcoming"  <?= $f_time==='upcoming' ?'selected':'' ?>>กำลังจะมาถึง</option>
                <option value="ongoing"   <?= $f_time==='ongoing'  ?'selected':'' ?>>กำลังดำเนินอยู่</option>
                <option value="completed" <?= $f_time==='completed'?'selected':'' ?>>เสร็จสิ้น</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <button type="submit" class="btn btn-primary w-100 mt-3">
                <i class="bi bi-funnel me-1"></i>กรอง
            </button>
        </div>
        <?php if ($f_fiscal || $f_type || $f_time): ?>
        <div class="col-6 col-md-1">
            <a href="<?= $app_url_safe ?>/director/reports.php"
               class="btn btn-outline-secondary w-100 mt-3" title="ล้างตัวกรอง">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>
        <?php endif; ?>
    </div>
</form>

<!-- Summary cards -->
<div class="row g-3 mb-4 summary-print-grid">
    <div class="col-6 col-lg-3">
        <div class="card text-center p-3">
            <div class="fs-2 fw-bold text-primary"><?= number_format($total_activities) ?></div>
            <div class="small text-muted">กิจกรรมทั้งหมด</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card text-center p-3">
            <div class="fs-2 fw-bold text-secondary"><?= number_format($total_reg) ?></div>
            <div class="small text-muted">รายการเข้าร่วม</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card text-center p-3">
            <div class="fs-2 fw-bold text-success"><?= number_format($total_attended) ?></div>
            <div class="small text-muted">เข้าร่วมแล้ว</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card text-center p-3">
            <div class="fs-2 fw-bold <?= $attend_rate >= 80 ? 'text-success' : ($attend_rate >= 60 ? 'text-warning' : 'text-danger') ?>">
                <?= $attend_rate ?>%
            </div>
            <div class="small text-muted">อัตราเข้าร่วม</div>
        </div>
    </div>
</div>

<!-- Monthly breakdown -->
<?php if (!empty($monthly_rows)): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-bar-chart me-1"></i>สรุปรายเดือน
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>เดือน</th>
                    <th class="text-center">กิจกรรม</th>
                    <th class="text-center">ผู้เข้าร่วม</th>
                    <th class="text-center">เข้าร่วมแล้ว</th>
                    <th class="text-center">อัตรา</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthly_rows as $mo):
                    $mo_rate = (int)$mo['reg_count'] > 0
                        ? round(($mo['attended'] / $mo['reg_count']) * 100)
                        : 0;
                    $mo_color = $mo_rate >= 80 ? 'success' : ($mo_rate >= 60 ? 'warning' : 'danger');
                ?>
                <tr>
                    <td><?= htmlspecialchars(dir_rpt_ym_th((string)$mo['ym']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="text-center"><?= (int)$mo['act_count'] ?></td>
                    <td class="text-center"><?= (int)$mo['reg_count'] ?></td>
                    <td class="text-center"><?= (int)$mo['attended'] ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $mo_color ?>"><?= $mo_rate ?>%</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Department breakdown -->
<div class="card mb-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-building me-1"></i>สรุปรายแผนก
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>แผนก</th>
                    <th class="text-center">สมาชิก</th>
                    <th class="text-center">ผู้เข้าร่วม</th>
                    <th class="text-center">เข้าร่วมแล้ว</th>
                    <th class="text-center">ไม่เข้าร่วม</th>
                    <th class="text-center">อัตรา</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dept_rows as $dr):
                    $d_rate = (int)$dr['reg_count'] > 0
                        ? round(((int)$dr['attended'] / (int)$dr['reg_count']) * 100)
                        : 0;
                    $d_color = $d_rate >= 80 ? 'success' : ($d_rate >= 60 ? 'warning' : 'secondary');
                ?>
                <tr>
                    <td class="fw-medium">
                        <?= htmlspecialchars($dr['dept_name'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="text-center"><?= (int)$dr['member_count'] ?></td>
                    <td class="text-center"><?= (int)$dr['reg_count'] ?></td>
                    <td class="text-center text-success"><?= (int)$dr['attended'] ?></td>
                    <td class="text-center text-danger"><?= (int)$dr['absent'] ?></td>
                    <td class="text-center">
                        <?php if ($dr['reg_count'] > 0): ?>
                        <span class="badge bg-<?= $d_color ?>"><?= $d_rate ?>%</span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Activity detail table -->
<div class="card">
    <div class="card-header fw-semibold">
        <i class="bi bi-table me-1"></i>รายละเอียดกิจกรรม (<?= $total_activities ?>)
    </div>
    <?php if (empty($activities)): ?>
    <div class="p-5 text-center text-muted">ไม่พบกิจกรรมตามเงื่อนไขที่เลือก</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>กิจกรรม</th>
                    <th>วันที่</th>
                    <th class="text-center">ผู้เข้าร่วม</th>
                    <th class="text-center">เข้าร่วมแล้ว</th>
                    <th class="text-center">ไม่เข้าร่วม</th>
                    <th class="text-center">อัตรา</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activities as $a):
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#5F5E5A';
                    $rate  = (int)$a['reg_total'] > 0
                        ? round(((int)$a['attended'] / (int)$a['reg_total']) * 100)
                        : null;
                    $rc = $rate === null ? 'secondary' : ($rate >= 80 ? 'success' : ($rate >= 60 ? 'warning' : 'danger'));
                ?>
                <tr>
                    <td data-label="กิจกรรม">
                        <a href="<?= $app_url_safe ?>/director/activity_view.php?id=<?= (int)$a['id'] ?>"
                           class="fw-medium text-decoration-none">
                            <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <div class="small">
                            <span class="badge"
                                  style="background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
                                <?= htmlspecialchars($a['type_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if (!empty($a['location'])): ?>
                            <span class="text-muted">
                                · <?= htmlspecialchars($a['location'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="วันที่" class="small text-muted text-nowrap">
                        <?= htmlspecialchars(dir_rpt_fmt($a['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td data-label="ผู้เข้าร่วม" class="text-center"><?= (int)$a['reg_total'] ?></td>
                    <td data-label="เข้าร่วมแล้ว" class="text-center text-success fw-medium"><?= (int)$a['attended'] ?></td>
                    <td data-label="ไม่เข้าร่วม" class="text-center text-danger"><?= (int)$a['absent'] ?></td>
                    <td data-label="อัตรา" class="text-center">
                        <?php if ($rate !== null): ?>
                        <span class="badge bg-<?= $rc ?>"><?= $rate ?>%</span>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
