# Activity & Document Management System (ActivityDMS) — Unified Spec v1.1

> เอกสารสเปคฉบับรวมของระบบจัดการกิจกรรมและเอกสารองค์กร  
> รวมจากเอกสารต้นฉบับ 2 ฉบับ + ข้อมูลบุคลากรจริง `STAFF_ARIT.xlsx`  
> **v1.1** — ปิดข้อตัดสินใจครบ 5 ข้อ + เพิ่ม Email Notification + ระบุ Mobile-first ชัดเจน

---

## 1. ภาพรวมระบบ

ระบบเว็บแอปพลิเคชันสำหรับจัดการข้อมูลการเข้าร่วมกิจกรรม การประชุม การอบรม เอกสาร และเกียรติบัตรภายในองค์กร (สำนักวิทยบริการและเทคโนโลยีสารสนเทศ — ARIT) โดยแบ่งสิทธิ์การใช้งาน 3 ระดับ

**ขอบเขตการใช้งาน:** หน่วยงานภายใน 3 แผนก รวมบุคลากร 29 คน

| รหัสแผนก | ชื่อแผนก | จำนวนบุคลากร |
|:---:|---|:---:|
| 1 | งานบริหารทั่วไป | 5 |
| 2 | งานเทคโนโลยีสารสนเทศดิจิทัล | 9 |
| 3 | งานทรัพยากรสารสนเทศและภาษาต่างประเทศ | 15 |

---

## 2. Tech Stack

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3, JavaScript (Vanilla / jQuery) |
| UI Framework | Bootstrap 5 + Bootstrap Icons (**mobile-first**) |
| Backend | Pure PHP 8.x |
| Database | MySQL 8.x (เชื่อมต่อผ่าน PDO + Prepared Statements) |
| Calendar UI | FullCalendar.js (สำหรับรายงานปฏิทิน) |
| Email | PHPMailer + SMTP (เช่น Gmail App Password / SMTP องค์กร) |
| File Handling | PHP native + การ validate MIME type |

---

## 3. Roles & Permissions

ระบบมี 3 บทบาท สิทธิ์สรุปดังนี้

### 3.1 Admin (ผู้ดูแลระบบ) — สิทธิ์เต็ม
- จัดการบัญชีผู้ใช้ (เพิ่ม/ลบ/แก้ไข/รีเซ็ตรหัสผ่าน)
- ตั้งค่าปีงบประมาณ (เลือกเดือนเริ่ม-จบ)
- สร้าง/แก้ไข/ลบ **กิจกรรมขององค์กร** ทุกประเภท
- เช็คชื่อผู้เข้าร่วมกิจกรรม
- เปิด/ปิดให้พนักงานสมัครเข้าร่วมกิจกรรมได้เอง
- อัปโหลดเอกสาร (วาระ, รายงาน, สไลด์, สื่ออบรม)
- **อัปโหลด** เกียรติบัตรรายบุคคล (ไม่มี generate อัตโนมัติ)
- ตั้งค่า Email Notification (เปิด/ปิด, ตั้งค่า SMTP)
- ดูรายงานสรุปทั้งหมด (รายแผนก, รายบุคคล, รายเดือน, รายปี)

### 3.2 Director (ผู้อำนวยการ) — Read-only ทั้งระบบ
- ดู Dashboard ภาพรวมและสถิติทั้งหมด
- ดูรายละเอียดกิจกรรมทุกประเภท (รวม personal ของ Employee? **ไม่** — ดูแค่ organization scope)
- ดาวน์โหลดเอกสารประกอบทั้งหมด
- ดูรายงานสรุปและสถิติการเข้าร่วม (รายแผนก, รายบุคคล, ปฏิทิน)
- **ไม่มีสิทธิ์แก้ไข/ลบ/อัปโหลด ใดๆ**

