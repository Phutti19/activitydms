<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('director');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: ' . APP_URL . '/director/activities.php');
    exit;
}

$pdo = db();

$activity = $pdo->prepare(
    'SELECT a.*, t.name AS type_name, t.color AS type_color,
            fy.name AS fiscal_name,
            TRIM(CONCAT_WS(" ", u.prefix_name, u.first_name, u.last_name)) AS creator_name
     FROM activities a
     LEFT JOIN activity_types t ON t.id = a.activity_type_id
     LEFT JOIN fiscal_years fy  ON fy.id = a.fiscal_year_id
     LEFT JOIN users u          ON u.id = a.created_by
     WHERE a.id = :id AND a.scope = "organization" LIMIT 1'
);
$activity->execute([':id' => $id]);
$activity = $activity->fetch();

if (!$activity) {
    header('Location: ' . APP_URL . '/director/activities.php');
    exit;
}

$photos_stmt = $pdo->prepare(
    'SELECT * FROM activity_photos WHERE activity_id = :id ORDER BY sort_order ASC, id ASC'
);
$photos_stmt->execute([':id' => $id]);
$photos = $photos_stmt->fetchAll();

$attachments_stmt = $pdo->prepare(
    'SELECT * FROM activity_attachments WHERE activity_id = :id ORDER BY id ASC'
);
$attachments_stmt->execute([':id' => $id]);
$attachments = $attachments_stmt->fetchAll();

$reg_stmt = $pdo->prepare(
    'SELECT r.id AS reg_id, r.status, r.registered_at, r.checked_at,
            u.id AS uid, u.username,
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

$certs_stmt = $pdo->prepare(
    "SELECT c.*, TRIM(CONCAT_WS(' ', u.prefix_name, u.first_name, u.last_name)) AS user_name,
            u.username
     FROM certificates c
     JOIN users u ON u.id = c.user_id
     WHERE c.activity_id = :a
     ORDER BY u.first_name"
);
$certs_stmt->execute([':a' => $id]);
$all_certs    = $certs_stmt->fetchAll();
$cert_by_user = array_column($all_certs, null, 'user_id');

$attended_count = 0; $absent_count = 0; $registered_count = 0;
foreach ($registrations as $r) {
    if      ($r['status'] === 'attended') $attended_count++;
    elseif  ($r['status'] === 'absent')   $absent_count++;
    else                                  $registered_count++;
}

function dir_view_fmt(string $datetime): string {
    $ts = strtotime($datetime);
    $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' '
         . (date('Y', $ts) + 543) . ', ' . date('H:i', $ts);
}

$type_color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$activity['type_color'])
    ? $activity['type_color'] : '#5F5E5A';

$page_title  = $activity['title'];
$page_active = 'activities';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>

<div class="page-header">
    <div class="flex-grow-1">
        <a href="<?= $app_url_safe ?>/director/activities.php"
           class="text-muted small text-decoration-none">
            <i class="bi bi-arrow-left"></i> กลับสู่รายการ
        </a>
        <h1 class="page-title mt-1"><?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    </div>
    <span class="badge bg-secondary align-self-start mt-2">อ่านอย่างเดียว</span>
</div>

