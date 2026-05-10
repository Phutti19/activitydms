<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/mailer.php';

require_role('admin');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_cert') {
        $cert_activity_id = (int)($_POST['cert_activity_id'] ?? 0);
        $cert_user_id     = (int)($_POST['cert_user_id'] ?? 0);

        if ($cert_activity_id <= 0 || $cert_user_id <= 0) {
            flash_set('error', 'กรุณาเลือกกิจกรรมและผู้รับเกียรติบัตร');
        } else {
            $act = $pdo->prepare(
                'SELECT id, title FROM activities WHERE id = :id AND scope = "organization" LIMIT 1'
            );
            $act->execute([':id' => $cert_activity_id]);
            $activity_row = $act->fetch();

            $usr = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
            $usr->execute([':id' => $cert_user_id]);

            $is_participant = $pdo->prepare(
                'SELECT 1 FROM activity_registrations
                 WHERE activity_id = :a AND user_id = :u LIMIT 1'
            );
            $is_participant->execute([':a' => $cert_activity_id, ':u' => $cert_user_id]);

            if (!$activity_row || !$usr->fetch()) {
                flash_set('error', 'ไม่พบกิจกรรมหรือผู้ใช้ที่เลือก');
            } elseif (!$is_participant->fetch()) {
                flash_set('error', 'ผู้ใช้นี้ไม่ได้เป็นผู้เข้าร่วมกิจกรรมนี้ — ออกเกียรติบัตรให้ได้เฉพาะผู้เข้าร่วมเท่านั้น');
            } else {
                $dup = $pdo->prepare(
                    'SELECT id FROM certificates WHERE activity_id = :a AND user_id = :u LIMIT 1'
                );
                $dup->execute([':a' => $cert_activity_id, ':u' => $cert_user_id]);
                if ($dup->fetch()) {
                    flash_set('error', 'ผู้ใช้นี้มีเกียรติบัตรสำหรับกิจกรรมนี้แล้ว');
                } else {
                    $file = $_FILES['cert_file'] ?? null;
                    if (!$file) {
                        flash_set('error', 'ไม่ได้เลือกไฟล์');
                    } else {
                        // Validate with generous initial limit, then enforce per-type limit
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
                                    ':a'  => $cert_activity_id,
                                    ':u'  => $cert_user_id,
                                    ':f'  => $filename,
                                    ':o'  => $original_name,
                                    ':by' => (int)$_SESSION['user_id'],
                                ]);
                                $cert_id = (int)$pdo->lastInsertId();
                                audit_log('upload_cert', 'certificates', $cert_id, null, [
                                    'activity_id' => $cert_activity_id,
                                    'user_id'     => $cert_user_id,
                                    'filename'    => $filename,
                                    'mime'        => $check['mime'],
                                    'size'        => $check['size'],
                                ]);
                                enqueue_new_certificate_email($cert_user_id, $cert_activity_id);
                                flash_set('success', 'อัปโหลดเกียรติบัตรสำเร็จ');
                            } catch (Throwable $e) {
                                error_log('[manage_certificates] ' . $e->getMessage());
                                flash_set('error', 'อัปโหลดไม่สำเร็จ');
                            }
                        }
                    }
                }
            }
        }

    } elseif ($action === 'delete_cert') {
        $cert_id = (int)($_POST['cert_id'] ?? 0);
        $row     = $pdo->prepare('SELECT * FROM certificates WHERE id = :id LIMIT 1');
        $row->execute([':id' => $cert_id]);
        $cert = $row->fetch();
        if ($cert) {
            $pdo->prepare('DELETE FROM certificates WHERE id = :id')->execute([':id' => $cert_id]);
            audit_log('delete_cert', 'certificates', $cert_id, $cert, null);
            safe_unlink_upload('certificates', $cert['filename']);
            flash_set('success', 'ลบเกียรติบัตรสำเร็จ');
        }
    }

    $redir      = APP_URL . '/admin/manage_certificates.php';
    $filter_act = (int)($_POST['_filter_activity'] ?? 0);
    if ($filter_act > 0) $redir .= '?activity_id=' . $filter_act;
    header('Location: ' . $redir);
    exit;
}