### 3.3 Employee (พนักงาน) — สิทธิ์ส่วนตัว
- ดูเฉพาะ**กิจกรรมขององค์กรที่ตนเองเข้าร่วม**
- สมัครเข้าร่วมกิจกรรมที่ Admin เปิดให้สมัครเองได้
- เพิ่ม/แก้ไข/ลบ **กิจกรรมส่วนตัว** ของตนเองได้ — **เห็นได้แค่ตัวเองเท่านั้น** (Admin/Director ไม่เห็น)
- ดาวน์โหลดเอกสารทั่วไปและเกียรติบัตรของตนเอง
- ดูรายงานของตนเอง (รายเดือน/รายปี/ปฏิทิน)
- รับ Email แจ้งเตือนเมื่อมีกิจกรรมใหม่ที่ตนถูกเพิ่ม + เมื่อได้รับเกียรติบัตรใหม่

> ⚠️ **กฎสำคัญ:**
> 1. Employee แก้กิจกรรมขององค์กรไม่ได้ — แก้ได้เฉพาะกิจกรรมส่วนตัวที่ `created_by = user_id`
> 2. Personal activity เป็นพื้นที่ส่วนตัว 100% — Admin/Director **ไม่เห็น** เลย

---

## 4. ฟีเจอร์หลัก

### 4.1 ปีงบประมาณ (Fiscal Year)
- Admin ตั้งค่าเดือนเริ่ม-จบของปีงบประมาณได้ (เช่น ต.ค.–ก.ย. ตามราชการไทย)
- กิจกรรมทุกรายการผูกกับปีงบประมาณ → ใช้กรองรายงาน

### 4.2 กิจกรรม (Activity)
ฟิลด์หลัก:
- ชื่อกิจกรรม, รายละเอียด, สถานที่, วัน-เวลา เริ่ม/สิ้นสุด
- **ประเภท (Tag):** ประชุม / อบรม / สัมมนา / อื่นๆ
- **ขอบเขต (Scope):** `organization` (Admin สร้าง) | `personal` (Employee สร้างให้ตนเอง)
- ปีงบประมาณ
- เปิดให้สมัครเข้าร่วมเองได้หรือไม่ (`is_open_registration`)
- ผู้เข้าร่วม (Admin เพิ่มได้เอง หรือพนักงานสมัครเอง)
- ลิงก์ภายนอก (URL) + ไฟล์ PDF สรุป
- ภาพถ่ายกิจกรรม (สูงสุด **5 ภาพ**)

### 4.3 การเข้าร่วม (Attendance)
- Admin เช็คชื่อรายกิจกรรม
- พนักงานเลือกสมัครเข้าร่วมเองได้ (เฉพาะกิจกรรมที่ Admin เปิดอนุญาต)
- บันทึกสถานะ: `registered` / `attended` / `absent`

### 4.4 เอกสาร
- เอกสารประชุม / รายงาน / สื่อการอบรม → ผูกกับกิจกรรม
- ดาวน์โหลดผ่าน PHP script ที่เช็คสิทธิ์ (ไม่ลิงก์ตรงไปยังไฟล์)

### 4.5 เกียรติบัตร (Upload-only)
- Admin **อัปโหลด** ไฟล์เกียรติบัตร (PDF/JPG/PNG) ผูกกับ user + activity
- 1 ไฟล์ต่อคนต่อกิจกรรม
- พนักงานเห็นเฉพาะเกียรติบัตรของตัวเอง
- **ไม่มีระบบ generate อัตโนมัติจาก template** (อาจเพิ่มในเฟสถัดไปถ้าต้องการ)

### 4.6 Email Notification
ระบบส่ง email แจ้งเตือนอัตโนมัติใน 2 เหตุการณ์:

| Trigger | ผู้รับ | เนื้อหา |
|---|---|---|
| Admin เพิ่มผู้เข้าร่วมในกิจกรรมใหม่ | ผู้ที่ถูกเพิ่ม | ชื่อ/วัน/สถานที่กิจกรรม + ลิงก์เข้าระบบ |
| Admin อัปโหลดเกียรติบัตรใหม่ | เจ้าของเกียรติบัตร | ชื่อกิจกรรม + ลิงก์ดาวน์โหลด |

