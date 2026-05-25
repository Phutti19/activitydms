<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/format.php';

// ============================================================
// Session bootstrap
// ============================================================
if (session_status() === PHP_SESSION_NONE) {

    // เก็บ session file นอก system temp (กัน leak ระหว่างโปรเจกต์ dev)
    $session_path = realpath(__DIR__ . '/..') . '/storage/sessions';
    if (!is_dir($session_path)) {
        mkdir($session_path, 0700, true);
    }
    session_save_path($session_path);

    // Cookie hardening (สเปค §7.1)
    $secure_cookie = filter_var($_ENV['SESSION_SECURE_COOKIE'], FILTER_VALIDATE_BOOLEAN);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure_cookie,
        'httponly' => true,
        'samesite' => $_ENV['SESSION_SAMESITE'] ?? 'Lax',
    ]);

    session_name($_ENV['SESSION_NAME']);
    session_start();
}

// ============================================================
// Idle timeout — 30 นาทีไม่มี activity → logout (สเปค §7.1)
// ============================================================
$lifetime = (int) $_ENV['SESSION_LIFETIME'];
if (isset($_SESSION['last_activity'])
    && (time() - $_SESSION['last_activity']) > $lifetime) {
    $_SESSION = [];
    session_unset();
    session_destroy();
    header('Location: ' . APP_URL . '/index.php?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();

// ============================================================
// Session helpers
// ============================================================
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

function current_user_id(): ?int
{
    return is_logged_in() ? (int) $_SESSION['user_id'] : null;
}

function current_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}

// ============================================================
// require_role() — บังคับ role + Director middleware + must_change_password gate
// ============================================================
function require_role(string $expected): void
{
    require_login();

    if ($_SESSION['role'] !== $expected) {
        http_response_code(403);
        exit('Forbidden: insufficient role.');
    }

    // Director read-only middleware (CLAUDE.md ข้อ 2)
    $allow_post_for_director = ['change_password.php', 'logout.php'];
    $current_script = basename($_SERVER['SCRIPT_NAME']);

    if (
        $_SESSION['role'] === 'director'
        && $_SERVER['REQUEST_METHOD'] !== 'GET'
        && !in_array($current_script, $allow_post_for_director, true)
    ) {
        http_response_code(403);
        exit('Director is read-only.');
    }

    // must_change_password gate — บังคับเปลี่ยนรหัสครั้งแรก
    $allow_when_must_change = ['change_password.php', 'logout.php'];
    if (
        !empty($_SESSION['must_change_password'])
        && !in_array($current_script, $allow_when_must_change, true)
    ) {
        header('Location: ' . APP_URL . '/change_password.php');
        exit;
    }
}

// ============================================================
// login_user() — เรียกหลัง verify รหัสผ่านสำเร็จ
// ============================================================
function login_user(array $user): void
{
    // ป้องกัน session fixation
    session_regenerate_id(true);

    $_SESSION['user_id']              = (int) $user['id'];
    $_SESSION['username']             = $user['username'];
    $_SESSION['role']                 = $user['role'];
    $_SESSION['display_name']         = $user['display_name'] ?? $user['username'];
    $_SESSION['department_id']        = $user['department_id'] ?? null;
    $_SESSION['must_change_password'] = (bool) ($user['must_change_password'] ?? false);
    $_SESSION['last_activity']        = time();

    require_once __DIR__ . '/csrf.php';
    csrf_regenerate();
}

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_unset();
    session_destroy();
}
