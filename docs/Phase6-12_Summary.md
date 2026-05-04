# ActivityDMS — Phase 6–12 Summary

> บันทึกสิ่งที่ implement แต่ละ phase ตั้งแต่ Phase 6 ถึง 12
> อ้างอิงสเปคเต็ม: `docs/ActivityDMS_Spec_v1_1.md`

---

## Phase 6 — Email Queue & Notification Settings

### ไฟล์ใหม่ / แก้ไข

| ไฟล์ | Action |
|---|---|
| `cron/send_emails.php` | ใหม่ — cron job ส่ง email ทุก 5 นาที |
| `includes/mailer.php` | ใหม่ — `enqueue_email()` insert `email_queue` |
| `templates/emails/new_activity.php` | ใหม่ — HTML template แจ้งเตือนกิจกรรมใหม่ |
| `templates/emails/new_certificate.php` | ใหม่ — HTML template แจ้งเตือนเกียรติบัตรใหม่ |
| `admin/notification_settings.php` | ใหม่ — Admin ตั้งค่า notify_new_activity / notify_new_certificate |

### สิ่งสำคัญ

- **ห้าม `$mailer->send()` ตอน submit** — ทุก trigger insert `email_queue` แทน
- Cron อ่าน `status = 'pending'` → ส่ง → อัปเดต status + insert `email_logs`
- Retry สูงสุด 3 ครั้ง → `failed` หยุดส่ง
- Rate limit ≤ 50 emails/ชั่วโมงต่อ trigger
- 2 Triggers: `new_activity` (เพิ่ม user ใน registration) + `new_certificate` (upload cert)

---

## Phase 7 — Employee Views

### ไฟล์ใหม่

| ไฟล์ | หน้าที่ |
|---|---|
| `employee/dashboard.php` | หน้าหลัก: กิจกรรมที่ต้องเข้าร่วม, ปฏิทินเล็ก, เกียรติบัตรล่าสุด |
| `employee/my_activities.php` | รายการกิจกรรมที่ตนเองเข้าร่วม (organization) |
| `employee/activity_view.php` | ดูรายละเอียดกิจกรรม (ที่ตนเองเป็น participant เท่านั้น) |
| `employee/available_activities.php` | กิจกรรมเปิดลงทะเบียน (`is_open_registration = 1`) |
| `employee/personal_activities.php` | กิจกรรม scope = personal ของตัวเอง (CRUD) |
| `employee/my_certificates.php` | เกียรติบัตรของตัวเอง — download ผ่าน `api/download.php` |
| `employee/documents.php` | เอกสารแนบของกิจกรรมที่ตนเองเข้าร่วม |
| `employee/my_reports.php` | รายงานสรุปส่วนตัว |
| `employee/calendar.php` | FullCalendar แสดงเฉพาะกิจกรรมของตัวเอง |

### กฎ scope ที่บังคับใช้

```sql
-- หน้า personal: ต้องมีทั้ง scope AND created_by
WHERE scope = 'personal' AND created_by = :session_user_id

-- หน้า my_activities: join ผ่าน activity_registrations
WHERE ar.user_id = :session_user_id AND a.scope = 'organization'
```

---

## Phase 8 — Employee Open Registration Flow

### ไฟล์ใหม่ / แก้ไข

| ไฟล์ | Action |
|---|---|
| `employee/available_activities.php` | เพิ่มปุ่ม "สมัครเข้าร่วม"/"ยกเลิก" + CSRF POST + handler inline (action=register / unregister) |
| `admin/activity_view.php` | เพิ่ม tab "ผู้สมัคร" แสดงรายชื่อ + เช็คชื่อ |

> หมายเหตุ: ในสเปคต้นฉบับเสนอแยกเป็น `api/register_activity.php` แต่ implement จริงเลือกฝัง logic ไว้ใน `employee/available_activities.php` (block `if ($_SERVER['REQUEST_METHOD'] === 'POST')`) เพื่อให้ flash message + redirect กลับหน้าเดิมได้สะดวก ไม่ต้องสร้าง endpoint แยก

### Logic การสมัคร (`employee/available_activities.php:41-76`)

1. ตรวจ `is_open_registration = 1` + `scope = 'organization'` (ก่อนถึง action handler)
2. ห้ามสมัครหลัง `end_datetime < NOW()`
3. INSERT `activity_registrations` status = `'registered'`
4. ถ้า `PDOException` code `'23000'` (UNIQUE `activity_id, user_id`) → flash "ลงทะเบียนแล้ว"
5. Unregister: DELETE เฉพาะ record ของตัวเองที่ `status = 'registered'` (ห้ามยกเลิกถ้าเช็คชื่อไปแล้ว)

