<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('employee');

$uid = (int) current_user_id();
$pdo = db();

$q      = trim((string)($_GET['q'] ?? ''));
$f_status = $_GET['reg_status'] ?? '';

$where  = ['r.user_id = :uid', 'a.scope = "organization"'];
$params = [':uid' => $uid];

if ($q !== '') {
    $where[] = '(a.title LIKE :q OR a.location LIKE :q2)';
    $params[':q']  = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if (in_array($f_status, ['registered', 'attended', 'absent'], true)) {
    $where[] = 'r.status = :rs';
    $params[':rs'] = $f_status;
}

$stmt = $pdo->prepare(
    "SELECT a.id, a.title, a.location, a.start_datetime, a.end_datetime, a.external_url,
            t.name AS type_name, t.color AS type_color,
            r.status AS reg_status, r.registered_at AS reg_at
     FROM activity_registrations r
     JOIN activities a ON a.id = r.activity_id
     LEFT JOIN activity_types t ON t.id = a.activity_type_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY a.start_datetime DESC"
);
$stmt->execute($params);
$activities = $stmt->fetchAll();

function emp_time_status(array $a): array {
    $now = time();
    $s = strtotime((string)$a['start_datetime']);
    $e = strtotime((string)$a['end_datetime']);
    if ($e < $now)  return ['label'=>'เสร็จสิ้น',        'bg'=>'#D1FAE5','fg'=>'#065F46'];
    if ($s <= $now) return ['label'=>'กำลังดำเนินอยู่',  'bg'=>'#FEF3C7','fg'=>'#92400E'];
    return                 ['label'=>'กำลังจะมาถึง',     'bg'=>'#DBEAFE','fg'=>'#1E40AF'];
}

function emp_fmt_date(string $dt): string {
    $ts = strtotime($dt);
    $m  = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543) . ', ' . date('H:i', $ts);
}

$reg_label = ['registered'=>'ยืนยันเข้าร่วม','attended'=>'เข้าร่วมแล้ว','absent'=>'ไม่เข้าร่วม'];
$reg_badge = ['registered'=>'secondary','attended'=>'success','absent'=>'danger'];

$page_title  = 'กิจกรรมของฉัน';
$page_active = 'my_activities';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">กิจกรรมของฉัน</h1>
        <p class="text-muted small mb-0">กิจกรรมองค์กรที่คุณได้รับมอบหมาย</p>
    </div>
</div>

<form method="GET" class="card p-3 mb-3">
    <div class="row g-2">
        <div class="col-12 col-md-5">
            <input type="text" name="q" class="form-control" placeholder="ค้นหาชื่อ / สถานที่"
                   value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-8 col-md-4">
            <select name="reg_status" class="form-select">
                <option value="">ทุกสถานะ</option>
                <option value="registered" <?= $f_status==='registered'?'selected':'' ?>>ยืนยันเข้าร่วม</option>
                <option value="attended"   <?= $f_status==='attended'  ?'selected':'' ?>>เข้าร่วมแล้ว</option>
                <option value="absent"     <?= $f_status==='absent'    ?'selected':'' ?>>ไม่เข้าร่วม</option>
            </select>
        </div>
        <div class="col-4 col-md-2">
            <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i></button>
        </div>
        <?php if ($q !== '' || $f_status !== ''): ?>
        <div class="col-12 col-md-1">
            <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/employee/my_activities.php"
               class="btn btn-outline-secondary w-100">ล้าง</a>
        </div>
        <?php endif; ?>
    </div>
</form>

<?php if (empty($activities)): ?>
<div class="card p-5 text-center text-muted">
    <i class="bi bi-person-check" style="font-size:48px;opacity:0.3;"></i>
    <p class="mt-2 mb-0">ยังไม่มีกิจกรรมที่ได้รับมอบหมาย</p>
</div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($activities as $a):
    $ts   = emp_time_status($a);
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#5F5E5A';
    $rs   = (string)$a['reg_status'];
?>
<div class="col-12 col-md-6 col-lg-4">
    <div class="card h-100" style="border-left: 4px solid <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
        <div class="card-body d-flex flex-column gap-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h6 class="fw-semibold mb-0 lh-sm">
                    <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/employee/activity_view.php?id=<?= (int)$a['id'] ?>"
                       class="text-decoration-none text-body stretched-link">
                        <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </h6>
                <span class="badge"
                      style="background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;white-space:nowrap;flex-shrink:0;">
                    <?= htmlspecialchars($a['type_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <div class="small text-muted">
                <i class="bi bi-clock me-1"></i>
                <?= htmlspecialchars(emp_fmt_date($a['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
                — <?= htmlspecialchars(emp_fmt_date($a['end_datetime']), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php if (!empty($a['location'])): ?>
            <div class="small text-muted">
                <i class="bi bi-geo-alt me-1"></i>
                <?= htmlspecialchars($a['location'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>
            <div class="mt-auto d-flex align-items-center justify-content-between pt-1">
                <div class="d-flex gap-1 flex-wrap">
                    <span class="badge"
                          style="background:<?= $ts['bg'] ?>;color:<?= $ts['fg'] ?>;font-weight:500;">
                        <?= $ts['label'] ?>
                    </span>
                    <span class="badge bg-<?= $reg_badge[$rs] ?? 'secondary' ?>">
                        <?= $reg_label[$rs] ?? htmlspecialchars($rs, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <?php if (!empty($a['external_url'])): ?>
                <a href="<?= htmlspecialchars($a['external_url'], ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                    <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
