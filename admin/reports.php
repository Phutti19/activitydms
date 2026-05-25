<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fiscal_year.php';
require_role('admin');

$pdo = db();

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$f_fiscal = (int)($_GET['fiscal']  ?? 0);
$f_type   = (int)($_GET['type']    ?? 0);
$f_dept   = (int)($_GET['dept']    ?? 0);
$f_time   = $_GET['time'] ?? '';
$f_user   = (int)($_GET['user']    ?? 0);

// Month-range filter (เดือน ถึง เดือน — รายไตรมาส มติประชุม 2026-05-14)
// ค่า 1-12 (เดือน ค.ศ.). 0/empty = ไม่กรอง
$f_smonth = (int)($_GET['smonth'] ?? 0);
$f_emonth = (int)($_GET['emonth'] ?? 0);
if ($f_smonth < 1 || $f_smonth > 12) $f_smonth = 0;
if ($f_emonth < 1 || $f_emonth > 12) $f_emonth = 0;

$years_stmt = $pdo->prepare('SELECT id, name FROM fiscal_years ORDER BY start_year DESC');
$years_stmt->execute();
$years = $years_stmt->fetchAll();

$types_stmt = $pdo->prepare('SELECT id, name, color FROM activity_types WHERE is_active = 1 ORDER BY id');
$types_stmt->execute();
$types = $types_stmt->fetchAll();

$depts_stmt = $pdo->prepare('SELECT id, name FROM departments ORDER BY id');
$depts_stmt->execute();
$depts = $depts_stmt->fetchAll();

// Active fiscal year default
if ($f_fiscal === 0 && !isset($_GET['fiscal'])) {
    $f_fiscal = active_fiscal_year_id() ?? 0;
}

// ดึงข้อมูลปีงบประมาณที่เลือก (ใช้กับ month range)
$fy_row = null;
if ($f_fiscal > 0) {
    $fy_stmt = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = :id LIMIT 1');
    $fy_stmt->execute([':id' => $f_fiscal]);
    $fy_row = $fy_stmt->fetch() ?: null;
}

// แปลง (month) → ปี ค.ศ. ที่ถูกต้องในปีงบประมาณ (ต.ค.-ธ.ค.=start_year, ม.ค.-ก.ย.=end_year)
$resolve_fy_year = function (?array $fy, int $month): ?int {
    if (!$fy || $month < 1 || $month > 12) return null;
    return ($month >= (int)$fy['start_month']) ? (int)$fy['start_year'] : (int)$fy['end_year'];
};

$range_start = null;  // YYYY-MM-DD 00:00:00
$range_end   = null;  // YYYY-MM-DD 23:59:59
if ($fy_row && ($f_smonth > 0 || $f_emonth > 0)) {
    $sm = $f_smonth > 0 ? $f_smonth : (int)$fy_row['start_month'];
    $em = $f_emonth > 0 ? $f_emonth : (int)$fy_row['end_month'];
    $sy = $resolve_fy_year($fy_row, $sm);
    $ey = $resolve_fy_year($fy_row, $em);
    if ($sy && $ey) {
        $range_start = sprintf('%04d-%02d-01 00:00:00', $sy, $sm);
        $last_day    = (int) date('t', strtotime(sprintf('%04d-%02d-01', $ey, $em)));
        $range_end   = sprintf('%04d-%02d-%02d 23:59:59', $ey, $em, $last_day);
    }
}

// ---------------------------------------------------------------------------
// Build WHERE for activities
// ---------------------------------------------------------------------------
$act_where  = ['a.scope = "organization"'];
$act_params = [];

if ($f_fiscal > 0) { $act_where[] = 'a.fiscal_year_id = :fy'; $act_params[':fy'] = $f_fiscal; }
if ($f_type   > 0) { $act_where[] = 'a.activity_type_id = :t'; $act_params[':t'] = $f_type; }
if ($range_start !== null) {
    $act_where[] = 'a.start_datetime >= :rs AND a.start_datetime <= :re';
    $act_params[':rs'] = $range_start;
    $act_params[':re'] = $range_end;
}
if ($f_time === 'upcoming')  $act_where[] = 'a.start_datetime > NOW()';
if ($f_time === 'ongoing')   $act_where[] = 'a.start_datetime <= NOW() AND a.end_datetime >= NOW()';
if ($f_time === 'completed') $act_where[] = 'a.end_datetime < NOW()';

