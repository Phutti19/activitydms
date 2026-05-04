<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/upload.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo = db();

        $stmt = $pdo->prepare(
            'SELECT * FROM activities WHERE id = :id AND scope = "organization" LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $activity = $stmt->fetch();

        if (!$activity) {
            flash_set('error', 'ไม่พบกิจกรรม');
            header('Location: ' . APP_URL . '/admin/manage_activities.php');
            exit;
        }

        $photo_files = $pdo->prepare('SELECT filename FROM activity_photos WHERE activity_id = :id');
        $photo_files->execute([':id' => $id]);
        $files_to_delete = array_column($photo_files->fetchAll(), 'filename');

        $att_files = $pdo->prepare(
            'SELECT filename FROM activity_attachments
             WHERE activity_id = :id AND type = "file" AND filename IS NOT NULL'
        );
        $att_files->execute([':id' => $id]);
        $files_to_delete = array_merge($files_to_delete, array_column($att_files->fetchAll(), 'filename'));

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM activities WHERE id = :id')->execute([':id' => $id]);
            audit_log('delete_activity', 'activities', $id, $activity, null);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $unlink_failures = 0;
        foreach ($files_to_delete as $fn) {
            if (!safe_unlink_upload('activities', $fn)) $unlink_failures++;
        }
        if ($unlink_failures > 0) {
            error_log("[manage_activities] orphan files: $unlink_failures from activity #$id");
        }

        flash_set('success', 'ลบกิจกรรม "' . $activity['title'] . '" สำเร็จ');
    }

    header('Location: ' . APP_URL . '/admin/manage_activities.php');
    exit;
}

$q        = trim((string)($_GET['q'] ?? ''));
$f_type   = (int)($_GET['type'] ?? 0);
$f_fiscal = (int)($_GET['fiscal'] ?? 0);
$f_time   = $_GET['time'] ?? '';
$f_open   = $_GET['open'] ?? '';

$where  = ['a.scope = "organization"'];
$params = [];

