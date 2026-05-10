<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$pdo = db();

// ปีงบประมาณที่ active
$fy_stmt = $pdo->prepare('SELECT id, name FROM fiscal_years WHERE is_active = 1 LIMIT 1');
$fy_stmt->execute();
$fy = $fy_stmt->fetch();
$fy_id   = $fy ? (int)$fy['id'] : 0;
$fy_name = $fy ? $fy['name'] : '—';

$fy_clause = $fy_id > 0 ? 'AND a.fiscal_year_id = :fy' : '';
$fy_params = $fy_id > 0 ? [':fy' => $fy_id] : [];

// กิจกรรมองค์กรปีนี้
$ta = $pdo->prepare(
    'SELECT COUNT(*) FROM activities a WHERE a.scope = "organization" ' . $fy_clause
);
$ta->execute($fy_params);
$total_activities = (int)$ta->fetchColumn();

// เข้าร่วม
$reg = $pdo->prepare(
    'SELECT COUNT(*) AS reg_total,
            SUM(r.status = "attended") AS attended
     FROM activity_registrations r
     JOIN activities a ON a.id = r.activity_id
     WHERE a.scope = "organization" ' . $fy_clause
);
$reg->execute($fy_params);
$reg_row = $reg->fetch();
$total_reg      = (int)($reg_row['reg_total'] ?? 0);
$total_attended = (int)($reg_row['attended'] ?? 0);
$attend_rate    = $total_reg > 0 ? round(($total_attended / $total_reg) * 100, 1) : 0;

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

function admin_dash_fmt(string $dt): string {
    $ts = strtotime($dt);
    $m  = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543) . ' ' . date('H:i', $ts);
}

$page_title  = 'หน้าหลัก';
$page_active = 'dashboard';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>