> ⚠️ ในโค้ดยังไม่มีการเช็ค `max_participants` capacity — ถ้าจะเพิ่มต้อง implement ใน Phase ถัดไป

---

## Phase 9 — Reports & Calendar

### ไฟล์ใหม่

| ไฟล์ | หน้าที่ |
|---|---|
| `admin/reports.php` | รายงาน Admin: กรอง fiscal year, ตาราง monthly/dept/activity |
| `api/calendar_events.php` | JSON feed ให้ FullCalendar ทุก role |
| `admin/calendar.php` | ปฏิทิน Admin: FullCalendar 6 + event tooltip |

### `api/calendar_events.php` — Role-based query

```php
// Admin → organization activities ทั้งหมด
// Director → organization activities ทั้งหมด (เหมือน Admin)
// Employee → organization activities ที่ตนเป็น participant + personal ของตัวเอง
```

- ส่งคืน JSON array พร้อม `backgroundColor` ตาม activity_type color
- รองรับ `start` / `end` parameter ของ FullCalendar (ISO 8601)

---

## Phase 10 — Director Read-only Views

### ไฟล์ใหม่ / แก้ไข

| ไฟล์ | Action |
|---|---|
| `director/dashboard.php` | แก้จาก stub → stats card, upcoming, dept breakdown |
| `director/activities.php` | ใหม่ — list org activities พร้อม filter |
| `director/activity_view.php` | ใหม่ — 5 tabs (read-only): overview, photos, attachments, attendance, certs |
| `director/reports.php` | ใหม่ — รายงานสรุป (query เดียวกับ admin/reports.php) |
| `director/calendar.php` | ใหม่ — FullCalendar org events |
| `includes/header.php` | Unlock director menu URLs (เดิม null) |

### Director dashboard stats

```sql
-- ดึงจาก fiscal year ที่ active (is_active = 1)
SELECT COUNT(a.id) AS total_activities,
       COUNT(ar.id) AS total_registrations,
       SUM(ar.status = 'attended') AS attended,
       COUNT(c.id) AS total_certs
FROM activities a
LEFT JOIN activity_registrations ar ON ar.activity_id = a.id
LEFT JOIN certificates c ON c.activity_id = a.id
WHERE a.scope = 'organization' AND a.fiscal_year_id = :fy_id
```

### กฎ read-only ของ Director

- ทุกหน้าใน `director/` ไม่มี POST handler — ไม่ต้องเขียน middleware block เพราะไม่มี form submit
- `api/download.php` อนุญาต director ดาวน์โหลดไฟล์ขององค์กร
- Middleware ใน `includes/auth.php` บล็อก POST ทุกหน้า ยกเว้น `change_password.php` + `logout.php`

---

## Phase 11 — Mobile UI Polish

### ไฟล์ที่แก้ไข

| ไฟล์ | สิ่งที่เปลี่ยน |
|---|---|
| `assets/css/app.css` | เพิ่ม ~107 บรรทัด mobile/print CSS |
| `admin/activity_view.php` | เพิ่ม `capture="environment"` บน photo file input |
| `index.php` | ลบ `maximum-scale=1` ออกจาก viewport meta |

### CSS ที่เพิ่ม

```css
/* Nav tabs scrollable บน mobile */
.nav-tabs {
    flex-wrap: nowrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}

/* FullCalendar toolbar บน mobile */
@media (max-width: 540px) {
    .fc-header-toolbar { flex-direction: column; gap: 8px; }
    .fc-button { font-size: 12px !important; min-height: 36px !important; }
}

/* table-stack บน <380px: เรียงแนวตั้ง */
@media (max-width: 380px) {
    .table-stack td { flex-direction: column; }
}

/* Print styles: ซ่อน sidebar/topbar, restore table-stack */
@media print {
    .sidebar, .topbar, .btn, .no-print { display: none !important; }
    .table-stack td::before { display: none; }
    .table-stack td { display: table-cell !important; }
}
```

### Checklist CLAUDE.md ที่ทำเสร็จ

- [x] `capture="environment"` บน photo input
- [x] ลบ `maximum-scale=1` (ไม่ block accessibility zoom)
- [x] Nav tabs scrollable บน narrow screen
- [x] FullCalendar toolbar scale ลงบน mobile
- [x] `.empty-state` + `.card-hover` utility classes
- [x] Print styles

---

## Phase 12 — Hardening & UAT

### ไฟล์ใหม่

