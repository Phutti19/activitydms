<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';

require_role('admin');

$th_months = [
    1=>'มกราคม', 2=>'กุมภาพันธ์', 3=>'มีนาคม',   4=>'เมษายน',
    5=>'พฤษภาคม', 6=>'มิถุนายน',  7=>'กรกฎาคม', 8=>'สิงหาคม',
    9=>'กันยายน', 10=>'ตุลาคม',   11=>'พฤศจิกายน',12=>'ธันวาคม',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name        = trim((string)($_POST['name'] ?? ''));
        $start_month = (int)($_POST['start_month'] ?? 0);
        // UI รับเป็น พ.ศ. — แปลงเป็น ค.ศ. ก่อนเก็บ (DB column ใช้ MySQL YEAR ซึ่งรองรับเฉพาะ 1901–2155)
        $start_year_be = (int)($_POST['start_year']  ?? 0);
        $end_month   = (int)($_POST['end_month']   ?? 0);
        $end_year_be = (int)($_POST['end_year']    ?? 0);
        $start_year  = $start_year_be - 543;
        $end_year    = $end_year_be   - 543;
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        $errors = [];
        if ($name === '' || mb_strlen($name) > 50) $errors[] = 'กรุณากรอกชื่อปีงบประมาณ (ไม่เกิน 50 ตัว)';
        if ($start_month < 1 || $start_month > 12) $errors[] = 'เดือนเริ่มต้นไม่ถูกต้อง';
        if ($end_month   < 1 || $end_month   > 12) $errors[] = 'เดือนสิ้นสุดไม่ถูกต้อง';
        if ($start_year_be < 2543 || $start_year_be > 2643) $errors[] = 'ปี พ.ศ. เริ่มต้นไม่ถูกต้อง';
        if ($end_year_be   < 2543 || $end_year_be   > 2643) $errors[] = 'ปี พ.ศ. สิ้นสุดไม่ถูกต้อง';
        if (empty($errors) && ($end_year * 100 + $end_month) < ($start_year * 100 + $start_month)) {
            $errors[] = 'เดือน/ปีสิ้นสุดต้องไม่ก่อนเดือน/ปีเริ่มต้น';
        }

        if ($errors) {
            foreach ($errors as $e) flash_set('error', $e);
            header('Location: ' . APP_URL . '/admin/manage_fiscal_year.php');
            exit;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($is_active === 1) {
                $pdo->prepare('UPDATE fiscal_years SET is_active = 0')->execute([]);
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO fiscal_years (name, start_month, start_year, end_month, end_year, is_active)
                     VALUES (:n, :sm, :sy, :em, :ey, :a)'
                );
                $stmt->execute([
                    ':n'=>$name, ':sm'=>$start_month, ':sy'=>$start_year,
                    ':em'=>$end_month, ':ey'=>$end_year, ':a'=>$is_active,
                ]);
                $new_id = (int)$pdo->lastInsertId();
                audit_log('create_fiscal_year', 'fiscal_years', $new_id, null, [
                    'name'=>$name, 'start_month'=>$start_month, 'start_year'=>$start_year,
                    'end_month'=>$end_month, 'end_year'=>$end_year, 'is_active'=>$is_active,
                ]);
                flash_set('success', 'เพิ่มปีงบประมาณ "' . $name . '" สำเร็จ');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $old = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = :id');
                $old->execute([':id'=>$id]);
                $old_row = $old->fetch();
                if (!$old_row) {
                    $pdo->rollBack();
                    flash_set('error', 'ไม่พบปีงบประมาณที่ต้องการแก้ไข');
                    header('Location: ' . APP_URL . '/admin/manage_fiscal_year.php');
                    exit;
                }
                $stmt = $pdo->prepare(
                    'UPDATE fiscal_years
                     SET name=:n, start_month=:sm, start_year=:sy,
                         end_month=:em, end_year=:ey, is_active=:a
                     WHERE id=:id'
                );
                $stmt->execute([
                    ':n'=>$name, ':sm'=>$start_month, ':sy'=>$start_year,
                    ':em'=>$end_month, ':ey'=>$end_year, ':a'=>$is_active, ':id'=>$id,
                ]);
                audit_log('update_fiscal_year', 'fiscal_years', $id, $old_row, [
                    'name'=>$name, 'start_month'=>$start_month, 'start_year'=>$start_year,
                    'end_month'=>$end_month, 'end_year'=>$end_year, 'is_active'=>$is_active,
                ]);
                flash_set('success', 'แก้ไขปีงบประมาณ "' . $name . '" สำเร็จ');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo = db();
        try {
            $old = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = :id');
            $old->execute([':id'=>$id]);
            $old_row = $old->fetch();
            if ($old_row) {
                $pdo->prepare('DELETE FROM fiscal_years WHERE id = :id')->execute([':id'=>$id]);
                audit_log('delete_fiscal_year', 'fiscal_years', $id, $old_row, null);
                flash_set('success', 'ลบปีงบประมาณ "' . $old_row['name'] . '" สำเร็จ');
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                flash_set('error', 'ลบไม่ได้: มีกิจกรรมผูกอยู่กับปีงบประมาณนี้');
            } else {
                throw $e;
            }
        }
    }

    header('Location: ' . APP_URL . '/admin/manage_fiscal_year.php');
    exit;
}

