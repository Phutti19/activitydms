<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * บันทึก audit log
 *
 * @param string     $action     เช่น 'create_user', 'update_user', 'reset_password'
 * @param string     $table      ตารางที่ถูกกระทำ
 * @param int|null   $record_id  id ของ record (NULL ได้ถ้ายังไม่มี)
 * @param array|null $old        ข้อมูลก่อนแก้ (เก็บเป็น JSON)
 * @param array|null $new        ข้อมูลหลังแก้
 *
 * ห้ามใส่ password / password_hash / token ลง $old หรือ $new
 * ใช้ audit_redact() strip ก่อนเสมอ
 */
function audit_log(
    string $action,
    string $table,
    ?int $record_id = null,
    ?array $old = null,
    ?array $new = null
): void {
    $stmt = db()->prepare(
        'INSERT INTO audit_logs
            (user_id, action, table_name, record_id, old_values, new_values, ip_address)
         VALUES (:u, :a, :t, :r, :o, :n, :ip)'
    );

    $stmt->execute([
        ':u'  => $_SESSION['user_id'] ?? null,
        ':a'  => $action,
        ':t'  => $table,
        ':r'  => $record_id,
        ':o'  => $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
        ':n'  => $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
}

function audit_redact(array $row): array
{
    unset($row['password'], $row['password_hash'], $row['csrf_token']);
    return $row;
}
