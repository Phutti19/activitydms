<?php
declare(strict_types=1);

// Helper สำหรับปีงบประมาณ — ใช้ทุกที่ที่ต้อง default = ปีปัจจุบัน
// (มติประชุม 2026-05-14: ฟอร์มเพิ่มกิจกรรม/ผู้ใช้ ต้องตั้งค่าเริ่มต้นเป็นปีงบประมาณที่ active)
//
// 2026-05-14: เปลี่ยนเป็น date-based lookup — auto-switch ตามวันที่ปัจจุบัน
// คอลัมน์ is_active ใน fiscal_years เก็บไว้เป็น manual override (กรณีไม่มีปีไหนคลุมวันนี้)

require_once __DIR__ . '/../config/database.php';

/**
 * คืน id ของปีงบประมาณที่ครอบคลุมวันที่ปัจจุบัน
 * Fallback: ถ้าไม่มีปีไหนคลุมวันนี้ (เช่น admin ลืม seed) → ใช้ is_active=1 ล่าสุด
 * Cache ระดับ request เพื่อไม่ query ซ้ำ
 */
function active_fiscal_year_id(): ?int
{
    static $cached = false;
    static $value  = null;
    if ($cached) return $value;

    $sql = "SELECT id FROM fiscal_years
            WHERE CURDATE() >= STR_TO_DATE(CONCAT(start_year,'-',start_month,'-01'), '%Y-%m-%d')
              AND CURDATE() <= LAST_DAY(STR_TO_DATE(CONCAT(end_year,'-',end_month,'-01'), '%Y-%m-%d'))
            ORDER BY start_year DESC LIMIT 1";
    $id = db()->query($sql)->fetchColumn();

    if ($id === false) {
        $id = db()->query(
            'SELECT id FROM fiscal_years WHERE is_active = 1 ORDER BY start_year DESC LIMIT 1'
        )->fetchColumn();
    }

    $value  = $id !== false ? (int)$id : null;
    $cached = true;
    return $value;
}

/**
 * คืน record ของปีงบประมาณ active เต็ม ๆ (id, name, start_year, end_year, ...)
 */
function active_fiscal_year(): ?array
{
    $id = active_fiscal_year_id();
    if ($id === null) return null;

    $stmt = db()->prepare('SELECT * FROM fiscal_years WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * คืนช่วงวันที่ของปีงบประมาณ (start_date, end_date เป็น YYYY-MM-DD)
 * ใช้ใน report เพื่อ filter ด้วย DATE range
 */
function fiscal_year_date_range(int $fy_id): ?array
{
    $stmt = db()->prepare('SELECT start_month, start_year, end_month, end_year FROM fiscal_years WHERE id = :id');
    $stmt->execute([':id' => $fy_id]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $start = sprintf('%04d-%02d-01', (int)$row['start_year'], (int)$row['start_month']);
    $end_y = (int)$row['end_year'];
    $end_m = (int)$row['end_month'];
    $last_day = (int) date('t', strtotime(sprintf('%04d-%02d-01', $end_y, $end_m)));
    $end = sprintf('%04d-%02d-%02d', $end_y, $end_m, $last_day);

    return ['start' => $start, 'end' => $end];
}

/**
 * มีปีงบประมาณถัดไปใน DB หรือยัง (สำหรับเตือน admin ให้ seed ล่วงหน้า)
 */
function next_fiscal_year_exists(): bool
{
    $active = active_fiscal_year();
    if (!$active) return false;

    // เทียบเป็นค่าเดียว (year*100+month) เพื่อเลี่ยงปัญหา placeholder ซ้ำใน PDO non-emulated
    $stmt = db()->prepare(
        'SELECT 1 FROM fiscal_years
         WHERE (start_year * 100 + start_month) > :v
         LIMIT 1'
    );
    $stmt->execute([
        ':v' => (int)$active['start_year'] * 100 + (int)$active['start_month'],
    ]);
    return (bool)$stmt->fetchColumn();
}

/**
 * จำนวนวันที่เหลือก่อนปีงบประมาณ active จะหมด (negative = หมดแล้ว)
 * คืน null ถ้าไม่มีปีงบประมาณ active
 */
function days_until_fy_end(): ?int
{
    $active = active_fiscal_year();
    if (!$active) return null;

    $end_y    = (int)$active['end_year'];
    $end_m    = (int)$active['end_month'];
    $last_day = (int) date('t', strtotime(sprintf('%04d-%02d-01', $end_y, $end_m)));
    $end_ts   = strtotime(sprintf('%04d-%02d-%02d 23:59:59', $end_y, $end_m, $last_day));

    return (int) floor(($end_ts - time()) / 86400);
}
