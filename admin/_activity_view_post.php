<?php
// Partial: POST action handler ของ admin/activity_view.php — include จากไฟล์นั้นเท่านั้น ห้ามเรียกตรง
if (!defined('APP_URL') || !function_exists('require_role')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var int $id ส่งผ่าน scope จาก activity_view.php */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'delete_photo') {
        $photo_id = (int)($_POST['photo_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM activity_photos WHERE id = :id AND activity_id = :a');
        $stmt->execute([':id' => $photo_id, ':a' => $id]);
        $photo = $stmt->fetch();
        if ($photo) {
            $pdo->prepare('DELETE FROM activity_photos WHERE id = :id')->execute([':id' => $photo_id]);
            audit_log('delete_photo', 'activity_photos', $photo_id, $photo, null);
            if (($photo['source'] ?? 'upload') === 'upload' && !empty($photo['filename'])) {
                safe_unlink_upload('activities', $photo['filename']);
            }
            flash_set('success', 'ลบภาพสำเร็จ');
        }

    } elseif ($action === 'add_drive_photo') {
        $drive_url = trim((string)($_POST['drive_url'] ?? ''));
        $parsed    = parse_url($drive_url);
        $host      = strtolower((string)($parsed['host'] ?? ''));
        $allowed_hosts = ['drive.google.com', 'docs.google.com'];

        if ($drive_url === '' || mb_strlen($drive_url) > 500
            || !$parsed
            || ($parsed['scheme'] ?? '') !== 'https'
            || !filter_var($drive_url, FILTER_VALIDATE_URL)
            || !in_array($host, $allowed_hosts, true)) {
            flash_set('error', 'ลิงก์ Drive ไม่ถูกต้อง (ต้องเป็น https://drive.google.com/... หรือ https://docs.google.com/...)');
        } else {
            // หา sort_order ถัดไปจาก drive_link เท่านั้น (ไม่ชนกับ upload ที่จำกัด 1-5)
            $next = $pdo->prepare(
                "SELECT COALESCE(MAX(sort_order),0)+1 FROM activity_photos
                 WHERE activity_id = :a AND source = 'drive_link'"
            );
            $next->execute([':a' => $id]);
            $sort_order = (int)$next->fetchColumn();
            if ($sort_order < 1) $sort_order = 1;

            $stmt = $pdo->prepare(
                "INSERT INTO activity_photos (activity_id, source, drive_url, sort_order)
                 VALUES (:a, 'drive_link', :u, :s)"
            );
            $stmt->execute([':a' => $id, ':u' => $drive_url, ':s' => $sort_order]);
            $photo_id = (int)$pdo->lastInsertId();
            audit_log('add_drive_photo', 'activity_photos', $photo_id, null, [
                'activity_id' => $id, 'drive_url' => $drive_url, 'sort_order' => $sort_order,
            ]);
            flash_set('success', 'เพิ่มลิงก์ Drive สำเร็จ');
        }

    } elseif ($action === 'add_attachment') {
        $type  = (string)($_POST['type'] ?? '');
        $label = trim((string)($_POST['label'] ?? ''));

        if (!in_array($type, ['file', 'url'], true)) {
            flash_set('error', 'ประเภทไฟล์แนบไม่ถูกต้อง');
        } elseif ($label === '' || mb_strlen($label) > 255) {
            flash_set('error', 'กรุณาใส่ชื่อไฟล์แนบ (ไม่เกิน 255 ตัว)');
        } elseif ($type === 'url') {
            $url = trim((string)($_POST['url'] ?? ''));
            $parsed = parse_url($url);
            if ($url === '' || mb_strlen($url) > 500
                || !$parsed || !in_array($parsed['scheme'] ?? '', ['http','https'], true)
                || !filter_var($url, FILTER_VALIDATE_URL)) {
                flash_set('error', 'URL ไม่ถูกต้อง (ต้องเป็น http:// หรือ https://)');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO activity_attachments (activity_id, type, label, url)
                     VALUES (:a, "url", :l, :u)'
                );
                $stmt->execute([':a' => $id, ':l' => $label, ':u' => $url]);
                $att_id = (int)$pdo->lastInsertId();
                audit_log('add_attachment_url', 'activity_attachments', $att_id, null, [
                    'activity_id' => $id, 'type' => 'url', 'label' => $label, 'url' => $url,
                ]);
                flash_set('success', 'เพิ่มลิงก์ "' . $label . '" สำเร็จ');
            }
        } else {
            $file = $_FILES['file'] ?? null;
            if (!$file) {
                flash_set('error', 'ไม่ได้เลือกไฟล์');
            } else {
                $max_doc = mb_to_bytes((int)($_ENV['UPLOAD_MAX_DOC_MB'] ?? 10));
                $allowed = UPLOAD_DOC_MIMES + UPLOAD_IMAGE_MIMES;
                $check   = validate_uploaded_file($file, $allowed, $max_doc);
                if (!$check['ok']) {
                    flash_set('error', $check['error']);
                } else {
                    try {
                        $filename = move_uploaded_with_uuid($file, 'activities', $check['ext']);
                        $stmt = $pdo->prepare(
                            'INSERT INTO activity_attachments (activity_id, type, label, filename)
                             VALUES (:a, "file", :l, :f)'
                        );
                        $stmt->execute([':a' => $id, ':l' => $label, ':f' => $filename]);
                        $att_id = (int)$pdo->lastInsertId();
                        audit_log('add_attachment_file', 'activity_attachments', $att_id, null, [
                            'activity_id' => $id, 'type' => 'file', 'label' => $label,
                            'filename' => $filename, 'mime' => $check['mime'], 'size' => $check['size'],
                        ]);
                        flash_set('success', 'อัปโหลด "' . $label . '" สำเร็จ');
                    } catch (Throwable $e) {
                        error_log('[activity_view add_attachment] ' . $e->getMessage());
                        flash_set('error', 'อัปโหลดไม่สำเร็จ');
                    }
                }
            }
        }

    } elseif ($action === 'delete_attachment') {
        $att_id = (int)($_POST['attachment_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT * FROM activity_attachments WHERE id = :id AND activity_id = :a'
        );
        $stmt->execute([':id' => $att_id, ':a' => $id]);
        $att = $stmt->fetch();
        if ($att) {
            $pdo->prepare('DELETE FROM activity_attachments WHERE id = :id')->execute([':id' => $att_id]);
            audit_log('delete_attachment', 'activity_attachments', $att_id, $att, null);
            if ($att['type'] === 'file' && !empty($att['filename'])) {
                safe_unlink_upload('activities', $att['filename']);
            }
            flash_set('success', 'ลบไฟล์แนบสำเร็จ');
        }

    } elseif ($action === 'add_participants') {
        $user_ids = $_POST['user_ids'] ?? [];
        if (!is_array($user_ids) || empty($user_ids)) {
            flash_set('error', 'กรุณาเลือกผู้ใช้อย่างน้อย 1 คน');
        } else {
            $user_ids = array_filter(array_map('intval', $user_ids), fn($x) => $x > 0);
            $user_ids = array_values(array_unique($user_ids));

            $pdo->beginTransaction();
            try {
                $added = 0;
                $email_count = 0;
                foreach ($user_ids as $uid) {
                    $uchk = $pdo->prepare('SELECT 1 FROM users WHERE id = :id AND is_active = 1');
                    $uchk->execute([':id' => $uid]);
                    if (!$uchk->fetch()) continue;

                    $exists = $pdo->prepare(
                        'SELECT 1 FROM activity_registrations WHERE activity_id = :a AND user_id = :u'
                    );
                    $exists->execute([':a' => $id, ':u' => $uid]);
                    if ($exists->fetch()) continue;

                    $ins = $pdo->prepare(
                        'INSERT INTO activity_registrations
                            (activity_id, user_id, status, checked_by, checked_at)
                         VALUES (:a, :u, "attended", :cb, NOW())'
                    );
                    $ins->execute([
                        ':a'  => $id,
                        ':u'  => $uid,
                        ':cb' => (int)$_SESSION['user_id'],
                    ]);
                    $reg_id = (int)$pdo->lastInsertId();

                    audit_log('add_participant', 'activity_registrations', $reg_id, null, [
                        'activity_id' => $id, 'user_id' => $uid,
                    ]);

                    if (enqueue_new_activity_email($uid, $id)) {
                        $email_count++;
                    }
                    notify_new_activity($uid, $id);
                    $added++;
                }
                $pdo->commit();

                $msg = "เพิ่มผู้เข้าร่วม {$added} คน";
                if ($email_count > 0) {
                    $msg .= " · ส่งอีเมลแจ้งเตือน {$email_count} ฉบับ";
                } elseif ($added > 0) {
                    $msg .= " · ไม่ได้ส่งอีเมล (ปิดใน notification settings)";
                }
                flash_set('success', $msg);
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

    } elseif ($action === 'update_attendance') {
        $reg_ids = $_POST['reg_ids'] ?? [];
        $new_status = $_POST['new_status'] ?? '';
        if (!in_array($new_status, ['registered','attended','absent'], true)) {
            flash_set('error', 'สถานะไม่ถูกต้อง');
        } elseif (!is_array($reg_ids) || empty($reg_ids)) {
            flash_set('error', 'กรุณาเลือกผู้เข้าร่วม');
        } else {
            $reg_ids = array_filter(array_map('intval', $reg_ids), fn($x) => $x > 0);
            $reg_ids = array_values(array_unique($reg_ids));

            $pdo->beginTransaction();
            try {
                $updated = 0;
                foreach ($reg_ids as $rid) {
                    $old = $pdo->prepare(
                        'SELECT * FROM activity_registrations WHERE id = :id AND activity_id = :a'
                    );
                    $old->execute([':id' => $rid, ':a' => $id]);
                    $old_row = $old->fetch();
                    if (!$old_row) continue;

                    if ($new_status === 'registered') {
                        $pdo->prepare(
                            'UPDATE activity_registrations
                             SET status = "registered", checked_by = NULL, checked_at = NULL
                             WHERE id = :id'
                        )->execute([':id' => $rid]);
                    } else {
                        $pdo->prepare(
                            'UPDATE activity_registrations
                             SET status = :s, checked_by = :cb, checked_at = NOW()
                             WHERE id = :id'
                        )->execute([
                            ':s' => $new_status,
                            ':cb' => (int)$_SESSION['user_id'],
                            ':id' => $rid,
                        ]);
                    }
                    audit_log('update_attendance', 'activity_registrations', $rid,
                        ['status' => $old_row['status']],
                        ['status' => $new_status]
                    );
                    $updated++;
                }
                $pdo->commit();
                $label = ['registered'=>'รอเช็ค', 'attended'=>'มา', 'absent'=>'ไม่เข้าร่วม'][$new_status];
                flash_set('success', "อัปเดตสถานะ \"{$label}\" จำนวน {$updated} รายการ");
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

    } elseif ($action === 'remove_participant') {
        $reg_id = (int)($_POST['reg_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT * FROM activity_registrations WHERE id = :id AND activity_id = :a'
        );
        $stmt->execute([':id' => $reg_id, ':a' => $id]);
        $old_row = $stmt->fetch();
        if ($old_row) {
            $pdo->prepare('DELETE FROM activity_registrations WHERE id = :id')
                ->execute([':id' => $reg_id]);
            audit_log('remove_participant', 'activity_registrations', $reg_id, $old_row, null);
            flash_set('success', 'ลบผู้เข้าร่วมสำเร็จ');
        }

    } elseif ($action === 'remove_participants_bulk') {
        $reg_ids = $_POST['reg_ids'] ?? [];
        if (!is_array($reg_ids) || empty($reg_ids)) {
            flash_set('error', 'กรุณาเลือกผู้เข้าร่วมที่จะลบ');
        } else {
            $reg_ids = array_filter(array_map('intval', $reg_ids), fn($x) => $x > 0);
            $reg_ids = array_values(array_unique($reg_ids));

            $pdo->beginTransaction();
            try {
                $removed = 0;
                foreach ($reg_ids as $rid) {
                    $stmt = $pdo->prepare(
                        'SELECT * FROM activity_registrations WHERE id = :id AND activity_id = :a'
                    );
                    $stmt->execute([':id' => $rid, ':a' => $id]);
                    $old_row = $stmt->fetch();
                    if (!$old_row) continue;

                    $pdo->prepare('DELETE FROM activity_registrations WHERE id = :id')
                        ->execute([':id' => $rid]);
                    audit_log('remove_participant', 'activity_registrations', $rid, $old_row, null);
                    $removed++;
                }
                $pdo->commit();
                flash_set('success', "ลบผู้เข้าร่วม {$removed} คน");
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

    } elseif ($action === 'upload_cert') {
        $cert_user_id = (int)($_POST['cert_user_id'] ?? 0);
        $file = $_FILES['cert_file'] ?? null;
        if ($cert_user_id <= 0) {
            flash_set('error', 'กรุณาระบุผู้รับเกียรติบัตร');
        } elseif (!$file) {
            flash_set('error', 'ไม่ได้เลือกไฟล์');
        } else {
            $usr = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $usr->execute([':id' => $cert_user_id]);
            $is_participant = $pdo->prepare(
                'SELECT 1 FROM activity_registrations
                 WHERE activity_id = :a AND user_id = :u LIMIT 1'
            );
            $is_participant->execute([':a' => $id, ':u' => $cert_user_id]);

            if (!$usr->fetch()) {
                flash_set('error', 'ไม่พบผู้ใช้');
            } elseif (!$is_participant->fetch()) {
                flash_set('error', 'ผู้ใช้นี้ไม่ได้เป็นผู้เข้าร่วมกิจกรรมนี้ — ออกเกียรติบัตรให้ได้เฉพาะผู้เข้าร่วมเท่านั้น');
            } else {
                $dup = $pdo->prepare(
                    'SELECT id FROM certificates WHERE activity_id = :a AND user_id = :u LIMIT 1'
                );
                $dup->execute([':a' => $id, ':u' => $cert_user_id]);
                if ($dup->fetch()) {
                    flash_set('error', 'ผู้ใช้นี้มีเกียรติบัตรสำหรับกิจกรรมนี้แล้ว');
                } else {
                    $check = validate_uploaded_file($file, UPLOAD_CERT_MIMES, mb_to_bytes(10));
                    if ($check['ok']) {
                        $per_limit = $check['ext'] === 'pdf'
                            ? mb_to_bytes((int)($_ENV['UPLOAD_MAX_DOC_MB'] ?? 10))
                            : mb_to_bytes((int)($_ENV['UPLOAD_MAX_IMAGE_MB'] ?? 5));
                        if ($check['size'] > $per_limit) {
                            $max_mb = $check['ext'] === 'pdf' ? 10 : 5;
                            $check  = ['ok' => false, 'error' => "ไฟล์ใหญ่เกิน {$max_mb} MB"];
                        }
                    }
                    if (!$check['ok']) {
                        flash_set('error', $check['error']);
                    } else {
                        $original_name = basename((string)($file['name'] ?? ''));
                        if ($original_name === '' || mb_strlen($original_name) > 255) {
                            $original_name = 'certificate.' . $check['ext'];
                        }
                        try {
                            $filename = move_uploaded_with_uuid($file, 'certificates', $check['ext']);
                            $ins = $pdo->prepare(
                                'INSERT INTO certificates
                                   (activity_id, user_id, filename, original_name, uploaded_by)
                                 VALUES (:a, :u, :f, :o, :by)'
                            );
                            $ins->execute([
                                ':a'  => $id,
                                ':u'  => $cert_user_id,
                                ':f'  => $filename,
                                ':o'  => $original_name,
                                ':by' => (int)$_SESSION['user_id'],
                            ]);
                            $cert_id = (int)$pdo->lastInsertId();
                            audit_log('upload_cert', 'certificates', $cert_id, null, [
                                'activity_id' => $id,
                                'user_id'     => $cert_user_id,
                                'filename'    => $filename,
                                'mime'        => $check['mime'],
                                'size'        => $check['size'],
                            ]);
                            enqueue_new_certificate_email($cert_user_id, $id);
                            notify_new_certificate($cert_user_id, $id);
                            flash_set('success', 'อัปโหลดเกียรติบัตรสำเร็จ');
                        } catch (Throwable $e) {
                            error_log('[activity_view upload_cert] ' . $e->getMessage());
                            flash_set('error', 'อัปโหลดไม่สำเร็จ');
                        }
                    }
                }
            }
        }

    } elseif ($action === 'delete_cert') {
        $cert_id  = (int)($_POST['cert_id'] ?? 0);
        $cert_row = $pdo->prepare(
            'SELECT * FROM certificates WHERE id = :id AND activity_id = :a LIMIT 1'
        );
        $cert_row->execute([':id' => $cert_id, ':a' => $id]);
        $cert = $cert_row->fetch();
        if ($cert) {
            $pdo->prepare('DELETE FROM certificates WHERE id = :id')->execute([':id' => $cert_id]);
            audit_log('delete_cert', 'certificates', $cert_id, $cert, null);
            safe_unlink_upload('certificates', $cert['filename']);
            flash_set('success', 'ลบเกียรติบัตรสำเร็จ');
        }
    }

    $tab = $_POST['_tab'] ?? '';
    header('Location: ' . APP_URL . '/admin/activity_view.php?id=' . $id
        . ($tab !== '' ? '#' . urlencode($tab) : ''));
    exit;
}
