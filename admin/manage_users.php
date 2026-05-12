<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit.php';

require_role('admin');

// Default/temp password format: <username>@<4-digit random>
// ใช้กับครั้งแรก (create) และตอน reset — must_change_password=1 บังคับเปลี่ยนทันทีหลัง login
function generate_default_password(string $username): string {
    $digits = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    return $username . '@' . $digits;
}

$valid_roles = ['admin', 'director', 'employee'];
$role_label  = ['admin'=>'ผู้ดูแลระบบ', 'director'=>'ผู้อำนวยการ', 'employee'=>'พนักงาน'];

// ประเภทบุคลากร — ค่าปิดตามต้นทางข้อมูล HR (ARIT) — ถ้ามีประเภทใหม่ต้องแก้ที่นี่
$staff_type_choices = [
    'พนักงานราชการ',
    'พนักงานมหาวิทยาลัย',
    'พนักงานประจำตามสัญญา',
    'ลูกจ้างประจำ',
];

// Seed ตำแหน่งพื้นฐานจากข้อมูล HR — รวมกับ distinct ใน DB เพื่อให้มี suggestion ตั้งแต่ระบบเพิ่งติดตั้ง
$position_seed = [
    'อาจารย์',
    'บรรณารักษ์',
    'บรรณารักษ์ชำนาญการ',
    'นักวิชาการคอมพิวเตอร์',
    'นักวิชาการคอมพิวเตอร์ชำนาญการ',
    'นักวิชาการศึกษาชำนาญการ',
    'นักวิชาการโสตทัศนศึกษา',
    'นักวิเทศสัมพันธ์',
    'นักเอกสารสนเทศชำนาญการ',
    'เจ้าหน้าที่บริหารงานทั่วไป',
    'เจ้าหน้าที่บริหารงานทั่วไปชำนาญการ',
    'พนักงานห้องสมุด',
];

$dept_stmt = db()->prepare('SELECT id, name FROM departments ORDER BY id');
$dept_stmt->execute();
$departments = $dept_stmt->fetchAll();
$dept_map = [];
foreach ($departments as $d) $dept_map[(int)$d['id']] = $d['name'];