if ($q !== '') {
    $where[] = '(a.title LIKE :q OR a.location LIKE :q2)';
    $params[':q']  = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if ($f_type > 0)   { $where[] = 'a.activity_type_id = :t'; $params[':t'] = $f_type; }
if ($f_fiscal > 0) { $where[] = 'a.fiscal_year_id = :fy'; $params[':fy'] = $f_fiscal; }
if ($f_time === 'upcoming')  $where[] = 'a.start_datetime > NOW()';
if ($f_time === 'ongoing')   $where[] = 'a.start_datetime <= NOW() AND a.end_datetime >= NOW()';
if ($f_time === 'completed') $where[] = 'a.end_datetime < NOW()';
if ($f_open === '1') $where[] = 'a.is_open_registration = 1';
if ($f_open === '0') $where[] = 'a.is_open_registration = 0';

$sql = 'SELECT a.*,
               t.name AS type_name, t.color AS type_color,
               fy.name AS fiscal_name,
               (SELECT COUNT(*) FROM activity_registrations WHERE activity_id = a.id) AS reg_count,
               (SELECT COUNT(*) FROM activity_photos WHERE activity_id = a.id) AS photo_count,
               (SELECT COUNT(*) FROM activity_attachments WHERE activity_id = a.id) AS attach_count
        FROM activities a
        LEFT JOIN activity_types t ON t.id = a.activity_type_id
        LEFT JOIN fiscal_years fy  ON fy.id = a.fiscal_year_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY a.start_datetime DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

$types_stmt = db()->prepare('SELECT id, name, color FROM activity_types WHERE is_active = 1 ORDER BY id');
$types_stmt->execute();
$types = $types_stmt->fetchAll();

$years_stmt = db()->prepare('SELECT id, name FROM fiscal_years ORDER BY start_year DESC');
$years_stmt->execute();
$years = $years_stmt->fetchAll();

function time_status_of(array $a): array {
    $now = time();
    $s = strtotime($a['start_datetime']);
    $e = strtotime($a['end_datetime']);
    if ($e < $now)  return ['key'=>'completed', 'label'=>'เสร็จสิ้น',     'bg'=>'#D1FAE5', 'fg'=>'#065F46'];
    if ($s <= $now) return ['key'=>'ongoing',   'label'=>'กำลังดำเนินอยู่', 'bg'=>'#FEF3C7', 'fg'=>'#92400E'];
    return                 ['key'=>'upcoming',  'label'=>'กำลังจะมาถึง',   'bg'=>'#DBEAFE', 'fg'=>'#1E40AF'];
}

function format_date_th(string $datetime): string {
    $ts = strtotime($datetime);
    $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' '
         . (date('Y', $ts) + 543) . ', ' . date('H:i', $ts);
}

$page_title  = 'กิจกรรม';
$page_active = 'activities';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">กิจกรรมขององค์กร</h1>
        <p class="text-muted small mb-0">ทั้งหมด <?= count($activities) ?> รายการ (เฉพาะ scope องค์กร)</p>
    </div>
    <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/admin/activity_form.php?action=create"
       class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> เพิ่มกิจกรรม
    </a>
</div>

<form method="GET" class="card p-3 mb-3">
    <div class="row g-2">
        <div class="col-12 col-md-4">
            <input type="text" name="q" class="form-control" placeholder="ค้นหาชื่อ / สถานที่"
                   value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-6 col-md-2">
            <select name="type" class="form-select">
                <option value="0">ทุกประเภท</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= $f_type===(int)$t['id']?'selected':'' ?>>
                        <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select name="fiscal" class="form-select">
                <option value="0">ทุกปี</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= (int)$y['id'] ?>" <?= $f_fiscal===(int)$y['id']?'selected':'' ?>>
                        <?= htmlspecialchars($y['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select name="time" class="form-select">
                <option value="">ทุกสถานะเวลา</option>
                <option value="upcoming"  <?= $f_time==='upcoming' ?'selected':'' ?>>กำลังจะมาถึง</option>
                <option value="ongoing"   <?= $f_time==='ongoing'  ?'selected':'' ?>>กำลังดำเนินอยู่</option>
                <option value="completed" <?= $f_time==='completed'?'selected':'' ?>>เสร็จสิ้น</option>
            </select>
        </div>
        <div class="col-6 col-md-1">
            <select name="open" class="form-select" title="การสมัคร">
                <option value="">ทั้งหมด</option>
                <option value="1" <?= $f_open==='1'?'selected':'' ?>>เปิด</option>
                <option value="0" <?= $f_open==='0'?'selected':'' ?>>ปิด</option>
            </select>
        </div>
        <div class="col-12 col-md-1">
            <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i></button>
        </div>
    </div>
</form>

<?php if (empty($activities)): ?>
    <div class="card p-5 text-center text-muted">
        <i class="bi bi-calendar-x" style="font-size:48px;opacity:0.3;"></i>
        <p class="mt-3 mb-0">ไม่พบกิจกรรมตามเงื่อนไข</p>
    </div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($activities as $a):
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#5F5E5A';
        $title_safe = htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8');
        $ts = time_status_of($a);
        $view_url = APP_URL . '/admin/activity_view.php?id=' . (int)$a['id'];
        $edit_url = APP_URL . '/admin/activity_form.php?action=edit&id=' . (int)$a['id'];
    ?>
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card h-100 overflow-hidden">
            <div style="height:5px;background:linear-gradient(90deg, <?= $color ?>, <?= $color ?>cc);"></div>
            <div class="p-3 d-flex flex-column h-100">
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <span class="badge-pill" style="background:<?= $color ?>1A;color:<?= $color ?>;">
                        <?= htmlspecialchars($a['type_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="badge-pill" style="background:#DBEAFE;color:#1E40AF;">
                        <i class="bi bi-building"></i> องค์กร
                    </span>
                    <span class="badge-pill" style="background:<?= $ts['bg'] ?>;color:<?= $ts['fg'] ?>;">
                        <?= htmlspecialchars($ts['label'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php if ((int)$a['is_open_registration'] === 1 && $ts['key'] === 'upcoming'): ?>
                        <span class="badge-pill bg-success text-white">เปิดสมัคร</span>
                    <?php endif; ?>
                </div>

                <h3 class="h6 fw-semibold mb-2"
                    style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                    <?= $title_safe ?>
                </h3>
                <?php if (!empty($a['description'])): ?>
                    <p class="text-muted small mb-2"
                       style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                        <?= htmlspecialchars($a['description'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                <?php endif; ?>

                <div class="small text-muted mb-1">
                    <i class="bi bi-calendar3 me-1"></i>
                    <?= htmlspecialchars(format_date_th($a['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
                    – <?= htmlspecialchars(date('H:i', strtotime($a['end_datetime'])), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php if (!empty($a['location'])): ?>
                <div class="small text-muted mb-2 text-truncate">
                    <i class="bi bi-geo-alt me-1"></i>
                    <?= htmlspecialchars($a['location'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>

                <div class="mt-auto pt-2 border-top d-flex align-items-center justify-content-between flex-wrap gap-2"
                     style="border-color:#F1F5F9 !important;">
                    <div class="small text-muted">
                        <i class="bi bi-people"></i> <?= (int)$a['reg_count'] ?>
                        <span class="mx-1">·</span>
                        <i class="bi bi-image"></i> <?= (int)$a['photo_count'] ?>
                        <span class="mx-1">·</span>
                        <i class="bi bi-paperclip"></i> <?= (int)$a['attach_count'] ?>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="<?= htmlspecialchars($view_url, ENT_QUOTES, 'UTF-8') ?>"
                           class="btn btn-sm btn-outline-primary" title="ดูรายละเอียด">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="<?= htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8') ?>"
                           class="btn btn-sm btn-outline-secondary" title="แก้ไข">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('ลบกิจกรรม &quot;<?= $title_safe ?>&quot;?\n\nระบบจะลบภาพ ไฟล์แนบ และการลงทะเบียนทั้งหมด');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
