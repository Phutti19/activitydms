<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name      = trim((string)($_POST['name']  ?? ''));
        $color     = trim((string)($_POST['color'] ?? ''));
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'กรุณากรอกชื่อประเภท (ไม่เกิน 100 ตัว)';
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors[] = 'รหัสสีไม่ถูกต้อง (ต้องเป็นรูปแบบ #RRGGBB)';
        }

        if ($errors) {
            foreach ($errors as $e) flash_set('error', $e);
            header('Location: ' . APP_URL . '/admin/manage_activity_types.php');
            exit;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO activity_types (name, color, is_active) VALUES (:n, :c, :a)'
                );
                $stmt->execute([':n'=>$name, ':c'=>$color, ':a'=>$is_active]);
                $new_id = (int)$pdo->lastInsertId();
                audit_log('create_activity_type', 'activity_types', $new_id, null, [
                    'name'=>$name, 'color'=>$color, 'is_active'=>$is_active,
                ]);
                flash_set('success', 'เพิ่มประเภท "' . $name . '" สำเร็จ');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $old = $pdo->prepare('SELECT * FROM activity_types WHERE id = :id');
                $old->execute([':id'=>$id]);
                $old_row = $old->fetch();
                if (!$old_row) {
                    $pdo->rollBack();
                    flash_set('error', 'ไม่พบประเภทที่ต้องการแก้ไข');
                    header('Location: ' . APP_URL . '/admin/manage_activity_types.php');
                    exit;
                }
                $stmt = $pdo->prepare(
                    'UPDATE activity_types SET name=:n, color=:c, is_active=:a WHERE id=:id'
                );
                $stmt->execute([':n'=>$name, ':c'=>$color, ':a'=>$is_active, ':id'=>$id]);
                audit_log('update_activity_type', 'activity_types', $id, $old_row, [
                    'name'=>$name, 'color'=>$color, 'is_active'=>$is_active,
                ]);
                flash_set('success', 'แก้ไขประเภท "' . $name . '" สำเร็จ');
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
            $old = $pdo->prepare('SELECT * FROM activity_types WHERE id = :id');
            $old->execute([':id'=>$id]);
            $old_row = $old->fetch();
            if ($old_row) {
                $pdo->prepare('DELETE FROM activity_types WHERE id = :id')->execute([':id'=>$id]);
                audit_log('delete_activity_type', 'activity_types', $id, $old_row, null);
                flash_set('success', 'ลบประเภท "' . $old_row['name'] . '" สำเร็จ');
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                flash_set('error', 'ลบไม่ได้: มีกิจกรรมที่ใช้ประเภทนี้อยู่ — ปิดใช้งานแทน (uncheck "ใช้งาน")');
            } else {
                throw $e;
            }
        }
    }

    header('Location: ' . APP_URL . '/admin/manage_activity_types.php');
    exit;
}

$types_stmt = db()->prepare(
    'SELECT t.*, COUNT(a.id) AS activity_count
     FROM activity_types t
     LEFT JOIN activities a ON a.activity_type_id = t.id
     GROUP BY t.id
     ORDER BY t.id ASC'
);
$types_stmt->execute();
$types = $types_stmt->fetchAll();

$page_title  = 'ประเภทกิจกรรม';
$page_active = 'manage_activity_types';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">ประเภทกิจกรรม</h1>
        <p class="text-muted small mb-0">ตั้งค่าประเภท (Tag) และสีที่แสดงในปฏิทินและบัตรกิจกรรม</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#atModal"
            onclick="openAtModal(null)">
        <i class="bi bi-plus-lg me-1"></i> เพิ่มประเภท
    </button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>ชื่อประเภท</th>
                    <th>สี</th>
                    <th>กิจกรรม</th>
                    <th>สถานะ</th>
                    <th class="text-end">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($types)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีประเภทกิจกรรม</td></tr>
                <?php else: foreach ($types as $t):
                    $cnt = (int)$t['activity_count'];
                    $name_safe = h($t['name']);
                    $color_safe = preg_match('/^#[0-9a-fA-F]{6}$/', $t['color']) ? $t['color'] : '#6c757d';
                    $data_json = json_encode($t, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
                ?>
                    <tr>
                        <td data-label="ชื่อ"><strong><?= $name_safe ?></strong></td>
                        <td data-label="สี">
                            <span class="d-inline-block rounded align-middle"
                                  style="width:18px;height:18px;background:<?= $color_safe ?>;border:1px solid #ddd;"></span>
                            <code class="small ms-1"><?= h($color_safe) ?></code>
                        </td>
                        <td data-label="กิจกรรม">
                            <span class="badge bg-secondary"><?= $cnt ?> รายการ</span>
                        </td>
                        <td data-label="สถานะ">
                            <?php if ((int)$t['is_active'] === 1): ?>
                                <span class="badge bg-success">ใช้งาน</span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">ปิดใช้งาน</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="จัดการ" class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal" data-bs-target="#atModal"
                                    onclick='openAtModal(<?= $data_json ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($cnt > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" disabled
                                        title="ลบไม่ได้: มีกิจกรรมใช้อยู่ — ปิดใช้งานแทน">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php else: ?>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('ยืนยันการลบประเภท &quot;<?= $name_safe ?>&quot; หรือไม่?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
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

<div class="modal fade" id="atModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <form method="POST" id="atForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="atAction" value="create">
                <input type="hidden" name="id" id="atId" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="atTitle">เพิ่มประเภทกิจกรรม</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="atName" class="form-label small fw-medium">
                            ชื่อประเภท <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="atName" name="name" class="form-control"
                               required maxlength="100" placeholder="เช่น ประชุม / อบรม / สัมมนา">
                    </div>

                    <div class="mb-3">
                        <label for="atColor" class="form-label small fw-medium">
                            สี <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="color" id="atColor" name="color" class="form-control form-control-color"
                                   required value="#0EA5E9" style="width:60px;min-height:44px;">
                            <code id="atColorText" class="text-muted">#0EA5E9</code>
                        </div>
                        <div class="form-text small">
                            <i class="bi bi-info-circle"></i>
                            หากเปลี่ยนสีของ 4 ประเภทพื้นฐาน (ประชุม/อบรม/สัมมนา/อื่นๆ) อาจไม่ตรงกับ design system
                        </div>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" id="atIsActive" name="is_active" value="1" class="form-check-input" checked>
                        <label for="atIsActive" class="form-check-label small">
                            แสดงเป็นตัวเลือกในฟอร์มสร้างกิจกรรม
                        </label>
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
function openAtModal(data) {
    const isEdit = !!data;
    document.getElementById('atTitle').textContent = isEdit ? 'แก้ไขประเภทกิจกรรม' : 'เพิ่มประเภทกิจกรรม';
    document.getElementById('atAction').value = isEdit ? 'update' : 'create';
    document.getElementById('atId').value = isEdit ? data.id : '';
    document.getElementById('atName').value = isEdit ? data.name : '';
    document.getElementById('atColor').value = isEdit ? data.color : '#0EA5E9';
    document.getElementById('atColorText').textContent = isEdit ? data.color : '#0EA5E9';
    document.getElementById('atIsActive').checked = isEdit ? (parseInt(data.is_active) === 1) : true;
}

document.getElementById('atColor').addEventListener('input', function() {
    document.getElementById('atColorText').textContent = this.value.toUpperCase();
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