$filter_activity = (int)($_GET['activity_id'] ?? 0);
$q_user          = trim((string)($_GET['q'] ?? ''));

$act_stmt = $pdo->prepare(
    'SELECT id, title FROM activities WHERE scope = "organization" ORDER BY start_datetime DESC'
);
$act_stmt->execute();
$act_list = $act_stmt->fetchAll();

$users_stmt = $pdo->prepare(
    "SELECT id, TRIM(CONCAT_WS(' ', prefix_name, first_name, last_name)) AS fullname, email
     FROM users WHERE is_active = 1 ORDER BY first_name"
);
$users_stmt->execute();
$users_list = $users_stmt->fetchAll();

$where  = 'WHERE 1=1';
$params = [];
if ($filter_activity > 0) {
    $where .= ' AND c.activity_id = :act_id';
    $params[':act_id'] = $filter_activity;
}
if ($q_user !== '') {
    $where .= ' AND (u.first_name LIKE :q OR u.last_name LIKE :q2 OR u.username LIKE :q3)';
    $params[':q']  = '%' . $q_user . '%';
    $params[':q2'] = '%' . $q_user . '%';
    $params[':q3'] = '%' . $q_user . '%';
}

$cert_stmt = $pdo->prepare(
    "SELECT c.*,
            a.title AS activity_title,
            TRIM(CONCAT_WS(' ', u.prefix_name, u.first_name, u.last_name)) AS user_name,
            u.username,
            TRIM(CONCAT_WS(' ', ub.prefix_name, ub.first_name, ub.last_name)) AS uploader_name
     FROM certificates c
     JOIN activities a ON a.id = c.activity_id
     JOIN users u      ON u.id = c.user_id
     JOIN users ub     ON ub.id = c.uploaded_by
     $where
     ORDER BY c.created_at DESC"
);
$cert_stmt->execute($params);
$certs = $cert_stmt->fetchAll();

$page_title  = 'เกียรติบัตร';
$page_active = 'certificates';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">เกียรติบัตร</h1>
    <button type="button" class="btn btn-primary"
            data-bs-toggle="modal" data-bs-target="#uploadCertModal">
        <i class="bi bi-award me-1"></i> อัปโหลดเกียรติบัตร
    </button>
</div>

<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label class="form-label small mb-1">กิจกรรม</label>
            <select name="activity_id" class="form-select">
                <option value="">— ทั้งหมด —</option>
                <?php foreach ($act_list as $a): ?>
                <option value="<?= (int)$a['id'] ?>"
                    <?= $filter_activity === (int)$a['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1">ค้นหาชื่อผู้รับ</label>
            <input type="text" name="q" class="form-control"
                   placeholder="ชื่อ / username"
                   value="<?= htmlspecialchars($q_user, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-6 col-md-2">
            <button type="submit" class="btn btn-outline-primary w-100">
                <i class="bi bi-search me-1"></i> ค้นหา
            </button>
        </div>
        <?php if ($filter_activity > 0 || $q_user !== ''): ?>
        <div class="col-6 col-md-1">
            <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/admin/manage_certificates.php"
               class="btn btn-outline-secondary w-100" title="ล้างตัวกรอง">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($certs)): ?>
