<?php
declare(strict_types=1);

// flash message แสดงครั้งเดียวหลัง redirect (PRG pattern)
// require auth.php (session) ก่อนใช้

function flash_set(string $type, string $message, ?array $extra = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session must be active');
    }
    $_SESSION['_flash'][] = [
        'type'    => $type,
        'message' => $message,
        'extra'   => $extra,
    ];
}

function flash_pull(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

function flash_render(): string
{
    $messages = flash_pull();
    if (empty($messages)) {
        return '';
    }

    $type_map = [
        'success' => ['class' => 'alert-success', 'icon' => 'bi-check-circle'],
        'error'   => ['class' => 'alert-danger',  'icon' => 'bi-exclamation-triangle'],
        'warning' => ['class' => 'alert-warning', 'icon' => 'bi-exclamation-circle'],
        'info'    => ['class' => 'alert-info',    'icon' => 'bi-info-circle'],
    ];

    $html = '';
    foreach ($messages as $msg) {
        $cfg = $type_map[$msg['type']] ?? $type_map['info'];
        $message_safe = htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8');
        $extra_html = '';

        if (!empty($msg['extra']['password_plaintext'])) {
            // กรณี reset password — โชว์ครั้งเดียว
            $pwd = htmlspecialchars($msg['extra']['password_plaintext'], ENT_QUOTES, 'UTF-8');
            $extra_html = '<div class="mt-2 p-2 bg-white border rounded font-monospace small"
                            style="user-select: all;">' . $pwd . '</div>
                          <div class="form-text mt-1">
                            <i class="bi bi-info-circle"></i>
                            จดรหัสนี้ไว้ก่อนปิดหน้านี้ — ระบบจะไม่แสดงอีก
                          </div>';
        }

        $html .= '<div class="alert ' . $cfg['class'] . ' alert-dismissible fade show small" role="alert">
                    <i class="bi ' . $cfg['icon'] . ' me-1"></i>' . $message_safe . $extra_html . '
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                  </div>';
    }
    return $html;
}
