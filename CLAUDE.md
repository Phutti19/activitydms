# CLAUDE.md — ActivityDMS

> ไฟล์นี้สำหรับ Claude / AI assistant อ่านก่อนเริ่มทำงานในโปรเจกต์
> Single source of truth ของสเปคเต็ม: **`docs/ActivityDMS_Spec_v1_1.md`**
> Schema เต็ม: **`database/schema.sql`**

---

## 1. โปรเจกต์ทำอะไร

Activity & Document Management System (**ActivityDMS**) — เว็บแอประบบจัดการกิจกรรม/ประชุม/อบรม/เอกสาร/เกียรติบัตร ของสำนักวิทยบริการและเทคโนโลยีสารสนเทศ (**ARIT**) ใช้ภายใน 3 แผนก รวม **29 บุคลากร** (+ admin 1 + director 1 = 31 บัญชี)

มี 3 roles: **Admin** (สิทธิ์เต็ม) / **Director** (read-only ทั้งระบบ) / **Employee** (ส่วนตัว)

---

## 2. ⛔ ห้ามทำเด็ดขาด (Non-negotiables)

กฎเหล่านี้ confirm ปิดแล้วใน decision log ของสเปค — **ห้าม re-litigate ห้าม "ขอเสนอวิธีดีกว่า"**

1. **Personal activity เป็น private 100%** — Employee สร้าง personal scope ได้ แต่ Admin/Director **ห้ามเห็น** ไม่ว่ากรณีไหน กฎการ query (เลือกใช้ตามบริบท ห้ามรวมกัน):
   - หน้า list/dashboard ของ Admin/Director → `WHERE scope = 'organization'` เสมอ
   - หน้า personal ของ Employee → `WHERE scope = 'personal' AND created_by = :session_user_id`
   - หน้ารวมของ Employee (เช่น "กิจกรรมของฉัน") → join ผ่าน `activity_registrations` + filter scope ตามที่ต้องการ — ห้ามดึง personal ของคนอื่น

2. **Director เป็น read-only ทั้งระบบ** — middleware บล็อก HTTP `POST/PUT/DELETE` ทั้งหมดเมื่อ `role = 'director'` **ยกเว้น 2 endpoint** ที่ต้อง whitelist: `change_password.php` (เพราะทุกบัญชี seed มี `must_change_password=1`) และ `logout.php` (ถ้าใช้ POST) — ดูตัวอย่าง code ใน §6

3. **เกียรติบัตรอัปโหลดไฟล์อย่างเดียว ไม่มี auto-generate** — อย่าเสนอใส่ template/PDF library เพื่อสร้างใบเกียรติบัตร เด็ดขาด

4. **รหัสผ่าน = bcrypt เท่านั้น** ใช้ `password_hash($pwd, PASSWORD_BCRYPT)` + `password_verify()` **ห้าม `==` หรือ `strcmp` เปรียบเทียบ string ตรง ๆ** ห้าม md5/sha1

5. **SQL = PDO Prepared Statements ทุกที่** ห้าม `$pdo->query("...$var...")` แม้ครั้งเดียว — แม้ว่าค่าจะมาจาก session ก็ตาม

6. **Output = `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` ทุกจุด** ที่ echo ค่าจาก DB / user input ออก HTML

7. **CSRF token ทุก form POST** — ไม่มียกเว้น แม้แต่หน้า admin

8. **ภาพกิจกรรมสูงสุด 5 ภาพต่อกิจกรรม** validate ทั้ง PHP **และ** DB CHECK constraint (`chk_photos_order`)

9. **ไฟล์อัปโหลด:**
   - ตรวจ MIME ด้วย `finfo_file()` **เท่านั้น** ห้ามเชื่อ extension หรือ `$_FILES['type']`
   - rename เป็น UUID **ห้ามใช้ original filename เป็นชื่อไฟล์จริง**
   - เก็บ**นอก** document root, serve ผ่าน `api/download.php` ที่เช็คสิทธิ์
   - `basename()` กันทุกจุดที่รับ filename จาก URL/POST

