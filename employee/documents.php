<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('employee');

$pdo = db();

$q       = trim((string)($_GET['q'] ?? ''));
$f_type  = trim((string)($_GET['type'] ?? '')); // pdf | word | excel | ppt | image

$where  = ['d.activity_id IS NULL'];
$params = [];
if ($q !== '') {
    $where[] = '(d.title LIKE :q OR d.original_name LIKE :q2)';
    $params[':q']  = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}

$mime_map = [
    'pdf'   => ["d.mime_type LIKE '%pdf%'"],
    'word'  => ["d.mime_type LIKE '%word%' OR d.mime_type LIKE '%officedocument.wordprocessing%'"],
    'excel' => ["d.mime_type LIKE '%excel%' OR d.mime_type LIKE '%spreadsheet%'"],
    'ppt'   => ["d.mime_type LIKE '%powerpoint%' OR d.mime_type LIKE '%presentation%'"],
    'image' => ["d.mime_type LIKE 'image/%'"],
];
if (isset($mime_map[$f_type])) {
    $where[] = '(' . $mime_map[$f_type][0] . ')';
}

$has_filter = ($q !== '' || $f_type !== '');

$stmt = $pdo->prepare(
    "SELECT d.id, d.title, d.original_name, d.file_size, d.mime_type, d.created_at,
            TRIM(CONCAT_WS(' ', u.prefix_name, u.first_name, u.last_name)) AS uploader_name
     FROM documents d
     JOIN users u ON u.id = d.uploaded_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY d.created_at DESC"
);
$stmt->execute($params);
$documents = $stmt->fetchAll();

function emp_doc_icon(string $mime): string {
    return match(true) {
        str_contains($mime, 'pdf')        => 'bi-file-earmark-pdf text-danger',
        str_contains($mime, 'word')       => 'bi-file-earmark-word text-primary',
        str_contains($mime, 'excel')
            || str_contains($mime,'spreadsheet') => 'bi-file-earmark-excel text-success',
        str_contains($mime, 'powerpoint')
            || str_contains($mime,'presentation') => 'bi-file-earmark-ppt text-warning',
        str_contains($mime, 'image')      => 'bi-file-earmark-image text-info',
        default                           => 'bi-file-earmark text-secondary',
    };
}

$page_title  = 'เอกสารทั่วไป';
$page_active = 'documents';
require __DIR__ . '/../includes/header.php';
$app_url_safe = h(APP_URL);
?>

<div class="page-header">
    <div>
        <h1 class="page-title">เอกสารทั่วไป</h1>
        <p class="text-muted small mb-0">เอกสารขององค์กรที่เปิดให้ดาวน์โหลด</p>
    </div>
</div>

<form method="GET" class="card p-3 mb-3" data-autofilter>
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-7 col-lg-8">
            <label class="form-label small text-muted mb-1">
                <i class="bi bi-search"></i> ค้นหา
            </label>
            <input type="text" name="q" class="form-control" placeholder="ชื่อเอกสาร / ชื่อไฟล์"
                   value="<?= h($q) ?>">
        </div>
        <div class="col-8 col-md-3 col-lg-3">
            <label class="form-label small text-muted mb-1">ประเภทไฟล์</label>
            <select name="type" class="form-select">
                <option value="">— ทั้งหมด —</option>
                <option value="pdf"   <?= $f_type === 'pdf'   ? 'selected' : '' ?>>PDF</option>
                <option value="word"  <?= $f_type === 'word'  ? 'selected' : '' ?>>Word</option>
                <option value="excel" <?= $f_type === 'excel' ? 'selected' : '' ?>>Excel</option>
                <option value="ppt"   <?= $f_type === 'ppt'   ? 'selected' : '' ?>>PowerPoint</option>
                <option value="image" <?= $f_type === 'image' ? 'selected' : '' ?>>รูปภาพ</option>
            </select>
        </div>
        <div class="col-4 col-md-2 col-lg-1 d-flex gap-1">
            <button type="submit" class="btn btn-primary flex-grow-1" title="ค้นหา">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($has_filter): ?>
            <a href="<?= $app_url_safe ?>/employee/documents.php"
               class="btn btn-outline-secondary flex-grow-1" title="ล้างตัวกรอง">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (empty($documents)): ?>
<div class="card p-5 text-center text-muted">
    <i class="bi bi-folder2-open" style="font-size:48px;opacity:0.3;"></i>
    <p class="mt-2 mb-0">
        <?= $has_filter ? 'ไม่พบเอกสารที่ตรงกับเงื่อนไขที่เลือก — ลองล้างตัวกรองดู' : 'ยังไม่มีเอกสาร' ?>
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
                    <th>วันที่อัปโหลด</th>
                    <th class="text-end">ดาวน์โหลด</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($documents as $doc):
                    $ext = strtoupper(pathinfo((string)$doc['original_name'], PATHINFO_EXTENSION));
                    $kb  = number_format((int)$doc['file_size'] / 1024, 0);
                    $icon = emp_doc_icon((string)$doc['mime_type']);
                ?>
                <tr>
                    <td data-label="ชื่อเอกสาร">
                        <div class="d-flex align-items-start gap-2">
                            <i class="bi <?= $icon ?> fs-5 flex-shrink-0 mt-1"></i>
                            <div>
                                <div class="fw-medium">
                                    <?= h($doc['title']) ?>
                                </div>
                                <div class="small text-muted text-truncate" style="max-width:260px;">
                                    <?= h($doc['original_name']) ?>
                                    <?= $ext !== '' ? "<span class=\"badge bg-light text-dark border ms-1\">{$ext}</span>" : '' ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td data-label="ขนาด" class="small text-muted"><?= $kb ?> KB</td>
                    <?php $dts = strtotime((string)$doc['created_at']); ?>
                    <td data-label="วันที่" class="small text-muted">
                        <?= htmlspecialchars(
                            date('d/m/', $dts) . (date('Y', $dts) + 543),
                            ENT_QUOTES, 'UTF-8'
                        ) ?>
                    </td>
                    <td data-label="ดาวน์โหลด" class="text-end">
                        <a href="<?= $app_url_safe ?>/api/download.php?type=document&id=<?= (int)$doc['id'] ?>"
                           class="btn btn-sm btn-outline-primary" target="_blank">
                            <i class="bi bi-download me-1"></i>ดาวน์โหลด
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
