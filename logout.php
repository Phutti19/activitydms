<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// รับเฉพาะ POST + CSRF (กัน CSRF logout จาก <img src> หรือ link ภายนอก)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

verify_csrf_or_die();
logout_user();

header('Location: ' . APP_URL . '/index.php');
exit;
