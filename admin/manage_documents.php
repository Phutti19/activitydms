<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/audit.php';

require_role('admin');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            flash_set('error', 'กรุณาใส่ชื่อเอกสาร (ไม่เกิน 255 ตัวอักษร)');
        } else {
            $file = $_FILES['file'] ?? null;
            if (!$file) {
                flash_set('error', 'ไม่ได้เลือกไฟล์');
            } else {
                $max   = mb_to_bytes((int)($_ENV['UPLOAD_MAX_DOC_MB'] ?? 10));
                $check = validate_uploaded_file($file, UPLOAD_DOC_MIMES + UPLOAD_IMAGE_MIMES, $max);
                if (!$check['ok']) {
                    flash_set('error', $check['error']);
                } else {
                    $original_name = basename((string)($file['name'] ?? ''));
                    if ($original_name === '' || mb_strlen($original_name) > 255) {
                        $original_name = 'document.' . $check['ext'];
                    }
                    try {
                        $filename = move_uploaded_with_uuid($file, 'documents', $check['ext']);
                        $stmt = $pdo->prepare(
                            'INSERT INTO documents
                               (title, filename, original_name, file_size, mime_type, uploaded_by)
                             VALUES (:t, :f, :o, :s, :m, :u)'
                        );
                        $stmt->execute([
                            ':t' => $title,
                            ':f' => $filename,
                            ':o' => $original_name,
                            ':s' => $check['size'],
                            ':m' => $check['mime'],
                            ':u' => (int)$_SESSION['user_id'],
                        ]);
                        $doc_id = (int)$pdo->lastInsertId();
                        audit_log('upload_document', 'documents', $doc_id, null, [
                            'title'    => $title,
                            'filename' => $filename,
                            'mime'     => $check['mime'],
                            'size'     => $check['size'],
                        ]);
                        flash_set('success', 'อัปโหลด "' . $title . '" สำเร็จ');
                    } catch (Throwable $e) {
                        error_log('[manage_documents] ' . $e->getMessage());
                        flash_set('error', 'อัปโหลดไม่สำเร็จ');
                    }
                }
            }
        }

    } elseif ($action === 'delete') {
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        $row    = $pdo->prepare(
            'SELECT * FROM documents WHERE id = :id AND activity_id IS NULL LIMIT 1'
        );
        $row->execute([':id' => $doc_id]);
        $doc = $row->fetch();
        if ($doc) {
            $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute([':id' => $doc_id]);
            audit_log('delete_document', 'documents', $doc_id, $doc, null);
            safe_unlink_upload('documents', $doc['filename']);
            flash_set('success', 'ลบ "' . $doc['title'] . '" สำเร็จ');
        }
    }

    header('Location: ' . APP_URL . '/admin/manage_documents.php');
    exit;
}

$q      = trim((string)($_GET['q'] ?? ''));
$where  = 'WHERE d.activity_id IS NULL';
$params = [];
if ($q !== '') {
    $where .= ' AND (d.title LIKE :q OR d.original_name LIKE :q2)';
    $params = [':q' => '%' . $q . '%', ':q2' => '%' . $q . '%'];
}

$stmt = $pdo->prepare(
    "SELECT d.*, TRIM(CONCAT_WS(' ', u.prefix_name, u.first_name, u.last_name)) AS uploader_name
     FROM documents d
     JOIN users u ON u.id = d.uploaded_by
     $where
     ORDER BY d.created_at DESC"
);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$max_doc_mb  = (int)($_ENV['UPLOAD_MAX_DOC_MB'] ?? 10);
$page_title  = 'เอกสารทั่วไป';
$page_active = 'documents';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">เอกสารทั่วไป</h1>
    <button type="button" class="btn btn-primary"
            data-bs-toggle="modal" data-bs-target="#uploadModal">
        <i class="bi bi-cloud-upload me-1"></i> อัปโหลดเอกสาร
    </button>
</div>

<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end" data-autofilter>
        <div class="col-12 col-md-8">
            <input type="text" name="q" class="form-control"
                   placeholder="ค้นหาชื่อเอกสาร..."
                   value="<?= h($q) ?>">
        </div>
        <div class="col-6 col-md-2">
            <button type="submit" class="btn btn-outline-primary w-100">
                <i class="bi bi-search me-1"></i> ค้นหา
            </button>
        </div>
        <?php if ($q !== ''): ?>
        <div class="col-6 col-md-2">
            <a href="<?= h(APP_URL) ?>/admin/manage_documents.php"
               class="btn btn-outline-secondary w-100">ล้าง</a>
        </div>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($documents)): ?>
<div class="card p-5 text-center text-muted">
    <i class="bi bi-file-earmark" style="font-size:48px;opacity:0.3;"></i>
    <p class="mt-2 mb-0">
        <?= $q !== '' ? 'ไม่พบเอกสารที่ตรงกับการค้นหา' : 'ยังไม่มีเอกสาร — กดปุ่ม "อัปโหลดเอกสาร"' ?>
    </p>
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>ชื่อเอกสาร</th>
                    <th>ขนาด</th>
                    <th>อัปโหลดโดย</th>
                    <th>วันที่อัปโหลด</th>
                    <th class="text-end">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documents as $doc):
                    $title_safe = h($doc['title']);
                    $ext = strtoupper(pathinfo((string)$doc['original_name'], PATHINFO_EXTENSION));
                    $kb  = number_format((int)$doc['file_size'] / 1024, 0);
                ?>
                <tr>
                    <td data-label="ชื่อเอกสาร">
                        <div class="fw-medium"><?= $title_safe ?></div>
                        <div class="small text-muted text-truncate" style="max-width:280px;">
                            <?= h($doc['original_name']) ?>
                            <?= $ext !== '' ? "($ext)" : '' ?>
                        </div>
                    </td>
                    <td data-label="ขนาด" class="small"><?= $kb ?> KB</td>
                    <td data-label="อัปโหลดโดย" class="small">
                        <?= h($doc['uploader_name']) ?>
                    </td>
                    <?php $dts = strtotime((string)$doc['created_at']); ?>
                    <td data-label="วันที่" class="small text-muted">
                        <?= htmlspecialchars(
                            date('d/m/', $dts) . (date('Y', $dts) + 543) . ' ' . date('H:i', $dts),
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>
                    <td data-label="จัดการ" class="text-end text-nowrap">
                        <a href="<?= h(APP_URL) ?>/api/download.php?type=document&id=<?= (int)$doc['id'] ?>"
                           class="btn btn-sm btn-outline-primary" target="_blank" title="ดาวน์โหลด">
                            <i class="bi bi-download"></i>
                        </a>
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('ลบ &quot;<?= $title_safe ?>&quot;?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
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

<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload">
                <div class="modal-header">
                    <h5 class="modal-title">อัปโหลดเอกสารทั่วไป</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="docTitle" class="form-label fw-medium">
                            ชื่อเอกสาร <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="docTitle" name="title" class="form-control"
                               required maxlength="255"
                               placeholder="เช่น นโยบายองค์กร 2568">
                    </div>
                    <div>
                        <label for="docFile" class="form-label fw-medium">
                            ไฟล์ <span class="text-danger">*</span>
                        </label>
                        <input type="file" id="docFile" name="file" class="form-control" required
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp">
                        <div class="form-text">PDF / Word / Excel / PPT / รูปภาพ — สูงสุด <?= $max_doc_mb ?> MB</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary"
                            data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-cloud-upload me-1"></i> อัปโหลด
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
