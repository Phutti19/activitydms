<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';

require_role('employee');

$uid = (int) current_user_id();
$pdo = db();

// ---------------------------------------------------------------------------
// POST — register / unregister
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $action      = $_POST['action'] ?? '';
    $activity_id = (int)($_POST['activity_id'] ?? 0);

    if ($activity_id <= 0) {
        flash_set('error', 'ข้อมูลไม่ถูกต้อง');
        header('Location: ' . APP_URL . '/employee/available_activities.php');
        exit;
    }

    // ตรวจว่ากิจกรรมเปิดให้เข้าร่วมจริง + scope = organization
    $act_check = $pdo->prepare(
        'SELECT id, title, end_datetime FROM activities
         WHERE id = :id AND scope = "organization" AND is_open_registration = 1 LIMIT 1'
    );
    $act_check->execute([':id' => $activity_id]);
    $act = $act_check->fetch();

    if (!$act) {
        flash_set('error', 'ไม่พบกิจกรรมหรือกิจกรรมไม่ได้เปิดให้เข้าร่วม');
        header('Location: ' . APP_URL . '/employee/available_activities.php');
        exit;
    }

    if ($action === 'register') {
        // ห้ามเข้าร่วมหลังกิจกรรมจบแล้ว
        if (strtotime((string)$act['end_datetime']) < time()) {
            flash_set('error', 'กิจกรรมนี้สิ้นสุดแล้ว ไม่สามารถเข้าร่วมได้');
            header('Location: ' . APP_URL . '/employee/available_activities.php');
            exit;
        }
        try {
            $ins = $pdo->prepare(
                'INSERT INTO activity_registrations (activity_id, user_id, status)
                 VALUES (:a, :u, "attended")'
            );
            $ins->execute([':a' => $activity_id, ':u' => $uid]);
            flash_set('success', 'เข้าร่วม "' . $act['title'] . '" สำเร็จ');
        } catch (PDOException $e) {
            // UNIQUE constraint violation — already registered
            if ($e->getCode() === '23000') {
                flash_set('error', 'คุณได้เข้าร่วมกิจกรรมนี้แล้ว');
            } else {
                error_log('[available_activities] ' . $e->getMessage());
                flash_set('error', 'เกิดข้อผิดพลาด กรุณาลองใหม่');
            }
        }

    } elseif ($action === 'unregister') {
        $del = $pdo->prepare(
            'DELETE FROM activity_registrations
             WHERE activity_id = :a AND user_id = :u AND status = "registered" LIMIT 1'
        );
        $del->execute([':a' => $activity_id, ':u' => $uid]);
        if ($del->rowCount() > 0) {
            flash_set('success', 'ยกเลิกการเข้าร่วม "' . $act['title'] . '" สำเร็จ');
        } else {
            flash_set('error', 'ไม่พบข้อมูลการเข้าร่วม หรือไม่สามารถยกเลิกได้');
        }
    }

    header('Location: ' . APP_URL . '/employee/available_activities.php');
    exit;
}

// ---------------------------------------------------------------------------
// GET — list
// ---------------------------------------------------------------------------
$q        = trim((string)($_GET['q'] ?? ''));
$f_time   = trim((string)($_GET['time'] ?? ''));
$f_type   = (int)($_GET['type'] ?? 0);
$f_fiscal = (int)($_GET['fiscal'] ?? 0);
$f_reg    = trim((string)($_GET['reg'] ?? '')); // joined | not_joined

$where  = ['a.scope = "organization"', 'a.is_open_registration = 1'];
$params = [];

if ($q !== '') {
    $where[] = '(a.title LIKE :q OR a.location LIKE :q2 OR a.description LIKE :q3)';
    $params[':q']  = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
if ($f_time === 'upcoming')  $where[] = 'a.start_datetime > NOW()';
if ($f_time === 'ongoing')   $where[] = 'a.start_datetime <= NOW() AND a.end_datetime >= NOW()';
if ($f_time === 'completed') $where[] = 'a.end_datetime < NOW()';

if ($f_type > 0) {
    $where[] = 'a.activity_type_id = :ftype';
    $params[':ftype'] = $f_type;
}
if ($f_fiscal > 0) {
    $where[] = 'a.fiscal_year_id = :ffy';
    $params[':ffy'] = $f_fiscal;
}
if ($f_reg === 'joined') {
    $where[] = 'EXISTS (SELECT 1 FROM activity_registrations r WHERE r.activity_id = a.id AND r.user_id = :ruid)';
    $params[':ruid'] = $uid;
} elseif ($f_reg === 'not_joined') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM activity_registrations r WHERE r.activity_id = a.id AND r.user_id = :ruid)';
    $params[':ruid'] = $uid;
}

