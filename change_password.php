<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

require_login();
// หมายเหตุ: ไม่ใช้ require_role() เพราะหน้านี้ใช้ทุก role
// (Director ก็ต้องเปลี่ยนรหัสได้ — whitelist ไว้ใน auth.php)

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => current_user_id()]);
    $row = $stmt->fetch();

    if (!$row) {
        logout_user();
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }

    if (!password_verify($current, $row['password_hash'])) {
        sleep(1);
        $error = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
    } elseif (strlen($new) < 8) {
        $error = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
        $error = 'รหัสผ่านต้องประกอบด้วยตัวอักษรและตัวเลข';
    } elseif ($new !== $confirm) {
        $error = 'การยืนยันรหัสผ่านไม่ตรงกัน';
    } elseif (password_verify($new, $row['password_hash'])) {
        $error = 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม';
    } else {
        $new_hash = password_hash($new, PASSWORD_BCRYPT);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare(
                'UPDATE users
                 SET password_hash = :h, must_change_password = 0, updated_at = NOW()
                 WHERE id = :id'
            );
            $upd->execute([':h' => $new_hash, ':id' => current_user_id()]);

            // Audit log — เปลี่ยนรหัสผ่านเป็น action สำคัญ (CLAUDE.md ข้อ 7)
            // ไม่เก็บ hash ลง JSON เด็ดขาด
            $audit = $pdo->prepare(
                'INSERT INTO audit_logs (user_id, action, table_name, record_id, ip_address)
                 VALUES (:u, :a, :t, :r, :ip)'
            );
            $audit->execute([
                ':u'  => current_user_id(),
                ':a'  => 'change_password',
                ':t'  => 'users',
                ':r'  => current_user_id(),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $_SESSION['must_change_password'] = false;
        session_regenerate_id(true);
        csrf_regenerate();

        $target = [
            'admin'    => '/admin/dashboard.php',
            'director' => '/director/dashboard.php',
            'employee' => '/employee/dashboard.php',
        ][current_role()] ?? '/index.php';

        header('Location: ' . APP_URL . $target . '?pwd_changed=1');
        exit;
    }
}

$is_first_time = !empty($_SESSION['must_change_password']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เปลี่ยนรหัสผ่าน — <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/assets/vendor/fonts/kanit/kanit.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Kanit', system-ui, sans-serif; background: #F8FAFC; min-height: 100vh; display: flex; align-items: center; }
        .card { border-radius: 14px; border: 1px solid #E2E8F0; }
        .form-control { min-height: 44px; border-radius: 9px; }
        .btn-primary { min-height: 44px; border-radius: 10px; background: linear-gradient(135deg, #0EA5E9, #0284C7); border: none; }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-12 col-md-6 col-lg-5">
                <div class="card p-4 p-md-5">
                    <h1 class="h4 fw-semibold mb-1">
                        <i class="bi bi-shield-lock me-2 text-primary"></i>เปลี่ยนรหัสผ่าน
                    </h1>
                    <p class="text-muted small">
                        <?= htmlspecialchars($_SESSION['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <?php if ($is_first_time): ?>
                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle me-1"></i>
                            กรุณาเปลี่ยนรหัสผ่านในการเข้าใช้งานครั้งแรก
                        </div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger small">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" autocomplete="off" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="current_password" class="form-label small fw-medium">รหัสผ่านปัจจุบัน</label>
                            <input type="password" id="current_password" name="current_password" class="form-control"
                                   required autocomplete="current-password" autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label small fw-medium">รหัสผ่านใหม่</label>
                            <input type="password" id="new_password" name="new_password" class="form-control"
                                   required minlength="8" autocomplete="new-password">
                            <div class="form-text">อย่างน้อย 8 ตัวอักษร ประกอบด้วยตัวอักษรและตัวเลข</div>
                        </div>
                        <div class="mb-4">
                            <label for="confirm_password" class="form-label small fw-medium">ยืนยันรหัสผ่านใหม่</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                                   required minlength="8" autocomplete="new-password">
                        </div>

                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <button type="submit" class="btn btn-primary fw-semibold flex-grow-1">
                                <i class="bi bi-check-lg me-1"></i> บันทึกรหัสผ่านใหม่
                            </button>
                            <?php if (!$is_first_time): ?>
                                <a href="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/index.php"
                                   class="btn btn-outline-secondary">ยกเลิก</a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if ($is_first_time): ?>
                        <hr class="my-4">
                        <form method="POST" action="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/logout.php" class="text-center">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-link btn-sm text-muted">
                                <i class="bi bi-box-arrow-right me-1"></i> ออกจากระบบ
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
