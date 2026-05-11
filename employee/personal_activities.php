<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/upload.php';

require_role('employee');

const PA_ATTACH_MAX_BYTES = 10 * 1024 * 1024; // 10 MB ตามสเปค §7
const PA_ATTACH_MAX_FILES = 10;
const PA_CERT_PDF_MAX_BYTES = 10 * 1024 * 1024; // PDF ≤ 10 MB
const PA_CERT_IMG_MAX_BYTES = 5  * 1024 * 1024; // JPG/PNG ≤ 5 MB

$uid = (int) current_user_id();
$pdo = db();

// แยก datetime เป็น [date, hour, minute] + ปัดนาทีเป็นเลขใกล้สุดที่หาร 5 ลงตัว
function pa_split_dt($v): array {
    if (!$v) return ['', '', ''];
    $ts = strtotime((string)$v);
    if ($ts === false) return ['', '', ''];
    $rounded = (int) round($ts / 300) * 300;
    return [date('Y-m-d', $rounded), date('H', $rounded), date('i', $rounded)];
}

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ตรวจ post_max_size: ถ้าเกินเพดาน PHP, $_POST/$_FILES จะว่าง CSRF จะ fail เงียบ ๆ
    if (
        empty($_POST) && empty($_FILES)
        && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
    ) {
        $limit = ini_get('post_max_size');
        flash_set('error',
            'ไฟล์/ข้อมูลที่ส่งใหญ่เกินเพดานเซิร์ฟเวอร์ (post_max_size = '
            . $limit . ') — รัน dev server ด้วย "php -c php-dev.ini -S localhost:8000"'
        );
        header('Location: ' . APP_URL . '/employee/personal_activities.php');
        exit;
    }

    verify_csrf_or_die();
    $action = $_POST['action'] ?? '';

    // ---- helper: process uploaded attachments for an activity owned by current user ----
    $process_attachments = function(int $activity_id) use ($pdo, $uid): void {
        if (empty($_FILES['attachments']) || !is_array($_FILES['attachments']['name'])) return;
        $names = $_FILES['attachments']['name'];
        $count = count($names);
        if ($count === 0) return;

        $existing = $pdo->prepare('SELECT COUNT(*) FROM activity_attachments WHERE activity_id = :a');
        $existing->execute([':a' => $activity_id]);
        $current_total = (int) $existing->fetchColumn();

        $uploaded = 0;
        for ($i = 0; $i < $count; $i++) {
            if (($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if ($current_total + $uploaded >= PA_ATTACH_MAX_FILES) {
                flash_set('error', 'ไฟล์แนบเกินจำนวนสูงสุด (' . PA_ATTACH_MAX_FILES . ' ไฟล์ต่อกิจกรรม)');
                break;
            }

            $one = [
                'name'     => $_FILES['attachments']['name'][$i],
                'type'     => $_FILES['attachments']['type'][$i],
                'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                'error'    => $_FILES['attachments']['error'][$i],
                'size'     => $_FILES['attachments']['size'][$i],
            ];

            $result = validate_uploaded_file($one, UPLOAD_DOC_MIMES + UPLOAD_IMAGE_MIMES, PA_ATTACH_MAX_BYTES);
            if (!$result['ok']) {
                flash_set('error', 'อัปโหลด "' . $one['name'] . '": ' . $result['error']);
                continue;
            }

            try {
                $stored = move_uploaded_with_uuid($one, 'activities', $result['ext']);
            } catch (Throwable $ex) {
                flash_set('error', 'บันทึกไฟล์ "' . $one['name'] . '" ไม่สำเร็จ');
                continue;
            }

            $label = pathinfo((string)$one['name'], PATHINFO_FILENAME);
            if (mb_strlen($label) > 200) $label = mb_substr($label, 0, 200);

            $ins = $pdo->prepare(
                'INSERT INTO activity_attachments (activity_id, type, label, filename)
                 VALUES (:a, "file", :l, :f)'
            );
            $ins->execute([':a' => $activity_id, ':l' => $label, ':f' => $stored]);
            audit_log('upload_personal_attachment', 'activity_attachments',
                (int)$pdo->lastInsertId(), null,
                ['activity_id' => $activity_id, 'label' => $label, 'filename' => $stored]);
            $uploaded++;
        }
        if ($uploaded > 0) flash_set('success', 'อัปโหลดไฟล์แนบ ' . $uploaded . ' ไฟล์');
    };

    // ---- upload attachment(s) inline (จากการ์ดในหน้า list) ----
    if ($action === 'upload_attachment') {
        $act_id = (int)($_POST['activity_id'] ?? 0);
        $own = $pdo->prepare(
            'SELECT id, title FROM activities
             WHERE id = :id AND scope = "personal" AND created_by = :u LIMIT 1'
        );
        $own->execute([':id' => $act_id, ':u' => $uid]);
        $act_row = $own->fetch();
        if (!$act_row) {
            flash_set('error', 'ไม่พบกิจกรรมหรือไม่มีสิทธิ์แนบไฟล์');
            header('Location: ' . APP_URL . '/employee/personal_activities.php');
            exit;
        }
        $process_attachments($act_id);
        header('Location: ' . APP_URL . '/employee/personal_activities.php');
        exit;
    }

    // ---- delete attachment ----
    if ($action === 'delete_attachment') {
        $att_id = (int)($_POST['attachment_id'] ?? 0);
        $own = $pdo->prepare(
            'SELECT att.id, att.filename, att.label, a.id AS activity_id
             FROM activity_attachments att
             JOIN activities a ON a.id = att.activity_id
             WHERE att.id = :id AND att.type = "file"
               AND a.scope = "personal" AND a.created_by = :u
             LIMIT 1'
        );
        $own->execute([':id' => $att_id, ':u' => $uid]);
        $row = $own->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM activity_attachments WHERE id = :id')->execute([':id' => $att_id]);
            if (!empty($row['filename'])) safe_unlink_upload('activities', (string)$row['filename']);
            audit_log('delete_personal_attachment', 'activity_attachments', $att_id,
                ['label' => $row['label'], 'filename' => $row['filename']], null);
            flash_set('success', 'ลบไฟล์แนบสำเร็จ');
        } else {
            flash_set('error', 'ไม่พบไฟล์แนบหรือไม่มีสิทธิ์ลบ');
        }
        header('Location: ' . APP_URL . '/employee/personal_activities.php');
        exit;
    }

    // ---- upload certificate ----
    if ($action === 'upload_cert') {
        $act_id = (int)($_POST['activity_id'] ?? 0);
        $own = $pdo->prepare(
            'SELECT id, title FROM activities
             WHERE id = :id AND scope = "personal" AND created_by = :u LIMIT 1'
        );
        $own->execute([':id' => $act_id, ':u' => $uid]);
        $act_row = $own->fetch();
        if (!$act_row) {
            flash_set('error', 'ไม่พบกิจกรรมหรือไม่มีสิทธิ์อัปโหลด');
            header('Location: ' . APP_URL . '/employee/personal_activities.php');
            exit;
        }

        $exists = $pdo->prepare(
            'SELECT 1 FROM certificates WHERE activity_id = :a AND user_id = :u LIMIT 1'
        );
        $exists->execute([':a' => $act_id, ':u' => $uid]);
        if ($exists->fetch()) {
            flash_set('error', 'มีเกียรติบัตรของกิจกรรมนี้อยู่แล้ว — ลบของเดิมก่อนอัปโหลดใหม่');
            header('Location: ' . APP_URL . '/employee/personal_activities.php');
            exit;
        }

        $file = $_FILES['cert_file'] ?? null;
        if (!$file) {
            flash_set('error', 'ไม่ได้เลือกไฟล์');
            header('Location: ' . APP_URL . '/employee/personal_activities.php');
            exit;
        }

        $check = validate_uploaded_file($file, UPLOAD_CERT_MIMES, PA_CERT_PDF_MAX_BYTES);
        if ($check['ok']) {
            $per_limit = $check['ext'] === 'pdf' ? PA_CERT_PDF_MAX_BYTES : PA_CERT_IMG_MAX_BYTES;
            if ($check['size'] > $per_limit) {
                $max_mb = $check['ext'] === 'pdf' ? 10 : 5;
                $check = ['ok' => false, 'error' => "ไฟล์ใหญ่เกิน {$max_mb} MB"];
            }
        }
        if (!$check['ok']) {
            flash_set('error', 'อัปโหลดเกียรติบัตรไม่สำเร็จ: ' . $check['error']);
            header('Location: ' . APP_URL . '/employee/personal_activities.php');
            exit;
        }

        $original_name = sanitize_download_filename((string)($file['name'] ?? ''));
        if ($original_name === 'download') $original_name = 'certificate.' . $check['ext'];

        try {
            $filename = move_uploaded_with_uuid($file, 'certificates', $check['ext']);
        } catch (Throwable $ex) {
            flash_set('error', 'บันทึกไฟล์เกียรติบัตรไม่สำเร็จ');
            header('Location: ' . APP_URL . '/employee/personal_activities.php');
            exit;
        }

        try {
            $ins = $pdo->prepare(
                'INSERT INTO certificates
                   (activity_id, user_id, filename, original_name, uploaded_by)
                 VALUES (:a, :u, :f, :o, :by)'
            );
            $ins->execute([
                ':a' => $act_id, ':u' => $uid,
                ':f' => $filename, ':o' => $original_name, ':by' => $uid,
            ]);
            $cert_id = (int)$pdo->lastInsertId();
            audit_log('upload_personal_certificate', 'certificates', $cert_id, null, [
                'activity_id' => $act_id, 'filename' => $filename, 'original_name' => $original_name,
            ]);
            flash_set('success', 'อัปโหลดเกียรติบัตรของ "' . $act_row['title'] . '" สำเร็จ');
        } catch (PDOException $e) {
            // ลบไฟล์ที่ย้ายไปแล้ว เพื่อไม่ให้กลายเป็น orphan
            safe_unlink_upload('certificates', $filename);
            if ($e->getCode() === '23000') {
                // UNIQUE(activity_id, user_id) — race condition จาก double-submit
                flash_set('error', 'มีเกียรติบัตรของกิจกรรมนี้อยู่แล้ว — ลบของเดิมก่อนอัปโหลดใหม่');
            } else {
                error_log('[personal_activities.upload_cert] ' . $e->getMessage());
                flash_set('error', 'บันทึกเกียรติบัตรไม่สำเร็จ');
            }
        }
        header('Location: ' . APP_URL . '/employee/personal_activities.php');
        exit;
    }

    // ---- delete certificate ----
    if ($action === 'delete_cert') {
        $cert_id = (int)($_POST['cert_id'] ?? 0);
        $own = $pdo->prepare(
            'SELECT c.id, c.filename, c.original_name, a.title
             FROM certificates c
             JOIN activities a ON a.id = c.activity_id
             WHERE c.id = :id
               AND c.user_id = :u1
               AND a.scope = "personal" AND a.created_by = :u2
             LIMIT 1'
        );
        $own->execute([':id' => $cert_id, ':u1' => $uid, ':u2' => $uid]);
        $row = $own->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM certificates WHERE id = :id')->execute([':id' => $cert_id]);
            if (!empty($row['filename'])) safe_unlink_upload('certificates', (string)$row['filename']);
            audit_log('delete_personal_certificate', 'certificates', $cert_id,
                ['filename' => $row['filename'], 'original_name' => $row['original_name']], null);
            flash_set('success', 'ลบเกียรติบัตรสำเร็จ');
        } else {
            flash_set('error', 'ไม่พบเกียรติบัตรหรือไม่มีสิทธิ์ลบ');
        }
        header('Location: ' . APP_URL . '/employee/personal_activities.php');
        exit;
    }

    // ---- create / update ----
    if ($action === 'save') {
        $edit_id     = (int)($_POST['edit_id'] ?? 0);
        $is_edit     = $edit_id > 0;

        $title       = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $location    = trim((string)($_POST['location'] ?? ''));
        $type_id     = (int)($_POST['activity_type_id'] ?? 0);
        $fiscal_id   = (int)($_POST['fiscal_year_id'] ?? 0);
        $start_date   = trim((string)($_POST['start_date']   ?? ''));
        $start_hour   = trim((string)($_POST['start_hour']   ?? ''));
        $start_minute = trim((string)($_POST['start_minute'] ?? ''));
        $end_date     = trim((string)($_POST['end_date']     ?? ''));
        $end_hour     = trim((string)($_POST['end_hour']     ?? ''));
        $end_minute   = trim((string)($_POST['end_minute']   ?? ''));
        $start_raw = $start_date . ' ' . $start_hour . ':' . $start_minute . ':00';
        $end_raw   = $end_date   . ' ' . $end_hour   . ':' . $end_minute   . ':00';

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
            $process_attachments($new_id);
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
            $process_attachments($edit_id);
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
            // เก็บรายชื่อไฟล์ก่อน เพื่อลบใน disk หลัง CASCADE DELETE
            $files = $pdo->prepare(
                'SELECT filename FROM activity_attachments
                 WHERE activity_id = :a AND type = "file" AND filename IS NOT NULL'
            );
            $files->execute([':a' => $del_id]);
            $filenames = $files->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare('DELETE FROM activities WHERE id = :id')->execute([':id'=>$del_id]);

            foreach ($filenames as $fn) {
                if (!empty($fn)) safe_unlink_upload('activities', (string)$fn);
            }

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
$q          = trim((string)($_GET['q'] ?? ''));
$f_type     = (int)($_GET['type'] ?? 0);
$f_fiscal   = (int)($_GET['fiscal'] ?? 0);
$f_status   = trim((string)($_GET['status'] ?? ''));   // upcoming | ongoing | done
$f_cert     = trim((string)($_GET['cert'] ?? ''));     // has | none

$where  = ['a.scope = "personal"', 'a.created_by = :uid'];
$params = [':uid' => $uid];
if ($q !== '') {
    $where[] = '(a.title LIKE :q OR a.location LIKE :q2 OR a.description LIKE :q3)';
    $params[':q']  = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
}
if ($f_type > 0) {
    $where[] = 'a.activity_type_id = :ftype';
    $params[':ftype'] = $f_type;
}
if ($f_fiscal > 0) {
    $where[] = 'a.fiscal_year_id = :ffy';
    $params[':ffy'] = $f_fiscal;
}
if ($f_status === 'upcoming') {
    $where[] = 'a.start_datetime > NOW()';
} elseif ($f_status === 'ongoing') {
    $where[] = 'a.start_datetime <= NOW() AND a.end_datetime >= NOW()';
} elseif ($f_status === 'done') {
    $where[] = 'a.end_datetime < NOW()';
}
if ($f_cert === 'has') {
    $where[] = 'EXISTS (SELECT 1 FROM certificates c WHERE c.activity_id = a.id AND c.user_id = :cuid)';
    $params[':cuid'] = $uid;
} elseif ($f_cert === 'none') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM certificates c WHERE c.activity_id = a.id AND c.user_id = :cuid)';
    $params[':cuid'] = $uid;
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