$stmt = $pdo->prepare(
    "SELECT a.id, a.title, a.description, a.location,
            a.start_datetime, a.end_datetime, a.external_url,
            t.name AS type_name, t.color AS type_color,
            fy.name AS fiscal_name,
            (SELECT status FROM activity_registrations
             WHERE activity_id = a.id AND user_id = :uid LIMIT 1) AS my_reg_status,
            (SELECT COUNT(*) FROM activity_registrations WHERE activity_id = a.id) AS reg_count
     FROM activities a
     LEFT JOIN activity_types t ON t.id = a.activity_type_id
     LEFT JOIN fiscal_years fy  ON fy.id = a.fiscal_year_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY a.start_datetime DESC"
);
$params[':uid'] = $uid;
$stmt->execute($params);
$activities = $stmt->fetchAll();

$types_stmt = $pdo->prepare('SELECT id, name FROM activity_types WHERE is_active = 1 ORDER BY id');
$types_stmt->execute();
$types = $types_stmt->fetchAll();

$years_stmt = $pdo->prepare('SELECT id, name FROM fiscal_years ORDER BY start_year DESC');
$years_stmt->execute();
$years = $years_stmt->fetchAll();

$has_filter = ($q !== '' || $f_time !== '' || $f_type > 0 || $f_fiscal > 0 || $f_reg !== '');


$page_title  = 'กิจกรรมที่เข้าร่วมได้';
$page_active = 'available';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">กิจกรรมที่เข้าร่วมได้</h1>
        <p class="text-muted small mb-0">กิจกรรมองค์กรที่เปิดให้เข้าร่วมเอง</p>
    </div>
</div>