// Distinct values สำหรับ datalist autocomplete (ตำแหน่ง / ประเภทบุคลากร)
// — admin ยังพิมพ์ค่าใหม่เองได้ ค่าใหม่จะปรากฏใน list ครั้งถัดไปอัตโนมัติ
$pos_stmt = db()->prepare(
    "SELECT DISTINCT position_name FROM users
     WHERE position_name <> '' ORDER BY position_name"
);
$pos_stmt->execute();
$db_positions = $pos_stmt->fetchAll(PDO::FETCH_COLUMN);
// รวม seed + ค่าจริงใน DB, unique + sort
$position_options = array_values(array_unique(array_merge($position_seed, $db_positions)));
sort($position_options, SORT_FLAG_CASE | SORT_STRING);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();
    $action      = $_POST['action'] ?? '';
    $current_uid = (int)$_SESSION['user_id'];

    if ($action === 'create' || $action === 'update') {
        $username      = trim((string)($_POST['username'] ?? ''));
        $prefix_name   = trim((string)($_POST['prefix_name'] ?? ''));
        $first_name    = trim((string)($_POST['first_name'] ?? ''));
        $last_name     = trim((string)($_POST['last_name'] ?? ''));
        $position_name = trim((string)($_POST['position_name'] ?? ''));
        $staff_type    = trim((string)($_POST['staff_type'] ?? ''));
        $staff_code    = trim((string)($_POST['staff_code'] ?? ''));
        $email         = trim((string)($_POST['email'] ?? ''));
        $department_id = (int)($_POST['department_id'] ?? 0);
        $role          = (string)($_POST['role'] ?? '');
        $id            = (int)($_POST['id'] ?? 0);

        $errors = [];
        if ($username === '' || mb_strlen($username) > 50)
            $errors[] = 'ชื่อผู้ใช้ไม่ถูกต้อง (ไม่เกิน 50 ตัว)';
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $username))
            $errors[] = 'ชื่อผู้ใช้ใช้ได้เฉพาะ a-z, 0-9, จุด ขีดล่าง ขีดกลาง';
        if ($first_name === '') $errors[] = 'กรุณากรอกชื่อ';
        if ($last_name  === '') $errors[] = 'กรุณากรอกนามสกุล';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)
            $errors[] = 'อีเมลไม่ถูกต้อง';
        if (!isset($dept_map[$department_id])) $errors[] = 'แผนกไม่ถูกต้อง';
        if (!in_array($role, $valid_roles, true)) $errors[] = 'บทบาทไม่ถูกต้อง';
        if ($staff_type !== '' && !in_array($staff_type, $staff_type_choices, true))
            $errors[] = 'ประเภทบุคลากรไม่ถูกต้อง';

        // Self role-change guard (defense in depth)
        if ($action === 'update' && $id === $current_uid && $role !== 'admin') {
            $errors[] = 'ไม่อนุญาตให้เปลี่ยนบทบาทของตัวเอง';
        }

        // Unique check (username + email)
        if (empty($errors)) {
            $check_sql = 'SELECT username, email FROM users WHERE (username = :u OR email = :e)';
            $params = [':u'=>$username, ':e'=>$email];
            if ($action === 'update') {
                $check_sql .= ' AND id != :id';
                $params[':id'] = $id;
            }
            $check = db()->prepare($check_sql);
            $check->execute($params);
            if ($dup = $check->fetch()) {
                if ($dup['username'] === $username) $errors[] = 'ชื่อผู้ใช้ "' . $username . '" ถูกใช้แล้ว';
                if ($dup['email']    === $email)    $errors[] = 'อีเมล "' . $email . '" ถูกใช้แล้ว';
            }
        }

        if ($errors) {
            foreach ($errors as $e) flash_set('error', $e);
            header('Location: ' . APP_URL . '/admin/manage_users.php');
            exit;
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($action === 'create') {
                $initial_pwd = generate_default_password($username);
                $hash = password_hash($initial_pwd, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare(
                    'INSERT INTO users
                       (staff_code, username, password_hash, prefix_name, first_name, last_name,
                        position_name, staff_type, email, department_id, role,
                        is_active, must_change_password)
                     VALUES (:sc, :u, :h, :pre, :fn, :ln, :pos, :st, :e, :d, :r, 1, 1)'
                );
                $stmt->execute([
                    ':sc'=>$staff_code, ':u'=>$username, ':h'=>$hash,
                    ':pre'=>$prefix_name, ':fn'=>$first_name, ':ln'=>$last_name,
                    ':pos'=>$position_name, ':st'=>$staff_type,
                    ':e'=>$email, ':d'=>$department_id, ':r'=>$role,
                ]);
                $new_id = (int)$pdo->lastInsertId();
                audit_log('create_user', 'users', $new_id, null, [
                    'username'=>$username, 'first_name'=>$first_name, 'last_name'=>$last_name,
                    'email'=>$email, 'role'=>$role, 'department_id'=>$department_id,
                    'staff_code'=>$staff_code,
                ]);
                flash_set('success',
                    'สร้างบัญชี "' . $username . '" สำเร็จ — รหัสผ่านชั่วคราว:',
                    ['password_plaintext' => $initial_pwd]
                );
            } else {
                $old = $pdo->prepare('SELECT * FROM users WHERE id = :id');
                $old->execute([':id'=>$id]);
                $old_row = $old->fetch();
                if (!$old_row) {
                    $pdo->rollBack();
                    flash_set('error', 'ไม่พบบัญชี');
                    header('Location: ' . APP_URL . '/admin/manage_users.php');
                    exit;
                }

                $stmt = $pdo->prepare(
                    'UPDATE users SET
                        staff_code=:sc, username=:u, prefix_name=:pre,
                        first_name=:fn, last_name=:ln,
                        position_name=:pos, staff_type=:st,
                        email=:e, department_id=:d, role=:r
                     WHERE id=:id'
                );
                $stmt->execute([
                    ':sc'=>$staff_code, ':u'=>$username, ':pre'=>$prefix_name,
                    ':fn'=>$first_name, ':ln'=>$last_name,
                    ':pos'=>$position_name, ':st'=>$staff_type,
                    ':e'=>$email, ':d'=>$department_id, ':r'=>$role, ':id'=>$id,
                ]);
                audit_log('update_user', 'users', $id,
                    audit_redact($old_row),
                    [
                        'username'=>$username, 'first_name'=>$first_name, 'last_name'=>$last_name,
                        'email'=>$email, 'role'=>$role, 'department_id'=>$department_id,
                        'staff_code'=>$staff_code,
                    ]
                );
                flash_set('success', 'แก้ไขบัญชี "' . $username . '" สำเร็จ');

                if ($id === $current_uid) {
                    $_SESSION['display_name'] = trim($prefix_name . ' ' . $first_name . ' ' . $last_name);
                    $_SESSION['username'] = $username;
                    $_SESSION['department_id'] = $department_id;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $current_uid) {
            flash_set('error', 'ไม่อนุญาตให้ปิด/เปิดบัญชีของตัวเอง');
            header('Location: ' . APP_URL . '/admin/manage_users.php');
            exit;
        }
        $pdo = db();
        $old = $pdo->prepare('SELECT * FROM users WHERE id=:id');
        $old->execute([':id'=>$id]);
        $old_row = $old->fetch();
        if ($old_row) {
            $new_active = (int)$old_row['is_active'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE users SET is_active=:a WHERE id=:id')
                ->execute([':a'=>$new_active, ':id'=>$id]);
            audit_log($new_active ? 'enable_user' : 'disable_user', 'users', $id,
                ['is_active'=>(int)$old_row['is_active']],
                ['is_active'=>$new_active]);
            $msg = $new_active === 1 ? 'เปิดบัญชี' : 'ปิดบัญชี';
            flash_set('success', $msg . ' "' . $old_row['username'] . '" สำเร็จ');
        }
    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $current_uid) {
            flash_set('error', 'ไม่อนุญาตให้รีเซ็ตรหัสของตัวเอง — ใช้หน้า "เปลี่ยนรหัสผ่าน" ของบัญชีคุณแทน');
            header('Location: ' . APP_URL . '/admin/manage_users.php');
            exit;
        }
        $pdo = db();
        $old = $pdo->prepare('SELECT id, username FROM users WHERE id=:id');
        $old->execute([':id'=>$id]);
        $old_row = $old->fetch();
        if ($old_row) {
            $new_pwd = generate_default_password($old_row['username']);
            $hash = password_hash($new_pwd, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET password_hash=:h, must_change_password=1 WHERE id=:id')
                ->execute([':h'=>$hash, ':id'=>$id]);
            audit_log('reset_password', 'users', $id, null, ['username'=>$old_row['username']]);
            flash_set('success',
                'รีเซ็ตรหัสบัญชี "' . $old_row['username'] . '" สำเร็จ — รหัสใหม่:',
                ['password_plaintext' => $new_pwd]
            );
        }
    }

    header('Location: ' . APP_URL . '/admin/manage_users.php');
    exit;
}

$q       = trim((string)($_GET['q'] ?? ''));
$f_dept  = (int)($_GET['dept'] ?? 0);
$f_role  = $_GET['role']   ?? '';
$f_state = $_GET['status'] ?? '';

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(u.username LIKE :q OR u.email LIKE :q2 OR u.staff_code LIKE :q3
                 OR CONCAT_WS(\' \', u.first_name, u.last_name) LIKE :q4)';
    $params[':q']  = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
    $params[':q3'] = '%' . $q . '%';
    $params[':q4'] = '%' . $q . '%';
}
if ($f_dept > 0) {
    $where[] = 'u.department_id = :d';
    $params[':d'] = $f_dept;
}
if (in_array($f_role, $valid_roles, true)) {
    $where[] = 'u.role = :r';
    $params[':r'] = $f_role;
}
if ($f_state === 'active')   $where[] = 'u.is_active = 1';
if ($f_state === 'inactive') $where[] = 'u.is_active = 0';

$sql = 'SELECT u.*, d.name AS department_name
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY FIELD(u.role, "admin","director","employee"), u.first_name ASC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$page_title  = 'ผู้ใช้งาน';
$page_active = 'manage_users';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">ผู้ใช้งาน</h1>
        <p class="text-muted small mb-0">จัดการบัญชี (ทั้งหมด <?= count($users) ?> คน)</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal"
            onclick="openUserModal(null)">
        <i class="bi bi-plus-lg me-1"></i> เพิ่มผู้ใช้
    </button>
</div>

<form method="GET" class="card p-3 mb-3">
    <div class="row g-2">
        <div class="col-12 col-md-4">
            <input type="text" name="q" class="form-control" placeholder="ค้นหาชื่อ / อีเมล / รหัส"
                   value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-6 col-md-3">
            <select name="dept" class="form-select">
                <option value="0">ทุกแผนก</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $f_dept===(int)$d['id']?'selected':'' ?>>
                        <?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select name="role" class="form-select">
                <option value="">ทุกบทบาท</option>
                <?php foreach ($valid_roles as $r): ?>
                    <option value="<?= $r ?>" <?= $f_role===$r?'selected':'' ?>>
                        <?= htmlspecialchars($role_label[$r], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <select name="status" class="form-select">
                <option value="">ทุกสถานะ</option>
                <option value="active"   <?= $f_state==='active'  ?'selected':'' ?>>ใช้งาน</option>
                <option value="inactive" <?= $f_state==='inactive'?'selected':'' ?>>ปิด</option>
            </select>
        </div>
        <div class="col-6 col-md-1">
            <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i></button>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-stack mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>ชื่อ-สกุล</th>
                    <th>รหัส</th>
                    <th>แผนก</th>
                    <th>บทบาท</th>
                    <th>สถานะ</th>
                    <th class="text-end">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">ไม่พบผู้ใช้</td></tr>
                <?php else: foreach ($users as $u):
                    $is_self = ((int)$u['id'] === (int)$_SESSION['user_id']);
                    $username_safe = htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8');
                    $fullname = trim(($u['prefix_name'] ?? '') . ' ' . $u['first_name'] . ' ' . $u['last_name']);
                    $fullname_safe = htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8');
                    $data_json = json_encode(audit_redact($u), JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
                ?>
                <tr>
                    <td data-label="ชื่อ-สกุล">
                        <div class="fw-semibold"><?= $fullname_safe ?></div>
                        <div class="text-muted small">
                            @<?= $username_safe ?> · <?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </td>
                    <td data-label="รหัส" class="small text-muted">
                        <?= htmlspecialchars($u['staff_code'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td data-label="แผนก" class="small">
                        <?= htmlspecialchars($u['department_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td data-label="บทบาท">
                        <span class="badge-pill badge-role-<?= htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($role_label[$u['role']] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td data-label="สถานะ">
                        <?php if ((int)$u['is_active'] === 1): ?>
                            <span class="badge bg-success">ใช้งาน</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">ปิด</span>
                        <?php endif; ?>
                        <?php if ((int)$u['must_change_password'] === 1): ?>
                            <span class="badge bg-warning text-dark" title="ต้องเปลี่ยนรหัสครั้งแรก">
                                <i class="bi bi-key"></i>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td data-label="จัดการ" class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal" data-bs-target="#userModal"
                                onclick='openUserModal(<?= $data_json ?>)' title="แก้ไข">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php if (!$is_self): ?>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('รีเซ็ตรหัสผ่านของ &quot;<?= $username_safe ?>&quot;?\n\nระบบจะสร้างรหัสใหม่และแสดงครั้งเดียว');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="รีเซ็ตรหัสผ่าน">
                                    <i class="bi bi-key"></i>
                                </button>
                            </form>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('<?= (int)$u['is_active'] === 1 ? 'ปิดบัญชี' : 'เปิดบัญชี' ?> &quot;<?= $username_safe ?>&quot;?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button type="submit"
                                        class="btn btn-sm <?= (int)$u['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                                        title="<?= (int)$u['is_active'] === 1 ? 'ปิดบัญชี' : 'เปิดบัญชี' ?>">
                                    <i class="bi <?= (int)$u['is_active'] === 1 ? 'bi-toggle-off' : 'bi-toggle-on' ?>"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="badge bg-light text-muted border">บัญชีของคุณ</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down modal-lg">
        <div class="modal-content">
            <form method="POST" id="userForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" id="uAction" value="create">
                <input type="hidden" name="id" id="uId" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="uTitle">เพิ่มผู้ใช้</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="uCreateInfo" class="alert alert-info small">
                        <i class="bi bi-info-circle me-1"></i>
                        ระบบจะสร้างรหัสผ่านชั่วคราวให้อัตโนมัติ และแสดงครั้งเดียวหลังบันทึก
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label for="uPrefix" class="form-label small fw-medium">คำนำหน้า</label>
                            <input type="text" id="uPrefix" name="prefix_name" class="form-control" maxlength="20" placeholder="นาย/นาง/นางสาว">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="uFirst" class="form-label small fw-medium">ชื่อ <span class="text-danger">*</span></label>
                            <input type="text" id="uFirst" name="first_name" class="form-control" required maxlength="100">
                        </div>
                        <div class="col-12 col-md-5">
                            <label for="uLast" class="form-label small fw-medium">นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" id="uLast" name="last_name" class="form-control" required maxlength="100">
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="uUsername" class="form-label small fw-medium">ชื่อผู้ใช้ <span class="text-danger">*</span></label>
                            <input type="text" id="uUsername" name="username" class="form-control" required maxlength="50"
                                   pattern="[a-zA-Z0-9._\-]+" placeholder="เช่น kobsak.na">
                            <div class="form-text small">a-z, 0-9, จุด ขีดล่าง ขีดกลาง</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="uEmail" class="form-label small fw-medium">อีเมล <span class="text-danger">*</span></label>
                            <input type="email" id="uEmail" name="email" class="form-control" required maxlength="150">
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="uStaffCode" class="form-label small fw-medium">รหัสพนักงาน</label>
                            <input type="text" id="uStaffCode" name="staff_code" class="form-control" maxlength="20">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="uPosition" class="form-label small fw-medium">ตำแหน่ง</label>
                            <input type="text" id="uPosition" name="position_name" class="form-control"
                                   list="positionOptions" maxlength="150"
                                   autocomplete="off" placeholder="เลือกหรือพิมพ์ตำแหน่ง">
                            <datalist id="positionOptions">
                                <?php foreach ($position_options as $opt): ?>
                                    <option value="<?= htmlspecialchars((string)$opt, ENT_QUOTES, 'UTF-8') ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="uStaffType" class="form-label small fw-medium">ประเภทบุคลากร</label>
                            <select id="uStaffType" name="staff_type" class="form-select">
                                <option value="">— เลือกประเภท —</option>
                                <?php foreach ($staff_type_choices as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label for="uDept" class="form-label small fw-medium">แผนก <span class="text-danger">*</span></label>
                            <select id="uDept" name="department_id" class="form-select" required>
                                <option value="">— เลือกแผนก —</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="uRole" class="form-label small fw-medium">บทบาท <span class="text-danger">*</span></label>
                            <select id="uRole" name="role" class="form-select" required>
                                <?php foreach ($valid_roles as $r): ?>
                                    <option value="<?= $r ?>"><?= htmlspecialchars($role_label[$r], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="uRoleSelfWarn" class="form-text small text-warning d-none">
                                <i class="bi bi-exclamation-triangle"></i>
                                บัญชีของคุณ — ไม่อนุญาตให้เปลี่ยนบทบาทของตัวเอง
                            </div>
                        </div>
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
const CURRENT_USER_ID = <?= (int)$_SESSION['user_id'] ?>;

function openUserModal(data) {
    const isEdit = !!data;
    const isSelf = isEdit && parseInt(data.id) === CURRENT_USER_ID;

    document.getElementById('uTitle').textContent = isEdit ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้';
    document.getElementById('uAction').value = isEdit ? 'update' : 'create';
    document.getElementById('uId').value = isEdit ? data.id : '';
    document.getElementById('uCreateInfo').classList.toggle('d-none', isEdit);

    document.getElementById('uPrefix').value     = isEdit ? (data.prefix_name || '') : '';
    document.getElementById('uFirst').value      = isEdit ? data.first_name : '';
    document.getElementById('uLast').value       = isEdit ? data.last_name : '';
    document.getElementById('uUsername').value   = isEdit ? data.username : '';
    document.getElementById('uEmail').value      = isEdit ? data.email : '';
    document.getElementById('uStaffCode').value  = isEdit ? (data.staff_code || '') : '';
    document.getElementById('uPosition').value   = isEdit ? (data.position_name || '') : '';
    document.getElementById('uStaffType').value  = isEdit ? (data.staff_type || '') : '';
    document.getElementById('uDept').value       = isEdit ? data.department_id : '';
    document.getElementById('uRole').value       = isEdit ? data.role : 'employee';

    const roleSel = document.getElementById('uRole');
    const warn = document.getElementById('uRoleSelfWarn');
    removeLockedRoleHidden();
    if (isSelf) {
        roleSel.disabled = true;
        warn.classList.remove('d-none');
        addLockedRoleHidden(data.role);
    } else {
        roleSel.disabled = false;
        warn.classList.add('d-none');
    }
}

function addLockedRoleHidden(role) {
    const form = document.getElementById('userForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'role';
    input.id = 'roleLockedHidden';
    input.value = role;
    form.appendChild(input);
}
function removeLockedRoleHidden() {
    const old = document.getElementById('roleLockedHidden');
    if (old) old.remove();
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
