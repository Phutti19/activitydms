<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/fiscal_year.php';

require_role('admin');

const VALID_FORMATS = ['onsite', 'online'];

$action_param = $_GET['action'] ?? '';
$id           = (int)($_GET['id'] ?? 0);
$is_edit      = ($action_param === 'edit' && $id > 0);

$activity = null;
if ($is_edit) {
    $stmt = db()->prepare(
        'SELECT * FROM activities WHERE id = :id AND scope = "organization" LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $activity = $stmt->fetch();
    if (!$activity) {
        flash_set('error', 'ไม่พบกิจกรรม');
        header('Location: ' . APP_URL . '/admin/manage_activities.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $title        = trim((string)($_POST['title'] ?? ''));
    $description  = trim((string)($_POST['description'] ?? ''));
    $location     = trim((string)($_POST['location'] ?? ''));
    $type_id      = (int)($_POST['activity_type_id'] ?? 0);
    $format       = trim((string)($_POST['format'] ?? 'onsite'));
    $fiscal_id    = (int)($_POST['fiscal_year_id'] ?? 0);
    $start_date   = trim((string)($_POST['start_date']   ?? ''));
    $start_hour   = trim((string)($_POST['start_hour']   ?? ''));
    $start_minute = trim((string)($_POST['start_minute'] ?? ''));
    $end_date     = trim((string)($_POST['end_date']     ?? ''));
    $end_hour     = trim((string)($_POST['end_hour']     ?? ''));
    $end_minute   = trim((string)($_POST['end_minute']   ?? ''));
    $external_url = trim((string)($_POST['external_url'] ?? ''));
    $is_open_reg  = isset($_POST['is_open_registration']) ? 1 : 0;

    $start_db = $start_date . ' ' . $start_hour . ':' . $start_minute . ':00';
    $end_db   = $end_date   . ' ' . $end_hour   . ':' . $end_minute   . ':00';

    $errors = [];
    if ($title === '' || mb_strlen($title) > 255) $errors[] = 'กรุณากรอกชื่อกิจกรรม (ไม่เกิน 255 ตัว)';
    if (mb_strlen($location) > 255) $errors[] = 'สถานที่ยาวเกินกำหนด';
    if (!in_array($format, VALID_FORMATS, true)) $errors[] = 'รูปแบบกิจกรรมไม่ถูกต้อง';
    if ($format === 'onsite' && $location === '') $errors[] = 'รูปแบบออนไซต์ต้องระบุสถานที่';

    $check = db()->prepare('SELECT 1 FROM activity_types WHERE id = :id AND is_active = 1');
    $check->execute([':id' => $type_id]);
    if (!$check->fetch()) $errors[] = 'ประเภทกิจกรรมไม่ถูกต้อง';

    $check2 = db()->prepare('SELECT 1 FROM fiscal_years WHERE id = :id');
    $check2->execute([':id' => $fiscal_id]);
    if (!$check2->fetch()) $errors[] = 'ปีงบประมาณไม่ถูกต้อง';

    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start_db)) $errors[] = 'วันเวลาเริ่มต้นไม่ถูกต้อง';
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end_db))   $errors[] = 'วันเวลาสิ้นสุดไม่ถูกต้อง';
    if (empty($errors) && strtotime($start_db) > strtotime($end_db)) {
        $errors[] = 'วันเวลาสิ้นสุดต้องไม่ก่อนวันเวลาเริ่มต้น';
    }

    if ($external_url !== '') {
        $parsed = parse_url($external_url);
        if (!$parsed || !in_array($parsed['scheme'] ?? '', ['http', 'https'], true)
            || !filter_var($external_url, FILTER_VALIDATE_URL)
            || mb_strlen($external_url) > 500) {
            $errors[] = 'ลิงก์ภายนอกไม่ถูกต้อง (ต้องเป็น http:// หรือ https://)';
        }
    }

    if ($errors) {
        foreach ($errors as $e) flash_set('error', $e);
        $_SESSION['_form_activity'] = $_POST;
        $back = $is_edit
            ? APP_URL . '/admin/activity_form.php?action=edit&id=' . $id
            : APP_URL . '/admin/activity_form.php?action=create';
        header('Location: ' . $back);
        exit;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (!$is_edit) {
            $stmt = $pdo->prepare(
                'INSERT INTO activities
                    (title, description, location, activity_type_id, format, fiscal_year_id,
                     scope, is_open_registration, start_datetime, end_datetime,
                     external_url, created_by)
                 VALUES (:t, :desc, :loc, :type, :fmt, :fy, "organization", :open,
                         :s, :e, :url, :cb)'
            );
            $stmt->execute([
                ':t'=>$title, ':desc'=>$description, ':loc'=>$location,
                ':type'=>$type_id, ':fmt'=>$format, ':fy'=>$fiscal_id, ':open'=>$is_open_reg,
                ':s'=>$start_db, ':e'=>$end_db,
                ':url'=>$external_url !== '' ? $external_url : null,
                ':cb'=>(int)$_SESSION['user_id'],
            ]);
            $new_id = (int)$pdo->lastInsertId();
            audit_log('create_activity', 'activities', $new_id, null, [
                'title'=>$title, 'type_id'=>$type_id, 'format'=>$format, 'fiscal_year_id'=>$fiscal_id,
                'start'=>$start_db, 'end'=>$end_db, 'is_open_registration'=>$is_open_reg,
            ]);
            flash_set('success', 'สร้างกิจกรรมสำเร็จ — เพิ่มภาพและไฟล์แนบได้ที่หน้ารายละเอียด');
            $pdo->commit();
            header('Location: ' . APP_URL . '/admin/activity_view.php?id=' . $new_id);
            exit;
        } else {
            $stmt = $pdo->prepare(
                'UPDATE activities SET
                    title=:t, description=:desc, location=:loc,
                    activity_type_id=:type, format=:fmt, fiscal_year_id=:fy,
                    is_open_registration=:open,
                    start_datetime=:s, end_datetime=:e,
                    external_url=:url
                 WHERE id=:id AND scope = "organization"'
            );
            $stmt->execute([
                ':t'=>$title, ':desc'=>$description, ':loc'=>$location,
                ':type'=>$type_id, ':fmt'=>$format, ':fy'=>$fiscal_id, ':open'=>$is_open_reg,
                ':s'=>$start_db, ':e'=>$end_db,
                ':url'=>$external_url !== '' ? $external_url : null,
                ':id'=>$id,
            ]);
            audit_log('update_activity', 'activities', $id, $activity, [
                'title'=>$title, 'type_id'=>$type_id, 'format'=>$format, 'fiscal_year_id'=>$fiscal_id,
                'start'=>$start_db, 'end'=>$end_db, 'is_open_registration'=>$is_open_reg,
            ]);
            flash_set('success', 'แก้ไขกิจกรรมสำเร็จ');
            $pdo->commit();
            header('Location: ' . APP_URL . '/admin/activity_view.php?id=' . $id);
            exit;
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$form = $_SESSION['_form_activity'] ?? null;
unset($_SESSION['_form_activity']);

$types_stmt = db()->prepare('SELECT id, name FROM activity_types WHERE is_active = 1 ORDER BY id');
$types_stmt->execute();
$types = $types_stmt->fetchAll();

$years_stmt = db()->prepare('SELECT id, name, is_active FROM fiscal_years ORDER BY start_year DESC');
$years_stmt->execute();
$years = $years_stmt->fetchAll();

$val = function(string $key, $default = '') use ($form, $activity) {
    if ($form !== null && isset($form[$key])) return $form[$key];
    if ($activity && isset($activity[$key])) return $activity[$key];
    return $default;
};

// แยก datetime เป็น [date, hour, minute] + ปัดนาทีเป็นเลขใกล้สุดที่หาร 5 ลงตัว
$split_dt = function ($v): array {
    if (!$v) return ['', '', ''];
    $ts = strtotime((string)$v);
    if ($ts === false) return ['', '', ''];
    $rounded = (int) round($ts / 300) * 300;
    return [date('Y-m-d', $rounded), date('H', $rounded), date('i', $rounded)];
};

if ($form !== null) {
    $start_date_val   = (string)($form['start_date']   ?? '');
    $start_hour_val   = (string)($form['start_hour']   ?? '');
    $start_minute_val = (string)($form['start_minute'] ?? '');
    $end_date_val     = (string)($form['end_date']     ?? '');
    $end_hour_val     = (string)($form['end_hour']     ?? '');
    $end_minute_val   = (string)($form['end_minute']   ?? '');
} elseif ($activity) {
    [$start_date_val, $start_hour_val, $start_minute_val] = $split_dt($activity['start_datetime']);
    [$end_date_val,   $end_hour_val,   $end_minute_val]   = $split_dt($activity['end_datetime']);
} else {
    $start_date_val = $start_hour_val = $start_minute_val = '';
    $end_date_val   = $end_hour_val   = $end_minute_val   = '';
}

$default_fiscal = (int)$val('fiscal_year_id', 0);
if ($default_fiscal === 0 && !$is_edit) {
    $default_fiscal = active_fiscal_year_id() ?? 0;
}

$current_format = (string)$val('format', 'onsite');
if (!in_array($current_format, VALID_FORMATS, true)) $current_format = 'onsite';

$is_open_default = $is_edit
    ? (int)($activity['is_open_registration'] ?? 0)
    : ($form ? (isset($form['is_open_registration']) ? 1 : 0) : 0);

$page_title  = $is_edit ? 'แก้ไขกิจกรรม' : 'เพิ่มกิจกรรม';
$page_active = 'activities';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= $is_edit ? 'แก้ไขกิจกรรม' : 'เพิ่มกิจกรรม' ?></h1>
        <p class="text-muted small mb-0">
            <?= $is_edit
                ? 'แก้ไขข้อมูลพื้นฐาน — ภาพและไฟล์แนบจัดการในหน้ารายละเอียด'
                : 'กรอกข้อมูลพื้นฐาน — เพิ่มภาพและไฟล์แนบได้หลังบันทึก' ?>
        </p>
    </div>
    <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/admin/manage_activities.php"
       class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> กลับ
    </a>
</div>

<form method="POST" novalidate>
    <?= csrf_field() ?>

    <div class="card p-3 p-md-4">
        <div class="row g-3">
            <div class="col-12">
                <label for="aTitle" class="form-label small fw-medium">
                    ชื่อกิจกรรม <span class="text-danger">*</span>
                </label>
                <input type="text" id="aTitle" name="title" class="form-control" required maxlength="255"
                       value="<?= htmlspecialchars($val('title'), ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="col-12 col-md-6">
                <label for="aType" class="form-label small fw-medium">
                    ประเภท <span class="text-danger">*</span>
                </label>
                <select id="aType" name="activity_type_id" class="form-select" required>
                    <option value="">— เลือกประเภท —</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"
                                <?= (int)$val('activity_type_id') === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label small fw-medium d-block">
                    รูปแบบ <span class="text-danger">*</span>
                </label>
                <div class="btn-group w-100" role="group" aria-label="รูปแบบกิจกรรม">
                    <input type="radio" class="btn-check" name="format" id="fmtOnsite" value="onsite"
                           <?= $current_format === 'onsite' ? 'checked' : '' ?> required>
                    <label class="btn btn-outline-primary" for="fmtOnsite">
                        <i class="bi bi-geo-alt me-1"></i> ออนไซต์
                    </label>
                    <input type="radio" class="btn-check" name="format" id="fmtOnline" value="online"
                           <?= $current_format === 'online' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="fmtOnline">
                        <i class="bi bi-camera-video me-1"></i> ออนไลน์
                    </label>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <label for="aFiscal" class="form-label small fw-medium">
                    ปีงบประมาณ <span class="text-danger">*</span>
                </label>
                <select id="aFiscal" name="fiscal_year_id" class="form-select" required>
                    <option value="">— เลือกปี —</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y['id'] ?>"
                                <?= $default_fiscal === (int)$y['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($y['name'], ENT_QUOTES, 'UTF-8') ?>
                            <?= (int)$y['is_active'] === 1 ? ' (ปัจจุบัน)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12 col-md-6">
                <label for="aStartDate" class="form-label small fw-medium">
                    เริ่มต้น <span class="text-danger">*</span>
                </label>
                <div class="row g-1">
                    <div class="col-7">
                        <input type="date" id="aStartDate" name="start_date" class="form-control" required
                               value="<?= htmlspecialchars($start_date_val, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-5">
                        <div class="time-pill">
                            <i class="bi bi-clock"></i>
                            <select name="start_hour" class="time-pill-select" required aria-label="ชั่วโมงเริ่ม">
                                <option value="" disabled <?= $start_hour_val === '' ? 'selected' : '' ?>>HH</option>
                                <?php for ($h = 0; $h < 24; $h++): $hv = sprintf('%02d', $h); ?>
                                <option value="<?= $hv ?>" <?= $start_hour_val === $hv ? 'selected' : '' ?>><?= $hv ?></option>
                                <?php endfor; ?>
                            </select>
                            <span class="time-pill-sep">:</span>
                            <select name="start_minute" class="time-pill-select" required aria-label="นาทีเริ่ม">
                                <option value="" disabled <?= $start_minute_val === '' ? 'selected' : '' ?>>MM</option>
                                <?php for ($m = 0; $m < 60; $m += 5): $mv = sprintf('%02d', $m); ?>
                                <option value="<?= $mv ?>" <?= $start_minute_val === $mv ? 'selected' : '' ?>><?= $mv ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <label for="aEndDate" class="form-label small fw-medium">
                    สิ้นสุด <span class="text-danger">*</span>
                </label>
                <div class="row g-1">
                    <div class="col-7">
                        <input type="date" id="aEndDate" name="end_date" class="form-control" required
                               value="<?= htmlspecialchars($end_date_val, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="col-5">
                        <div class="time-pill">
                            <i class="bi bi-clock"></i>
                            <select name="end_hour" class="time-pill-select" required aria-label="ชั่วโมงสิ้นสุด">
                                <option value="" disabled <?= $end_hour_val === '' ? 'selected' : '' ?>>HH</option>
                                <?php for ($h = 0; $h < 24; $h++): $hv = sprintf('%02d', $h); ?>
                                <option value="<?= $hv ?>" <?= $end_hour_val === $hv ? 'selected' : '' ?>><?= $hv ?></option>
                                <?php endfor; ?>
                            </select>
                            <span class="time-pill-sep">:</span>
                            <select name="end_minute" class="time-pill-select" required aria-label="นาทีสิ้นสุด">
                                <option value="" disabled <?= $end_minute_val === '' ? 'selected' : '' ?>>MM</option>
                                <?php for ($m = 0; $m < 60; $m += 5): $mv = sprintf('%02d', $m); ?>
                                <option value="<?= $mv ?>" <?= $end_minute_val === $mv ? 'selected' : '' ?>><?= $mv ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <label for="aLocation" class="form-label small fw-medium">
                    <span data-fmt-label data-onsite="สถานที่" data-online="แพลตฟอร์มออนไลน์ / ลิงก์เข้าร่วม">สถานที่</span>
                </label>
                <input type="text" id="aLocation" name="location" class="form-control" maxlength="255"
                       value="<?= htmlspecialchars($val('location'), ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="เช่น ห้องประชุมใหญ่ ชั้น 3 อาคาร 50">
                <div class="form-text small">
                    <span data-fmt-hint data-onsite="ออนไซต์ — ระบุสถานที่จริง"
                          data-online="ออนไลน์ — แนะนำใส่ชื่อแพลตฟอร์ม (Zoom, Google Meet) ที่ช่อง 'ลิงก์ภายนอก' ด้านล่าง">
                        ออนไซต์ — ระบุสถานที่จริง
                    </span>
                </div>
            </div>

            <div class="col-12">
                <label for="aDesc" class="form-label small fw-medium">รายละเอียด</label>
                <textarea id="aDesc" name="description" class="form-control" rows="4"><?=
                    htmlspecialchars($val('description'), ENT_QUOTES, 'UTF-8')
                ?></textarea>
            </div>

            <div class="col-12">
                <label for="aUrl" class="form-label small fw-medium">ลิงก์ภายนอก</label>
                <input type="url" id="aUrl" name="external_url" class="form-control" maxlength="500"
                       value="<?= htmlspecialchars($val('external_url'), ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="https://meet.google.com/... หรือ https://...">
                <div class="form-text">ใช้สำหรับ Google Meet / เว็บไซต์งาน — ไม่จำเป็น</div>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input type="checkbox" id="aOpenReg" name="is_open_registration" value="1"
                           class="form-check-input" <?= $is_open_default === 1 ? 'checked' : '' ?>>
                    <label for="aOpenReg" class="form-check-label small">
                        เปิดให้พนักงานเข้าร่วมเอง
                    </label>
                    <div class="form-text small">
                        <i class="bi bi-info-circle"></i>
                        ถ้าไม่เปิด — Admin ต้องเป็นผู้เพิ่มผู้เข้าร่วมเอง
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-column flex-sm-row gap-2 mt-3">
        <button type="submit" class="btn btn-primary fw-semibold flex-grow-1">
            <i class="bi bi-check-lg me-1"></i>
            <?= $is_edit ? 'บันทึกการแก้ไข' : 'สร้างกิจกรรม' ?>
        </button>
        <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/admin/manage_activities.php"
           class="btn btn-outline-secondary">ยกเลิก</a>
    </div>
</form>

<script>
(function () {
    const sync = () => {
        const sel = document.querySelector('input[name="format"]:checked');
        const f = sel ? sel.value : 'onsite';
        document.querySelectorAll('[data-fmt-label], [data-fmt-hint]').forEach(el => {
            const v = el.dataset[f];
            if (v) el.textContent = v;
        });
    };
    document.querySelectorAll('input[name="format"]').forEach(el =>
        el.addEventListener('change', sync));
    sync();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
