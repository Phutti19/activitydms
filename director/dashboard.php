<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fiscal_year.php';
require_role('director');

$pdo = db();

// Active fiscal year (date-based auto-switch)
$fy = active_fiscal_year();
$fy_id   = $fy ? (int)$fy['id'] : 0;
$fy_name = $fy ? $fy['name'] : '—';

// Stats for active fiscal year — bound parameter, not concatenated
$fy_clause = $fy_id > 0 ? 'AND a.fiscal_year_id = :fy' : '';
$fy_params = $fy_id > 0 ? [':fy' => $fy_id] : [];

// Total org activities this year
$ta_stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM activities a WHERE a.scope = "organization" ' . $fy_clause
);
$ta_stmt->execute($fy_params);
$total_activities = (int)$ta_stmt->fetchColumn();

// Total registrations / attended / absent
$reg_stmt = $pdo->prepare(
    'SELECT COUNT(*) AS reg_total,
            SUM(r.status = "attended") AS attended,
            SUM(r.status = "absent")   AS absent
     FROM activity_registrations r
     JOIN activities a ON a.id = r.activity_id
     WHERE a.scope = "organization" ' . $fy_clause
);
$reg_stmt->execute($fy_params);
$reg_row = $reg_stmt->fetch();
$total_reg      = (int)($reg_row['reg_total'] ?? 0);
$total_attended = (int)($reg_row['attended']  ?? 0);
$total_absent   = (int)($reg_row['absent']    ?? 0);

// อัตราเข้าร่วม = attended / (attended + absent)
$rate_base   = $total_attended + $total_absent;
$attend_rate = $rate_base > 0 ? (int) round(($total_attended / $rate_base) * 100) : 0;

// Total certs issued this year
$tc_stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM certificates c
     JOIN activities a ON a.id = c.activity_id
     WHERE a.scope = "organization" ' . $fy_clause
);
$tc_stmt->execute($fy_params);
$total_certs = (int)$tc_stmt->fetchColumn();

// กิจกรรมวันนี้
$td_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM activities
     WHERE scope = 'organization'
       AND DATE(start_datetime) = CURDATE()"
);
$td_stmt->execute();
$today_count = (int)$td_stmt->fetchColumn();

// Upcoming activities (next 5)
$upcoming_stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location,
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

// Department overview — เพิ่ม absent
$dept_clause = $fy_id > 0 ? 'AND a.fiscal_year_id = :fy_d' : '';
$dept_params = $fy_id > 0 ? [':fy_d' => $fy_id] : [];
$dept_stmt   = $pdo->prepare(
    'SELECT d.name AS dept_name,
            COUNT(DISTINCT u.id) AS member_count,
            COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN r.id END) AS reg_count,
            SUM(CASE WHEN a.id IS NOT NULL AND r.status = "attended" THEN 1 ELSE 0 END) AS attended,
            SUM(CASE WHEN a.id IS NOT NULL AND r.status = "absent"   THEN 1 ELSE 0 END) AS absent
     FROM departments d
     LEFT JOIN users u ON u.department_id = d.id AND u.is_active = 1 AND u.role = "employee"
     LEFT JOIN activity_registrations r ON r.user_id = u.id
     LEFT JOIN activities a ON a.id = r.activity_id AND a.scope = "organization" ' . $dept_clause . '
     GROUP BY d.id
     ORDER BY d.id'
);
$dept_stmt->execute($dept_params);
$dept_rows = $dept_stmt->fetchAll();

// Pre-compute rate per dept for chart + table
$dept_chart = ['labels' => [], 'attended' => [], 'absent' => [], 'rate' => []];
foreach ($dept_rows as $i => &$dr) {
    $base = (int)$dr['attended'] + (int)$dr['absent'];
    $dr['rate'] = $base > 0 ? (int) round(((int)$dr['attended'] / $base) * 100) : 0;
    $dept_chart['labels'][]   = $dr['dept_name'];
    $dept_chart['attended'][] = (int)$dr['attended'];
    $dept_chart['absent'][]   = (int)$dr['absent'];
    $dept_chart['rate'][]     = $dr['rate'];
}
unset($dr);

function dir_dash_fmt(string $dt): string {
    $ts = strtotime($dt);
    $m  = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543) . ' ' . date('H:i', $ts);
}

$page_title  = 'หน้าหลัก';
$page_active = 'dashboard';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');