$has_filter = ($q !== '' || $f_type > 0 || $f_fiscal > 0 || $f_status !== '' || $f_cert !== '');

// Group attachments by activity_id (เฉพาะ personal ของ user คนนี้ ตาม scope filter ข้างบน)
$attach_map = [];
if (!empty($activities)) {
    $ids = array_map(fn($a) => (int)$a['id'], $activities);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $att_stmt = $pdo->prepare(
        "SELECT id, activity_id, label, filename
         FROM activity_attachments
         WHERE type = 'file' AND activity_id IN ($placeholders)
         ORDER BY id ASC"
    );
    $att_stmt->execute($ids);
    foreach ($att_stmt->fetchAll() as $att) {
        $attach_map[(int)$att['activity_id']][] = $att;
    }
}

// Map cert by activity_id (เฉพาะ personal ของ user คนนี้)
$cert_map = [];
if (!empty($activities)) {
    $ids = array_map(fn($a) => (int)$a['id'], $activities);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params_cert = array_merge($ids, [$uid]);
    $cert_stmt = $pdo->prepare(
        "SELECT id, activity_id, original_name, created_at
         FROM certificates
         WHERE activity_id IN ($placeholders) AND user_id = ?"
    );
    $cert_stmt->execute($params_cert);
    foreach ($cert_stmt->fetchAll() as $c) {
        $cert_map[(int)$c['activity_id']] = $c;
    }
}