10. **Email = queue + cron** ห้าม `$mailer->send()` ตอนกด submit — เพราะ SMTP ช้าจะทำให้หน้าเว็บค้าง insert `email_queue` แล้วให้ `cron/send_emails.php` ดึงไปส่งทุก 5 นาที (retry สูงสุด 3 ครั้ง)

11. **Mobile-first คือใช้งานจริงบนมือถือได้** ไม่ใช่แค่ "เปิดดูได้" ทดสอบจริงบน ≤ 380px ทุกหน้า

---

## 3. Tech Stack (ตายตัว — ห้ามเสนอเปลี่ยน)

| Layer | Stack |
|---|---|
| Frontend | HTML5 + CSS3 + Vanilla JS / jQuery |
| UI | **Bootstrap 5** + Bootstrap Icons (mobile-first) |
| Calendar | FullCalendar.js |
| Backend | **Pure PHP 8.x** (ไม่ใช้ framework) |
| Database | MySQL 8.x ผ่าน PDO |
| Email | PHPMailer + SMTP |

> เหตุผลที่ pure PHP ไม่ใช้ Laravel/Slim: เป็นข้อกำหนดของลูกค้า — อย่าเสนอ refactor

---

## 4. โครงสร้างโปรเจกต์

```
activitydms/
├── assets/                 # CSS/JS/img static
├── config/
│   ├── database.php        # PDO connection (อ่านจาก .env)
│   ├── mailer.php          # SMTP (อ่านจาก .env)
│   └── config.php          # APP_NAME, BASE_URL, upload limits
├── includes/
│   ├── auth.php            # session check + role guard
│   ├── csrf.php            # generate/verify CSRF token
│   ├── upload.php          # finfo_file + UUID rename helpers
│   ├── mailer.php          # send_email() → enqueue
│   ├── header.php / footer.php
├── cron/send_emails.php    # cron ทุก 5 นาที
├── database/               # SQL — ห้าม serve ผ่าน web (.htaccess block)
│   ├── schema.sql
│   └── seed_mock_data.sql
# uploads อยู่ "นอก" project root — กำหนดผ่าน .env: UPLOAD_PATH
├── admin/    (ขั้นต่ำ 8 ไฟล์ตามสเปค §8 — แต่ Phase 3 ต้อง `manage_activity_types.php` และ Figma A12 มี admin calendar; รวมจริงราว 9–10 ไฟล์)
├── director/ (3 ไฟล์: dashboard / reports / calendar)
├── employee/ (7 ไฟล์)
├── api/
│   ├── calendar_events.php # FullCalendar feed (JSON)
│   └── download.php        # serve ไฟล์ + auth check
├── templates/emails/       # HTML email templates
├── index.php               # login + router
├── change_password.php
├── logout.php
└── (no SQL ที่ root — ย้ายไป database/ แล้ว)
```

**กฎโครงสร้าง:**
- ทุกไฟล์ใน `admin/`, `director/`, `employee/` **ต้อง** `require_once 'includes/auth.php'` บรรทัดแรก แล้วเรียก `require_role('admin')` / `require_role('director')` / `require_role('employee')` ตาม folder
- **ห้ามลิงก์ไปยัง `/uploads/...` ตรง ๆ** ใช้ `api/download.php?type=cert&id=...` เสมอ

---

## 5. Database (14 ตาราง — ดู `database/schema.sql`)

`departments` → `users` → `activities` → `activity_photos` / `activity_attachments` / `activity_registrations` → `documents` / `certificates` + `email_queue` / `email_logs` / `notification_settings` / `audit_logs` + `fiscal_years` / `activity_types`

