<?php
declare(strict_types=1);

/**
 * includes/error_handler.php — Global PHP error & exception handler
 *
 * ต้อง include หลัง config.php (ต้องการ APP_DEBUG, APP_URL, APP_NAME)
 *
 * - dev  (APP_DEBUG=true)  : แสดง full stack trace
 * - prod (APP_DEBUG=false) : log เฉพาะ, แสดงหน้า error สวยงาม
 */

// ---------------------------------------------------------------------------
// Helper: render a clean error page without depending on the full app stack
// ---------------------------------------------------------------------------
function _adms_error_page(int $code, string $heading, string $detail): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $app_name = defined('APP_NAME') ? APP_NAME : 'ActivityDMS';
    $app_url  = defined('APP_URL')  ? APP_URL  : '';
    $h = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $d = htmlspecialchars($detail,  ENT_QUOTES, 'UTF-8');

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="th">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$h} — {$app_name}</title>
        <link href="{$app_url}/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-family: 'Kanit', system-ui, sans-serif; background: #F8FAFC; }
            .error-box { max-width: 480px; margin: 10vh auto; text-align: center; }
            .error-code { font-size: 72px; font-weight: 700; color: #CBD5E1; line-height: 1; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="error-box p-4">
                <div class="error-code">{$code}</div>
                <h1 class="h4 fw-semibold mt-3 mb-2">{$h}</h1>
                <p class="text-muted mb-4">{$d}</p>
                <a href="{$app_url}/" class="btn btn-primary">
                    <i class="bi bi-house me-1"></i> กลับหน้าหลัก
                </a>
            </div>
        </div>
    </body>
    </html>
    HTML;

    exit;
}

// ---------------------------------------------------------------------------
// PHP error → exception conversion (ทำให้ error ทุกตัวกลายเป็น Exception)
// ---------------------------------------------------------------------------
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // Respect the @ (error suppression) operator
    if (!($errno & error_reporting())) {
        return false;
    }

    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// ---------------------------------------------------------------------------
// Uncaught exception handler
// ---------------------------------------------------------------------------
set_exception_handler(function (Throwable $e): void {
    $debug = defined('APP_DEBUG') && APP_DEBUG;

    $log_msg = sprintf(
        '[%s] %s: %s in %s:%d',
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
    error_log($log_msg);

    if ($debug) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<pre style="margin:2em;font-size:13px;line-height:1.6;">';
        echo '<strong>' . htmlspecialchars(get_class($e), ENT_QUOTES, 'UTF-8') . '</strong>: ';
        echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n\n";
        echo htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        echo '</pre>';
    } else {
        _adms_error_page(500, 'ข้อผิดพลาดภายในระบบ',
            'เกิดข้อผิดพลาดที่ไม่คาดคิด กรุณาติดต่อผู้ดูแลระบบ');
    }

    exit;
});

// ---------------------------------------------------------------------------
// Fatal error shutdown handler (E_ERROR, E_PARSE, E_CORE_ERROR, etc.)
// ---------------------------------------------------------------------------
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }

    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
    if (!in_array($err['type'], $fatal_types, true)) {
        return;
    }

    $log_msg = sprintf(
        '[%s] Fatal Error [%d]: %s in %s:%d',
        date('Y-m-d H:i:s'),
        $err['type'],
        $err['message'],
        $err['file'],
        $err['line']
    );
    error_log($log_msg);

    if (!headers_sent() && !(defined('APP_DEBUG') && APP_DEBUG)) {
        _adms_error_page(500, 'ข้อผิดพลาดภายในระบบ',
            'เกิดข้อผิดพลาดที่ไม่คาดคิด กรุณาติดต่อผู้ดูแลระบบ');
    }
});
