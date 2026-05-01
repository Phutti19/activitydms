<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';

require_role('employee');

$uid = (int) current_user_id();
$pdo = db();

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $action = $_POST['action'] ?? '';

    // ---- create / update ----
    if ($action === 'save') {
        $edit_id     = (int)($_POST['edit_id'] ?? 0);
        $is_edit     = $edit_id > 0;

        $title       = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $location    = trim((string)($_POST['location'] ?? ''));
        $type_id     = (int)($_POST['activity_type_id'] ?? 0);
        $fiscal_id   = (int)($_POST['fiscal_year_id'] ?? 0);
        $start_raw   = str_replace('T', ' ', trim((string)($_POST['start_datetime'] ?? '')));
        $end_raw     = str_replace('T', ' ', trim((string)($_POST['end_datetime'] ?? '')));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $start_raw)) $start_raw .= ':00';
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $end_raw))   $end_raw   .= ':00';

        $errors = [];
        if ($title === '' || mb_strlen($title) > 255) $errors[] = 'กรุณากรอกชื่อกิจกรรม (ไม่เกิน 255 ตัวอักษร)';

        $chk_type = $pdo->prepare('SELECT 1 FROM activity_types WHERE id = :id AND is_active = 1 LIMIT 1');
        $chk_type->execute([':id' => $type_id]);
        if (!$chk_type->fetch()) $errors[] = 'ประเภทกิจกรรมไม่ถูกต้อง';

        $chk_fy = $pdo->prepare('SELECT 1 FROM fiscal_years WHERE id = :id LIMIT 1');
        $chk_fy->execute([':id' => $fiscal_id]);
        if (!$chk_fy->fetch()) $errors[] = 'ปีงบประมาณไม่ถูกต้อง';

        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start_raw)) $errors[] = 'วันเวลาเริ่มต้นไม่ถูกต้อง';
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end_raw))   $errors[] = 'วันเวลาสิ้นสุดไม่ถูกต้อง';
        if (empty($errors) && strtotime($start_raw) > strtotime($end_raw)) {
            $errors[] = 'วันเวลาสิ้นสุดต้องไม่ก่อนวันเวลาเริ่มต้น';
        }

        if ($errors) {
            foreach ($errors as $e) flash_set('error', $e);
            header('Location: ' . APP_URL . '/employee/personal_activities.php');
            exit;
        }

        if (!$is_edit) {
            $stmt = $pdo->prepare(
                'INSERT INTO activities
                    (title, description, location, activity_type_id, fiscal_year_id,
                     scope, is_open_registration, start_datetime, end_datetime, created_by)
                 VALUES (:t,:desc,:loc,:type,:fy,"personal",0,:s,:e,:cb)'
            );
            $stmt->execute([
                ':t'=>$title, ':desc'=>$description, ':loc'=>$location,
                ':type'=>$type_id, ':fy'=>$fiscal_id,
                ':s'=>$start_raw, ':e'=>$end_raw, ':cb'=>$uid,
            ]);
            $new_id = (int)$pdo->lastInsertId();
            audit_log('create_personal_activity', 'activities', $new_id, null, [
                'title'=>$title, 'scope'=>'personal',
            ]);
            flash_set('success', 'สร้างกิจกรรมส่วนตัว "' . $title . '" สำเร็จ');
        } else {
            // ตรวจว่าเป็นของ user คนนี้จริง
            $own = $pdo->prepare(
                'SELECT id FROM activities WHERE id = :id AND scope = "personal" AND created_by = :u LIMIT 1'
            );
            $own->execute([':id'=>$edit_id, ':u'=>$uid]);
            if (!$own->fetch()) {
                flash_set('error', 'ไม่พบกิจกรรมหรือไม่มีสิทธิ์แก้ไข');
                header('Location: ' . APP_URL . '/employee/personal_activities.php');
                exit;
            }
            $upd = $pdo->prepare(
                'UPDATE activities SET
                    title=:t, description=:desc, location=:loc,
                    activity_type_id=:type, fiscal_year_id=:fy,
                    start_datetime=:s, end_datetime=:e
                 WHERE id=:id AND scope="personal" AND created_by=:u'
            );
            $upd->execute([
                ':t'=>$title, ':desc'=>$description, ':loc'=>$location,
                ':type'=>$type_id, ':fy'=>$fiscal_id,
                ':s'=>$start_raw, ':e'=>$end_raw,
                ':id'=>$edit_id, ':u'=>$uid,
            ]);
            audit_log('update_personal_activity', 'activities', $edit_id, null, ['title'=>$title]);
            flash_set('success', 'แก้ไขกิจกรรม "' . $title . '" สำเร็จ');
        }

        header('Location: ' . APP_URL . '/employee/personal_activities.php');
        exit;
    }

    // ---- delete ----
    if ($action === 'delete') {
        $del_id = (int)($_POST['del_id'] ?? 0);
        $own = $pdo->prepare(
            'SELECT * FROM activities WHERE id = :id AND scope = "personal" AND created_by = :u LIMIT 1'
        );
        $own->execute([':id'=>$del_id, ':u'=>$uid]);
        $row = $own->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM activities WHERE id = :id')->execute([':id'=>$del_id]);
            audit_log('delete_personal_activity', 'activities', $del_id, ['title'=>$row['title']], null);
            flash_set('success', 'ลบกิจกรรม "' . $row['title'] . '" สำเร็จ');
        }
        header('Location: ' . APP_URL . '/employee/personal_activities.php');
        exit;
    }

    header('Location: ' . APP_URL . '/employee/personal_activities.php');
    exit;
}

