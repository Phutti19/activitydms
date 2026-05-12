<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('employee');

$uid = (int) current_user_id();
$pdo = db();

$q        = trim((string)($_GET['q'] ?? ''));
$f_type   = (int)($_GET['type'] ?? 0);
$f_fiscal = (int)($_GET['fiscal'] ?? 0);
$f_scope  = trim((string)($_GET['scope'] ?? '')); // organization | personal

$where  = ['c.user_id = :uid'];
$params = [':uid' => $uid];

if ($q !== '') {
    $where[] = '(a.title LIKE :q OR c.original_name LIKE :q2)';
    $params[':q']  = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if ($f_type > 0) {
    $where[] = 'a.activity_type_id = :ftype';
    $params[':ftype'] = $f_type;
}
if ($f_fiscal > 0) {
    $where[] = 'a.fiscal_year_id = :ffy';
    $params[':ffy'] = $f_fiscal;
}
if ($f_scope === 'organization' || $f_scope === 'personal') {
    $where[] = 'a.scope = :fscope';
    $params[':fscope'] = $f_scope;
}

$stmt = $pdo->prepare(
    "SELECT c.id, c.activity_id, c.original_name, c.created_at,
            a.title AS activity_title, a.start_datetime, a.end_datetime, a.scope,
            t.name AS type_name, t.color AS type_color
     FROM certificates c
     JOIN activities a ON a.id = c.activity_id
     LEFT JOIN activity_types t ON t.id = a.activity_type_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY c.created_at DESC"
);
$stmt->execute($params);
$certs = $stmt->fetchAll();

$types_stmt = $pdo->prepare('SELECT id, name FROM activity_types WHERE is_active = 1 ORDER BY id');
$types_stmt->execute();
$types = $types_stmt->fetchAll();

$years_stmt = $pdo->prepare('SELECT id, name FROM fiscal_years ORDER BY start_year DESC');
$years_stmt->execute();
$years = $years_stmt->fetchAll();

$has_filter = ($q !== '' || $f_type > 0 || $f_fiscal > 0 || $f_scope !== '');

$page_title  = 'เกียรติบัตรของฉัน';
$page_active = 'my_certificates';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">เกียรติบัตรของฉัน</h1>
        <p class="text-muted small mb-0">เกียรติบัตรที่ได้รับทั้งหมด <?= count($certs) ?> รายการ</p>
    </div>
</div>

<form method="GET" class="card p-3 mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-6 col-lg-4">
            <label class="form-label small text-muted mb-1">
                <i class="bi bi-search"></i> ค้นหา
            </label>
            <input type="text" name="q" class="form-control" placeholder="ชื่อกิจกรรม / ชื่อไฟล์"
                   value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-6 col-md-4 col-lg-3">
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
        <div class="col-6 col-md-4 col-lg-2">
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
        <div class="col-6 col-md-4 col-lg-2">
            <label class="form-label small text-muted mb-1">แหล่งที่มา</label>
            <select name="scope" class="form-select">
                <option value="">— ทั้งหมด —</option>
                <option value="organization" <?= $f_scope === 'organization' ? 'selected' : '' ?>>องค์กร</option>
                <option value="personal"     <?= $f_scope === 'personal'     ? 'selected' : '' ?>>ส่วนตัว</option>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-1 d-flex gap-1">
            <button type="submit" class="btn btn-primary flex-grow-1" title="ค้นหา">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($has_filter): ?>
            <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/employee/my_certificates.php"
               class="btn btn-outline-secondary flex-grow-1" title="ล้างตัวกรอง">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (empty($certs)): ?>
<div class="card p-5 text-center text-muted">
    <i class="bi bi-award" style="font-size:48px;opacity:0.3;"></i>
    <?php if ($has_filter): ?>
    <p class="mt-2 mb-0">ไม่พบเกียรติบัตรที่ตรงกับเงื่อนไขที่เลือก — ลองล้างตัวกรองดู</p>
    <?php else: ?>
    <p class="mt-2 mb-0">ยังไม่มีเกียรติบัตร</p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($certs as $c):
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c['type_color']) ? $c['type_color'] : '#D97706';
    $ext   = strtoupper(pathinfo((string)$c['original_name'], PATHINFO_EXTENSION));
    $icon  = $ext === 'PDF' ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-info';
    $ts    = strtotime((string)$c['start_datetime']);
    $m     = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    $date_str = date('j', $ts) . ' ' . $m[(int)date('n', $ts)-1] . ' ' . (date('Y', $ts)+543);
?>
<div class="col-12 col-md-6 col-lg-4">
    <div class="card h-100" style="border-top: 4px solid <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
        <div class="card-body d-flex flex-column gap-2">
            <div class="d-flex align-items-start gap-3">
                <div style="font-size:36px;line-height:1;flex-shrink:0;">📜</div>
                <div class="overflow-hidden">
                    <h6 class="fw-semibold mb-1 lh-sm">
                        <?= htmlspecialchars($c['activity_title'], ENT_QUOTES, 'UTF-8') ?>
                    </h6>
                    <div class="d-flex flex-wrap gap-1">
                        <?php if (!empty($c['type_name'])): ?>
                        <span class="badge"
                              style="background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
                            <?= htmlspecialchars($c['type_name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php endif; ?>
                        <?php if (($c['scope'] ?? '') === 'personal'): ?>
                        <span class="badge bg-light text-dark border" title="เกียรติบัตรจากกิจกรรมส่วนตัว">
                            <i class="bi bi-person-lock"></i> ส่วนตัว
                        </span>
                        <?php else: ?>
                        <span class="badge bg-light text-dark border" title="เกียรติบัตรจากกิจกรรมขององค์กร">
                            <i class="bi bi-building"></i> องค์กร
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="small text-muted">
                <i class="bi bi-calendar-event me-1"></i><?= $date_str ?>
            </div>
            <div class="small text-muted d-flex align-items-center gap-1">
                <i class="bi <?= $icon ?>"></i>
                <span class="text-truncate">
                    <?= htmlspecialchars($c['original_name'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?= $ext !== '' ? "<span class=\"badge bg-light text-dark border\">{$ext}</span>" : '' ?>
            </div>
            <div class="small text-muted">
                <i class="bi bi-clock-history me-1"></i>
                <?php $cts = strtotime((string)$c['created_at']); ?>
                ออกเมื่อ <?= htmlspecialchars(
                    date('d/m/', $cts) . (date('Y', $cts) + 543),
                    ENT_QUOTES, 'UTF-8'
                ) ?>
            </div>
            <div class="mt-auto pt-1">
                <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/api/download.php?type=cert&id=<?= (int)$c['id'] ?>"
                   class="btn btn-warning w-100" target="_blank">
                    <i class="bi bi-download me-1"></i> ดาวน์โหลดเกียรติบัตร
                </a>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
