<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/fiscal_year.php';

require_role('employee');

const PA_VALID_FORMATS = ['onsite', 'online'];

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
// POST action handler แยกไป _personal_activities_post.php
require __DIR__ . '/_personal_activities_post.php';

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
        "SELECT id, activity_id, type, label, filename, url
         FROM activity_attachments
         WHERE activity_id IN ($placeholders)
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

$default_fy_id = active_fiscal_year_id();


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

<form method="GET" class="card p-3 mb-3" data-autofilter>
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6 col-lg-3">
            <label class="form-label small text-muted mb-1">
                <i class="bi bi-search"></i> ค้นหา
            </label>
            <input type="text" name="q" class="form-control" placeholder="ชื่อ / สถานที่ / รายละเอียด"
                   value="<?= h($q) ?>">
        </div>
        <div class="col-6 col-md-3 col-lg-2">
            <label class="form-label small text-muted mb-1">ประเภท</label>
            <select name="type" class="form-select">
                <option value="0">— ทั้งหมด —</option>
                <?php foreach ($types as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= $f_type === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= h($t['name']) ?>
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
                    <?= h($y['name']) ?>
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
            <a href="<?= h(APP_URL) ?>/employee/personal_activities.php"
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
    <div class="card h-100" style="border-left: 4px solid <?= h($color) ?>;">
        <div class="card-body d-flex flex-column gap-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <h6 class="fw-semibold mb-0 lh-sm">
                    <?= h($a['title']) ?>
                </h6>
                <span class="badge"
                      style="background:<?= h($color) ?>;white-space:nowrap;flex-shrink:0;">
                    <?= h($a['type_name'] ?? '—') ?>
                </span>
            </div>
            <div>
                <?php if (($a['format'] ?? 'onsite') === 'online'): ?>
                    <span class="badge-pill" style="background:#E0F2FE;color:#0369A1;">
                        <i class="bi bi-camera-video"></i> ออนไลน์
                    </span>
                <?php else: ?>
                    <span class="badge-pill" style="background:#FEF3C7;color:#92400E;">
                        <i class="bi bi-geo-alt"></i> ออนไซต์
                    </span>
                <?php endif; ?>
            </div>
            <?php if (!empty($a['description'])): ?>
            <p class="small text-muted mb-0 text-truncate-2">
                <?= h($a['description']) ?>
            </p>
            <?php endif; ?>
            <div class="small text-muted">
                <i class="bi bi-clock me-1"></i>
                <?= h(th_datetime($a['start_datetime'])) ?><br>
                <span class="ms-3">— <?= h(th_datetime($a['end_datetime'])) ?></span>
            </div>
            <?php if (!empty($a['location'])): ?>
            <div class="small text-muted">
                <i class="bi bi-geo-alt me-1"></i>
                <?= h($a['location']) ?>
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
                    ไฟล์/ลิงก์ <?= $att_count ?>/<?= PA_ATTACH_MAX_FILES ?>
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
                            data-title="<?= h($a['title']) ?>"
                            data-description="<?= h($a['description'] ?? '') ?>"
                            data-location="<?= h($a['location'] ?? '') ?>"
                            data-type="<?= (int)$a['activity_type_id'] ?>"
                            data-fiscal="<?= (int)$a['fiscal_year_id'] ?>"
                            data-format="<?= h((string)($a['format'] ?? 'onsite')) ?>"
                            <?php
                            [$pa_sd, $pa_sh, $pa_sm] = pa_split_dt((string)$a['start_datetime']);
                            [$pa_ed, $pa_eh, $pa_em] = pa_split_dt((string)$a['end_datetime']);
                            ?>
                            data-start-date="<?= h($pa_sd) ?>"
                            data-start-hour="<?= h($pa_sh) ?>"
                            data-start-minute="<?= h($pa_sm) ?>"
                            data-end-date="<?= h($pa_ed) ?>"
                            data-end-hour="<?= h($pa_eh) ?>"
                            data-end-minute="<?= h($pa_em) ?>"
                            title="แก้ไข">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('ลบ &quot;<?= h($a['title']) ?>&quot;?');">
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
                    จัดการไฟล์ — <?= h($a['title']) ?>
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
                        <a href="<?= h(APP_URL) ?>/api/download.php?type=cert&id=<?= (int)$cert['id'] ?>"
                           class="text-decoration-none text-truncate" target="_blank" rel="noopener"
                           title="<?= h($cert['original_name']) ?>">
                            <i class="bi bi-file-earmark-check me-1 text-warning"></i>
                            <?= h($cert['original_name']) ?>
                        </a>
                        <form method="POST" class="m-0"
                              onsubmit="return confirm('ลบเกียรติบัตรของ &quot;<?= h($a['title']) ?>&quot;?');">
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

                <!-- ไฟล์แนบและลิงก์ -->
                <section>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="fw-medium">
                            <i class="bi bi-paperclip text-primary me-1"></i>ไฟล์แนบและลิงก์
                        </span>
                        <span class="badge bg-light text-dark border"><?= $att_count ?>/<?= PA_ATTACH_MAX_FILES ?></span>
                    </div>

                    <?php if ($att_count > 0): ?>
                    <ul class="list-group list-group-flush border rounded mb-3 small overflow-hidden">
                    <?php foreach ($atts as $att):
                        $att_is_link  = (($att['type'] ?? 'file') === 'url');
                        $att_label    = trim((string)$att['label']) !== '' ? $att['label'] : ($att_is_link ? 'ลิงก์' : 'ไฟล์แนบ');
                        $att_label_safe = h($att_label);
                        if ($att_is_link) {
                            $att_href     = h((string)($att['url'] ?? ''));
                            $att_icon     = 'bi-link-45deg';
                            $att_icon_bg  = 'bg-success-subtle text-success';
                            $att_sub      = h((string)($att['url'] ?? ''));
                        } else {
                            $att_href     = h(APP_URL) . '/api/download.php?type=attachment&id=' . (int)$att['id'];
                            $att_icon     = 'bi-file-earmark-text';
                            $att_icon_bg  = 'bg-primary-subtle text-primary';
                            $att_sub      = 'ไฟล์แนบ';
                        }
                    ?>
                        <li class="list-group-item d-flex align-items-center gap-2 py-2 px-2">
                            <span class="d-inline-flex align-items-center justify-content-center rounded <?= $att_icon_bg ?>"
                                  style="width:32px;height:32px;flex-shrink:0;">
                                <i class="bi <?= $att_icon ?>" style="font-size:18px;"></i>
                            </span>
                            <a href="<?= $att_href ?>"
                               class="text-decoration-none flex-grow-1" style="min-width:0;"
                               target="_blank" rel="noopener noreferrer"
                               title="<?= $att_sub ?>">
                                <div class="text-truncate fw-medium"><?= $att_label_safe ?></div>
                                <div class="text-truncate text-muted small"><?= $att_sub ?></div>
                            </a>
                            <form method="POST" class="m-0 flex-shrink-0"
                                  onsubmit="return confirm('ลบ &quot;<?= $att_label_safe ?>&quot;?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_attachment">
                                <input type="hidden" name="attachment_id" value="<?= (int)$att['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-link text-danger p-1" title="ลบ">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <div class="text-center text-muted small py-3 mb-3 border rounded bg-light">
                        <i class="bi bi-inbox" style="font-size:24px;opacity:0.5;"></i>
                        <div class="mt-1">ยังไม่มีไฟล์แนบหรือลิงก์</div>
                    </div>
                    <?php endif; ?>

                    <?php if ($att_remaining > 0): ?>
                    <ul class="nav nav-pills nav-fill gap-1 mb-2" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active py-1 px-2 small" data-bs-toggle="tab"
                                    data-bs-target="#addFile-<?= (int)$a['id'] ?>" type="button" role="tab">
                                <i class="bi bi-file-earmark-arrow-up me-1"></i> เพิ่มไฟล์
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link py-1 px-2 small" data-bs-toggle="tab"
                                    data-bs-target="#addLink-<?= (int)$a['id'] ?>" type="button" role="tab">
                                <i class="bi bi-link-45deg me-1"></i> เพิ่มลิงก์
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content border rounded p-2 bg-light" style="min-height:7.5rem;">
                        <div class="tab-pane fade show active" id="addFile-<?= (int)$a['id'] ?>" role="tabpanel">
                            <form method="POST" enctype="multipart/form-data"
                                  class="d-flex gap-2 align-items-center attach-add-form mb-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="upload_attachment">
                                <input type="hidden" name="activity_id" value="<?= (int)$a['id'] ?>">
                                <input type="file" name="attachments[]" class="form-control form-control-sm attach-add-input"
                                       multiple
                                       accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp"
                                       required>
                                <button type="submit" class="btn btn-sm btn-primary flex-shrink-0">
                                    <i class="bi bi-upload me-1"></i> อัปโหลด
                                </button>
                            </form>
                            <div class="form-text small mb-0">PDF/Word/Excel/PPT/รูป — สูงสุด 10 MB ต่อไฟล์</div>
                        </div>
                        <div class="tab-pane fade" id="addLink-<?= (int)$a['id'] ?>" role="tabpanel">
                            <form method="POST" class="d-flex flex-column gap-2 mb-1">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_link">
                                <input type="hidden" name="activity_id" value="<?= (int)$a['id'] ?>">
                                <input type="text" name="link_label" class="form-control form-control-sm"
                                       maxlength="100" required placeholder="ชื่อลิงก์ เช่น Zoom นัด Q1">
                                <div class="d-flex gap-2 align-items-center">
                                    <input type="url" name="link_url" class="form-control form-control-sm"
                                           maxlength="500" required pattern="https://.*"
                                           placeholder="https://...">
                                    <button type="submit" class="btn btn-sm btn-success flex-shrink-0">
                                        <i class="bi bi-plus-lg me-1"></i> เพิ่ม
                                    </button>
                                </div>
                            </form>
                            <div class="form-text small mb-0">ต้องเป็น https:// เท่านั้น</div>
                        </div>
                    </div>
                    <div class="form-text small text-end mt-1">
                        เหลือ <?= $att_remaining ?> รายการ (รวมไฟล์ + ลิงก์)
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning small mb-0 py-2">
                        <i class="bi bi-info-circle"></i> ครบจำนวนสูงสุด <?= PA_ATTACH_MAX_FILES ?> รายการแล้ว
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
                                <?= h($t['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="fFiscal" class="form-label fw-medium">ปีงบประมาณ <span class="text-danger">*</span></label>
                        <select id="fFiscal" name="fiscal_year_id" class="form-select" required data-default-fy="<?= (int)($default_fy_id ?? 0) ?>">
                            <option value="">— เลือกปี —</option>
                            <?php foreach ($years as $y): ?>
                            <option value="<?= (int)$y['id'] ?>"
                                    <?= ($default_fy_id !== null && (int)$y['id'] === $default_fy_id) ? 'selected' : '' ?>>
                                <?= h($y['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-medium d-block">รูปแบบ <span class="text-danger">*</span></label>
                        <div class="btn-group w-100" role="group" aria-label="รูปแบบกิจกรรม">
                            <input type="radio" class="btn-check" name="format" id="paFmtOnsite" value="onsite" autocomplete="off" checked required>
                            <label class="btn btn-outline-primary" for="paFmtOnsite">
                                <i class="bi bi-geo-alt me-1"></i> ออนไซต์
                            </label>
                            <input type="radio" class="btn-check" name="format" id="paFmtOnline" value="online" autocomplete="off">
                            <label class="btn btn-outline-primary" for="paFmtOnline">
                                <i class="bi bi-camera-video me-1"></i> ออนไลน์
                            </label>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="fStartDate" class="form-label fw-medium">วันเวลาเริ่ม <span class="text-danger">*</span></label>
                        <div class="row g-1">
                            <div class="col-12 col-sm-7">
                                <input type="date" id="fStartDate" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-12 col-sm-5">
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
                            <div class="col-12 col-sm-7">
                                <input type="date" id="fEndDate" name="end_date" class="form-control" required>
                            </div>
                            <div class="col-12 col-sm-5">
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
window.PERSONAL_ACTIVITIES = {
    uploadLimitBytes: <?= (int) (function (): int {
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
    })() ?>,
    uploadLimitLabel: <?= json_encode((string) ini_get('upload_max_filesize'), JSON_HEX_TAG|JSON_HEX_QUOT) ?>
};
</script>
<script src="<?= h(APP_URL) ?>/assets/js/personal_activities.js?v=<?= @filemtime(__DIR__ . '/../assets/js/personal_activities.js') ?: time() ?>"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