**ข้อกำหนดการทำงาน:**
- ใช้ PHPMailer + SMTP (configurable ใน `config/mailer.php`)
- ส่งแบบ queue (เก็บลง `email_queue`) แล้วใช้ cron job ส่งทุก 5 นาที — ป้องกันหน้าเว็บค้างถ้า SMTP ช้า
- มีตาราง `email_logs` บันทึก success/fail + retry สูงสุด 3 ครั้ง
- Admin ปิด/เปิดการแจ้งเตือนแต่ละ trigger ได้ในหน้า settings
- ใช้ HTML template + plain-text fallback

### 4.7 รายงาน
- Dashboard สรุปจำนวนกิจกรรมตามประเภท/แผนก/ปี
- รายงานรายบุคคล (รายเดือน/รายปี)
- รายงานรายแผนก/กลุ่มผู้ใช้
- มุมมองปฏิทิน (FullCalendar) แสดงกิจกรรมทั้งหมด/ของฉัน
- Export เป็น Excel/PDF (อนาคต)

---

## 5. UI/UX Requirements (Mobile-First)

ระบบต้องใช้งานจริงบนมือถือได้ — **ไม่ใช่แค่เปิดดูได้**

### 5.1 Responsive Breakpoints (Bootstrap 5 default)
- Mobile: < 576px (เป้าหมายหลัก)
- Tablet: 576–991px
- Desktop: ≥ 992px

### 5.2 หลักออกแบบที่ต้องปฏิบัติ
- ใช้ Grid system ของ Bootstrap (`col-12 col-md-6 col-lg-4` ฯลฯ) ทุก layout
- Navbar collapse เป็น hamburger menu บน mobile
- ตาราง wide → ใช้ `.table-responsive` หรือ card view บน mobile (≤ 576px)
- ปุ่ม/ฟอร์ม touch-friendly: ขนาดต่ำสุด 44×44px
- File upload รองรับ camera capture บน mobile (`<input type="file" accept="image/*" capture="environment">`) — สำหรับการอัปโหลดภาพกิจกรรม
- Calendar view สลับเป็น `listMonth` อัตโนมัติบน mobile
- Modal/Dialog ใช้ full-screen บนหน้าจอเล็ก
- ทดสอบจริงบนหน้าจอ ≤ 380px (iPhone SE/มือถือเล็ก)

---

## 6. โครงสร้างฐานข้อมูล (Schema Overview)

ตารางที่ต้องมีอย่างน้อย **13 ตาราง** (รายละเอียดเต็มใน `database.sql`)

| ตาราง | หน้าที่ | จุดสำคัญ |
|---|---|---|
| `departments` | แผนก | seed จาก Excel (3 แผนก) |
| `users` | บัญชีผู้ใช้ | + `role`, `password_hash`, `is_active` |
| `fiscal_years` | ปีงบประมาณ | `start_month`, `end_month`, `is_active` |
| `activity_types` | ประเภทกิจกรรม (Tag) | seed: ประชุม, อบรม, สัมมนา, อื่นๆ |
| `activities` | กิจกรรม | + `scope` ('organization'/'personal'), `is_open_registration`, `created_by` |
| `activity_photos` | ภาพถ่ายกิจกรรม | จำกัดสูงสุด 5 (validate ทั้ง app และ DB trigger) |
| `activity_attachments` | ไฟล์/ลิงก์สรุป | type: 'file' หรือ 'url' |
| `activity_registrations` | การสมัครเข้าร่วม | `status`: registered/attended/absent |
| `documents` | เอกสารทั่วไป | ผูกกับ activity (optional) |
| `certificates` | เกียรติบัตร | ผูก user + activity |
| `email_queue` | คิวอีเมลรอส่ง | ใช้ cron ดึงไปส่ง |
| `email_logs` | ประวัติการส่งอีเมล | บันทึก success/fail + retry count |
| `notification_settings` | ตั้งค่าเปิด/ปิด trigger | key-value: `notify_new_activity`, `notify_new_certificate` |
| `audit_logs` | บันทึกการใช้งาน | (optional) ติดตามการกระทำสำคัญ |

### 6.1 การ Map ข้อมูลจาก STAFF_ARIT.xlsx → users
- `STAFFID` → `users.staff_code`
- `LOGIN` → `users.username`
- `PREFIXNAME + STAFFNAME + STAFFSURNAME` → ใช้รวม display_name
- `MAIL` → `users.email`
- `POSITIONNAME`, `STAFFTYPENAME` → เก็บไว้แสดงผล
- `DEPARTMENTID` → `users.department_id`
- **เพิ่ม** `role` (default = `employee`), `password_hash` (default + reset on first login), `is_active = 1`

