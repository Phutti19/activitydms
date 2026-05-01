<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/mailer.php';

require_role('admin');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('error', 'ไม่พบกิจกรรม');
    header('Location: ' . APP_URL . '/admin/manage_activities.php');
    exit;
}

$pre_check = db()->prepare(
    'SELECT id FROM activities WHERE id = :id AND scope = "organization" LIMIT 1'
);
$pre_check->execute([':id' => $id]);
if (!$pre_check->fetch()) {
    flash_set('error', 'ไม่พบกิจกรรม');
    header('Location: ' . APP_URL . '/admin/manage_activities.php');
    exit;
}

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
            safe_unlink_upload('activities', $photo['filename']);
            flash_set('success', 'ลบภาพสำเร็จ');
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
                        flash_set('error', 'อัปโหลดไม่สำเร็จ: ' . $e->getMessage());
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
                        'INSERT INTO activity_registrations (activity_id, user_id, status)
                         VALUES (:a, :u, "registered")'
                    );
                    $ins->execute([':a' => $id, ':u' => $uid]);
                    $reg_id = (int)$pdo->lastInsertId();

                    audit_log('add_participant', 'activity_registrations', $reg_id, null, [
                        'activity_id' => $id, 'user_id' => $uid,
                    ]);

                    if (enqueue_new_activity_email($uid, $id) !== null) {
                        $email_count++;
                    }
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
                $label = ['registered'=>'รอเช็ค', 'attended'=>'มา', 'absent'=>'ขาด'][$new_status];
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
            if (!$usr->fetch()) {
                flash_set('error', 'ไม่พบผู้ใช้');
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

$stmt = db()->prepare(
    'SELECT a.*, t.name AS type_name, t.color AS type_color,
            fy.name AS fiscal_name,
            TRIM(CONCAT_WS(" ", u.prefix_name, u.first_name, u.last_name)) AS creator_name
     FROM activities a
     LEFT JOIN activity_types t ON t.id = a.activity_type_id
     LEFT JOIN fiscal_years fy  ON fy.id = a.fiscal_year_id
     LEFT JOIN users u          ON u.id = a.created_by
     WHERE a.id = :id LIMIT 1'
);
$stmt->execute([':id' => $id]);
$activity = $stmt->fetch();

$photos = db()->prepare(
    'SELECT * FROM activity_photos WHERE activity_id = :id ORDER BY sort_order ASC, id ASC'
);
$photos->execute([':id' => $id]);
$photos = $photos->fetchAll();

$attachments = db()->prepare(
    'SELECT * FROM activity_attachments WHERE activity_id = :id ORDER BY id ASC'
);
$attachments->execute([':id' => $id]);
$attachments = $attachments->fetchAll();

$reg_stmt = db()->prepare(
    'SELECT r.id AS reg_id, r.status, r.registered_at, r.checked_at,
            u.id AS uid, u.username, u.email, u.role,
            TRIM(CONCAT_WS(" ", u.prefix_name, u.first_name, u.last_name)) AS fullname,
            d.name AS dept_name,
            TRIM(CONCAT_WS(" ", cu.prefix_name, cu.first_name, cu.last_name)) AS checker_name
     FROM activity_registrations r
     JOIN users u ON u.id = r.user_id
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN users cu ON cu.id = r.checked_by
     WHERE r.activity_id = :a
     ORDER BY u.role, u.first_name'
);
$reg_stmt->execute([':a' => $id]);
$registrations = $reg_stmt->fetchAll();

$avail_stmt = db()->prepare(
    'SELECT u.id, u.username, u.email, u.role, u.department_id AS dept_id,
            TRIM(CONCAT_WS(" ", u.prefix_name, u.first_name, u.last_name)) AS fullname,
            d.name AS dept_name
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.is_active = 1
       AND u.id NOT IN (
         SELECT user_id FROM activity_registrations WHERE activity_id = :a
       )
     ORDER BY d.id, u.first_name'
);
$avail_stmt->execute([':a' => $id]);
$available_users = $avail_stmt->fetchAll();

$attended_count = 0; $absent_count = 0; $registered_count = 0;
foreach ($registrations as $r) {
    if      ($r['status'] === 'attended') $attended_count++;
    elseif  ($r['status'] === 'absent')   $absent_count++;
    else                                  $registered_count++;
}

$photo_count   = count($photos);
$can_add_photo = $photo_count < 5;

$certs_raw = db()->prepare(
    "SELECT c.*, TRIM(CONCAT_WS(' ', u.prefix_name, u.first_name, u.last_name)) AS user_name,
            u.username
     FROM certificates c
     JOIN users u ON u.id = c.user_id
     WHERE c.activity_id = :a
     ORDER BY u.first_name"
);
$certs_raw->execute([':a' => $id]);
$all_certs    = $certs_raw->fetchAll();
$cert_by_user = array_column($all_certs, null, 'user_id');
$cert_count   = count($all_certs);

$max_cert_pdf_mb = (int)($_ENV['UPLOAD_MAX_DOC_MB'] ?? 10);
$max_cert_img_mb = (int)($_ENV['UPLOAD_MAX_IMAGE_MB'] ?? 5);

function format_date_th(string $datetime): string {
    $ts = strtotime($datetime);
    $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' '
         . (date('Y', $ts) + 543) . ', ' . date('H:i', $ts);
}

$type_color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$activity['type_color'])
    ? $activity['type_color'] : '#5F5E5A';

