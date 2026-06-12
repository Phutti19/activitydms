<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/audit.php';

if (is_logged_in()) {
    if (!empty($_SESSION['must_change_password'])) {
        header('Location: ' . APP_URL . '/change_password.php');
        exit;
    }
    $role_dashboard = [
        'admin'    => '/admin/dashboard.php',
        'director' => '/director/dashboard.php',
        'employee' => '/employee/dashboard.php',
    ];
    $target = $role_dashboard[$_SESSION['role']] ?? '/index.php';
    header('Location: ' . APP_URL . $target);
    exit;
}

$error = '';
$timeout = isset($_GET['timeout']);

// ---------------------------------------------------------------------------
// Login rate limiting: max 10 failed attempts per IP per 15 minutes
// ---------------------------------------------------------------------------
$client_ip  = $_SERVER['REMOTE_ADDR'] ?? '';
$rate_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rate_stmt = db()->prepare(
        "SELECT COUNT(*) FROM audit_logs
         WHERE action = 'login_failed'
           AND ip_address = :ip
           AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $rate_stmt->execute([':ip' => $client_ip]);
    $recent_failures = (int) $rate_stmt->fetchColumn();

    if ($recent_failures >= 10) {
        $rate_error = 'ลองเข้าสู่ระบบผิดพลาดหลายครั้ง กรุณารอ 15 นาทีแล้วลองใหม่';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_die();

    if ($rate_error !== '') {
        $error = $rate_error;
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
        } else {
            $stmt = db()->prepare(
                "SELECT id, username, password_hash, role,
                        TRIM(CONCAT_WS(' ', NULLIF(prefix_name, ''), first_name, last_name)) AS display_name,
                        department_id, must_change_password, is_active
                 FROM users
                 WHERE username = :u
                 LIMIT 1"
            );
            $stmt->execute([':u' => $username]);
            $user = $stmt->fetch();

            if ($user && (int) $user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
                audit_log('login_success', 'users', (int) $user['id'], null,
                    ['role' => $user['role']]);
                login_user($user);

                if (!empty($_SESSION['must_change_password'])) {
                    header('Location: ' . APP_URL . '/change_password.php');
                    exit;
                }
                $target = [
                    'admin'    => '/admin/dashboard.php',
                    'director' => '/director/dashboard.php',
                    'employee' => '/employee/dashboard.php',
                ][$user['role']] ?? '/index.php';
                header('Location: ' . APP_URL . $target);
                exit;
            }

            audit_log('login_failed', 'users', null, null,
                ['username' => $username]);
            sleep(1);
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เข้าสู่ระบบ — <?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <!-- Google Fonts: Kanit (Thai) -->
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Kanit', system-ui, sans-serif;
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            border-radius: 16px;
            border: none;
            box-shadow: 0 25px 60px rgba(0,0,0,0.30);
        }
        .brand-icon {
            width: 56px; height: 56px;
            border-radius: 14px;
            background: linear-gradient(135deg, #38BDF8, #0EA5E9);
            display: inline-flex; align-items: center; justify-content: center;
            color: #fff; font-size: 24px;
            box-shadow: 0 10px 30px rgba(56,189,248,0.35);
        }
        .form-control { min-height: 44px; border-radius: 9px; }
        .btn-primary {
            min-height: 44px; border-radius: 10px;
            background: linear-gradient(135deg, #0EA5E9, #0284C7);
            border: none;
        }
        .btn-primary:hover { filter: brightness(0.95); }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-6 col-lg-4">
                <div class="card login-card p-4 p-md-5">
                    <div class="text-center mb-4">
                        <span class="brand-icon mb-3"><i class="bi bi-shield-check"></i></span>
                        <h1 class="h4 fw-semibold mb-1"><?= htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class="text-muted small mb-0">สำนักวิทยบริการและเทคโนโลยีสารสนเทศ (ARIT)</p>
                    </div>

                    <?php if ($timeout): ?>
                        <div class="alert alert-warning small" role="alert">
                            <i class="bi bi-clock-history me-1"></i>
                            หมดเวลาใช้งาน กรุณาเข้าสู่ระบบใหม่
                        </div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger small" role="alert">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" autocomplete="on" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label for="username" class="form-label small fw-medium">ชื่อผู้ใช้</label>
                            <input type="text" id="username" name="username" class="form-control"
                                   required autofocus autocomplete="username"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label small fw-medium">รหัสผ่าน</label>
                            <input type="password" id="password" name="password" class="form-control"
                                   required autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-semibold">
                            <i class="bi bi-box-arrow-in-right me-1"></i> เข้าสู่ระบบ
                        </button>
                    </form>

                    <p class="text-center text-muted small mt-4 mb-0">
                        ระบบใช้ภายในองค์กร — ติดต่อผู้ดูแลหากลืมรหัสผ่าน
                    </p>
                </div>
            </div>
        </div>
    </div>
    <!-- Bootstrap 5 Bundle CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