<form method="GET" class="card p-3 mb-3" data-autofilter>
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6 col-lg-3">
            <label class="form-label small text-muted mb-1">
                <i class="bi bi-search"></i> ค้นหา
            </label>
            <input type="text" name="q" class="form-control" placeholder="ชื่อ / สถานที่ / รายละเอียด"
                   value="<?= h($q) ?>">
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small text-muted mb-1">ประเภท</label>
            <select name="type" class="form-select">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $f_type === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= h($t['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small text-muted mb-1">ปีงบประมาณ</label>
            <select name="fiscal" class="form-select">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($years as $y): ?>
                <option value="<?= (int)$y['id'] ?>" <?= $f_fiscal === (int)$y['id'] ? 'selected' : '' ?>>
                    <?= h($y['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small text-muted mb-1">สถานะ</label>
            <select name="time" class="form-select">
                <option value="">— ทั้งหมด —</option>
                <option value="upcoming"  <?= $f_time==='upcoming'  ? 'selected' : '' ?>>กำลังจะมาถึง</option>
                <option value="ongoing"   <?= $f_time==='ongoing'   ? 'selected' : '' ?>>กำลังดำเนินอยู่</option>
                <option value="completed" <?= $f_time==='completed' ? 'selected' : '' ?>>เสร็จสิ้น</option>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small text-muted mb-1">การเข้าร่วม</label>
            <select name="reg" class="form-select">
                <option value="">— ทั้งหมด —</option>
                <option value="joined"     <?= $f_reg==='joined'     ? 'selected' : '' ?>>เข้าร่วมแล้ว</option>
                <option value="not_joined" <?= $f_reg==='not_joined' ? 'selected' : '' ?>>ยังไม่เข้าร่วม</option>
            </select>
        </div>
        <div class="col-12 col-lg-1 d-flex gap-1">
            <button type="submit" class="btn btn-primary flex-grow-1" title="ค้นหา">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($has_filter): ?>
            <a href="<?= h(APP_URL) ?>/employee/available_activities.php"
               class="btn btn-outline-secondary flex-grow-1" title="ล้างตัวกรอง">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (empty($activities)): ?>
<div class="card p-5 text-center text-muted">
    <i class="bi bi-megaphone" style="font-size:48px;opacity:0.3;"></i>
    <?php if ($has_filter): ?>
    <p class="mt-2 mb-0">ไม่พบกิจกรรมที่ตรงกับเงื่อนไขที่เลือก — ลองล้างตัวกรองดู</p>
    <?php else: ?>
    <p class="mt-2 mb-0">ไม่พบกิจกรรมที่เข้าร่วมได้</p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($activities as $a):
    $now       = time();
    $s         = strtotime((string)$a['start_datetime']);
    $e         = strtotime((string)$a['end_datetime']);
    $ended     = $e < $now;
    $color     = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#5F5E5A';
    $ts_label  = $ended ? 'เสร็จสิ้น' : ($s <= $now ? 'กำลังดำเนินอยู่' : 'กำลังจะมาถึง');
    $ts_bg     = $ended ? '#D1FAE5' : ($s <= $now ? '#FEF3C7' : '#DBEAFE');
    $ts_fg     = $ended ? '#065F46' : ($s <= $now ? '#92400E' : '#1E40AF');
    $reg_status = $a['my_reg_status'];  // null or 'registered'/'attended'/'absent'
    $is_registered = !empty($reg_status);
?>
<div class="col-12 col-md-6 col-lg-4">
    <div class="card h-100" style="border-left: 4px solid <?= h($color) ?>;">
        <div class="card-body d-flex flex-column gap-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h6 class="fw-semibold mb-0 lh-sm">
                    <a href="<?= h(APP_URL) ?>/employee/activity_view.php?id=<?= (int)$a['id'] ?>"
                       class="text-decoration-none text-body">
                        <?= h($a['title']) ?>
                    </a>
                </h6>
                <span class="badge"
                      style="background:<?= h($color) ?>;white-space:nowrap;flex-shrink:0;">
                    <?= h($a['type_name'] ?? '—') ?>
                </span>
            </div>
            <?php if (!empty($a['description'])): ?>
            <p class="small text-muted mb-0" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">
                <?= h($a['description']) ?>
            </p>
            <?php endif; ?>
            <div class="small text-muted">
                <i class="bi bi-clock me-1"></i>
                <?= h(th_datetime($a['start_datetime'])) ?>
            </div>
            <?php if (!empty($a['location'])): ?>
            <div class="small text-muted">
                <i class="bi bi-geo-alt me-1"></i>
                <?= h($a['location']) ?>
            </div>
            <?php endif; ?>
            <div class="small text-muted">
                <i class="bi bi-people me-1"></i>
                <?= (int)$a['reg_count'] ?> คนเข้าร่วม
            </div>
            <div class="mt-auto d-flex align-items-center justify-content-between pt-1 flex-wrap gap-2">
                <div class="d-flex gap-1 flex-wrap">
                    <span class="badge" style="background:<?= $ts_bg ?>;color:<?= $ts_fg ?>;font-weight:500;">
                        <?= $ts_label ?>
                    </span>
                    <?php if ($is_registered): ?>
                    <?php $rl = ['registered'=>'ยืนยันเข้าร่วม','attended'=>'เข้าร่วมแล้ว','absent'=>'ไม่เข้าร่วม'];
                          $rb = ['registered'=>'success','attended'=>'success','absent'=>'danger']; ?>
                    <span class="badge bg-<?= $rb[$reg_status] ?? 'secondary' ?>">
                        <?= $rl[$reg_status] ?? h($reg_status) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-1">
                <?php if (!$ended): ?>
                    <?php if (!$is_registered): ?>
                    <form method="POST" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="activity_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-person-plus me-1"></i>เข้าร่วม
                        </button>
                    </form>
                    <?php elseif ($reg_status === 'registered'): ?>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('ยกเลิกการเข้าร่วมกิจกรรมนี้?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unregister">
                        <input type="hidden" name="activity_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-person-dash me-1"></i>ยกเลิก
                        </button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($a['external_url'])): ?>
                <a href="<?= h($a['external_url']) ?>"
                   class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                    <i class="bi bi-box-arrow-up-right"></i>
                </a>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