**สิ่งที่ต้องจำ:**
- `activities.scope`: `'organization'` | `'personal'` — เป็นเส้นแบ่ง permission ทั้งระบบ
- `activity_registrations.status`: `'registered'` | `'attended'` | `'absent'`
- `users.must_change_password = 1` ทุกคน seed → บังคับเปลี่ยนรหัสครั้งแรก
- `email_queue.status`: `'pending'` | `'sending'` | `'sent'` | `'failed'`, retry สูงสุด 3
- `notification_settings` มี 2 keys: `notify_new_activity`, `notify_new_certificate`
- Unique keys ที่ห้ามลืม: `users.username`, `users.email`, `activity_registrations(activity_id, user_id)`, `certificates(activity_id, user_id)`

**แก้ schema:**
- ทุกครั้งที่เพิ่ม/แก้ตาราง → อัปเดต `database/schema.sql` ด้วย **ห้ามแก้แค่ DB จริง**
- ใช้ `utf8mb4_unicode_ci` ทุกตาราง (รองรับภาษาไทย + emoji)

---

## 6. Roles & Permissions Matrix

| ฟีเจอร์ | Admin | Director | Employee |
|---|:---:|:---:|:---:|
| จัดการ users / fiscal year / activity types | ✅ | ❌ | ❌ |
| สร้าง/แก้ organization activity | ✅ | 👁️ ดู | 👁️ เฉพาะที่ตนเข้าร่วม |
| สร้าง personal activity | — | — | ✅ (ของตัวเอง) |
| เห็น personal ของคนอื่น | ❌ | ❌ | ❌ |
| เช็คชื่อ / อัปโหลดเอกสาร / อัปโหลดเกียรติบัตร | ✅ | ❌ | ❌ |
| สมัครเข้าร่วมเอง (เฉพาะที่ `is_open_registration=1`) | — | — | ✅ |
| ดู report / calendar | ✅ ทั้งหมด | ✅ org scope | ✅ ของตัวเอง |
| รับ email notification | ✅ ถ้าเป็น participant | ✅ ถ้าเป็น participant | ✅ ถ้าเป็น participant |

**Director middleware (เพิ่มใน `includes/auth.php`):**
```php
// Whitelist: หน้าที่ director ต้อง POST ได้ (ไม่งั้น lock ตัวเองเพราะ must_change_password=1)
$allow_post_for_director = ['change_password.php', 'logout.php'];
$current_script = basename($_SERVER['SCRIPT_NAME']);

if (
    $_SESSION['role'] === 'director'
    && $_SERVER['REQUEST_METHOD'] !== 'GET'
    && !in_array($current_script, $allow_post_for_director, true)
) {
    http_response_code(403);
    exit('Director is read-only');
}
```

---

## 7. Security Checklist (ทุกครั้งที่เพิ่ม endpoint ใหม่)

- [ ] บรรทัดแรก: `require_once 'includes/auth.php'; require_role('...');`
- [ ] ทุก SQL ใช้ `prepare()` + `execute([...])`
- [ ] ทุก echo จาก DB / `$_POST` / `$_GET` ผ่าน `htmlspecialchars()`
- [ ] ถ้าเป็น POST: `verify_csrf_token($_POST['csrf_token'])` ก่อนทำอะไร
- [ ] ถ้ารับ user_id ในการเข้าถึง personal data: เทียบกับ `$_SESSION['user_id']` (Employee)
- [ ] ถ้า upload: `finfo_file()` → check whitelist → UUID rename → ย้ายไป `uploads/<sub>/`
- [ ] ถ้า download: `basename()` ทุกครั้ง + เช็คสิทธิ์ก่อน `readfile()`
- [ ] ขนาดไฟล์: เอกสาร ≤ 10MB, ภาพ ≤ 5MB, เกียรติบัตร PDF ≤ 10MB / JPG-PNG ≤ 5MB
- [ ] ถ้าเป็น action สำคัญ (สร้าง/ลบ/อัปโหลดเกียรติบัตร): insert `audit_logs`

---

