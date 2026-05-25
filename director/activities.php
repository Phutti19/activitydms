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
$f_time   = $_GET['time']   ?? '';
$f_search = trim((string)($_GET['q'] ?? ''));

$years_stmt = $pdo->prepare('SELECT id, name FROM fiscal_years ORDER BY start_year DESC');
$years_stmt->execute();
$years = $years_stmt->fetchAll();

$types_stmt = $pdo->prepare('SELECT id, name, color FROM activity_types WHERE is_active = 1 ORDER BY id');
$types_stmt->execute();
$types = $types_stmt->fetchAll();

if ($f_fiscal === 0 && !isset($_GET['fiscal'])) {
    $f_fiscal = active_fiscal_year_id() ?? 0;
}

// ---------------------------------------------------------------------------
// Query
// ---------------------------------------------------------------------------
$where  = ['a.scope = "organization"'];
$params = [];

if ($f_fiscal > 0) { $where[] = 'a.fiscal_year_id = :fy'; $params[':fy'] = $f_fiscal; }
if ($f_type   > 0) { $where[] = 'a.activity_type_id = :t'; $params[':t'] = $f_type; }
if ($f_time === 'upcoming')  $where[] = 'a.start_datetime > NOW()';
if ($f_time === 'ongoing')   $where[] = 'a.start_datetime <= NOW() AND a.end_datetime >= NOW()';
if ($f_time === 'completed') $where[] = 'a.end_datetime < NOW()';
if ($f_search !== '') {
    $where[] = '(a.title LIKE :q OR a.location LIKE :q2)';
    $params[':q']  = '%' . $f_search . '%';
    $params[':q2'] = '%' . $f_search . '%';
}

$stmt = $pdo->prepare(
    'SELECT a.id, a.title, a.start_datetime, a.end_datetime, a.location,
            a.is_open_registration,
            t.name AS type_name, t.color AS type_color,
            COUNT(r.id)                  AS reg_total,
            SUM(r.status = "attended")   AS attended
     FROM activities a
     LEFT JOIN activity_types t         ON t.id = a.activity_type_id
     LEFT JOIN activity_registrations r ON r.activity_id = a.id
     WHERE ' . implode(' AND ', $where) . '
     GROUP BY a.id
     ORDER BY a.start_datetime DESC'
);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function dir_time_badge(string $start, string $end): string {
    $now = time();
    $s   = strtotime($start);
    $e   = strtotime($end);
    if ($now < $s) return '<span class="badge bg-primary">กำลังจะมาถึง</span>';
    if ($now <= $e) return '<span class="badge bg-success">กำลังดำเนินอยู่</span>';
    return '<span class="badge bg-secondary">เสร็จสิ้น</span>';
}

$page_title  = 'กิจกรรมทั้งหมด';
$page_active = 'activities';
require __DIR__ . '/../includes/header.php';
$app_url_safe = h(APP_URL);
?>

<div class="page-header">
    <h1 class="page-title">กิจกรรมทั้งหมด</h1>
    <span class="badge bg-secondary">อ่านอย่างเดียว</span>
</div>

<!-- Filters -->
<form method="GET" class="card p-3 mb-3" data-autofilter>
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-3">
            <label class="form-label small mb-1">ค้นหา</label>
            <input type="text" name="q" class="form-control" value="<?= h($f_search) ?>"
                   placeholder="ชื่อกิจกรรม, สถานที่">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">ปีงบประมาณ</label>
            <select name="fiscal" class="form-select">
                <option value="0">ทุกปี</option>
                <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y['id'] ?>" <?= $f_fiscal===(int)$y['id']?'selected':'' ?>>
                    <?= h($y['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">ประเภท</label>
            <select name="type" class="form-select">
                <option value="0">ทุกประเภท</option>
                <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $f_type===(int)$t['id']?'selected':'' ?>>
                    <?= h($t['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
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
        <?php if ($f_fiscal || $f_type || $f_time || $f_search !== ''): ?>
        <div class="col-6 col-md-1">
            <a href="<?= $app_url_safe ?>/director/activities.php"
               class="btn btn-outline-secondary w-100 mt-3" title="ล้าง">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>
        <?php endif; ?>
    </div>
</form>

<!-- Results -->
<div class="card">
    <div class="card-header fw-semibold">
        <i class="bi bi-calendar-event me-1"></i>กิจกรรม (<?= count($activities) ?>)
    </div>
    <?php if (empty($activities)): ?>
    <div class="p-5 text-center text-muted">ไม่พบกิจกรรมตามเงื่อนไข</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>กิจกรรม</th>
                    <th>วันที่</th>
                    <th class="text-center">ผู้เข้าร่วม</th>
                    <th class="text-center">อัตราเข้าร่วม</th>
                    <th class="text-center">สถานะ</th>
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
                            <?= h($a['title']) ?>
                        </a>
                        <div class="small mt-1">
                            <span class="badge"
                                  style="background:<?= h($color) ?>;">
                                <?= h($a['type_name'] ?? '—') ?>
                            </span>
                            <?php if (!empty($a['location'])): ?>
                            <span class="text-muted ms-1">
                                <i class="bi bi-geo-alt"></i>
                                <?= h($a['location']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="วันที่" class="small text-muted text-nowrap">
                        <?= h(th_date($a['start_datetime'])) ?>
                    </td>
                    <td data-label="ผู้เข้าร่วม" class="text-center">
                        <?= (int)$a['reg_total'] ?> คน
                    </td>
                    <td data-label="อัตราเข้าร่วม" class="text-center">
                        <?php if ($rate !== null): ?>
                        <span class="badge bg-<?= $rc ?>"><?= $rate ?>%</span>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="สถานะ" class="text-center">
                        <?= dir_time_badge($a['start_datetime'], $a['end_datetime']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