$act_sql = '
    SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location,
           t.name AS type_name, t.color AS type_color,
           COUNT(r.id)                                            AS reg_total,
           SUM(r.status = "attended")                            AS attended,
           SUM(r.status = "absent")                              AS absent,
           SUM(r.status = "registered")                          AS pending
    FROM activities a
    LEFT JOIN activity_types t        ON t.id = a.activity_type_id
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
$total_absent      = array_sum(array_column($activities, 'absent'));
$attend_rate = $total_reg > 0
    ? round(($total_attended / $total_reg) * 100, 1)
    : 0;

// ---------------------------------------------------------------------------
// Department participation breakdown (not filtered by dept — global overview)
// ---------------------------------------------------------------------------
$dept_where  = ['a.scope = "organization"'];
$dept_params = [];
if ($f_fiscal > 0) { $dept_where[] = 'a.fiscal_year_id = :fy2'; $dept_params[':fy2'] = $f_fiscal; }
if ($f_type   > 0) { $dept_where[] = 'a.activity_type_id = :t2'; $dept_params[':t2'] = $f_type; }
if ($range_start !== null) {
    $dept_where[] = 'a.start_datetime >= :rs2 AND a.start_datetime <= :re2';
    $dept_params[':rs2'] = $range_start;
    $dept_params[':re2'] = $range_end;
}

$dept_sql = '
    SELECT d.name AS dept_name,
           COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN r.id END)               AS reg_count,
           SUM(CASE WHEN a.id IS NOT NULL AND r.status = "attended" THEN 1 ELSE 0 END) AS attended,
           SUM(CASE WHEN a.id IS NOT NULL AND r.status = "absent"   THEN 1 ELSE 0 END) AS absent,
           COUNT(DISTINCT u.id)               AS member_count
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
// Monthly breakdown (for current filter)
// ---------------------------------------------------------------------------
$monthly_where  = ['a.scope = "organization"'];
$monthly_params = [];
if ($f_fiscal > 0) { $monthly_where[] = 'a.fiscal_year_id = :fy3'; $monthly_params[':fy3'] = $f_fiscal; }
if ($range_start !== null) {
    $monthly_where[] = 'a.start_datetime >= :rs3 AND a.start_datetime <= :re3';
    $monthly_params[':rs3'] = $range_start;
    $monthly_params[':re3'] = $range_end;
}

$monthly_sql = '
    SELECT DATE_FORMAT(a.start_datetime, "%Y-%m") AS ym,
           COUNT(DISTINCT a.id)                    AS act_count,
           COUNT(r.id)                             AS reg_count,
           SUM(r.status = "attended")              AS attended
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
// User dropdown list (สำหรับเลือกดูประวัติเข้าร่วมรายบุคคล)
// ---------------------------------------------------------------------------
$users_stmt = $pdo->prepare(
    "SELECT u.id,
            TRIM(CONCAT_WS(' ', u.prefix_name, u.first_name, u.last_name)) AS fullname,
            d.name AS dept_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.is_active = 1 AND u.role IN ('employee','director','admin')
     ORDER BY u.role = 'employee' DESC, u.first_name, u.last_name"
);
$users_stmt->execute();
$users_list = $users_stmt->fetchAll();

// ---------------------------------------------------------------------------
// Per-user attendance — โหลดเฉพาะเมื่อมีการเลือก user
// (เคารพ scope='organization' ตามกฎ §2.1 ใน CLAUDE.md — ห้ามดึง personal ของคนอื่น)
// ---------------------------------------------------------------------------
$user_info = null;
$user_rows = [];
$user_stat = ['total' => 0, 'attended' => 0, 'absent' => 0, 'registered' => 0, 'cert' => 0, 'rate' => 0];