<style>
/* โทนเข็มพาสเทล — สีหลังพื้นไม่สว่างจ้า */
.dash-stat-card {
    background: #E8EEF6;
    border: 1px solid #93A3BB;
    border-left: 5px solid var(--stat-color, #185FA5);
    box-shadow: 0 2px 6px rgba(15, 23, 42, .12);
}
.dash-stat-card .icon-wrap {
    width: 48px; height: 48px;
    border-radius: 10px;
    background: var(--stat-color, #185FA5);
    color: #FFFFFF;
    display: inline-flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 10px rgba(15, 23, 42, .15);
}
.dash-stat-card .icon-wrap i { font-size: 22px; line-height: 1; }
.dash-stat-num {
    color: #0F172A;
    font-weight: 700;
    font-size: 1.85rem;
    line-height: 1.1;
}
.dash-stat-label {
    color: #334155;
    font-size: .9rem;
    font-weight: 500;
}
.dash-stat-sub {
    color: #475569;
    font-size: .8rem;
}
.dash-mini-card {
    background: #E8EEF6;
    border: 1px solid #93A3BB;
    color: #0F172A;
    box-shadow: 0 2px 6px rgba(15, 23, 42, .10);
}
.dash-mini-card .mini-label {
    color: #334155;
    font-weight: 500;
    font-size: .85rem;
}
.dash-mini-card .mini-num {
    color: #0F172A;
    font-weight: 700;
}
.dash-card-header {
    background: #1E293B;
    color: #F1F5F9;
    font-weight: 600;
    border-bottom: 1px solid #0F172A;
}
.dash-card-header .btn-outline-primary {
    color: #F1F5F9;
    border-color: #94A3B8;
    background: transparent;
}
.dash-card-header .btn-outline-primary:hover {
    color: #0F172A;
    background: #F1F5F9;
    border-color: #F1F5F9;
}
.dash-list .list-group-item {
    color: #0F172A;
    background: #E8EEF6;
    border-color: rgba(148, 163, 184, 0.45);
}
.dash-list .list-group-item + .list-group-item {
    border-top-color: rgba(148, 163, 184, 0.45);
}
.dash-list .list-group-item .text-muted {
    color: #334155 !important;
}
.dash-list .item-title {
    color: #0F172A;
    font-weight: 600;
}
.dash-list .item-title:hover {
    color: #185FA5;
    text-decoration: underline;
}
.dash-quick-link {
    background: #1E293B;
    color: #F1F5F9 !important;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: .8rem;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
}
.dash-quick-link:hover {
    background: #0F172A;
    color: #FFFFFF !important;
}
.dash-quick-link.accent { background: #185FA5; }
.dash-quick-link.accent:hover { background: #0F4475; }
.dash-quick-link.muted { background: #475569; }
.dash-quick-link.muted:hover { background: #334155; }
</style>

<div class="page-header">
    <div>
        <h1 class="page-title">หน้าหลัก Admin</h1>
        <div class="text-muted small">
            สวัสดี <strong class="text-dark"><?= htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
            · ปีงบประมาณ <span class="fw-semibold text-dark"><?= htmlspecialchars($fy_name, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
    <a href="<?= $app_url_safe ?>/admin/activity_form.php?action=create" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>เพิ่มกิจกรรม
    </a>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <a href="<?= $app_url_safe ?>/admin/manage_activities.php" class="text-decoration-none">
            <div class="card dash-stat-card p-3 h-100" style="--stat-color:#185FA5;">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div>
                        <div class="dash-stat-num"><?= number_format($total_activities) ?></div>
                        <div class="dash-stat-label">กิจกรรมองค์กร</div>
                    </div>
                    <span class="icon-wrap"><i class="bi bi-calendar-event"></i></span>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat-card p-3 h-100" style="--stat-color:#0F6E56;">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div>
                    <div class="dash-stat-num"><?= number_format($total_reg) ?></div>
                    <div class="dash-stat-label">รายการเข้าร่วม</div>
                </div>
                <span class="icon-wrap"><i class="bi bi-people"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-stat-card p-3 h-100" style="--stat-color:#B45309;">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div>
                    <div class="dash-stat-num"><?= $attend_rate ?>%</div>
                    <div class="dash-stat-label">อัตราเข้าร่วม</div>
                    <div class="dash-stat-sub"><?= $total_attended ?>/<?= $total_reg ?></div>
                </div>
                <span class="icon-wrap"><i class="bi bi-check2-circle"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <a href="<?= $app_url_safe ?>/admin/manage_certificates.php" class="text-decoration-none">
            <div class="card dash-stat-card p-3 h-100" style="--stat-color:#993C1D;">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <div>
                        <div class="dash-stat-num"><?= number_format($total_certs) ?></div>
                        <div class="dash-stat-label">เกียรติบัตรที่ออก</div>
                    </div>
                    <span class="icon-wrap"><i class="bi bi-award"></i></span>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- Secondary stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <a href="<?= $app_url_safe ?>/admin/manage_users.php" class="text-decoration-none">
            <div class="card dash-mini-card p-3 h-100">
                <div class="mini-label mb-1">บัญชีทั้งหมด (active)</div>
                <div class="d-flex align-items-baseline gap-2 flex-wrap">
                    <span class="fs-4 mini-num"><?= (int)($user_stats['total_users'] ?? 0) ?></span>
                    <span class="small dash-stat-sub">
                        <?= (int)($user_stats['employees'] ?? 0) ?> พนักงาน ·
                        <?= (int)($user_stats['admins'] ?? 0) ?> admin ·
                        <?= (int)($user_stats['directors'] ?? 0) ?> director
                    </span>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-4">
        <a href="<?= $app_url_safe ?>/admin/notification_settings.php" class="text-decoration-none">
            <div class="card dash-mini-card p-3 h-100">
                <div class="mini-label mb-1">คิวอีเมล</div>
                <div class="d-flex align-items-baseline gap-2 flex-wrap">
                    <span class="fs-4 mini-num"><?= (int)($email_stats['pending'] ?? 0) ?></span>
                    <span class="small dash-stat-sub">รอส่ง</span>
                    <?php if ((int)($email_stats['failed'] ?? 0) > 0): ?>
                    <span class="small fw-semibold" style="color:#B91C1C;">
                        · <?= (int)$email_stats['failed'] ?> ล้มเหลว
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </a>
    </div>
    <div class="col-12 col-md-4">
        <div class="card dash-mini-card p-3 h-100">
            <div class="mini-label mb-2">ทางลัด</div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= $app_url_safe ?>/admin/calendar.php" class="dash-quick-link accent">
                    <i class="bi bi-calendar3"></i>ปฏิทิน
                </a>
                <a href="<?= $app_url_safe ?>/admin/reports.php" class="dash-quick-link">
                    <i class="bi bi-graph-up"></i>รายงาน
                </a>
                <a href="<?= $app_url_safe ?>/admin/manage_documents.php" class="dash-quick-link muted">
                    <i class="bi bi-folder"></i>เอกสาร
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Upcoming activities -->
    <div class="col-12 col-lg-7">
        <div class="card h-100 dash-list">
            <div class="card-header dash-card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-calendar-event me-1"></i>กิจกรรมที่กำลังจะมาถึง
                </span>
                <a href="<?= $app_url_safe ?>/admin/manage_activities.php"
                   class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a>
            </div>
            <?php if (empty($upcoming)): ?>
            <div class="p-4 text-center text-muted">ยังไม่มีกิจกรรมที่กำลังจะมาถึง</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($upcoming as $a):
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#185FA5';
                    $is_ongoing = strtotime($a['start_datetime']) <= time();
                ?>
                <li class="list-group-item px-3 py-3">
                    <div class="d-flex align-items-start gap-2">
                        <div class="flex-shrink-0 mt-1"
                             style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;"></div>
                        <div class="flex-grow-1 min-w-0">
                            <a href="<?= $app_url_safe ?>/admin/activity_view.php?id=<?= (int)$a['id'] ?>"
                               class="item-title text-decoration-none text-truncate d-block">
                                <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <div class="small text-muted mt-1">
                                <i class="bi bi-clock me-1"></i>
                                <?= htmlspecialchars(admin_dash_fmt($a['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
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
                            <?php if ((int)$a['is_open_registration'] === 1): ?>
                            <div class="small mt-1">
                                <span class="badge bg-info">เปิดให้เข้าร่วม</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent activities -->
    <div class="col-12 col-lg-5">
        <div class="card h-100 dash-list">
            <div class="card-header dash-card-header">
                <i class="bi bi-clock-history me-1"></i>กิจกรรมที่เพิ่งสร้าง
            </div>
            <?php if (empty($recent)): ?>
            <div class="p-4 text-center text-muted">ยังไม่มีกิจกรรม</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($recent as $a):
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#185FA5';
                ?>
                <li class="list-group-item px-3 py-3">
                    <div class="d-flex align-items-start gap-2">
                        <span class="badge"
                              style="background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
                            <?= htmlspecialchars($a['type_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <div class="flex-grow-1 min-w-0">
                            <a href="<?= $app_url_safe ?>/admin/activity_view.php?id=<?= (int)$a['id'] ?>"
                               class="item-title text-decoration-none text-truncate d-block">
                                <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <div class="small text-muted mt-1">
                                โดย <?= htmlspecialchars($a['creator_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
