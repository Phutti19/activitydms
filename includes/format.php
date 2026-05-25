<?php
declare(strict_types=1);

// ============================================================
// Thai date/time formatting helpers
// ใช้ร่วมทั้งระบบ — อย่า copy ตัวจัด format วันที่ไปไว้ในแต่ละหน้าอีก
// ============================================================

/**
 * Escape ค่าออก HTML — ย่อจาก htmlspecialchars($v, ENT_QUOTES, 'UTF-8')
 * รับ null/ตัวเลขได้ (cast เป็น string)
 */
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** เดือนไทยแบบย่อ index 1-12 */
const TH_MONTHS_ABBR = [
    1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
    'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.',
];

/** "5 ม.ค. 2569" (วันที่ พ.ศ. ไม่มีเวลา) */
function th_date(string $dt): string {
    $ts = strtotime($dt);
    if ($ts === false) {
        return '';
    }
    return date('j', $ts) . ' ' . TH_MONTHS_ABBR[(int)date('n', $ts)]
         . ' ' . (date('Y', $ts) + 543);
}

/**
 * "5 ม.ค. 2569, 14:30" (วันที่ + เวลา)
 * @param string $sep ตัวคั่นระหว่างวันที่กับเวลา (default ", " บางหน้าใช้ " ")
 */
function th_datetime(string $dt, string $sep = ', '): string {
    $ts = strtotime($dt);
    if ($ts === false) {
        return '';
    }
    return th_date($dt) . $sep . date('H:i', $ts);
}

/** "14:30" (เวลาอย่างเดียว) */
function th_time(string $dt): string {
    $ts = strtotime($dt);
    return $ts === false ? '' : date('H:i', $ts);
}

/** ชื่อวันไทยแบบย่อ index 0(อา)-6(ส) */
const TH_DAYS_ABBR = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];

/** "จ 5 ม.ค. 2569" (ชื่อวัน + วันที่ พ.ศ.) — default = วันนี้ */
function th_weekday_date(?string $dt = null): string {
    $ts = $dt === null ? time() : strtotime($dt);
    if ($ts === false) {
        return '';
    }
    return TH_DAYS_ABBR[(int)date('w', $ts)] . ' ' . th_date(date('Y-m-d', $ts));
}

/** "ม.ค. 2569" จาก string รูปแบบ "Y-m" (เช่น "2026-01") */
function th_month_year(string $ym): string {
    $parts = explode('-', $ym);
    if (count($parts) < 2) {
        return $ym;
    }
    $m = (int)$parts[1];
    if ($m < 1 || $m > 12) {
        return $ym;
    }
    return TH_MONTHS_ABBR[$m] . ' ' . ((int)$parts[0] + 543);
}

/** "วันที่ออกรายงาน" สำหรับหัวรายงาน PDF — เช่น "25 พ.ค. 2569 เวลา 14:30 น." */
function report_gen_datetime(): string {
    return th_date(date('Y-m-d')) . ' เวลา ' . date('H:i') . ' น.';
}

// ============================================================
// สถานะการลงทะเบียน (activity_registrations.status)
// ============================================================

const REG_STATUS_LABELS = [
    'registered' => 'ยืนยันเข้าร่วม',
    'attended'   => 'เข้าร่วมแล้ว',
    'absent'     => 'ไม่เข้าร่วม',
];

const REG_STATUS_BADGES = [
    'registered' => 'secondary',
    'attended'   => 'success',
    'absent'     => 'danger',
];

/** ป้ายข้อความสถานะลงทะเบียน — unknown → คืน status เดิม */
function reg_label(string $status): string {
    return REG_STATUS_LABELS[$status] ?? $status;
}

/** ชื่อสี Bootstrap badge ของสถานะลงทะเบียน — unknown → 'secondary' */
function reg_badge(string $status): string {
    return REG_STATUS_BADGES[$status] ?? 'secondary';
}

// ============================================================
// สถานะตามช่วงเวลาของกิจกรรม (อิง start/end_datetime)
// ============================================================

/**
 * คืน ['key','label','bg','fg'] ของสถานะเวลา:
 * completed (เสร็จสิ้น) / ongoing (กำลังดำเนินอยู่) / upcoming (กำลังจะมาถึง)
 */
function activity_time_status(array $a): array {
    $now = time();
    $s = strtotime((string)$a['start_datetime']);
    $e = strtotime((string)$a['end_datetime']);
    if ($e < $now) {
        return ['key' => 'completed', 'label' => 'เสร็จสิ้น',       'bg' => '#D1FAE5', 'fg' => '#065F46'];
    }
    if ($s <= $now) {
        return ['key' => 'ongoing',   'label' => 'กำลังดำเนินอยู่', 'bg' => '#FEF3C7', 'fg' => '#92400E'];
    }
    return ['key' => 'upcoming',  'label' => 'กำลังจะมาถึง',   'bg' => '#DBEAFE', 'fg' => '#1E40AF'];
}
