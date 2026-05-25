<?php
// Partial: POST action handler ของ employee/personal_activities.php — include จากไฟล์นั้นเท่านั้น
if (!defined('APP_URL') || !function_exists('require_role')) {
    http_response_code(403);
    exit('Forbidden');
}

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
    $process_attachments = function(int $activity_id) use ($pdo): void {
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
            } catch (Throwable) {
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

    // ---- delete attachment (รองรับทั้ง file และ url) ----
    if ($action === 'delete_attachment') {
        $att_id = (int)($_POST['attachment_id'] ?? 0);
        $own = $pdo->prepare(
            'SELECT att.id, att.type, att.filename, att.label, att.url, a.id AS activity_id
             FROM activity_attachments att
             JOIN activities a ON a.id = att.activity_id
             WHERE att.id = :id
               AND a.scope = "personal" AND a.created_by = :u
             LIMIT 1'
        );
        $own->execute([':id' => $att_id, ':u' => $uid]);
        $row = $own->fetch();
        if ($row) {
            $pdo->prepare('DELETE FROM activity_attachments WHERE id = :id')->execute([':id' => $att_id]);
            if (($row['type'] ?? '') === 'file' && !empty($row['filename'])) {
                safe_unlink_upload('activities', (string)$row['filename']);
            }
            audit_log('delete_personal_attachment', 'activity_attachments', $att_id,
                ['type' => $row['type'], 'label' => $row['label'], 'filename' => $row['filename'], 'url' => $row['url']], null);
            flash_set('success', ($row['type'] ?? '') === 'url' ? 'ลบลิงก์สำเร็จ' : 'ลบไฟล์แนบสำเร็จ');
        } else {
            flash_set('error', 'ไม่พบไฟล์แนบ/ลิงก์หรือไม่มีสิทธิ์ลบ');
        }
        header('Location: ' . APP_URL . '/employee/personal_activities.php');
        exit;
    }

    // ---- add link attachment (inline จากการ์ดในหน้า list) ----
    if ($action === 'add_link') {
        $act_id = (int)($_POST['activity_id'] ?? 0);
        $label  = trim((string)($_POST['link_label'] ?? ''));
        $url    = trim((string)($_POST['link_url'] ?? ''));

        $own = $pdo->prepare(
            'SELECT id, title FROM activities
             WHERE id = :id AND scope = "personal" AND created_by = :u LIMIT 1'
        );
        $own->execute([':id' => $act_id, ':u' => $uid]);
        $act_row = $own->fetch();
        if (!$act_row) {
            flash_set('error', 'ไม่พบกิจกรรมหรือไม่มีสิทธิ์เพิ่มลิงก์');
            header('Location: ' . APP_URL . '/employee/personal_activities.php');
            exit;
        }

        $existing = $pdo->prepare('SELECT COUNT(*) FROM activity_attachments WHERE activity_id = :a');
        $existing->execute([':a' => $act_id]);
        $current_total = (int)$existing->fetchColumn();

        $parsed = parse_url($url);
        $valid_url = ($url !== '' && mb_strlen($url) <= 500
            && $parsed && ($parsed['scheme'] ?? '') === 'https'
            && filter_var($url, FILTER_VALIDATE_URL));

        if ($label === '' || mb_strlen($label) > 100) {
            flash_set('error', 'กรุณากรอกชื่อลิงก์ (ไม่เกิน 100 ตัวอักษร)');
        } elseif (!$valid_url) {
            flash_set('error', 'ลิงก์ไม่ถูกต้อง (ต้องเป็น https:// และยาวไม่เกิน 500 ตัวอักษร)');
        } elseif ($current_total >= PA_ATTACH_MAX_FILES) {
            flash_set('error', 'แนบเกินจำนวนสูงสุด (' . PA_ATTACH_MAX_FILES . ' รายการต่อกิจกรรม)');
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO activity_attachments (activity_id, type, label, url)
                 VALUES (:a, 'url', :l, :u)"
            );
            $ins->execute([':a' => $act_id, ':l' => $label, ':u' => $url]);
            $new_att_id = (int)$pdo->lastInsertId();
            audit_log('add_personal_link', 'activity_attachments', $new_att_id, null,
                ['activity_id' => $act_id, 'label' => $label, 'url' => $url]);
            flash_set('success', 'เพิ่มลิงก์สำเร็จ');
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
        $format      = trim((string)($_POST['format'] ?? 'onsite'));
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

        if (!in_array($format, PA_VALID_FORMATS, true)) $errors[] = 'รูปแบบกิจกรรมไม่ถูกต้อง';

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
                    (title, description, location, activity_type_id, format, fiscal_year_id,
                     scope, is_open_registration, start_datetime, end_datetime, created_by)
                 VALUES (:t,:desc,:loc,:type,:fmt,:fy,"personal",0,:s,:e,:cb)'
            );
            $stmt->execute([
                ':t'=>$title, ':desc'=>$description, ':loc'=>$location,
                ':type'=>$type_id, ':fmt'=>$format, ':fy'=>$fiscal_id,
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
                    activity_type_id=:type, format=:fmt, fiscal_year_id=:fy,
                    start_datetime=:s, end_datetime=:e
                 WHERE id=:id AND scope="personal" AND created_by=:u'
            );
            $upd->execute([
                ':t'=>$title, ':desc'=>$description, ':loc'=>$location,
                ':type'=>$type_id, ':fmt'=>$format, ':fy'=>$fiscal_id,
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
            'SELECT id, title FROM activities WHERE id = :id AND scope = "personal" AND created_by = :u LIMIT 1'
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