## 8. Mobile-first Checklist (ทุกหน้าใหม่)

- [ ] ใช้ Bootstrap grid `col-12 col-md-6 col-lg-4` ห้ามใช้ width fixed px
- [ ] ตาราง wide → wrap ด้วย `.table-responsive` หรือ render เป็น card บน mobile
- [ ] ปุ่ม / input ขนาดต่ำสุด 44×44px
- [ ] Modal: `modal-fullscreen-sm-down`
- [ ] FullCalendar: `initialView: window.innerWidth < 768 ? 'listMonth' : 'dayGridMonth'`
- [ ] File input รับภาพ: `<input type="file" accept="image/*" capture="environment">`
- [ ] เปิดทดสอบที่ DevTools 375×667 (iPhone SE) ก่อน commit

---

## 9. Email Notification (2 triggers เท่านั้น)

| Trigger | ตอนไหน | ผู้รับ |
|---|---|---|
| `new_activity` | Admin เพิ่ม user เข้า `activity_registrations` | user ที่ถูกเพิ่ม |
| `new_certificate` | Admin upload `certificates` | เจ้าของเกียรติบัตร |

**Flow:**
1. Trigger เกิด → check `notification_settings` ว่า `setting_value = '1'` ไหม
2. ถ้าเปิด → render template `templates/emails/<trigger>.php` → INSERT `email_queue`
3. Cron `cron/send_emails.php` ทุก 5 นาที → SELECT pending → ส่ง → UPDATE status + INSERT `email_logs`
4. Failed 3 ครั้ง → `status = 'failed'` หยุดส่ง

**Rate limit:** ≤ 50 emails/ชั่วโมงต่อ trigger (กันสแปม)

---

## 10. Dev Workflow

**Setup ครั้งแรก:**
```bash
mysql -u root -p < database/schema.sql
cp .env.example .env   # แก้ DB + SMTP
php -S localhost:8000  # ทดสอบ local
```

**Cron:**
```cron
*/5 * * * * /usr/bin/php /path/activitydms/cron/send_emails.php >> /var/log/activitydms-mail.log 2>&1
```

**Test accounts (dev เท่านั้น — ห้าม commit ขึ้น production):**

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `Admin@2026` |
| Director | `director` | `Director@2026` |
| Employee | `kobsak.na` | `Skru@2026` |

> ทุกบัญชี seed มี `must_change_password = 1` → ครั้งแรก login จะถูกบังคับเปลี่ยนรหัส

---

## 11. Phased Roadmap (แผนลำดับงาน)

ทำตามลำดับ phase 1 → 12 (ดูสเปคข้อ 10) — อย่ากระโดดข้าม phase

ตอนนี้สถานะ:
- ✅ Phase 1: Schema + seed STAFF_ARIT (`database/schema.sql` พร้อมแล้ว)
- ⏭️ Phase 2: Login + auth + role guard ← **เริ่มที่นี่**

---

## 12. Decision Log (ปิดแล้ว — อย่าถามซ้ำ)

| # | คำถาม | คำตอบ |
|:-:|---|---|
| 1 | Auto-generate certificate? | ❌ Upload only |
| 2 | Email notification? | ✅ 2 triggers |
| 3 | Personal activity ใครเห็น? | เจ้าของเท่านั้น |
| 4 | Mobile responsive ระดับ? | Mobile-first ใช้งานจริง |
| 5 | Director permission? | Read-only ทั้งระบบ |

---

## 13. เมื่อไม่แน่ใจ

1. อ่าน `docs/ActivityDMS_Spec_v1_1.md` ก่อน — เป็น single source of truth
2. ถ้ายังไม่เคลียร์ → **ถามก่อน อย่าเดา** — โดยเฉพาะเรื่องสิทธิ์/security
3. ถ้าเป็นเรื่องที่อยู่ใน Decision Log แล้ว (ข้อ 12) → ไม่ต้องถาม ทำตามนั้น