if ($f_user > 0) {
    $uinfo_stmt = $pdo->prepare(
        "SELECT u.id, u.email,
                TRIM(CONCAT_WS(' ', u.prefix_name, u.first_name, u.last_name)) AS fullname,
                u.position_name, d.name AS dept_name, u.role
         FROM users u LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.id = :uid LIMIT 1"
    );
    $uinfo_stmt->execute([':uid' => $f_user]);
    $user_info = $uinfo_stmt->fetch() ?: null;

    if ($user_info) {
        $u_where  = ['a.scope = "organization"', 'r.user_id = :uid'];
        $u_params = [':uid' => $f_user];
        if ($f_fiscal > 0) { $u_where[] = 'a.fiscal_year_id = :fy_u'; $u_params[':fy_u'] = $f_fiscal; }
        if ($f_type   > 0) { $u_where[] = 'a.activity_type_id = :t_u'; $u_params[':t_u'] = $f_type; }
        if ($range_start !== null) {
            $u_where[] = 'a.start_datetime >= :rs_u AND a.start_datetime <= :re_u';
            $u_params[':rs_u'] = $range_start;
            $u_params[':re_u'] = $range_end;
        }

        $u_sql = '
            SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location, a.format,
                   r.status, r.registered_at, r.checked_at,
                   t.name AS type_name, t.color AS type_color,
                   c.id AS cert_id
            FROM activity_registrations r
            JOIN activities a ON a.id = r.activity_id
            LEFT JOIN activity_types t ON t.id = a.activity_type_id
            LEFT JOIN certificates c ON c.activity_id = a.id AND c.user_id = r.user_id
            WHERE ' . implode(' AND ', $u_where) . '
            ORDER BY a.start_datetime DESC
        ';
        $u_stmt = $pdo->prepare($u_sql);
        $u_stmt->execute($u_params);
        $user_rows = $u_stmt->fetchAll();

        foreach ($user_rows as $ur) {
            $user_stat['total']++;
            if      ($ur['status'] === 'attended')   $user_stat['attended']++;
            elseif  ($ur['status'] === 'absent')     $user_stat['absent']++;
            else                                     $user_stat['registered']++;
            if (!empty($ur['cert_id'])) $user_stat['cert']++;
        }
        $user_stat['rate'] = $user_stat['total'] > 0
            ? round(($user_stat['attended'] / $user_stat['total']) * 100, 1)
            : 0;
    }
}

// ค่าเริ่มต้นของ months_th สำหรับ dropdown
$months_th = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',
              7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

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
if ($range_start !== null) {
    $filter_parts[] = sprintf('ช่วงเดือน: %s–%s',
        $months_th[$f_smonth > 0 ? $f_smonth : (int)$fy_row['start_month']],
        $months_th[$f_emonth > 0 ? $f_emonth : (int)$fy_row['end_month']]);
}
if ($user_info) {
    $filter_parts[] = 'บุคคล: ' . $user_info['fullname'];
}
$filter_summary = empty($filter_parts) ? 'ทั้งหมด' : implode(' · ', $filter_parts);

$gen_dt = report_gen_datetime();

$page_title  = 'รายงานสรุป';
$page_active = 'reports';
require __DIR__ . '/../includes/header.php';
$app_url_safe = h(APP_URL);
?>

<div class="page-header d-flex flex-wrap align-items-center justify-content-between gap-2">
    <h1 class="page-title mb-0">รายงานสรุปกิจกรรม</h1>
    <button type="button" class="btn btn-outline-primary d-print-none" onclick="window.print()">
        <i class="bi bi-file-earmark-pdf me-1"></i>ส่งออก PDF
    </button>
</div>

<!-- Print-only header -->
<div class="print-only print-header">
    <div class="org-name"><?= h(ORG_FULL_NAME) ?></div>
    <div class="report-title">รายงานสรุปกิจกรรม</div>
    <div class="meta">
        ตัวกรอง: <?= h($filter_summary) ?>
        <br>วันที่ออกรายงาน: <?= h($gen_dt) ?>
    </div>
</div>

