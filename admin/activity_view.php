<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

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

// POST action handler แยกไป _activity_view_post.php (ใช้ $id จาก scope นี้)
require __DIR__ . '/_activity_view_post.php';

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

$upload_photos = array_values(array_filter($photos, fn($p) => ($p['source'] ?? 'upload') === 'upload'));
$drive_photos  = array_values(array_filter($photos, fn($p) => ($p['source'] ?? 'upload') === 'drive_link'));
$photo_count   = count($upload_photos);
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
        <a href="<?= h(APP_URL) ?>/admin/manage_activities.php"
           class="text-muted small text-decoration-none">
            <i class="bi bi-arrow-left"></i> กลับสู่รายการ
        </a>
        <h1 class="page-title mt-1"><?= h($activity['title']) ?></h1>
    </div>
    <a href="<?= h(APP_URL) ?>/admin/activity_form.php?action=edit&id=<?= $id ?>"
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
            <i class="bi bi-image me-1"></i> ภาพ
            <span class="badge bg-secondary"><?= $photo_count ?>/5</span>
            <?php if (!empty($drive_photos)): ?>
                <span class="badge bg-info text-dark">+<?= count($drive_photos) ?> Drive</span>
            <?php endif; ?>
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
                        <?= h($activity['type_name'] ?? '—') ?>
                    </span>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">รูปแบบ</div>
                    <?php $_fmt = (string)($activity['format'] ?? 'onsite'); ?>
                    <?php if ($_fmt === 'online'): ?>
                        <span class="badge-pill" style="background:#E0F2FE;color:#0369A1;">
                            <i class="bi bi-camera-video me-1"></i> ออนไลน์
                        </span>
                    <?php else: ?>
                        <span class="badge-pill" style="background:#FEF3C7;color:#92400E;">
                            <i class="bi bi-geo-alt me-1"></i> ออนไซต์
                        </span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">ปีงบประมาณ</div>
                    <div><?= h($activity['fiscal_name'] ?? '—') ?></div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">เริ่มต้น</div>
                    <div><i class="bi bi-calendar3 me-1"></i>
                        <?= h(th_datetime($activity['start_datetime'])) ?>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">สิ้นสุด</div>
                    <div><i class="bi bi-calendar3 me-1"></i>
                        <?= h(th_datetime($activity['end_datetime'])) ?>
                    </div>
                </div>
                <?php if (!empty($activity['location'])): ?>
                <div class="col-12">
                    <div class="text-muted small">สถานที่</div>
                    <div><i class="bi bi-geo-alt me-1"></i>
                        <?= h($activity['location']) ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($activity['description'])): ?>
                <div class="col-12">
                    <div class="text-muted small">รายละเอียด</div>
                    <div style="white-space: pre-wrap;"><?=
                        h($activity['description'])
                    ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($activity['external_url'])): ?>
                <div class="col-12">
                    <div class="text-muted small">ลิงก์ภายนอก</div>
                    <a href="<?= h($activity['external_url']) ?>"
                       target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-box-arrow-up-right me-1"></i>
                        <?= h($activity['external_url']) ?>
                    </a>
                </div>
                <?php endif; ?>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">การเข้าร่วม</div>
                    <?php if ((int)$activity['is_open_registration'] === 1): ?>
                        <span class="badge bg-success">เปิดให้พนักงานเข้าร่วมเอง</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Admin เพิ่มผู้เข้าร่วมเอง</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">สร้างโดย</div>
                    <div><?= h($activity['creator_name'] ?? '—') ?></div>
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
            <div class="text-muted small">เพิ่มได้อีก <span id="remainSlot"><?= 5 - $photo_count ?></span> ภาพ จาก 5 (อัปโหลด)</div>
            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp"
                   multiple capture="environment" style="display:none;">
        </div>
        <div id="uploadStatus"></div>
        <?php else: ?>
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            ครบ 5 ภาพ (อัปโหลด) แล้ว — ลบบางภาพหรือใช้ลิงก์ Drive แทน
        </div>
        <?php endif; ?>

        <div class="card p-3 mb-3">
            <h6 class="fw-semibold mb-2">
                <i class="bi bi-google me-1"></i> เพิ่มลิงก์ Google Drive
            </h6>
            <div class="text-muted small mb-2">รูปจำนวนมาก แนะนำให้แชร์เป็นลิงก์อัลบั้ม Drive — ไม่จำกัดจำนวน</div>
            <form method="POST" class="d-flex flex-column flex-md-row gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_drive_photo">
                <input type="hidden" name="_tab" value="tab-photos">
                <input type="url" name="drive_url" class="form-control" required maxlength="500"
                       placeholder="https://drive.google.com/drive/folders/..."
                       pattern="https://(drive|docs)\.google\.com/.*">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i> เพิ่มลิงก์
                </button>
            </form>
        </div>

        <?php if (!empty($drive_photos)): ?>
        <div class="mb-3">
            <h6 class="fw-semibold mb-2 text-muted small">
                <i class="bi bi-link-45deg me-1"></i> ลิงก์ Drive (<?= count($drive_photos) ?>)
            </h6>
            <div class="list-group">
                <?php foreach ($drive_photos as $dp):
                    $du_safe = h((string)$dp['drive_url']); ?>
                <div class="list-group-item d-flex align-items-center gap-2">
                    <i class="bi bi-google text-primary"></i>
                    <a href="<?= $du_safe ?>" target="_blank" rel="noopener" class="text-truncate flex-grow-1" title="<?= $du_safe ?>">
                        <?= $du_safe ?>
                    </a>
                    <form method="POST" class="m-0" onsubmit="return confirm('ลบลิงก์นี้?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_photo">
                        <input type="hidden" name="photo_id" value="<?= (int)$dp['id'] ?>">
                        <input type="hidden" name="_tab" value="tab-photos">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div id="photoGrid" class="row g-2">
            <?php foreach ($upload_photos as $p):
                $orig_safe = h($p['original_name']);
            ?>
            <div class="col-6 col-md-4 col-lg-3" data-photo-id="<?= (int)$p['id'] ?>">
                <div class="card h-100 overflow-hidden position-relative">
                    <a href="<?= h(APP_URL) ?>/api/download.php?type=photo&id=<?= (int)$p['id'] ?>"
                       target="_blank">
                        <img src="<?= h(APP_URL) ?>/api/download.php?type=photo&id=<?= (int)$p['id'] ?>"
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
            <?php if (empty($upload_photos) && empty($drive_photos)): ?>
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
                    $label_safe = h($att['label']);
                    $is_file = ($att['type'] === 'file');
                ?>
                <li class="list-group-item d-flex align-items-center gap-2 flex-wrap">
                    <i class="bi <?= $is_file ? 'bi-file-earmark' : 'bi-link-45deg' ?>"
                       style="font-size:20px;color:<?= $is_file ? '#3B82F6' : '#10B981' ?>;"></i>
                    <div class="flex-grow-1">
                        <div class="fw-medium"><?= $label_safe ?></div>
                        <?php if ($is_file): ?>
                            <div class="small text-muted text-truncate">
                                <?= h((string)$att['filename']) ?>
                            </div>
                        <?php else: ?>
                            <a href="<?= h((string)$att['url']) ?>"
                               class="small text-truncate d-inline-block" style="max-width:300px;"
                               target="_blank" rel="noopener noreferrer">
                                <?= h((string)$att['url']) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-1">
                        <?php if ($is_file): ?>
                            <a href="<?= h(APP_URL) ?>/api/download.php?type=attachment&id=<?= (int)$att['id'] ?>"
                               class="btn btn-sm btn-outline-primary" target="_blank" title="ดาวน์โหลด">
                                <i class="bi bi-download"></i>
                            </a>
                        <?php else: ?>
                            <a href="<?= h((string)$att['url']) ?>"
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
            <div class="small text-muted">
                ทั้งหมด <strong class="text-body"><?= count($registrations) ?></strong> คน
                <?php if (!empty($registrations)): ?>
                <span class="mx-1">·</span>
                <span title="ลงทะเบียนแล้ว ยังไม่เช็คชื่อ">
                    <span class="d-inline-block rounded-circle bg-warning align-middle" style="width:8px;height:8px;"></span>
                    รอเช็ค <strong class="text-body"><?= $registered_count ?></strong>
                </span>
                <span class="mx-1">·</span>
                <span title="เช็คชื่อแล้วว่าเข้าร่วม">
                    <span class="d-inline-block rounded-circle bg-success align-middle" style="width:8px;height:8px;"></span>
                    เข้าร่วม <strong class="text-body"><?= $attended_count ?></strong>
                </span>
                <span class="mx-1">·</span>
                <span title="เช็คชื่อแล้วว่าไม่เข้าร่วม">
                    <span class="d-inline-block rounded-circle bg-danger align-middle" style="width:8px;height:8px;"></span>
                    ไม่เข้าร่วม <strong class="text-body"><?= $absent_count ?></strong>
                </span>
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-primary"
                    data-bs-toggle="modal" data-bs-target="#addParticipantsModal"
                    <?= empty($available_users) ? 'disabled' : '' ?>>
                <i class="bi bi-person-plus me-1"></i> เพิ่มผู้เข้าร่วม
            </button>
        </div>

        <?php if (!empty($registrations) && $registered_count > 0): ?>
        <div class="alert alert-warning small d-flex align-items-start gap-2 py-2 mb-3">
            <i class="bi bi-info-circle-fill mt-1"></i>
            <div>
                มีผู้ลงทะเบียน <?= $registered_count ?> คนที่ยัง<strong>ไม่ได้เช็คชื่อ</strong>
                — กดเมนู <i class="bi bi-three-dots-vertical"></i> ที่แต่ละแถว
                หรือเลือกหลายคนเพื่อเช็คชื่อพร้อมกัน
                <span class="text-muted">(รายงานจะนับเฉพาะคนที่ถูกเช็คชื่อว่า "เข้าร่วม" แล้วเท่านั้น)</span>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($registrations)): ?>
            <div class="card p-5 text-center text-muted">
                <i class="bi bi-people" style="font-size:48px;opacity:0.3;"></i>
                <p class="mt-2 mb-0">ยังไม่มีผู้เข้าร่วม — กดปุ่ม "เพิ่มผู้เข้าร่วม"</p>
            </div>
        <?php else: ?>
            <form method="POST" id="bulkAttendanceForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="bulkAction" value="update_attendance">
                <input type="hidden" name="new_status" id="bulkNewStatus" value="">
                <input type="hidden" name="_tab" value="tab-attendance">
                <div id="bulkBar" class="card p-2 mb-2 d-none">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="small me-2">เลือก <span id="bulkCount">0</span> คน:</span>
                        <button type="button" class="btn btn-sm btn-success bulk-status-btn" data-status="attended">
                            <i class="bi bi-check-circle me-1"></i> เช็คว่าเข้าร่วม
                        </button>
                        <button type="button" class="btn btn-sm btn-danger bulk-status-btn" data-status="absent">
                            <i class="bi bi-x-circle me-1"></i> ไม่เข้าร่วม
                        </button>
                        <button type="button" class="btn btn-sm btn-warning bulk-status-btn" data-status="registered">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> รอเช็ค
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-md-auto" id="bulkRemoveBtn">
                            <i class="bi bi-trash me-1"></i> ลบที่เลือก
                        </button>
                    </div>
                </div>

                <div class="card">
                    <div class="table-responsive" style="overflow: visible;">
                        <table class="table table-stack mb-0 align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th style="width:36px;"><input type="checkbox" id="selAll" title="เลือกทั้งหมด"></th>
                                    <th>ชื่อ-สกุล</th>
                                    <th>แผนก</th>
                                    <th class="text-center" style="width:110px;">สถานะ</th>
                                    <th class="text-end" style="width:48px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registrations as $r):
                                    $name_safe = h($r['fullname']);
                                    $st_badge = match($r['status']) {
                                        'attended' => '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>เข้าร่วม</span>',
                                        'absent'   => '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>ไม่เข้าร่วม</span>',
                                        default    => '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>รอเช็ค</span>',
                                    };
                                ?>
                                <tr>
                                    <td data-label="">
                                        <input type="checkbox" name="reg_ids[]"
                                               value="<?= (int)$r['reg_id'] ?>" class="reg-check">
                                    </td>
                                    <td data-label="ชื่อ-สกุล">
                                        <div class="fw-medium"><?= $name_safe ?></div>
                                        <div class="text-muted small">@<?= h($r['username']) ?></div>
                                    </td>
                                    <td data-label="แผนก" class="small text-muted">
                                        <?= h($r['dept_name'] ?? '—') ?>
                                    </td>
                                    <td data-label="สถานะ" class="text-center"><?= $st_badge ?></td>
                                    <td data-label="" class="text-end text-nowrap">
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-sm btn-link text-muted p-1"
                                                    data-bs-toggle="dropdown" aria-expanded="false" title="เมนู">
                                                <i class="bi bi-three-dots-vertical"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php if ($r['status'] !== 'attended'): ?>
                                                <li>
                                                    <button type="button" class="dropdown-item text-success change-status-btn"
                                                            data-reg-id="<?= (int)$r['reg_id'] ?>"
                                                            data-new-status="attended">
                                                        <i class="bi bi-check-circle me-2"></i> เช็คว่าเข้าร่วม
                                                    </button>
                                                </li>
                                                <?php endif; ?>
                                                <?php if ($r['status'] !== 'absent'): ?>
                                                <li>
                                                    <button type="button" class="dropdown-item text-danger change-status-btn"
                                                            data-reg-id="<?= (int)$r['reg_id'] ?>"
                                                            data-new-status="absent">
                                                        <i class="bi bi-x-circle me-2"></i> ไม่เข้าร่วม
                                                    </button>
                                                </li>
                                                <?php endif; ?>
                                                <?php if ($r['status'] !== 'registered'): ?>
                                                <li>
                                                    <button type="button" class="dropdown-item text-warning change-status-btn"
                                                            data-reg-id="<?= (int)$r['reg_id'] ?>"
                                                            data-new-status="registered">
                                                        <i class="bi bi-arrow-counterclockwise me-2"></i> ตั้งเป็นรอเช็ค
                                                    </button>
                                                </li>
                                                <?php endif; ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button type="button" class="dropdown-item text-danger remove-one-btn"
                                                            data-reg-id="<?= (int)$r['reg_id'] ?>"
                                                            data-name="<?= $name_safe ?>">
                                                        <i class="bi bi-trash me-2"></i> ลบออกจากรายชื่อ
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </form>

            <form method="POST" id="removeOneForm" class="d-none">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove_participant">
                <input type="hidden" name="_tab" value="tab-attendance">
                <input type="hidden" name="reg_id" id="removeOneRegId" value="">
            </form>

            <form method="POST" id="updateOneForm" class="d-none">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_attendance">
                <input type="hidden" name="_tab" value="tab-attendance">
                <input type="hidden" name="new_status" id="updateOneNewStatus" value="">
                <input type="hidden" name="reg_ids[]" id="updateOneRegId" value="">
            </form>
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
                            $nm_safe = h($r['fullname']);
                            $st_badge = match($r['status']) {
                                'attended' => '<span class="badge bg-success">เข้าร่วม</span>',
                                'absent'   => '<span class="badge bg-danger">ไม่เข้าร่วม</span>',
                                default    => '<span class="badge bg-warning text-dark">รอเช็ค</span>',
                            };
                        ?>
                        <tr>
                            <td data-label="ชื่อ-สกุล">
                                <div class="fw-medium"><?= $nm_safe ?></div>
                                <div class="text-muted small">@<?= h($r['username']) ?></div>
                            </td>
                            <td data-label="การเข้าร่วม"><?= $st_badge ?></td>
                            <td data-label="เกียรติบัตร" class="text-end text-nowrap">
                                <?php if ($cert): ?>
                                    <a href="<?= h(APP_URL) ?>/api/download.php?type=cert&id=<?= (int)$cert['id'] ?>"
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
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                        <button type="button" id="selectAllUsers" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-check2-square me-1"></i> เลือกทั้งหมด
                        </button>
                        <div class="form-text ms-auto mb-0">
                            เลือก <span id="addSelCount">0</span> คน
                            — ระบบจะส่งอีเมลแจ้งเตือน (ถ้าเปิดในตั้งค่า)
                        </div>
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
                                <?= h($u['dept_name'] ?? '—') ?>
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
                                   data-search="<?= h($search_data) ?>"
                                   style="cursor: pointer;">
                                <input type="checkbox" name="user_ids[]"
                                       value="<?= (int)$u['id'] ?>" class="user-check">
                                <div class="flex-grow-1">
                                    <div class="small fw-medium">
                                        <?= h($u['fullname']) ?>
                                    </div>
                                    <div class="small text-muted">
                                        <?= h($u['email']) ?>
                                    </div>
                                </div>
                                <span class="badge-pill badge-role-<?= h($u['role']) ?>">
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
window.ACTIVITY_VIEW = {
    id: <?= (int)$id ?>,
    csrfToken: <?= json_encode(csrf_token(), JSON_HEX_TAG|JSON_HEX_QUOT) ?>,
    csrfName: <?= json_encode(CSRF_TOKEN_NAME, JSON_HEX_TAG|JSON_HEX_QUOT) ?>,
    appBase: <?= json_encode(APP_URL, JSON_HEX_TAG|JSON_HEX_QUOT) ?>,
    maxImageBytes: <?= mb_to_bytes($max_image_mb) ?>,
    maxImageMb: <?= (int)$max_image_mb ?>,
    photoCount: <?= (int)$photo_count ?>,
    canAddPhoto: <?= $can_add_photo ? 'true' : 'false' ?>
};
</script>
<script src="<?= h(APP_URL) ?>/assets/js/activity_view.js?v=<?= @filemtime(__DIR__ . '/../assets/js/activity_view.js') ?: time() ?>"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
