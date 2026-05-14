<?php
declare(strict_types=1);

// Try to load app config for APP_NAME / APP_URL; fail gracefully if not possible
$_app_name = 'ActivityDMS';
$_app_url  = '';
try {
    if (file_exists(__DIR__ . '/../config/config.php') && !defined('APP_NAME')) {
        require_once __DIR__ . '/../config/config.php';
    }
    if (defined('APP_NAME')) $_app_name = APP_NAME;
    if (defined('APP_URL'))  $_app_url  = APP_URL;
} catch (Throwable) {}

http_response_code(403);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 ไม่มีสิทธิ์เข้าถึง — <?= htmlspecialchars($_app_name, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="<?= htmlspecialchars($_app_url, ENT_QUOTES, 'UTF-8') ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($_app_url, ENT_QUOTES, 'UTF-8') ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($_app_url, ENT_QUOTES, 'UTF-8') ?>/assets/vendor/fonts/kanit/kanit.css" rel="stylesheet">
    <style>
        body { font-family: 'Kanit', system-ui, sans-serif; background: #F8FAFC; }
        .error-code { font-size: 80px; font-weight: 700; color: #E2E8F0; line-height: 1; }
    </style>
</head>
<body>
    <div class="container">
        <div class="text-center" style="margin-top: 10vh; max-width: 480px; margin-left: auto; margin-right: auto;">
            <div class="error-code">403</div>
            <div class="mb-3" style="font-size: 48px;">
                <i class="bi bi-lock-fill text-warning"></i>
            </div>
            <h1 class="h4 fw-semibold mb-2">ไม่มีสิทธิ์เข้าถึง</h1>
            <p class="text-muted mb-4">
                คุณไม่มีสิทธิ์เข้าถึงหน้านี้<br>
                หากคิดว่าเป็นข้อผิดพลาด กรุณาติดต่อผู้ดูแลระบบ
            </p>
            <a href="<?= htmlspecialchars($_app_url, ENT_QUOTES, 'UTF-8') ?>/"
               class="btn btn-primary me-2">
                <i class="bi bi-house me-1"></i> หน้าหลัก
            </a>
            <a href="javascript:history.back()" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> ย้อนกลับ
            </a>
        </div>
    </div>
</body>
</html>