<ul class="nav nav-tabs mb-3" id="activityTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overview" type="button">
            <i class="bi bi-info-circle me-1"></i>ภาพรวม
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-photos" type="button">
            <i class="bi bi-image me-1"></i>ภาพ
            <span class="badge bg-secondary"><?= count($photos) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-attachments" type="button">
            <i class="bi bi-paperclip me-1"></i>ไฟล์แนบ
            <span class="badge bg-secondary"><?= count($attachments) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-attendance" type="button">
            <i class="bi bi-people me-1"></i>ผู้เข้าร่วม
            <span class="badge bg-secondary"><?= count($registrations) ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-certs" type="button">
            <i class="bi bi-award me-1"></i>เกียรติบัตร
            <span class="badge bg-secondary"><?= count($all_certs) ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- Tab: Overview -->
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
                        <?= htmlspecialchars(dir_view_fmt($activity['start_datetime']), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">สิ้นสุด</div>
                    <div><i class="bi bi-calendar3 me-1"></i>
                        <?= htmlspecialchars(dir_view_fmt($activity['end_datetime']), ENT_QUOTES, 'UTF-8') ?>
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
                    <div style="white-space: pre-wrap;">
                        <?= htmlspecialchars($activity['description'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
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
                    <div class="text-muted small">การเข้าร่วม</div>
                    <?php if ((int)$activity['is_open_registration'] === 1): ?>
                        <span class="badge bg-success">เปิดให้พนักงานเข้าร่วมเอง</span>
                    <?php else: ?>
                        <span class="badge bg-secondary">Admin เพิ่มผู้เข้าร่วมเอง</span>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-6">
                    <div class="text-muted small">สร้างโดย</div>
                    <div><?= htmlspecialchars($activity['creator_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <!-- Attendance summary -->
                <div class="col-12">
                    <hr class="my-2">
                    <div class="d-flex flex-wrap gap-4 text-center">
                        <div>
                            <div class="fs-4 fw-bold text-secondary"><?= count($registrations) ?></div>
                            <div class="small text-muted">ผู้เข้าร่วม</div>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold text-primary"><?= count($all_certs) ?></div>
                            <div class="small text-muted">เกียรติบัตร</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab: Photos -->
    <div class="tab-pane fade" id="tab-photos">
        <?php if (empty($photos)): ?>
        <div class="card p-5 text-center text-muted">
            <i class="bi bi-image" style="font-size:48px;opacity:0.3;"></i>
            <p class="mt-2 mb-0">ยังไม่มีภาพ</p>
        </div>
        <?php else: ?>
        <div class="row g-2">
            <?php foreach ($photos as $p):
                $orig_safe = htmlspecialchars($p['original_name'], ENT_QUOTES, 'UTF-8');
            ?>
            <div class="col-6 col-md-4 col-lg-3">
                <div class="card h-100 overflow-hidden">
                    <a href="<?= $app_url_safe ?>/api/download.php?type=photo&id=<?= (int)$p['id'] ?>"
                       target="_blank">
                        <img src="<?= $app_url_safe ?>/api/download.php?type=photo&id=<?= (int)$p['id'] ?>"
                             alt="<?= $orig_safe ?>"
                             style="width:100%;aspect-ratio:1;object-fit:cover;display:block;">
                    </a>
                    <div class="p-2 small text-muted text-truncate" title="<?= $orig_safe ?>">
                        <?= $orig_safe ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Attachments -->
    <div class="tab-pane fade" id="tab-attachments">
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
                        <?php if (!$is_file): ?>
                        <a href="<?= htmlspecialchars((string)$att['url'], ENT_QUOTES, 'UTF-8') ?>"
                           class="small text-truncate d-inline-block" style="max-width:300px;"
                           target="_blank" rel="noopener noreferrer">
                            <?= htmlspecialchars((string)$att['url'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php if ($is_file): ?>
                    <a href="<?= $app_url_safe ?>/api/download.php?type=attachment&id=<?= (int)$att['id'] ?>"
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
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Attendance -->
    <div class="tab-pane fade" id="tab-attendance">
        <div class="text-muted small mb-3">
            ทั้งหมด <?= count($registrations) ?> คน
        </div>

        <?php if (empty($registrations)): ?>
        <div class="card p-5 text-center text-muted">
            <i class="bi bi-people" style="font-size:48px;opacity:0.3;"></i>
            <p class="mt-2 mb-0">ยังไม่มีผู้เข้าร่วม</p>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-stack mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>ชื่อ-สกุล</th>
                            <th>แผนก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $r): ?>
                        <tr>
                            <td data-label="ชื่อ-สกุล">
                                <div class="fw-medium">
                                    <?= htmlspecialchars($r['fullname'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="text-muted small">
                                    @<?= htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>
                            <td data-label="แผนก" class="small text-muted">
                                <?= htmlspecialchars($r['dept_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Certificates -->
    <div class="tab-pane fade" id="tab-certs">
        <?php if (empty($registrations)): ?>
        <div class="card p-4 text-center text-muted">
            <i class="bi bi-award" style="font-size:40px;opacity:0.3;"></i>
            <p class="mt-2 mb-0">ยังไม่มีผู้เข้าร่วม</p>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-stack mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th>ชื่อ-สกุล</th>
                            <th class="text-center">การเข้าร่วม</th>
                            <th class="text-end">เกียรติบัตร</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registrations as $r):
                            $cert    = $cert_by_user[(int)$r['uid']] ?? null;
                            $nm_safe = htmlspecialchars($r['fullname'], ENT_QUOTES, 'UTF-8');
                            $st_badge = match($r['status']) {
                                'attended' => '<span class="badge bg-success">เข้าร่วม</span>',
                                'absent'   => '<span class="badge bg-danger">ไม่เข้าร่วม</span>',
                                default    => '<span class="badge bg-warning text-dark">รอเช็ค</span>',
                            };
                        ?>
                        <tr>
                            <td data-label="ชื่อ-สกุล">
                                <div class="fw-medium"><?= $nm_safe ?></div>
                                <div class="text-muted small">
                                    @<?= htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </td>
                            <td data-label="การเข้าร่วม" class="text-center"><?= $st_badge ?></td>
                            <td data-label="เกียรติบัตร" class="text-end text-nowrap">
                                <?php if ($cert): ?>
                                <a href="<?= $app_url_safe ?>/api/download.php?type=cert&id=<?= (int)$cert['id'] ?>"
                                   class="btn btn-sm btn-outline-warning" target="_blank" title="ดาวน์โหลด">
                                    <i class="bi bi-download me-1"></i>ดาวน์โหลด
                                </a>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /tab-content -->

<script>
const hash = window.location.hash.replace('#', '');
if (hash) {
    const trigger = document.querySelector(`[data-bs-target="#${hash}"]`);
    if (trigger) new bootstrap.Tab(trigger).show();
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
