<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// โหลด .env (immutable — ห้าม override env vars ของระบบ)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Validate ตัวแปรที่ขาดไม่ได้ — fail fast ถ้า misconfig
$dotenv->required([
    'APP_ENV', 'APP_URL', 'APP_TIMEZONE',
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_CHARSET',
    'MAIL_HOST', 'MAIL_PORT', 'MAIL_FROM_ADDRESS',
    'UPLOAD_PATH',
    'SESSION_NAME', 'SESSION_LIFETIME',
])->notEmpty();

$dotenv->required('APP_ENV')->allowedValues(['dev', 'prod']);
$dotenv->required('APP_DEBUG')->isBoolean();
$dotenv->required('SESSION_SECURE_COOKIE')->isBoolean();

// Timezone — ต้องตั้งก่อน date() ทุกที่ในระบบ
date_default_timezone_set($_ENV['APP_TIMEZONE']);

// Error display ตาม APP_ENV
if ($_ENV['APP_ENV'] === 'prod') {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Constants ที่ใช้บ่อย — อ่านจาก env ครั้งเดียว
define('APP_NAME', $_ENV['APP_NAME'] ?? 'ActivityDMS');
define('APP_URL', rtrim($_ENV['APP_URL'], '/'));
define('APP_ENV', $_ENV['APP_ENV']);
define('APP_DEBUG', filter_var($_ENV['APP_DEBUG'], FILTER_VALIDATE_BOOLEAN));
define('UPLOAD_PATH', rtrim(str_replace('\\', '/', $_ENV['UPLOAD_PATH']), '/'));
define('CSRF_TOKEN_NAME', $_ENV['CSRF_TOKEN_NAME'] ?? 'adms_csrf');

// Global error & exception handler (ต้องอยู่หลัง define ด้านบน)
require_once __DIR__ . '/../includes/error_handler.php';
