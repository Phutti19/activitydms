<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('employee');

$uid = (int) current_user_id();
$pdo = db();

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$f_fiscal = (int)($_GET['fiscal'] ?? 0);
$f_status = $_GET['status'] ?? '';

$years = $pdo->query('SELECT id, name FROM fiscal_years ORDER BY start_year DESC')->fetchAll();

// Default to active fiscal year
if ($f_fiscal === 0 && !isset($_GET['fiscal'])) {
    $fy_active = $pdo->query('SELECT id FROM fiscal_years WHERE is_active = 1 LIMIT 1')->fetch();
    if ($fy_active) $f_fiscal = (int)$fy_active['id'];
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

// Personal activities count
$pers_stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM activities WHERE scope = "personal" AND created_by = :u'
    . ($f_fiscal > 0 ? ' AND fiscal_year_id = :fy' : '')
);
$pers_params = [':u' => $uid];
if ($f_fiscal > 0) $pers_params[':fy'] = $f_fiscal;
$pers_stmt->execute($pers_params);
$personal_count = (int)$pers_stmt->fetchColumn();

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function emprpt_fmt(string $dt): string {
    $ts = strtotime($dt);
    $m  = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543);
}

$reg_label = ['registered'=>'ลงทะเบียน','attended'=>'เข้าร่วมแล้ว','absent'=>'ขาด'];
$reg_badge = ['registered'=>'secondary','attended'=>'success','absent'=>'danger'];

$page_title  = 'รายงานของฉัน';
$page_active = 'my_reports';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>

<div class="page-header">
    <h1 class="page-title">รายงานของฉัน</h1>
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
                <option value="registered" <?= $f_status==='registered'?'selected':'' ?>>ลงทะเบียน</option>
                <option value="attended"   <?= $f_status==='attended'  ?'selected':'' ?>>เข้าร่วมแล้ว</option>
                <option value="absent"     <?= $f_status==='absent'    ?'selected':'' ?>>ขาด</option>
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
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <a href="<?= $app_url_safe ?>/employee/my_activities.php" class="text-decoration-none">
            <div class="card text-center p-3 h-100">
                <div class="fs-2 fw-bold text-primary"><?= $total_reg ?></div>
                <div class="small text-muted">กิจกรรมที่เข้าร่วม</div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 h-100">
            <div class="fs-2 fw-bold text-success"><?= $total_attended ?></div>
            <div class="small text-muted">เข้าร่วมแล้ว</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center p-3 h-100">
            <div class="fs-2 fw-bold text-danger"><?= $total_absent ?></div>
            <div class="small text-muted">ขาด</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <a href="<?= $app_url_safe ?>/employee/personal_activities.php" class="text-decoration-none">
            <div class="card text-center p-3 h-100">
                <div class="fs-2 fw-bold text-secondary"><?= $personal_count ?></div>
                <div class="small text-muted">กิจกรรมส่วนตัว</div>
            </div>
        </a>
    </div>
</div>

<!-- Activity table -->
<div class="card">
    <div class="card-header fw-semibold">
        <i class="bi bi-table me-1"></i>กิจกรรมองค์กรของฉัน (<?= count($org_acts) ?>)
    </div>
    <?php if (empty($org_acts)): ?>
    <div class="p-5 text-center text-muted">ไม่พบกิจกรรมตามเงื่อนไข</div>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