// ---------------------------------------------------------------------------
// GET — list
// ---------------------------------------------------------------------------
$q = trim((string)($_GET['q'] ?? ''));

$where  = ['a.scope = "personal"', 'a.created_by = :uid'];
$params = [':uid' => $uid];
if ($q !== '') {
    $where[] = '(a.title LIKE :q OR a.location LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$stmt = $pdo->prepare(
    "SELECT a.*, t.name AS type_name, t.color AS type_color, fy.name AS fiscal_name
     FROM activities a
     LEFT JOIN activity_types t  ON t.id = a.activity_type_id
     LEFT JOIN fiscal_years fy   ON fy.id = a.fiscal_year_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY a.start_datetime DESC"
);
$stmt->execute($params);
$activities = $stmt->fetchAll();

$types = $pdo->query(
    'SELECT id, name FROM activity_types WHERE is_active = 1 ORDER BY id'
)->fetchAll();
$years = $pdo->query(
    'SELECT id, name FROM fiscal_years ORDER BY start_year DESC'
)->fetchAll();

function pa_fmt_date(string $dt): string {
    $ts = strtotime($dt);
    $m  = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543) . ', ' . date('H:i', $ts);
}

$page_title  = 'กิจกรรมส่วนตัว';
$page_active = 'personal_activities';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">กิจกรรมส่วนตัว</h1>
        <p class="text-muted small mb-0">เฉพาะคุณเท่านั้นที่มองเห็น</p>
    </div>
    <button type="button" class="btn btn-primary"
            data-bs-toggle="modal" data-bs-target="#activityModal"
            data-mode="create">
        <i class="bi bi-plus-lg me-1"></i> สร้างกิจกรรม
    </button>
</div>

<form method="GET" class="card p-3 mb-3">
    <div class="row g-2">
        <div class="col-10 col-md-8">
            <input type="text" name="q" class="form-control" placeholder="ค้นหาชื่อ / สถานที่"
                   value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-2 col-md-2">
            <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i></button>
        </div>
        <?php if ($q !== ''): ?>
        <div class="col-12 col-md-2">
            <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/employee/personal_activities.php"
               class="btn btn-outline-secondary w-100">ล้าง</a>
        </div>
        <?php endif; ?>
    </div>
</form>

<?php if (empty($activities)): ?>
<div class="card p-5 text-center text-muted">
    <i class="bi bi-journal-bookmark" style="font-size:48px;opacity:0.3;"></i>
    <p class="mt-2 mb-0">ยังไม่มีกิจกรรมส่วนตัว — กดปุ่ม "สร้างกิจกรรม" เพื่อเริ่มต้น</p>
</div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($activities as $a):
    $now   = time();
    $s     = strtotime((string)$a['start_datetime']);
    $e     = strtotime((string)$a['end_datetime']);
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$a['type_color']) ? $a['type_color'] : '#5F5E5A';
    $ts_label = $e < $now ? 'เสร็จสิ้น' : ($s <= $now ? 'กำลังดำเนินอยู่' : 'กำลังจะมาถึง');
    $ts_bg = $e < $now ? '#D1FAE5' : ($s <= $now ? '#FEF3C7' : '#DBEAFE');
    $ts_fg = $e < $now ? '#065F46' : ($s <= $now ? '#92400E' : '#1E40AF');