$thai_months = ['','ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$thai_days   = ['อา','จ','อ','พ','พฤ','ศ','ส'];
$today_label = $thai_days[(int)date('w')] . ' ' . date('j') . ' ' . $thai_months[(int)date('n')] . ' ' . ((int)date('Y') + 543);
?>

<!-- Hero greeting -->
<div class="card mb-4 border-0 text-white"
     style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);">
    <div class="card-body p-4">
        <div class="row align-items-center g-3">
            <div class="col-12 col-md-8">
                <div class="small opacity-75 mb-1">
                    <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($today_label, ENT_QUOTES, 'UTF-8') ?>
                    · ปีงบประมาณ <?= htmlspecialchars($fy_name, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <h1 class="h3 fw-bold mb-2">
                    สวัสดี, <?= htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
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
                <a href="<?= $app_url_safe ?>/director/reports.php"
                   class="btn btn-light fw-semibold">
                    <i class="bi bi-graph-up me-1"></i>ดูรายงาน
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <a href="<?= $app_url_safe ?>/director/activities.php" class="text-decoration-none text-reset">
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
            <div class="fs-3 fw-bold <?= $attend_rate >= 80 ? 'text-success' : ($attend_rate >= 60 ? 'text-warning' : 'text-danger') ?>">
                <?= $attend_rate ?>%
            </div>
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
        <div class="card text-center p-3 h-100">
            <div class="text-warning mb-1"><i class="bi bi-award fs-3"></i></div>
            <div class="fs-3 fw-bold text-warning"><?= number_format($total_certs) ?></div>
            <div class="small text-muted">เกียรติบัตรที่ออก</div>
        </div>
    </div>
</div>

<!-- Department comparison chart -->
<?php if (!empty($dept_rows) && array_sum($dept_chart['attended']) + array_sum($dept_chart['absent']) > 0): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-bar-chart-line me-1"></i>เปรียบเทียบการเข้าร่วมรายแผนก
    </div>
    <div class="card-body">
        <canvas id="deptChart" style="max-height: 300px;"></canvas>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Upcoming activities -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">
                    <i class="bi bi-calendar-event me-1"></i>กิจกรรมที่กำลังจะมาถึง
                </span>
                <a href="<?= $app_url_safe ?>/director/activities.php?time=upcoming"
                   class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
            </div>
            <?php if (empty($upcoming)): ?>
            <div class="p-4 text-center text-muted small">
                <i class="bi bi-calendar-x fs-3 d-block mb-2"></i>
                ไม่มีกิจกรรมที่กำลังจะมาถึง
            </div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($upcoming as $a):
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#0EA5E9';
                    $is_ongoing = strtotime($a['start_datetime']) <= time() && strtotime($a['end_datetime']) >= time();
                ?>
                <li class="list-group-item d-flex align-items-center gap-3 py-3">
                    <div style="width:4px;height:40px;background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;border-radius:2px;flex-shrink:0;"></div>
                    <div class="flex-grow-1 overflow-hidden">
                        <a href="<?= $app_url_safe ?>/director/activity_view.php?id=<?= (int)$a['id'] ?>"
                           class="fw-medium text-decoration-none text-truncate d-block">
                            <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <div class="small text-muted">
                            <i class="bi bi-clock me-1"></i><?= htmlspecialchars(dir_dash_fmt($a['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!empty($a['location'])): ?>
                            · <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($a['location'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-end flex-shrink-0">
                        <?php if ($is_ongoing): ?>
                        <span class="badge" style="background:#FEF3C7;color:#92400E;">กำลังดำเนินอยู่</span>
                        <?php else: ?>
                        <span class="badge bg-secondary"><?= (int)$a['reg_count'] ?> คน</span>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Department overview -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">
                    <i class="bi bi-building me-1"></i>ภาพรวมรายแผนก
                </span>
                <a href="<?= $app_url_safe ?>/director/reports.php"
                   class="btn btn-sm btn-outline-primary">รายงาน</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>แผนก</th>
                            <th class="text-center">สมาชิก</th>
                            <th class="text-center">มาแล้ว</th>
                            <th class="text-center">ขาด</th>
                            <th class="text-center">อัตรา</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dept_rows as $dr):
                            $d_color = $dr['rate'] >= 80 ? 'success' : ($dr['rate'] >= 60 ? 'warning' : 'secondary');
                            $base    = (int)$dr['attended'] + (int)$dr['absent'];
                        ?>
                        <tr>
                            <td class="fw-medium small">
                                <?= htmlspecialchars($dr['dept_name'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center small"><?= (int)$dr['member_count'] ?></td>
                            <td class="text-center small text-success"><?= (int)$dr['attended'] ?></td>
                            <td class="text-center small text-danger"><?= (int)$dr['absent'] ?></td>
                            <td class="text-center">
                                <?php if ($base > 0): ?>
                                <span class="badge bg-<?= $d_color ?>"><?= $dr['rate'] ?>%</span>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($dept_rows) && array_sum($dept_chart['attended']) + array_sum($dept_chart['absent']) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const ctx = document.getElementById('deptChart');
    if (!ctx || typeof Chart === 'undefined') return;
    Chart.defaults.font.family = "'Kanit', system-ui, sans-serif";
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($dept_chart['labels'], JSON_UNESCAPED_UNICODE) ?>,
            datasets: [
                {
                    label: 'เข้าร่วมแล้ว',
                    data: <?= json_encode($dept_chart['attended']) ?>,
                    backgroundColor: '#198754'
                },
                {
                    label: 'ไม่เข้าร่วม',
                    data: <?= json_encode($dept_chart['absent']) ?>,
                    backgroundColor: '#dc3545'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true },
                y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
            },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { mode: 'index', intersect: false }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