| ไฟล์ | หน้าที่ |
|---|---|
| `.htaccess` (root) | Security headers, deny sensitive files, error documents |
| `config/.htaccess` | `Require all denied` |
| `includes/.htaccess` | `Require all denied` |
| `storage/.htaccess` | `Require all denied` |
| `templates/.htaccess` | `Require all denied` |
| `cron/.htaccess` | `Require all denied` |
| `includes/error_handler.php` | Global error/exception handler |
| `errors/403.php` | Custom 403 page (Thai UI) |
| `errors/404.php` | Custom 404 page (Thai UI) |
| `errors/500.php` | Custom 500 page (Thai UI) |

### ไฟล์ที่แก้ไข

| ไฟล์ | สิ่งที่เปลี่ยน |
|---|---|
| `config/config.php` | เพิ่ม `require_once error_handler.php` ท้ายไฟล์ |
| `index.php` | Rate limiting + audit log (login_success/login_failed) |
| `admin/manage_fiscal_year.php` | แก้ `pdo->exec()` → `prepare()->execute([])` |

### Root `.htaccess` — Security Headers

```apache
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
    Header always unset X-Powered-By
    Header always unset Server
</IfModule>
```

### Login Rate Limiting

```php
// นับ login_failed ของ IP นี้ใน 15 นาทีที่ผ่านมา
$rate_stmt = db()->prepare(
    "SELECT COUNT(*) FROM audit_logs
     WHERE action = 'login_failed'
       AND ip_address = :ip
       AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
);
$rate_stmt->execute([':ip' => $client_ip]);
$recent_failures = (int) $rate_stmt->fetchColumn();
if ($recent_failures >= 10) {
    $rate_error = 'ลองเข้าสู่ระบบผิดพลาดหลายครั้ง กรุณารอ 15 นาทีแล้วลองใหม่';
}
```

### Global Error Handler (`includes/error_handler.php`)

```
set_error_handler()     → throw ErrorException (ยกเว้น @ operator)
set_exception_handler() → log + show stack trace (dev) หรือ error page (prod)
register_shutdown_function() → จับ fatal error ที่ handler ปกติพลาด
```

- **Dev** (`APP_ENV=dev`): แสดง `<pre>` stack trace
- **Prod** (`APP_ENV=prod`): แสดง `errors/500.php` เท่านั้น (ไม่รั่ว stack)

### Security Fixes ใน Phase 12

| ปัญหา | วิธีแก้ |
|---|---|
| `pdo->exec("UPDATE ... SET ... = 0")` | เปลี่ยนเป็น `prepare()->execute([])` |
| directory listing | `Options -Indexes` ใน root `.htaccess` |
| `.env`/`.sql` เข้าถึงได้ผ่าน browser | `FilesMatch` + `Require all denied` |
| ไม่มี rate limit บน login | ใช้ `audit_logs` table นับ IP |
| stack trace รั่วบน production | `APP_DEBUG` guard ใน error handler |

---

## สรุปภาพรวม — สิ่งที่สร้างตลอด Phase 6–12

### จำนวนไฟล์

| ประเภท | จำนวน |
|---|---|
| ไฟล์ PHP ใหม่ | ~30 ไฟล์ |
| ไฟล์ PHP แก้ไข | ~10 ไฟล์ |
| `.htaccess` | 6 ไฟล์ |
| CSS เพิ่ม | ~107 บรรทัด |
| Email templates | 2 ไฟล์ |

### ตาราง DB ที่ใช้ใน Phase 6–12

| ตาราง | Phase |
|---|---|
| `email_queue` | 6 |
| `email_logs` | 6 |
| `notification_settings` | 6 |
| `activity_registrations` | 7, 8 |
| `certificates` | 7, 9 |
| `audit_logs` | 12 |

---

## Cron Job (ต้องตั้งค่าบน Production)

```cron
*/5 * * * * /usr/bin/php /path/to/activitydms/cron/send_emails.php >> /var/log/activitydms-mail.log 2>&1
```

## Production Checklist

- [ ] แก้ `.env`: `APP_ENV=prod`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`
- [ ] ตั้งค่า SMTP จริงใน `.env`
- [ ] ตั้ง cron job ส่ง email
- [ ] ตรวจ `uploads/` อยู่นอก document root
- [ ] ตรวจ `config/`, `includes/` ถูก deny โดย `.htaccess`
- [ ] ทดสอบ login rate limit (ล็อกหลัง 10 ครั้ง / 15 นาที)
- [ ] ทดสอบ error pages (403, 404, 500) แสดงไม่รั่ว stack trace
