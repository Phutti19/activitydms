<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('director');

$pdo = db();

// Active fiscal year
$fy_stmt = $pdo->prepare('SELECT id, name FROM fiscal_years WHERE is_active = 1 LIMIT 1');
$fy_stmt->execute();
$fy = $fy_stmt->fetch();
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

// Total registrations / attended
$reg_stmt = $pdo->prepare(
    'SELECT COUNT(*) AS reg_total,
            SUM(r.status = "attended") AS attended
     FROM activity_registrations r
     JOIN activities a ON a.id = r.activity_id
     WHERE a.scope = "organization" ' . $fy_clause
);
$reg_stmt->execute($fy_params);
$reg_row = $reg_stmt->fetch();
$total_reg      = (int)($reg_row['reg_total'] ?? 0);
$total_attended = (int)($reg_row['attended'] ?? 0);
$attend_rate    = $total_reg > 0 ? round(($total_attended / $total_reg) * 100, 1) : 0;

// Total certs issued this year
$tc_stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM certificates c
     JOIN activities a ON a.id = c.activity_id
     WHERE a.scope = "organization" ' . $fy_clause
);
$tc_stmt->execute($fy_params);
$total_certs = (int)$tc_stmt->fetchColumn();

// Total active employees
$te_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM users WHERE role = 'employee' AND is_active = 1"
);
$te_stmt->execute();
$total_employees = (int)$te_stmt->fetchColumn();

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

// Department overview
$dept_clause = $fy_id > 0 ? 'AND a.fiscal_year_id = :fy_d' : '';
$dept_params = $fy_id > 0 ? [':fy_d' => $fy_id] : [];
$dept_stmt   = $pdo->prepare(
    'SELECT d.name AS dept_name,
            COUNT(DISTINCT u.id) AS member_count,
            COUNT(DISTINCT r.id) AS reg_count,
            SUM(r.status = "attended") AS attended
     FROM departments d
     LEFT JOIN users u ON u.department_id = d.id AND u.is_active = 1 AND u.role = "employee"
     LEFT JOIN activity_registrations r ON r.user_id = u.id
     LEFT JOIN activities a ON a.id = r.activity_id AND a.scope = "organization" ' . $dept_clause . '
     GROUP BY d.id
     ORDER BY d.id'
);
$dept_stmt->execute($dept_params);
$dept_rows = $dept_stmt->fetchAll();

function dir_dash_fmt(string $dt): string {
    $ts = strtotime($dt);
    $m  = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543) . ' ' . date('H:i', $ts);
}

$page_title  = 'หน้าหลัก';
$page_active = 'dashboard';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>

<div class="page-header">
    <div>
        <h1 class="page-title">หน้าหลัก</h1>
        <div class="text-muted small">
            ปีงบประมาณที่ใช้งานอยู่:
            <span class="fw-semibold"><?= htmlspecialchars($fy_name, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <a href="<?= $app_url_safe ?>/director/activities.php" class="text-decoration-none">
            <div class="card text-center p-3 h-100">
                <div class="fs-2 fw-bold text-primary"><?= number_format($total_activities) ?></div>
                <div class="small text-muted">กิจกรรมองค์กร</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card text-center p-3 h-100">
            <div class="fs-2 fw-bold text-secondary"><?= number_format($total_reg) ?></div>
            <div class="small text-muted">รายการลงทะเบียน</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card text-center p-3 h-100">
            <div class="fs-2 fw-bold <?= $attend_rate >= 80 ? 'text-success' : ($attend_rate >= 60 ? 'text-warning' : 'text-danger') ?>">
                <?= $attend_rate ?>%
            </div>
            <div class="small text-muted">อัตราเข้าร่วม</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card text-center p-3 h-100">
            <div class="fs-2 fw-bold text-warning"><?= number_format($total_certs) ?></div>
            <div class="small text-muted">เกียรติบัตรที่ออก</div>
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
                <a href="<?= $app_url_safe ?>/director/activities.php?time=upcoming"
                   class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
            </div>
            <?php if (empty($upcoming)): ?>
            <div class="p-4 text-center text-muted">ไม่มีกิจกรรมที่กำลังจะมาถึง</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($upcoming as $a):
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#0EA5E9';
                    $is_ongoing = strtotime($a['start_datetime']) <= time();
                ?>
                <li class="list-group-item px-3 py-3">
                    <div class="d-flex align-items-start gap-2">
                        <div class="flex-shrink-0 mt-1"
                             style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;"></div>
                        <div class="flex-grow-1 min-w-0">
                            <a href="<?= $app_url_safe ?>/director/activity_view.php?id=<?= (int)$a['id'] ?>"
                               class="fw-medium text-decoration-none text-truncate d-block">
                                <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <div class="small text-muted mt-1">
                                <i class="bi bi-clock me-1"></i>
                                <?= htmlspecialchars(dir_dash_fmt($a['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!empty($a['location'])): ?>
                                · <i class="bi bi-geo-alt"></i>
                                <?= htmlspecialchars($a['location'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex-shrink-0 text-end">
                            <?php if ($is_ongoing): ?>
                            <span class="badge bg-success">กำลังดำเนินอยู่</span>
                            <?php else: ?>
                            <span class="badge bg-secondary"><?= (int)$a['reg_count'] ?> คน</span>
                            <?php endif; ?>
                        </div>
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
                            <th class="text-center">เข้าร่วม</th>
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
                            <td class="fw-medium small">
                                <?= htmlspecialchars($dr['dept_name'], ENT_QUOTES, 'UTF-8') ?>
                            </td>
                            <td class="text-center small"><?= (int)$dr['member_count'] ?></td>
                            <td class="text-center small text-success"><?= (int)$dr['attended'] ?></td>
                            <td class="text-center">
                                <?php if ($dr['reg_count'] > 0): ?>
                                <span class="badge bg-<?= $d_color ?>"><?= $d_rate ?>%</span>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