$types_stmt = $pdo->prepare(
    'SELECT id, name FROM activity_types WHERE is_active = 1 ORDER BY id'
);
$types_stmt->execute();
$types = $types_stmt->fetchAll();

$years_stmt = $pdo->prepare(
    'SELECT id, name FROM fiscal_years ORDER BY start_year DESC'
);
$years_stmt->execute();
$years = $years_stmt->fetchAll();

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
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6 col-lg-3">
            <label class="form-label small text-muted mb-1">
                <i class="bi bi-search"></i> ค้นหา
            </label>
            <input type="text" name="q" class="form-control" placeholder="ชื่อ / สถานที่ / รายละเอียด"
                   value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small text-muted mb-1">ประเภท</label>
            <select name="type" class="form-select">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $f_type === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
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
                    <?= htmlspecialchars($y['name'], ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small text-muted mb-1">สถานะ</label>
            <select name="status" class="form-select">
                <option value="">— ทั้งหมด —</option>
                <option value="upcoming" <?= $f_status === 'upcoming' ? 'selected' : '' ?>>กำลังจะมาถึง</option>
                <option value="ongoing"  <?= $f_status === 'ongoing'  ? 'selected' : '' ?>>กำลังดำเนินอยู่</option>
                <option value="done"     <?= $f_status === 'done'     ? 'selected' : '' ?>>เสร็จสิ้น</option>
            </select>
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small text-muted mb-1">เกียรติบัตร</label>
            <select name="cert" class="form-select">
                <option value="">— ทั้งหมด —</option>
                <option value="has"  <?= $f_cert === 'has'  ? 'selected' : '' ?>>มีเกียรติบัตร</option>
                <option value="none" <?= $f_cert === 'none' ? 'selected' : '' ?>>ยังไม่มี</option>
            </select>
        </div>
        <div class="col-12 col-lg-1 d-flex gap-1">
            <button type="submit" class="btn btn-primary flex-grow-1" title="ค้นหา">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($has_filter): ?>
            <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/employee/personal_activities.php"
               class="btn btn-outline-secondary flex-grow-1" title="ล้างตัวกรอง">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (empty($activities)): ?>
<div class="card p-5 text-center text-muted">
    <i class="bi bi-journal-bookmark" style="font-size:48px;opacity:0.3;"></i>
    <?php if ($has_filter): ?>
    <p class="mt-2 mb-0">ไม่พบกิจกรรมที่ตรงกับเงื่อนไขที่เลือก — ลองล้างตัวกรองดู</p>
    <?php else: ?>
    <p class="mt-2 mb-0">ยังไม่มีกิจกรรมส่วนตัว — กดปุ่ม "สร้างกิจกรรม" เพื่อเริ่มต้น</p>
    <?php endif; ?>
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

            <?php
            $cert = $cert_map[(int)$a['id']] ?? null;
            $atts = $attach_map[(int)$a['id']] ?? [];
            $att_count = count($atts);
            $att_remaining = PA_ATTACH_MAX_FILES - $att_count;
            ?>
            <div class="d-flex flex-wrap gap-1 mt-1">
                <span class="badge rounded-pill bg-light text-dark border d-inline-flex align-items-center gap-1 fw-normal">
                    <i class="bi bi-award <?= $cert ? 'text-warning' : 'text-muted' ?>"></i>
                    <?= $cert ? 'มีเกียรติบัตร' : 'ยังไม่มีเกียรติบัตร' ?>
                </span>
                <span class="badge rounded-pill bg-light text-dark border d-inline-flex align-items-center gap-1 fw-normal">
                    <i class="bi bi-paperclip <?= $att_count > 0 ? 'text-primary' : 'text-muted' ?>"></i>
                    ไฟล์แนบ <?= $att_count ?>/<?= PA_ATTACH_MAX_FILES ?>
                </span>
            </div>

            <div class="mt-auto d-flex align-items-center justify-content-between pt-1">
                <span class="badge"
                      style="background:<?= $ts_bg ?>;color:<?= $ts_fg ?>;font-weight:500;">
                    <?= $ts_label ?>
                </span>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline-warning"
                            data-bs-toggle="modal" data-bs-target="#filesModal-<?= (int)$a['id'] ?>"
                            title="จัดการไฟล์">
                        <i class="bi bi-folder2-open"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary edit-btn"
                            data-bs-toggle="modal" data-bs-target="#activityModal"
                            data-id="<?= (int)$a['id'] ?>"
                            data-title="<?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>"
                            data-description="<?= htmlspecialchars($a['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            data-location="<?= htmlspecialchars($a['location'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            data-type="<?= (int)$a['activity_type_id'] ?>"
                            data-fiscal="<?= (int)$a['fiscal_year_id'] ?>"
                            <?php
                            [$pa_sd, $pa_sh, $pa_sm] = pa_split_dt((string)$a['start_datetime']);
                            [$pa_ed, $pa_eh, $pa_em] = pa_split_dt((string)$a['end_datetime']);
                            ?>
                            data-start-date="<?= htmlspecialchars($pa_sd, ENT_QUOTES, 'UTF-8') ?>"
                            data-start-hour="<?= htmlspecialchars($pa_sh, ENT_QUOTES, 'UTF-8') ?>"
                            data-start-minute="<?= htmlspecialchars($pa_sm, ENT_QUOTES, 'UTF-8') ?>"
                            data-end-date="<?= htmlspecialchars($pa_ed, ENT_QUOTES, 'UTF-8') ?>"
                            data-end-hour="<?= htmlspecialchars($pa_eh, ENT_QUOTES, 'UTF-8') ?>"
                            data-end-minute="<?= htmlspecialchars($pa_em, ENT_QUOTES, 'UTF-8') ?>"
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

<!-- Files modal — เกียรติบัตร + ไฟล์แนบของกิจกรรมนี้ -->
<div class="modal fade" id="filesModal-<?= (int)$a['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-folder2-open me-1"></i>
                    จัดการไฟล์ — <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body d-flex flex-column gap-3">
                <!-- เกียรติบัตร -->
                <section>
                    <div class="fw-medium mb-2">
                        <i class="bi bi-award text-warning"></i> เกียรติบัตร
                    </div>
                    <?php if ($cert): ?>
                    <div class="d-flex align-items-center justify-content-between gap-2 small p-2 border rounded">
                        <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/api/download.php?type=cert&id=<?= (int)$cert['id'] ?>"
                           class="text-decoration-none text-truncate" target="_blank" rel="noopener"
                           title="<?= htmlspecialchars($cert['original_name'], ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi bi-file-earmark-check me-1 text-warning"></i>
                            <?= htmlspecialchars($cert['original_name'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <form method="POST" class="m-0"
                              onsubmit="return confirm('ลบเกียรติบัตรของ &quot;<?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>&quot;?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_cert">
                            <input type="hidden" name="cert_id" value="<?= (int)$cert['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบเกียรติบัตร">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                    <?php else: ?>
                    <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_cert">
                        <input type="hidden" name="activity_id" value="<?= (int)$a['id'] ?>">
                        <input type="file" name="cert_file" class="form-control form-control-sm"
                               accept=".pdf,.jpg,.jpeg,.png" required>
                        <button type="submit" class="btn btn-sm btn-warning flex-shrink-0">
                            <i class="bi bi-upload me-1"></i> อัปโหลด
                        </button>
                    </form>
                    <div class="form-text small">PDF ≤ 10MB / JPG-PNG ≤ 5MB</div>
                    <?php endif; ?>
                </section>

                <!-- ไฟล์แนบ -->
                <section>
                    <div class="fw-medium mb-2">
                        <i class="bi bi-paperclip text-primary"></i>
                        ไฟล์แนบ (<?= $att_count ?>/<?= PA_ATTACH_MAX_FILES ?>)
                    </div>
                    <?php if ($att_count > 0): ?>
                    <ul class="list-unstyled mb-2 small d-flex flex-column gap-1">
                    <?php foreach ($atts as $att):
                        $att_label = trim((string)$att['label']) !== '' ? $att['label'] : 'ไฟล์แนบ';
                        $att_label_safe = htmlspecialchars($att_label, ENT_QUOTES, 'UTF-8');
                    ?>
                        <li class="d-flex align-items-center justify-content-between gap-2 p-2 border rounded">
                            <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/api/download.php?type=attachment&id=<?= (int)$att['id'] ?>"
                               class="text-decoration-none text-truncate" target="_blank" rel="noopener"
                               title="<?= $att_label_safe ?>">
                                <i class="bi bi-file-earmark-text me-1"></i><?= $att_label_safe ?>
                            </a>
                            <form method="POST" class="m-0"
                                  onsubmit="return confirm('ลบไฟล์แนบ &quot;<?= $att_label_safe ?>&quot;?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_attachment">
                                <input type="hidden" name="attachment_id" value="<?= (int)$att['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบไฟล์">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="small text-muted mb-2">ยังไม่มีไฟล์แนบ</p>
                    <?php endif; ?>

                    <?php if ($att_remaining > 0): ?>
                    <form method="POST" enctype="multipart/form-data"
                          class="d-flex gap-2 align-items-center attach-add-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="upload_attachment">
                        <input type="hidden" name="activity_id" value="<?= (int)$a['id'] ?>">
                        <input type="file" name="attachments[]" class="form-control form-control-sm attach-add-input"
                               multiple
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp"
                               required>
                        <button type="submit" class="btn btn-sm btn-primary flex-shrink-0">
                            <i class="bi bi-plus-lg me-1"></i> เพิ่ม
                        </button>
                    </form>
                    <div class="form-text small">
                        เพิ่มได้อีก <?= $att_remaining ?> ไฟล์ — สูงสุด 10 MB ต่อไฟล์
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning small mb-0 py-2">
                        <i class="bi bi-info-circle"></i> ครบจำนวนสูงสุด <?= PA_ATTACH_MAX_FILES ?> ไฟล์แล้ว
                    </div>
                    <?php endif; ?>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
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
            <form method="POST" enctype="multipart/form-data">
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
                        <label for="fStartDate" class="form-label fw-medium">วันเวลาเริ่ม <span class="text-danger">*</span></label>
                        <div class="row g-1">
                            <div class="col-7">
                                <input type="date" id="fStartDate" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-5">
                                <div class="time-pill">
                                    <i class="bi bi-clock"></i>
                                    <select id="fStartHour" name="start_hour" class="time-pill-select" required aria-label="ชั่วโมงเริ่ม">
                                        <option value="" disabled selected>HH</option>
                                        <?php for ($h = 0; $h < 24; $h++): $hv = sprintf('%02d', $h); ?>
                                        <option value="<?= $hv ?>"><?= $hv ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="time-pill-sep">:</span>
                                    <select id="fStartMinute" name="start_minute" class="time-pill-select" required aria-label="นาทีเริ่ม">
                                        <option value="" disabled selected>MM</option>
                                        <?php for ($m = 0; $m < 60; $m += 5): $mv = sprintf('%02d', $m); ?>
                                        <option value="<?= $mv ?>"><?= $mv ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="fEndDate" class="form-label fw-medium">วันเวลาสิ้นสุด <span class="text-danger">*</span></label>
                        <div class="row g-1">
                            <div class="col-7">
                                <input type="date" id="fEndDate" name="end_date" class="form-control" required>
                            </div>
                            <div class="col-5">
                                <div class="time-pill">
                                    <i class="bi bi-clock"></i>
                                    <select id="fEndHour" name="end_hour" class="time-pill-select" required aria-label="ชั่วโมงสิ้นสุด">
                                        <option value="" disabled selected>HH</option>
                                        <?php for ($h = 0; $h < 24; $h++): $hv = sprintf('%02d', $h); ?>
                                        <option value="<?= $hv ?>"><?= $hv ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="time-pill-sep">:</span>
                                    <select id="fEndMinute" name="end_minute" class="time-pill-select" required aria-label="นาทีสิ้นสุด">
                                        <option value="" disabled selected>MM</option>
                                        <?php for ($m = 0; $m < 60; $m += 5): $mv = sprintf('%02d', $m); ?>
                                        <option value="<?= $mv ?>"><?= $mv ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label for="fLocation" class="form-label fw-medium">สถานที่</label>
                        <input type="text" id="fLocation" name="location" class="form-control" maxlength="255">
                    </div>
                    <div class="col-12">
                        <label for="fDesc" class="form-label fw-medium">รายละเอียด</label>
                        <textarea id="fDesc" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="col-12">
                        <label for="fAttach" class="form-label fw-medium">
                            <i class="bi bi-paperclip"></i> <span id="fAttachLabel">ไฟล์แนบ</span>
                        </label>
                        <input type="file" id="fAttach" name="attachments[]" class="form-control"
                               multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp">
                        <div class="form-text small">
                            PDF / Word / Excel / PowerPoint / รูปภาพ — สูงสุด 10 MB ต่อไฟล์,
                            <?= PA_ATTACH_MAX_FILES ?> ไฟล์ต่อกิจกรรม
                        </div>
                        <div class="form-text small text-warning d-none" id="fAttachServerHint">
                            <i class="bi bi-exclamation-triangle"></i>
                            เซิร์ฟเวอร์ปัจจุบันยอมรับไฟล์สูงสุด <span id="fAttachServerLimit"></span> ต่อไฟล์
                            — ถ้าเกินจะอัปโหลดไม่สำเร็จ
                        </div>
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
// ใช้ event delegation + show.bs.modal เพื่อให้ Bootstrap เป็นคนเปิด modal เอง (กัน race + ทำงานได้แม้ปุ่มถูก render ใหม่)
const activityModalEl = document.getElementById('activityModal');
const fAttachLabel    = document.getElementById('fAttachLabel');

document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.edit-btn');
    if (!btn) return;
    const d = btn.dataset;
    document.getElementById('modalEditId').value  = d.id || '0';
    document.getElementById('fTitle').value       = d.title || '';
    document.getElementById('fDesc').value        = d.description || '';
    document.getElementById('fLocation').value    = d.location || '';
    document.getElementById('fType').value        = d.type || '';
    document.getElementById('fFiscal').value      = d.fiscal || '';
    document.getElementById('fStartDate').value   = d.startDate   || '';
    document.getElementById('fStartHour').value   = d.startHour   || '';
    document.getElementById('fStartMinute').value = d.startMinute || '';
    document.getElementById('fEndDate').value     = d.endDate     || '';
    document.getElementById('fEndHour').value     = d.endHour     || '';
    document.getElementById('fEndMinute').value   = d.endMinute   || '';
    document.getElementById('modalTitle').textContent = 'แก้ไขกิจกรรมส่วนตัว';
    fAttachLabel.textContent = 'ไฟล์แนบ (เพิ่มไฟล์ใหม่)';
});

document.querySelectorAll('[data-mode="create"]').forEach((btn) => {
    btn.addEventListener('click', () => {
        fAttachLabel.textContent = 'ไฟล์แนบ';
    });
});

// แก้ Bootstrap 5 a11y: ย้าย focus ออกจาก modal ก่อนตั้ง aria-hidden
activityModalEl.addEventListener('hide.bs.modal', () => {
    if (activityModalEl.contains(document.activeElement)) {
        document.activeElement.blur();
    }
});

activityModalEl.addEventListener('hidden.bs.modal', () => {
    document.getElementById('modalEditId').value = '0';
    activityModalEl.querySelector('form').reset();
    document.getElementById('modalTitle').textContent = 'สร้างกิจกรรมส่วนตัว';
    fAttachLabel.textContent = 'ไฟล์แนบ';
});

// Client-side preflight: เตือนถ้าไฟล์เกินเพดาน server (อ่านจาก data-server-limit)
const fAttach        = document.getElementById('fAttach');
const fAttachHint    = document.getElementById('fAttachServerHint');
const fAttachLimitEl = document.getElementById('fAttachServerLimit');
const SERVER_UPLOAD_LIMIT_BYTES = <?= (int) (
    (function (): int {
        $v = ini_get('upload_max_filesize');
        if (!$v) return 0;
        $unit = strtolower(substr($v, -1));
        $num  = (int) $v;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    })()
) ?>;
const SERVER_UPLOAD_LIMIT_LABEL = '<?= htmlspecialchars((string) ini_get('upload_max_filesize'), ENT_QUOTES, 'UTF-8') ?>';
if (SERVER_UPLOAD_LIMIT_BYTES > 0 && SERVER_UPLOAD_LIMIT_BYTES < (10 * 1024 * 1024)) {
    fAttachLimitEl.textContent = SERVER_UPLOAD_LIMIT_LABEL;
    fAttachHint.classList.remove('d-none');
}
function checkOversize(input) {
    if (!input.files || !input.files.length) return;
    if (SERVER_UPLOAD_LIMIT_BYTES <= 0) return;
    const tooBig = Array.from(input.files).filter(f => f.size > SERVER_UPLOAD_LIMIT_BYTES);
    if (tooBig.length) {
        alert('ไฟล์ต่อไปนี้ใหญ่เกินเพดานเซิร์ฟเวอร์ (' + SERVER_UPLOAD_LIMIT_LABEL + '):\n\n'
              + tooBig.map(f => '• ' + f.name + ' (' + (f.size/1024/1024).toFixed(1) + ' MB)').join('\n'));
        input.value = '';
    }
}
fAttach.addEventListener('change', () => checkOversize(fAttach));
document.querySelectorAll('.attach-add-input').forEach((el) => {
    el.addEventListener('change', () => checkOversize(el));
});

</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