<div class="card p-5 text-center text-muted">
    <i class="bi bi-award" style="font-size:48px;opacity:0.3;"></i>
    <p class="mt-2 mb-0">
        <?= ($filter_activity > 0 || $q_user !== '')
            ? 'ไม่พบเกียรติบัตรที่ตรงกับการค้นหา'
            : 'ยังไม่มีเกียรติบัตร' ?>
    </p>
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>กิจกรรม</th>
                    <th>ผู้รับเกียรติบัตร</th>
                    <th>ไฟล์</th>
                    <th>อัปโหลดโดย</th>
                    <th>วันที่</th>
                    <th class="text-end">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($certs as $cert):
                    $act_safe  = htmlspecialchars($cert['activity_title'], ENT_QUOTES, 'UTF-8');
                    $user_safe = htmlspecialchars($cert['user_name'], ENT_QUOTES, 'UTF-8');
                    $orig_safe = htmlspecialchars($cert['original_name'], ENT_QUOTES, 'UTF-8');
                    $ext = strtoupper(pathinfo((string)$cert['original_name'], PATHINFO_EXTENSION));
                ?>
                <tr>
                    <td data-label="กิจกรรม">
                        <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/admin/activity_view.php?id=<?= (int)$cert['activity_id'] ?>#tab-certs"
                           class="fw-medium text-decoration-none">
                            <?= $act_safe ?>
                        </a>
                    </td>
                    <td data-label="ผู้รับ">
                        <div class="fw-medium"><?= $user_safe ?></div>
                        <div class="small text-muted">@<?= htmlspecialchars($cert['username'], ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td data-label="ไฟล์" class="small text-muted">
                        <?= $orig_safe ?> <?= $ext !== '' ? "($ext)" : '' ?>
                    </td>
                    <td data-label="อัปโหลดโดย" class="small">
                        <?= htmlspecialchars($cert['uploader_name'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <?php $cts = strtotime((string)$cert['created_at']); ?>
                    <td data-label="วันที่" class="small text-muted">
                        <?= htmlspecialchars(
                            date('d/m/', $cts) . (date('Y', $cts) + 543) . ' ' . date('H:i', $cts),
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>
                    <td data-label="จัดการ" class="text-end text-nowrap">
                        <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/api/download.php?type=cert&id=<?= (int)$cert['id'] ?>"
                           class="btn btn-sm btn-outline-warning" target="_blank" title="ดาวน์โหลด">
                            <i class="bi bi-download"></i>
                        </a>
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('ลบเกียรติบัตรของ &quot;<?= $user_safe ?>&quot;?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_cert">
                            <input type="hidden" name="cert_id" value="<?= (int)$cert['id'] ?>">
                            <input type="hidden" name="_filter_activity" value="<?= $filter_activity ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="ลบ">
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

<div class="modal fade" id="uploadCertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_cert">
                <input type="hidden" name="_filter_activity" value="<?= $filter_activity ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-award me-1"></i> อัปโหลดเกียรติบัตร</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="certActivity" class="form-label fw-medium">
                            กิจกรรม <span class="text-danger">*</span>
                        </label>
                        <select id="certActivity" name="cert_activity_id" class="form-select" required>
                            <option value="">— เลือกกิจกรรม —</option>
                            <?php foreach ($act_list as $a): ?>
                            <option value="<?= (int)$a['id'] ?>"
                                <?= $filter_activity === (int)$a['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="certUser" class="form-label fw-medium">
                            ผู้รับเกียรติบัตร <span class="text-danger">*</span>
                        </label>
                        <select id="certUser" name="cert_user_id" class="form-select" required>
                            <option value="">— เลือกผู้รับ —</option>
                            <?php foreach ($users_list as $u): ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= htmlspecialchars($u['fullname'], ENT_QUOTES, 'UTF-8') ?>
                                — <?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="certFile" class="form-label fw-medium">
                            ไฟล์เกียรติบัตร <span class="text-danger">*</span>
                        </label>
                        <input type="file" id="certFile" name="cert_file" class="form-control"
                               required accept=".pdf,.jpg,.jpeg,.png">
                        <div class="form-text">PDF (≤ 10 MB) / JPG, PNG (≤ 5 MB)</div>
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

<?php require __DIR__ . '/../includes/footer.php'; ?>