$years_stmt = db()->prepare(
    'SELECT fy.*, COUNT(a.id) AS activity_count
     FROM fiscal_years fy
     LEFT JOIN activities a ON a.fiscal_year_id = fy.id
     GROUP BY fy.id
     ORDER BY fy.start_year DESC, fy.id DESC'
);
$years_stmt->execute();
$years = $years_stmt->fetchAll();

$current_year = (int)date('Y');

$page_title  = 'ปีงบประมาณ';
$page_active = 'manage_fiscal_year';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">ปีงบประมาณ</h1>
        <p class="text-muted small mb-0">ตั้งค่าปีงบประมาณและช่วงเดือนเริ่ม–สิ้นสุด</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#fyModal"
            onclick="openFyModal(null)">
        <i class="bi bi-plus-lg me-1"></i> เพิ่มปีงบประมาณ
    </button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>ชื่อ</th>
                    <th>ช่วงเดือน</th>
                    <th>กิจกรรม</th>
                    <th>สถานะ</th>
                    <th class="text-end">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($years)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีปีงบประมาณ — กดปุ่มเพิ่มด้านบน</td></tr>
                <?php else: foreach ($years as $y):
                    $cnt = (int)$y['activity_count'];
                    $name_safe = h($y['name']);
                    $data_json = json_encode($y, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
                ?>
                    <tr>
                        <td data-label="ชื่อ"><strong><?= $name_safe ?></strong></td>
                        <td data-label="ช่วงเดือน" class="small text-muted">
                            <?= h($th_months[(int)$y['start_month']] ?? '') ?>
                            <?= (int)$y['start_year'] + 543 ?>
                            –
                            <?= h($th_months[(int)$y['end_month']] ?? '') ?>
                            <?= (int)$y['end_year'] + 543 ?>
                        </td>
                        <td data-label="กิจกรรม">
                            <span class="badge bg-secondary"><?= $cnt ?> รายการ</span>
                        </td>
                        <td data-label="สถานะ">
                            <?php if ((int)$y['is_active'] === 1): ?>
                                <span class="badge bg-success">กำลังใช้งาน</span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">ปิดใช้งาน</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="จัดการ" class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal" data-bs-target="#fyModal"
                                    onclick='openFyModal(<?= $data_json ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($cnt > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" disabled
                                        title="ลบไม่ได้: มีกิจกรรมผูกอยู่">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php else: ?>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('ยืนยันการลบปีงบประมาณ &quot;<?= $name_safe ?>&quot; หรือไม่?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$y['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="fyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" id="fyForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="fyAction" value="create">
                <input type="hidden" name="id" id="fyId" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="fyTitle">เพิ่มปีงบประมาณ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="fyName" class="form-label small fw-medium">
                            ชื่อปีงบประมาณ <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="fyName" name="name" class="form-control"
                               required maxlength="50" placeholder="เช่น 2568">
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label for="fyStartMonth" class="form-label small fw-medium">เดือนเริ่ม *</label>
                            <select id="fyStartMonth" name="start_month" class="form-select" required>
                                <?php foreach ($th_months as $m => $n): ?>
                                    <option value="<?= $m ?>" <?= $m===10?'selected':'' ?>>
                                        <?= h($n) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="fyStartYear" class="form-label small fw-medium">ปี พ.ศ. เริ่ม *</label>
                            <input type="number" id="fyStartYear" name="start_year" class="form-control"
                                   required min="2543" max="2643" value="<?= $current_year - 1 + 543 ?>">
                        </div>
                        <div class="col-6">
                            <label for="fyEndMonth" class="form-label small fw-medium">เดือนสิ้นสุด *</label>
                            <select id="fyEndMonth" name="end_month" class="form-select" required>
                                <?php foreach ($th_months as $m => $n): ?>
                                    <option value="<?= $m ?>" <?= $m===9?'selected':'' ?>>
                                        <?= h($n) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="fyEndYear" class="form-label small fw-medium">ปี พ.ศ. สิ้นสุด *</label>
                            <input type="number" id="fyEndYear" name="end_year" class="form-control"
                                   required min="2543" max="2643" value="<?= $current_year + 543 ?>">
                        </div>
                    </div>

                    <div class="form-check mt-3">
                        <input type="checkbox" id="fyIsActive" name="is_active" value="1" class="form-check-input">
                        <label for="fyIsActive" class="form-check-label small">
                            ตั้งเป็นปีงบประมาณที่ใช้งานปัจจุบัน
                        </label>
                        <div class="form-text small">
                            <i class="bi bi-info-circle"></i>
                            ระบบจะปิดสถานะใช้งานของปีอื่นโดยอัตโนมัติ (มี active ได้สูงสุด 1 ปี)
                        </div>
                    </div>

                    <div id="fyEditWarn" class="alert alert-warning small mt-3 d-none">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        ปีงบประมาณนี้มีกิจกรรมผูกอยู่ <span id="fyEditCount">0</span> รายการ
                        — การเปลี่ยนช่วงเดือนอาจทำให้กิจกรรมตกขอบช่วง
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openFyModal(data) {
    const isEdit = !!data;
    document.getElementById('fyTitle').textContent = isEdit ? 'แก้ไขปีงบประมาณ' : 'เพิ่มปีงบประมาณ';
    document.getElementById('fyAction').value = isEdit ? 'update' : 'create';
    document.getElementById('fyId').value = isEdit ? data.id : '';
    document.getElementById('fyName').value = isEdit ? data.name : '';
    document.getElementById('fyStartMonth').value = isEdit ? data.start_month : 10;
    document.getElementById('fyStartYear').value  = isEdit ? (parseInt(data.start_year) + 543) : (new Date().getFullYear() - 1 + 543);
    document.getElementById('fyEndMonth').value   = isEdit ? data.end_month   : 9;
    document.getElementById('fyEndYear').value    = isEdit ? (parseInt(data.end_year) + 543)   : (new Date().getFullYear() + 543);
    document.getElementById('fyIsActive').checked = isEdit ? (parseInt(data.is_active) === 1) : false;

    const warn = document.getElementById('fyEditWarn');
    if (isEdit && parseInt(data.activity_count) > 0) {
        document.getElementById('fyEditCount').textContent = data.activity_count;
        warn.classList.remove('d-none');
    } else {
        warn.classList.add('d-none');
    }
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
