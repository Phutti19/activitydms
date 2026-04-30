<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

// CSRF Strategy: Per-session token
// - สร้าง 1 ครั้งตอน login สำเร็จ ใช้ตลอด session
// - refresh เมื่อ session_regenerate_id

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session must be started before csrf_token()');
    }

    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrf_field(): string
{
    $token = csrf_token();
    $name = htmlspecialchars(CSRF_TOKEN_NAME, ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="' . $name . '" value="' . $token . '">';
}

function verify_csrf_or_die(): void
{
    $submitted = $_POST[CSRF_TOKEN_NAME] ?? '';
    $expected  = $_SESSION[CSRF_TOKEN_NAME] ?? '';

    if (!is_string($submitted) || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
}

function csrf_regenerate(): void
{
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}
