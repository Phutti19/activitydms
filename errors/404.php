<?php
declare(strict_types=1);

$_app_name = 'ActivityDMS';
$_app_url  = '';
try {
    if (file_exists(__DIR__ . '/../config/config.php') && !defined('APP_NAME')) {
        require_once __DIR__ . '/../config/config.php';
    }
    if (defined('APP_NAME')) $_app_name = APP_NAME;
    if (defined('APP_URL'))  $_app_url  = APP_URL;
} catch (Throwable) {}

http_response_code(404);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 ไม่พบหน้า — <?= htmlspecialchars($_app_name, ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Kanit', system-ui, sans-serif; background: #F8FAFC; }
        .error-code { font-size: 80px; font-weight: 700; color: #E2E8F0; line-height: 1; }
    </style>
</head>
<body>
    <div class="container">
        <div class="text-center" style="margin-top: 10vh; max-width: 480px; margin-left: auto; margin-right: auto;">
            <div class="error-code">404</div>
            <div class="mb-3" style="font-size: 48px;">
                <i class="bi bi-search text-muted"></i>
            </div>
            <h1 class="h4 fw-semibold mb-2">ไม่พบหน้าที่ต้องการ</h1>
            <p class="text-muted mb-4">
                หน้าที่คุณค้นหาอาจถูกย้าย ลบออก หรือ URL ไม่ถูกต้อง
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