?>
<div class="col-12 col-md-6 col-lg-4">
    <div class="card h-100" style="border-left: 4px solid <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
        <div class="card-body d-flex flex-column gap-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h6 class="fw-semibold mb-0 lh-sm">
                    <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                </h6>
                <span class="badge"
                      style="background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;white-space:nowrap;flex-shrink:0;">
                    <?= htmlspecialchars($a['type_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <?php if (!empty($a['description'])): ?>
            <p class="small text-muted mb-0 text-truncate-2">
                <?= htmlspecialchars($a['description'], ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php endif; ?>
            <div class="small text-muted">
                <i class="bi bi-clock me-1"></i>
                <?= htmlspecialchars(pa_fmt_date($a['start_datetime']), ENT_QUOTES, 'UTF-8') ?><br>
                <span class="ms-3">— <?= htmlspecialchars(pa_fmt_date($a['end_datetime']), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <?php if (!empty($a['location'])): ?>
            <div class="small text-muted">
                <i class="bi bi-geo-alt me-1"></i>
                <?= htmlspecialchars($a['location'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>
            <div class="mt-auto d-flex align-items-center justify-content-between pt-1">
                <span class="badge"
                      style="background:<?= $ts_bg ?>;color:<?= $ts_fg ?>;font-weight:500;">
                    <?= $ts_label ?>
                </span>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline-primary edit-btn"
                            data-id="<?= (int)$a['id'] ?>"
                            data-title="<?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>"
                            data-description="<?= htmlspecialchars($a['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            data-location="<?= htmlspecialchars($a['location'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            data-type="<?= (int)$a['activity_type_id'] ?>"
                            data-fiscal="<?= (int)$a['fiscal_year_id'] ?>"
                            data-start="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime((string)$a['start_datetime'])), ENT_QUOTES, 'UTF-8') ?>"
                            data-end="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime((string)$a['end_datetime'])), ENT_QUOTES, 'UTF-8') ?>"
                            title="แก้ไข">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('ลบ &quot;<?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>&quot;?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="del_id" value="<?= (int)$a['id'] ?>">
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

<!-- Modal Create / Edit -->
<div class="modal fade" id="activityModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="edit_id" id="modalEditId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">สร้างกิจกรรมส่วนตัว</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label for="fTitle" class="form-label fw-medium">ชื่อกิจกรรม <span class="text-danger">*</span></label>
                        <input type="text" id="fTitle" name="title" class="form-control" required maxlength="255">
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="fType" class="form-label fw-medium">ประเภท <span class="text-danger">*</span></label>
                        <select id="fType" name="activity_type_id" class="form-select" required>
                            <option value="">— เลือกประเภท —</option>
                            <?php foreach ($types as $t): ?>
                            <option value="<?= (int)$t['id'] ?>">
                                <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="fFiscal" class="form-label fw-medium">ปีงบประมาณ <span class="text-danger">*</span></label>
                        <select id="fFiscal" name="fiscal_year_id" class="form-select" required>
                            <option value="">— เลือกปี —</option>
                            <?php foreach ($years as $y): ?>
                            <option value="<?= (int)$y['id'] ?>">
                                <?= htmlspecialchars($y['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="fStart" class="form-label fw-medium">วันเวลาเริ่ม <span class="text-danger">*</span></label>
                        <input type="datetime-local" id="fStart" name="start_datetime" class="form-control" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="fEnd" class="form-label fw-medium">วันเวลาสิ้นสุด <span class="text-danger">*</span></label>
                        <input type="datetime-local" id="fEnd" name="end_datetime" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label for="fLocation" class="form-label fw-medium">สถานที่</label>
                        <input type="text" id="fLocation" name="location" class="form-control" maxlength="255">
                    </div>
                    <div class="col-12">
                        <label for="fDesc" class="form-label fw-medium">รายละเอียด</label>
                        <textarea id="fDesc" name="description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const d = btn.dataset;
        document.getElementById('modalEditId').value  = d.id;
        document.getElementById('fTitle').value       = d.title;
        document.getElementById('fDesc').value        = d.description;
        document.getElementById('fLocation').value    = d.location;
        document.getElementById('fType').value        = d.type;
        document.getElementById('fFiscal').value      = d.fiscal;
        document.getElementById('fStart').value       = d.start;
        document.getElementById('fEnd').value         = d.end;
        document.getElementById('modalTitle').textContent = 'แก้ไขกิจกรรมส่วนตัว';
        bootstrap.Modal.getOrCreate(document.getElementById('activityModal')).show();
    });
});
document.getElementById('activityModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('modalEditId').value = '0';
    document.getElementById('activityModal').querySelector('form').reset();
    document.getElementById('modalTitle').textContent = 'สร้างกิจกรรมส่วนตัว';
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