<!-- Filters -->
<?php $has_any_filter = ($f_fiscal || $f_type || $f_time || $f_smonth || $f_emonth || $f_user); ?>
<form method="GET" class="card p-3 p-md-4 mb-4 d-print-none">

    <!-- Row 1: ตัวกรองพื้นฐาน -->
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <label class="form-label small fw-medium mb-1">
                <i class="bi bi-calendar-range me-1 text-muted"></i>ปีงบประมาณ
            </label>
            <select name="fiscal" class="form-select">
                <option value="0">ทุกปี</option>
                <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y['id'] ?>" <?= $f_fiscal===(int)$y['id']?'selected':'' ?>>
                    <?= h($y['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label small fw-medium mb-1">
                <i class="bi bi-tag me-1 text-muted"></i>ประเภท
            </label>
            <select name="type" class="form-select">
                <option value="0">ทุกประเภท</option>
                <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $f_type===(int)$t['id']?'selected':'' ?>>
                    <?= h($t['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4">
            <label class="form-label small fw-medium mb-1">
                <i class="bi bi-flag me-1 text-muted"></i>สถานะ
            </label>
            <select name="time" class="form-select">
                <option value="">ทั้งหมด</option>
                <option value="upcoming"  <?= $f_time==='upcoming' ?'selected':'' ?>>กำลังจะมาถึง</option>
                <option value="ongoing"   <?= $f_time==='ongoing'  ?'selected':'' ?>>กำลังดำเนินอยู่</option>
                <option value="completed" <?= $f_time==='completed'?'selected':'' ?>>เสร็จสิ้น</option>
            </select>
        </div>
    </div>

    <!-- Row 2: ช่วงเดือน + Quarter shortcuts -->
    <div class="row g-3 mb-3">
        <div class="col-12">
            <label class="form-label small fw-medium mb-1">
                <i class="bi bi-calendar3 me-1 text-muted"></i>ช่วงเดือน (ในปีงบประมาณ)
            </label>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width:280px;max-width:500px;">
                    <select name="smonth" class="form-select">
                        <option value="0">— เริ่ม —</option>
                        <?php foreach ($months_th as $mn => $ml): ?>
                        <option value="<?= $mn ?>" <?= $f_smonth===$mn?'selected':'' ?>><?= $ml ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="text-muted small flex-shrink-0">ถึง</span>
                    <select name="emonth" class="form-select">
                        <option value="0">— สิ้นสุด —</option>
                        <?php foreach ($months_th as $mn => $ml): ?>
                        <option value="<?= $mn ?>" <?= $f_emonth===$mn?'selected':'' ?>><?= $ml ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="btn-group btn-group-sm" role="group" aria-label="ไตรมาส" data-quarter-shortcuts>
                    <button type="button" class="btn btn-outline-secondary" data-q="1" title="ต.ค.–ธ.ค.">Q1</button>
                    <button type="button" class="btn btn-outline-secondary" data-q="2" title="ม.ค.–มี.ค.">Q2</button>
                    <button type="button" class="btn btn-outline-secondary" data-q="3" title="เม.ย.–มิ.ย.">Q3</button>
                    <button type="button" class="btn btn-outline-secondary" data-q="4" title="ก.ค.–ก.ย.">Q4</button>
                    <button type="button" class="btn btn-outline-secondary" data-q="0">ทั้งปี</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 3: เลือกบุคคล -->
    <div class="row g-3 mb-3">
        <div class="col-12">
            <label class="form-label small fw-medium mb-1">
                <i class="bi bi-person-badge me-1 text-muted"></i>เลือกบุคคล (ดูประวัติเข้าร่วม)
            </label>
            <select name="user" class="form-select">
                <option value="0">— ทั้งหมด (รวมทุกคน) —</option>
                <?php foreach ($users_list as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $f_user===(int)$u['id']?'selected':'' ?>>
                    <?= h($u['fullname']) ?>
                    <?php if (!empty($u['dept_name'])): ?> · <?= h($u['dept_name']) ?><?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Action buttons -->
    <div class="d-flex flex-wrap justify-content-end gap-2 pt-2 border-top">
        <?php if ($has_any_filter): ?>
        <a href="<?= $app_url_safe ?>/admin/reports.php" class="btn btn-outline-secondary">
            <i class="bi bi-x-lg me-1"></i>ล้างตัวกรอง
        </a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-funnel me-1"></i>กรอง
        </button>
    </div>
</form>

<script>
// Quarter shortcuts — set smonth/emonth ตามไตรมาสของปีงบประมาณไทย
(function () {
    const Q = { 1: [10,12], 2: [1,3], 3: [4,6], 4: [7,9], 0: [0,0] };
    const form = document.querySelector('form[data-autofilter]');
    document.querySelectorAll('[data-quarter-shortcuts] [data-q]').forEach(btn => {
        btn.addEventListener('click', () => {
            const [s, e] = Q[btn.dataset.q] || [0,0];
            const sSel = document.querySelector('select[name="smonth"]');
            const eSel = document.querySelector('select[name="emonth"]');
            if (sSel) sSel.value = String(s);
            if (eSel) eSel.value = String(e);
            // กดไตรมาสแล้วกรองทันที (การเซ็ต .value เองไม่ทริกเกอร์ auto-submit)
            if (form) {
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            }
        });
    });
})();
</script>

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
                        ? round(($mo['attended']/$mo['reg_count'])*100)
                        : 0;
                    $mo_color = $mo_rate >= 80 ? 'success' : ($mo_rate >= 60 ? 'warning' : 'danger');
                ?>
                <tr>
                    <td><?= h(th_month_year((string)$mo['ym'])) ?></td>
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
                        ? round(((int)$dr['attended']/(int)$dr['reg_count'])*100)
                        : 0;
                    $d_color = $d_rate >= 80 ? 'success' : ($d_rate >= 60 ? 'warning' : 'secondary');
                ?>
                <tr>
                    <td class="fw-medium">
                        <?= h($dr['dept_name']) ?>
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

<!-- Per-user attendance (มติประชุม 2026-05-14 — เลือกดูประวัติเข้าร่วมรายบุคคล) -->
<?php if ($user_info): ?>
<div class="card mb-4">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <span class="fw-semibold">
                <i class="bi bi-person-badge me-1"></i>
                ประวัติเข้าร่วม: <?= h($user_info['fullname']) ?>
            </span>
            <?php if (!empty($user_info['dept_name'])): ?>
                <span class="text-muted small">· <?= h($user_info['dept_name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-3 small">
            <span>ทั้งหมด: <strong><?= (int)$user_stat['total'] ?></strong></span>
            <span class="text-success">เข้าร่วม: <strong><?= (int)$user_stat['attended'] ?></strong></span>
            <span class="text-danger">ไม่เข้าร่วม: <strong><?= (int)$user_stat['absent'] ?></strong></span>
            <span class="text-warning">เกียรติบัตร: <strong><?= (int)$user_stat['cert'] ?></strong></span>
            <span>อัตรา:
                <span class="badge bg-<?= $user_stat['rate'] >= 80 ? 'success' : ($user_stat['rate'] >= 60 ? 'warning' : 'danger') ?>">
                    <?= $user_stat['rate'] ?>%
                </span>
            </span>
        </div>
    </div>
    <?php if (empty($user_rows)): ?>
    <div class="p-5 text-center text-muted">ไม่มีประวัติเข้าร่วมในเงื่อนไขที่เลือก</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>กิจกรรม</th>
                    <th>วันที่</th>
                    <th class="text-center">สถานะ</th>
                    <th class="text-center">เกียรติบัตร</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($user_rows as $ur):
                    $u_color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$ur['type_color']) ? $ur['type_color'] : '#5F5E5A';
                    $st_map  = [
                        'attended'   => ['success', 'เข้าร่วม'],
                        'absent'     => ['danger',  'ไม่เข้าร่วม'],
                        'registered' => ['secondary','ลงทะเบียน'],
                    ];
                    $st = $st_map[$ur['status']] ?? ['secondary', $ur['status']];
                ?>
                <tr>
                    <td data-label="กิจกรรม">
                        <a href="<?= $app_url_safe ?>/admin/activity_view.php?id=<?= (int)$ur['id'] ?>"
                           class="fw-medium text-decoration-none">
                            <?= h($ur['title']) ?>
                        </a>
                        <div class="small">
                            <span class="badge" style="background:<?= h($u_color) ?>;">
                                <?= h($ur['type_name'] ?? '—') ?>
                            </span>
                            <?php if (($ur['format'] ?? 'onsite') === 'online'): ?>
                                <span class="badge-pill" style="background:#E0F2FE;color:#0369A1;">
                                    <i class="bi bi-camera-video"></i> ออนไลน์
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($ur['location'])): ?>
                                <span class="text-muted">· <?= h($ur['location']) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="วันที่" class="small text-nowrap">
                        <?= h(th_date($ur['start_datetime'])) ?>
                    </td>
                    <td data-label="สถานะ" class="text-center">
                        <span class="badge bg-<?= $st[0] ?>"><?= h($st[1]) ?></span>
                    </td>
                    <td data-label="เกียรติบัตร" class="text-center">
                        <?php if (!empty($ur['cert_id'])): ?>
                            <i class="bi bi-award-fill text-warning" title="ได้รับเกียรติบัตร"></i>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Activity detail table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-table me-1"></i>รายละเอียดกิจกรรม (<?= $total_activities ?>)</span>
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
                        ? round(((int)$a['attended']/(int)$a['reg_total'])*100)
                        : null;
                    $rc = $rate === null ? 'secondary' : ($rate >= 80 ? 'success' : ($rate >= 60 ? 'warning' : 'danger'));
                ?>
                <tr>
                    <td data-label="กิจกรรม">
                        <a href="<?= $app_url_safe ?>/admin/activity_view.php?id=<?= (int)$a['id'] ?>"
                           class="fw-medium text-decoration-none">
                            <?= h($a['title']) ?>
                        </a>
                        <div class="small">
                            <span class="badge"
                                  style="background:<?= h($color) ?>;">
                                <?= h($a['type_name'] ?? '—') ?>
                            </span>
                            <?php if (!empty($a['location'])): ?>
                            <span class="text-muted">
                                · <?= h($a['location']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="วันที่" class="small text-muted text-nowrap">
                        <?= h(th_date($a['start_datetime'])) ?>
                    </td>
                    <td data-label="ผู้เข้าร่วม" class="text-center">
                        <?= (int)$a['reg_total'] ?>
                    </td>
                    <td data-label="เข้าร่วมแล้ว" class="text-center text-success fw-medium">
                        <?= (int)$a['attended'] ?>
                    </td>
                    <td data-label="ไม่เข้าร่วม" class="text-center text-danger">
                        <?= (int)$a['absent'] ?>
                    </td>
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