> ⚠️ **ในรายชื่อ 29 คน ไม่มี "ผู้อำนวยการ" (Director)** ต้องสร้างบัญชี director เพิ่มเอง 1 บัญชี

---

## 7. ความปลอดภัย (Security Requirements)

### 7.1 Authentication
- เก็บรหัสผ่านด้วย `password_hash($pwd, PASSWORD_BCRYPT)` เท่านั้น
- ตรวจสอบด้วย `password_verify()` — ห้ามเปรียบเทียบ string ตรง
- บังคับเปลี่ยนรหัสผ่านครั้งแรกเข้าใช้งาน
- รหัสผ่านอย่างน้อย 8 ตัวอักษร (mix ตัวอักษร+ตัวเลข)
- Session: `session_regenerate_id(true)` หลัง login สำเร็จ
- Cookie flags: `HttpOnly`, `Secure` (ถ้าใช้ HTTPS), `SameSite=Lax`
- Session timeout 30 นาทีไม่มี activity

### 7.2 Input/Output
- **SQL Injection:** ใช้ PDO Prepared Statements ทุกที่
- **XSS:** ทุก output ใช้ `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`
- **CSRF:** ทุก form POST ต้องมี token

### 7.3 File Upload
- ตรวจ MIME type จริงด้วย `finfo_file()` (ไม่เชื่อ extension)
- จำกัดประเภทไฟล์ตามหมวด:
  - เอกสาร: pdf, doc, docx, xls, xlsx, ppt, pptx
  - ภาพ: jpg, jpeg, png, webp
  - เกียรติบัตร: pdf, jpg, png
- ขนาดไฟล์: เอกสาร ≤ 10MB, ภาพ ≤ 5MB
- เปลี่ยนชื่อไฟล์ใหม่ (UUID) — ห้ามใช้ชื่อเดิมจาก client
- เก็บไฟล์**นอก** document root และ serve ผ่าน PHP script ที่เช็คสิทธิ์
- ป้องกัน path traversal: ตรวจ `basename()` เสมอ

### 7.4 Authorization
- ทุก endpoint ต้องเช็ค session + role ผ่าน `includes/auth.php`
- การเข้าถึงข้อมูลรายบุคคลต้องเทียบ `user_id == session_user_id` (สำหรับ Employee)
- Director middleware: บล็อก HTTP method `POST/PUT/DELETE` ทั้งระบบ

### 7.5 Email Security
- เก็บ SMTP password ในไฟล์ config นอก git (เช่น `.env`)
- Sanitize เนื้อหา email ก่อนส่ง (ป้องกัน header injection)
- Rate limit การส่ง: ไม่เกิน 50 emails/ชั่วโมงต่อ trigger

---

## 8. โครงสร้างโปรเจกต์

```text
activitydms/
├── assets/                  # CSS, JS, รูปภาพ static
├── config/
│   ├── database.php         # PDO connection
│   ├── mailer.php           # SMTP config (PHPMailer)
│   └── config.php           # APP_NAME, BASE_URL, UPLOAD limits
├── includes/
│   ├── auth.php             # session check + role guard
│   ├── csrf.php             # generate/verify CSRF token
│   ├── upload.php           # file validation helpers
│   ├── mailer.php           # send_email() helper + queue
│   ├── header.php
│   └── footer.php
├── cron/
│   └── send_emails.php      # cron job ส่ง email queue ทุก 5 นาที
├── uploads/                 # อยู่นอก public root ถ้าทำได้ — git-ignore
│   ├── meetings/
│   ├── documents/
│   ├── activities/          # ภาพกิจกรรม
│   └── certificates/
├── admin/
│   ├── dashboard.php
│   ├── manage_users.php
│   ├── manage_fiscal_year.php
│   ├── manage_activities.php
│   ├── activity_attendance.php
│   ├── manage_documents.php
│   ├── manage_certificates.php
│   └── notification_settings.php
├── director/
│   ├── dashboard.php
│   ├── reports.php
│   └── calendar.php
├── employee/
│   ├── dashboard.php
│   ├── my_activities.php
│   ├── personal_activities.php
│   ├── available_activities.php
│   ├── my_certificates.php
│   ├── my_reports.php
│   └── calendar.php
├── api/
│   ├── calendar_events.php
│   └── download.php         # serve ไฟล์ผ่าน PHP + เช็คสิทธิ์
├── templates/
│   └── emails/              # HTML templates
│       ├── new_activity.php
│       └── new_certificate.php
├── index.php                # Login + router
├── change_password.php
├── logout.php
└── database.sql             # schema + seed
```