$max_image_mb = (int)($_ENV['UPLOAD_MAX_IMAGE_MB'] ?? 5);
$max_doc_mb   = (int)($_ENV['UPLOAD_MAX_DOC_MB'] ?? 10);

$page_title  = $activity['title'];
$page_active = 'activities';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="flex-grow-1">
        <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/admin/manage_activities.php"
           class="text-muted small text-decoration-none">
            <i class="bi bi-arrow-left"></i> กลับสู่รายการ
        </a>
        <h1 class="page-title mt-1"><?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/admin/activity_form.php?action=edit&id=<?= $id ?>"
       class="btn btn-outline-primary">
        <i class="bi bi-pencil me-1"></i> แก้ไข
    </a>
</div>

<ul class="nav nav-tabs mb-3" id="activityTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button">
            <i class="bi bi-info-circle me-1"></i> ภาพรวม
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-photos" type="button">
            <i class="bi bi-image me-1"></i> ภาพ <span class="badge bg-secondary"><?= $photo_count ?>/5</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-attachments" type="button">
            <i class="bi bi-paperclip me-1"></i> ไฟล์แนบ <span class="badge bg-secondary"><?= count($attachments) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-attendance" type="button">
            <i class="bi bi-people me-1"></i> ผู้เข้าร่วม <span class="badge bg-secondary"><?= count($registrations) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-certs" type="button">
            <i class="bi bi-award me-1"></i> เกียรติบัตร <span class="badge bg-secondary"><?= $cert_count ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tab-overview">
        <div class="card p-3 p-md-4">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <div class="text-muted small">ประเภท</div>
                    <span class="badge-pill" style="background:<?= $type_color ?>1A;color:<?= $type_color ?>;">
                        <?= htmlspecialchars($activity['type_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">ปีงบประมาณ</div>
                    <div><?= htmlspecialchars($activity['fiscal_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">เริ่มต้น</div>
                    <div><i class="bi bi-calendar3 me-1"></i>
                        <?= htmlspecialchars(format_date_th($activity['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">สิ้นสุด</div>
                    <div><i class="bi bi-calendar3 me-1"></i>
                        <?= htmlspecialchars(format_date_th($activity['end_datetime']), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
                <?php if (!empty($activity['location'])): ?>
                <div class="col-12">
                    <div class="text-muted small">สถานที่</div>
                    <div><i class="bi bi-geo-alt me-1"></i>
                        <?= htmlspecialchars($activity['location'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($activity['description'])): ?>
                <div class="col-12">
                    <div class="text-muted small">รายละเอียด</div>
                    <div style="white-space: pre-wrap;"><?=
                        htmlspecialchars($activity['description'], ENT_QUOTES, 'UTF-8')
                    ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($activity['external_url'])): ?>
                <div class="col-12">
                    <div class="text-muted small">ลิงก์ภายนอก</div>
                    <a href="<?= htmlspecialchars($activity['external_url'], ENT_QUOTES, 'UTF-8') ?>"
                       target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-box-arrow-up-right me-1"></i>
                        <?= htmlspecialchars($activity['external_url'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
                <?php endif; ?>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">การสมัคร</div>
                    <?php if ((int)$activity['is_open_registration'] === 1): ?>
                        <span class="badge bg-success">เปิดให้พนักงานสมัครเอง</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Admin เพิ่มผู้เข้าร่วมเอง</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">สร้างโดย</div>
                    <div><?= htmlspecialchars($activity['creator_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-photos">
        <?php if ($can_add_photo): ?>
        <div id="dropZone" class="card p-4 text-center mb-3"
             style="border: 2px dashed #CBD5E1; background: #F8FAFC; cursor: pointer;">
            <i class="bi bi-cloud-arrow-up" style="font-size: 32px; color: #94A3B8;"></i>
            <div class="mt-2 fw-medium">คลิกหรือลากภาพมาวางที่นี่</div>
            <div class="text-muted small">JPG / PNG / WEBP — สูงสุด <?= $max_image_mb ?> MB ต่อไฟล์</div>
            <div class="text-muted small">เพิ่มได้อีก <span id="remainSlot"><?= 5 - $photo_count ?></span> ภาพ จาก 5</div>
            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp"
                   multiple capture="environment" style="display:none;">
        </div>
        <div id="uploadStatus"></div>
        <?php else: ?>
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            ครบ 5 ภาพแล้ว — ลบบางภาพก่อนถ้าต้องการเพิ่มภาพใหม่
        </div>
        <?php endif; ?>

        <div id="photoGrid" class="row g-2">
            <?php foreach ($photos as $p):
                $orig_safe = htmlspecialchars($p['original_name'], ENT_QUOTES, 'UTF-8');
            ?>
            <div class="col-6 col-md-4 col-lg-3" data-photo-id="<?= (int)$p['id'] ?>">
                <div class="card h-100 overflow-hidden position-relative">
                    <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/api/download.php?type=photo&id=<?= (int)$p['id'] ?>"
                       target="_blank">
                        <img src="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/api/download.php?type=photo&id=<?= (int)$p['id'] ?>"
                             alt="<?= $orig_safe ?>"
                             style="width:100%;aspect-ratio:1;object-fit:cover;display:block;">
                    </a>
                    <form method="POST" class="position-absolute top-0 end-0 m-1"
                          onsubmit="return confirm('ลบภาพนี้?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_photo">
                        <input type="hidden" name="photo_id" value="<?= (int)$p['id'] ?>">
                        <input type="hidden" name="_tab" value="tab-photos">
                        <button type="submit" class="btn btn-sm btn-danger" title="ลบ" style="opacity:0.85;">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </form>
                    <div class="p-2 small text-muted text-truncate" title="<?= $orig_safe ?>">
                        <?= $orig_safe ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($photos)): ?>
                <div class="col-12 text-center text-muted py-4">
                    <i class="bi bi-image" style="font-size:48px;opacity:0.3;"></i>
                    <p class="mt-2 mb-0">ยังไม่มีภาพ</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-attachments">
        <div class="card p-3 p-md-4 mb-3">
            <h6 class="fw-semibold mb-3">เพิ่มไฟล์แนบ / ลิงก์</h6>
            <form method="POST" enctype="multipart/form-data" id="attachForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_attachment">
                <input type="hidden" name="_tab" value="tab-attachments">

                <div class="mb-3">
                    <div class="form-check form-check-inline">
                        <input type="radio" id="attTypeFile" name="type" value="file" class="form-check-input" checked>
                        <label for="attTypeFile" class="form-check-label">ไฟล์อัปโหลด</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input type="radio" id="attTypeUrl" name="type" value="url" class="form-check-input">
                        <label for="attTypeUrl" class="form-check-label">URL ภายนอก</label>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <label for="attLabel" class="form-label small fw-medium">
                            ชื่อที่แสดง <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="attLabel" name="label" class="form-control" required maxlength="255"
                               placeholder="เช่น สไลด์การอบรม / สรุปการประชุม">
                    </div>
                    <div class="col-12 col-md-6" id="attFileWrap">
                        <label for="attFile" class="form-label small fw-medium">
                            ไฟล์ <span class="text-danger">*</span>
                        </label>
                        <input type="file" id="attFile" name="file" class="form-control"
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp">
                        <div class="form-text small">PDF / Word / Excel / PPT / รูปภาพ — สูงสุด <?= $max_doc_mb ?> MB</div>
                    </div>
                    <div class="col-12 col-md-6 d-none" id="attUrlWrap">
                        <label for="attUrl" class="form-label small fw-medium">
                            URL <span class="text-danger">*</span>
                        </label>
                        <input type="url" id="attUrl" name="url" class="form-control" maxlength="500"
                               placeholder="https://...">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">
                    <i class="bi bi-plus-lg me-1"></i> เพิ่มไฟล์แนบ
                </button>
            </form>
        </div>

        <?php if (empty($attachments)): ?>
            <div class="card p-5 text-center text-muted">
                <i class="bi bi-paperclip" style="font-size:48px;opacity:0.3;"></i>
                <p class="mt-2 mb-0">ยังไม่มีไฟล์แนบ</p>
            </div>
        <?php else: ?>
        <div class="card">
            <ul class="list-group list-group-flush">
                <?php foreach ($attachments as $att):
                    $label_safe = htmlspecialchars($att['label'], ENT_QUOTES, 'UTF-8');
                    $is_file = ($att['type'] === 'file');
                ?>
                <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                    <i class="bi <?= $is_file ? 'bi-file-earmark' : 'bi-link-45deg' ?>"
                       style="font-size:20px;color:<?= $is_file ? '#3B82F6' : '#10B981' ?>;"></i>
                    <div class="flex-grow-1">
                        <div class="fw-medium"><?= $label_safe ?></div>
                        <?php if ($is_file): ?>
                            <div class="small text-muted text-truncate">
                                <?= htmlspecialchars((string)$att['filename'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars((string)$att['url'], ENT_QUOTES, 'UTF-8') ?>"
                               class="small text-truncate d-inline-block" style="max-width:300px;"
                               target="_blank" rel="noopener noreferrer">
                                <?= htmlspecialchars((string)$att['url'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-1">
                        <?php if ($is_file): ?>
                            <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/api/download.php?type=attachment&id=<?= (int)$att['id'] ?>"
                               class="btn btn-sm btn-outline-primary" target="_blank" title="ดาวน์โหลด">
                                <i class="bi bi-download"></i>
                            </a>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars((string)$att['url'], ENT_QUOTES, 'UTF-8') ?>"
                               class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener noreferrer"
                               title="เปิดลิงก์">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        <?php endif; ?>
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('ลบ &quot;<?= $label_safe ?>&quot;?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_attachment">
                            <input type="hidden" name="attachment_id" value="<?= (int)$att['id'] ?>">
                            <input type="hidden" name="_tab" value="tab-attachments">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <div class="tab-pane fade" id="tab-attendance">
        <div class="d-flex flex-column flex-md-row gap-2 mb-3 align-items-md-center justify-content-between">
            <div class="text-muted small">
                ทั้งหมด <?= count($registrations) ?> คน ·
                <span class="text-success">มา <?= $attended_count ?></span> ·
                <span class="text-danger">ขาด <?= $absent_count ?></span> ·
                <span class="text-warning">รอเช็ค <?= $registered_count ?></span>
            </div>
            <button type="button" class="btn btn-primary"
                    data-bs-toggle="modal" data-bs-target="#addParticipantsModal"
                    <?= empty($available_users) ? 'disabled' : '' ?>>
                <i class="bi bi-person-plus me-1"></i> เพิ่มผู้เข้าร่วม
            </button>
        </div>

        <?php if (empty($registrations)): ?>
            <div class="card p-5 text-center text-muted">
                <i class="bi bi-people" style="font-size:48px;opacity:0.3;"></i>
                <p class="mt-2 mb-0">ยังไม่มีผู้เข้าร่วม — กดปุ่ม "เพิ่มผู้เข้าร่วม"</p>
            </div>
        <?php else: ?>
            <form method="POST" id="attendanceForm" style="margin: 0;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_attendance">
                <input type="hidden" name="_tab" value="tab-attendance">
                <div id="bulkBar" class="card p-2 mb-2 d-none">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="small me-2">เลือก <span id="bulkCount">0</span> คน:</span>
                        <button type="submit" name="new_status" value="attended" class="btn btn-sm btn-success">
                            <i class="bi bi-check-lg"></i> ทำเป็น "มา"
                        </button>
                        <button type="submit" name="new_status" value="absent" class="btn btn-sm btn-danger">
                            <i class="bi bi-x-lg"></i> ทำเป็น "ขาด"
                        </button>
                        <button type="submit" name="new_status" value="registered" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-counterclockwise"></i> รีเซ็ต
                        </button>
                    </div>
                </div>
            </form>

            <div class="card">
                <div class="table-responsive">
                    <table class="table table-stack mb-0 align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th style="width:36px;"><input type="checkbox" id="selAll"></th>
                                <th>ชื่อ-สกุล</th>
                                <th>แผนก</th>
                                <th>สถานะ</th>
                                <th>เช็คโดย</th>
                                <th class="text-end">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registrations as $r):
                                $st = $r['status'];
                                $name_safe = htmlspecialchars($r['fullname'], ENT_QUOTES, 'UTF-8');
                                $st_badge = match($st) {
                                    'attended' => '<span class="badge bg-success">เข้าร่วม</span>',
                                    'absent'   => '<span class="badge bg-danger">ขาด</span>',
                                    default    => '<span class="badge bg-warning text-dark">รอเช็ค</span>',
                                };
                            ?>
                            <tr>
                                <td data-label="">
                                    <input type="checkbox" form="attendanceForm" name="reg_ids[]"
                                           value="<?= (int)$r['reg_id'] ?>" class="reg-check">
                                </td>
                                <td data-label="ชื่อ-สกุล">
                                    <div class="fw-medium"><?= $name_safe ?></div>
                                    <div class="text-muted small">@<?= htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td data-label="แผนก" class="small text-muted">
                                    <?= htmlspecialchars($r['dept_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td data-label="สถานะ"><?= $st_badge ?></td>
                                <td data-label="เช็คโดย" class="small text-muted">
                                    <?php if (!empty($r['checker_name']) && $r['checked_at']): ?>
                                        <?= htmlspecialchars($r['checker_name'], ENT_QUOTES, 'UTF-8') ?><br>
                                        <small><?= htmlspecialchars(format_date_th($r['checked_at']), ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td data-label="จัดการ" class="text-end text-nowrap">
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_attendance">
                                        <input type="hidden" name="_tab" value="tab-attendance">
                                        <input type="hidden" name="reg_ids[]" value="<?= (int)$r['reg_id'] ?>">
                                        <input type="hidden" name="new_status" value="attended">
                                        <button type="submit"
                                                class="btn btn-sm <?= $st==='attended'?'btn-success':'btn-outline-success' ?>"
                                                title="มา"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_attendance">
                                        <input type="hidden" name="_tab" value="tab-attendance">
                                        <input type="hidden" name="reg_ids[]" value="<?= (int)$r['reg_id'] ?>">
                                        <input type="hidden" name="new_status" value="absent">
                                        <button type="submit"
                                                class="btn btn-sm <?= $st==='absent'?'btn-danger':'btn-outline-danger' ?>"
                                                title="ขาด"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('ลบ &quot;<?= $name_safe ?>&quot; ออกจากรายชื่อ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="remove_participant">
                                        <input type="hidden" name="_tab" value="tab-attendance">
                                        <input type="hidden" name="reg_id" value="<?= (int)$r['reg_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="ลบ">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div><!-- /tab-attendance -->

    <div class="tab-pane fade" id="tab-certs">
        <?php if (empty($registrations)): ?>
        <div class="card p-4 text-center text-muted">
            <i class="bi bi-award" style="font-size:40px;opacity:0.3;"></i>
            <p class="mt-2 mb-0">ยังไม่มีผู้เข้าร่วม — เพิ่มผู้เข้าร่วมในแท็บ "ผู้เข้าร่วม" ก่อน</p>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-stack mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>ชื่อ-สกุล</th>
                            <th>การเข้าร่วม</th>
                            <th class="text-end">เกียรติบัตร</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $r):
                            $cert    = $cert_by_user[(int)$r['uid']] ?? null;
                            $nm_safe = htmlspecialchars($r['fullname'], ENT_QUOTES, 'UTF-8');
                            $st_badge = match($r['status']) {
                                'attended' => '<span class="badge bg-success">เข้าร่วม</span>',
                                'absent'   => '<span class="badge bg-danger">ขาด</span>',
                                default    => '<span class="badge bg-warning text-dark">รอเช็ค</span>',
                            };
                        ?>
                        <tr>
                            <td data-label="ชื่อ-สกุล">
                                <div class="fw-medium"><?= $nm_safe ?></div>
                                <div class="text-muted small">@<?= htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td data-label="การเข้าร่วม"><?= $st_badge ?></td>
                            <td data-label="เกียรติบัตร" class="text-end text-nowrap">
                                <?php if ($cert): ?>
                                    <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/api/download.php?type=cert&id=<?= (int)$cert['id'] ?>"
                                       class="btn btn-sm btn-outline-warning" target="_blank" title="ดาวน์โหลด">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('ลบเกียรติบัตรของ &quot;<?= $nm_safe ?>&quot;?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_cert">
                                        <input type="hidden" name="_tab" value="tab-certs">
                                        <input type="hidden" name="cert_id" value="<?= (int)$cert['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary upload-cert-btn"
                                            data-user-id="<?= (int)$r['uid'] ?>"
                                            data-user-name="<?= $nm_safe ?>">
                                        <i class="bi bi-upload me-1"></i> อัปโหลด
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div><!-- /tab-certs -->

</div><!-- /tab-content -->

<div class="modal fade" id="uploadCertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_cert">
                <input type="hidden" name="_tab" value="tab-certs">
                <input type="hidden" name="cert_user_id" id="certUserId">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-award me-1"></i>
                        อัปโหลดเกียรติบัตร — <span id="certUserName"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label for="certFileInput" class="form-label fw-medium">
                        ไฟล์เกียรติบัตร <span class="text-danger">*</span>
                    </label>
                    <input type="file" id="certFileInput" name="cert_file" class="form-control"
                           required accept=".pdf,.jpg,.jpeg,.png">
                    <div class="form-text">
                        PDF (≤ <?= $max_cert_pdf_mb ?> MB) / JPG, PNG (≤ <?= $max_cert_img_mb ?> MB)
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary"
                            data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload me-1"></i> อัปโหลด
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addParticipantsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_participants">
                <input type="hidden" name="_tab" value="tab-attendance">

                <div class="modal-header">
                    <h5 class="modal-title">เพิ่มผู้เข้าร่วม</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" id="userSearch" class="form-control mb-2"
                           placeholder="ค้นหาชื่อ / username / อีเมล / แผนก">
                    <div class="form-text mb-2">
                        เลือก <span id="addSelCount">0</span> คน
                        — ระบบจะส่งอีเมลแจ้งเตือนให้ผู้ที่เพิ่ม (ถ้าเปิดในตั้งค่า)
                    </div>
                    <div class="border rounded" style="max-height: 50vh; overflow-y: auto;">
                        <?php
                        $current_dept_id = -1;
                        foreach ($available_users as $u):
                            $u_dept_id = (int)$u['dept_id'];
                            if ($current_dept_id !== $u_dept_id):
                                $current_dept_id = $u_dept_id;
                        ?>
                            <div class="bg-light px-3 py-2 small fw-semibold border-bottom">
                                <?= htmlspecialchars($u['dept_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php
                            endif;
                            $search_data = mb_strtolower(
                                $u['fullname'] . ' ' . $u['email'] . ' ' . $u['username']
                                . ' ' . ($u['dept_name'] ?? ''),
                                'UTF-8'
                            );
                        ?>
                            <label class="d-flex align-items-center gap-2 px-3 py-2 border-bottom user-item m-0"
                                   data-search="<?= htmlspecialchars($search_data, ENT_QUOTES, 'UTF-8') ?>"
                                   style="cursor: pointer;">
                                <input type="checkbox" name="user_ids[]"
                                       value="<?= (int)$u['id'] ?>" class="user-check">
                                <div class="flex-grow-1">
                                    <div class="small fw-medium">
                                        <?= htmlspecialchars($u['fullname'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                                <span class="badge-pill badge-role-<?= htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= ['admin'=>'Admin','director'=>'Director','employee'=>'Employee'][$u['role']] ?? '' ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (empty($available_users)): ?>
                            <div class="text-center text-muted py-4">ทุกคนถูกเพิ่มในกิจกรรมนี้แล้ว</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary" id="addSubmit" disabled>
                        <i class="bi bi-person-plus me-1"></i> เพิ่ม
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const ACTIVITY_ID = <?= (int)$id ?>;
const CSRF_TOKEN = <?= json_encode(csrf_token(), JSON_HEX_TAG|JSON_HEX_QUOT) ?>;
const CSRF_NAME = <?= json_encode(CSRF_TOKEN_NAME, JSON_HEX_TAG|JSON_HEX_QUOT) ?>;
const APP_BASE = <?= json_encode(APP_URL, JSON_HEX_TAG|JSON_HEX_QUOT) ?>;
const MAX_IMAGE_BYTES = <?= mb_to_bytes($max_image_mb) ?>;

let currentPhotoCount = <?= $photo_count ?>;

const hash = window.location.hash.replace('#', '');
if (hash) {
    const trigger = document.querySelector(`[data-bs-target="#${hash}"]`);
    if (trigger) new bootstrap.Tab(trigger).show();
}

function toggleAttType() {
    const isFile = document.getElementById('attTypeFile').checked;
    document.getElementById('attFileWrap').classList.toggle('d-none', !isFile);
    document.getElementById('attUrlWrap').classList.toggle('d-none', isFile);
    document.getElementById('attFile').required = isFile;
    document.getElementById('attUrl').required = !isFile;
}
document.getElementById('attTypeFile').addEventListener('change', toggleAttType);
document.getElementById('attTypeUrl').addEventListener('change', toggleAttType);
toggleAttType();

// ===== Bulk attendance =====
const selAll = document.getElementById('selAll');
const regChecks = document.querySelectorAll('.reg-check');
const bulkBar = document.getElementById('bulkBar');
const bulkCount = document.getElementById('bulkCount');

function updateBulk() {
    if (!regChecks.length) return;
    const c = document.querySelectorAll('.reg-check:checked').length;
    if (bulkCount) bulkCount.textContent = c;
    if (bulkBar) bulkBar.classList.toggle('d-none', c === 0);
    if (selAll) selAll.checked = (c === regChecks.length && c > 0);
}
if (selAll) {
    selAll.addEventListener('change', () => {
        regChecks.forEach(c => c.checked = selAll.checked);
        updateBulk();
    });
}
regChecks.forEach(c => c.addEventListener('change', updateBulk));

// ===== Add participants modal — search filter + selection counter =====
const userSearch = document.getElementById('userSearch');
const userItems = document.querySelectorAll('.user-item');
if (userSearch) {
    userSearch.addEventListener('input', () => {
        const q = userSearch.value.toLowerCase().trim();
        userItems.forEach(item => {
            const data = item.dataset.search || '';
            item.style.display = (q === '' || data.includes(q)) ? '' : 'none';
        });
    });
}

const userChecks = document.querySelectorAll('.user-check');
const addSelCount = document.getElementById('addSelCount');
const addSubmit = document.getElementById('addSubmit');
function updateAddCount() {
    const c = document.querySelectorAll('.user-check:checked').length;
    if (addSelCount) addSelCount.textContent = c;
    if (addSubmit) addSubmit.disabled = c === 0;
}
userChecks.forEach(c => c.addEventListener('change', updateAddCount));

<?php if ($can_add_photo): ?>
const dropZone = document.getElementById('dropZone');
const photoInput = document.getElementById('photoInput');
const photoGrid = document.getElementById('photoGrid');
const uploadStatus = document.getElementById('uploadStatus');

dropZone.addEventListener('click', () => photoInput.click());
dropZone.addEventListener('dragover', e => {
    e.preventDefault();
    dropZone.style.borderColor = '#0EA5E9';
    dropZone.style.background = '#E0F2FE';
});
dropZone.addEventListener('dragleave', () => {
    dropZone.style.borderColor = '#CBD5E1';
    dropZone.style.background = '#F8FAFC';
});
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.style.borderColor = '#CBD5E1';
    dropZone.style.background = '#F8FAFC';
    handleFiles(e.dataTransfer.files);
});
photoInput.addEventListener('change', e => handleFiles(e.target.files));

function showStatus(text, type = 'info') {
    const cls = type === 'error' ? 'alert-danger' : (type === 'success' ? 'alert-success' : 'alert-info');
    const div = document.createElement('div');
    div.className = `alert ${cls} small alert-dismissible fade show`;
    div.innerHTML = text + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    uploadStatus.appendChild(div);
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(div).close(), 5000);
}

async function handleFiles(files) {
    for (const file of files) {
        if (currentPhotoCount >= 5) {
            showStatus('ครบ 5 ภาพแล้ว — กรุณารีเฟรชหน้า', 'error');
            return;
        }
        if (file.size > MAX_IMAGE_BYTES) {
            showStatus(`${file.name}: ไฟล์ใหญ่เกิน <?= $max_image_mb ?> MB`, 'error');
            continue;
        }
        if (!['image/jpeg','image/png','image/webp'].includes(file.type)) {
            showStatus(`${file.name}: ประเภทไม่อนุญาต (JPG/PNG/WEBP เท่านั้น)`, 'error');
            continue;
        }
        await uploadOne(file);
    }
    photoInput.value = '';
}

async function uploadOne(file) {
    const fd = new FormData();
    fd.append(CSRF_NAME, CSRF_TOKEN);
    fd.append('activity_id', ACTIVITY_ID);
    fd.append('photo', file);

    showStatus(`กำลังอัปโหลด ${file.name}...`, 'info');
    try {
        const res = await fetch(APP_BASE + '/api/activity_upload_photo.php', {
            method: 'POST',
            body: fd,
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'อัปโหลดไม่สำเร็จ');

        currentPhotoCount++;
        appendPhoto(data.photo);
        document.getElementById('remainSlot').textContent = (5 - currentPhotoCount).toString();
        if (currentPhotoCount >= 5) {
            dropZone.style.display = 'none';
        }
        showStatus(`อัปโหลด ${file.name} สำเร็จ`, 'success');
    } catch (e) {
        showStatus(`${file.name}: ${e.message}`, 'error');
    }
}

function appendPhoto(p) {
    const empty = photoGrid.querySelector('.col-12.text-center');
    if (empty) empty.remove();

    const col = document.createElement('div');
    col.className = 'col-6 col-md-4 col-lg-3';
    col.dataset.photoId = p.id;
    const url = APP_BASE + '/api/download.php?type=photo&id=' + p.id;
    const safeOrig = (p.original_name || '').replace(/[<>"']/g, '');
    col.innerHTML = `
        <div class="card h-100 overflow-hidden position-relative">
            <a href="${url}" target="_blank">
                <img src="${url}" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;display:block;">
            </a>
            <form method="POST" class="position-absolute top-0 end-0 m-1"
                  onsubmit="return confirm('ลบภาพนี้?');">
                <input type="hidden" name="${CSRF_NAME}" value="${CSRF_TOKEN}">
                <input type="hidden" name="action" value="delete_photo">
                <input type="hidden" name="photo_id" value="${p.id}">
                <input type="hidden" name="_tab" value="tab-photos">
                <button type="submit" class="btn btn-sm btn-danger" style="opacity:0.85;">
                    <i class="bi bi-x-lg"></i>
                </button>
            </form>
            <div class="p-2 small text-muted text-truncate" title="${safeOrig}">${safeOrig}</div>
        </div>`;
    photoGrid.appendChild(col);
}
<?php endif; ?>

// ===== Cert upload modal =====
document.querySelectorAll('.upload-cert-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('certUserId').value = btn.dataset.userId;
        document.getElementById('certUserName').textContent = btn.dataset.userName;
        const fi = document.getElementById('certFileInput');
        if (fi) fi.value = '';
        new bootstrap.Modal(document.getElementById('uploadCertModal')).show();
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