---

## 9. ข้อมูลเริ่มต้น (Seed)

1. **`departments`** — 3 records จาก `STAFF_ARIT.xlsx` sheet `tb_department`
2. **`users`** — 29 records จาก sheet `data` + บัญชี `director` 1 + บัญชี `admin` system 1 = **รวม 31 records**
3. **`activity_types`** — 4 records: ประชุม, อบรม, สัมมนา, อื่นๆ
4. **`fiscal_years`** — 1 record (ปีปัจจุบัน, ต.ค.–ก.ย.)
5. **`notification_settings`** — 2 records (เปิดทั้ง 2 trigger เป็น default)

### บัญชีทดสอบ (Development เท่านั้น)

| Role | Username | Password (ก่อน hash) | หมายเหตุ |
|---|---|---|---|
| Admin | `admin` | `Admin@2026` | บัญชีระบบ |
| Director | `director` | `Director@2026` | เพิ่มเอง — ไม่มีในไฟล์ Excel |
| Employee | `kobsak.na` | `Skru@2026` | ตัวอย่างจาก STAFF_ARIT (DEPT 3) |

---

## 10. แผนพัฒนาแนะนำ (Phased Roadmap)

| Phase | งาน | หมายเหตุ |
|:---:|---|---|
| 1 | Database schema + seed STAFF_ARIT | ออกแบบ ER + เขียน `database.sql` |
| 2 | Login + Auth + Role Guard | ครอบคลุม security ในข้อ 7 |
| 3 | Admin: User & Fiscal Year & Activity Type | CRUD พื้นฐาน |
| 4 | Admin: Activity + Photo (max 5) + Attachment | upload validation |
| 5 | Admin: Attendance + Certificate Upload | + trigger email queue |
| 6 | Email Notification (PHPMailer + cron) | ทดสอบส่งจริง |
| 7 | Employee: My Activities + Personal Activities | สิทธิ์ส่วนตัว |
| 8 | Employee: Available Activities (สมัครเอง) | ขึ้นกับ flag เปิดสมัคร |
| 9 | Reports + Calendar (ทุก role) | FullCalendar + responsive |
| 10 | Director Read-only views | ใช้ component ร่วมกับ Admin |
| 11 | Mobile UI polish + cross-device test | iPhone SE, iPad, Android |
| 12 | Hardening + UAT | audit log, error log, backup plan |

---

## 11. Decision Log (บันทึกการตัดสินใจ)

ข้อตัดสินใจที่ได้ confirm แล้วระหว่างจัดทำสเปคนี้

| # | คำถาม | คำตอบ |
|:---:|---|---|
| 1 | เกียรติบัตร generate อัตโนมัติ? | ❌ อัปโหลดไฟล์อย่างเดียว |
| 2 | Email Notification? | ✅ ต้องการ — แจ้งเมื่อมีกิจกรรมใหม่ + เกียรติบัตรใหม่ |
| 3 | Personal activity เห็นได้ใคร? | เห็นได้แค่เจ้าของเท่านั้น (Admin/Director ไม่เห็น) |
| 4 | Mobile responsive ระดับไหน? | ใช้งานจริงบนมือถือได้ — Mobile-first |
| 5 | สิทธิ์ Director? | Read-only ทั้งระบบ |

---

*เอกสารนี้แทนไฟล์เดิม `Activity & Document Management System.md` และ `ระบบจัดการกิจกรรมองค์กร ActivityDM.md` ทั้งคู่ — Single Source of Truth*
