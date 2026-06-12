# ActivityDMS

ระบบจัดการกิจกรรม / ประชุม / อบรม / เอกสาร / เกียรติบัตร ของสำนักวิทยบริการและเทคโนโลยีสารสนเทศ (ARIT)
ใช้ภายในองค์กร 3 แผนก รวม **31 บัญชี** (Admin 1 / Director 1 / Employee 29)

> เอกสารฉบับเต็ม: [`docs/ActivityDMS_Spec_v1_1.md`](docs/ActivityDMS_Spec_v1_1.md)
> กติกาสำหรับ AI / Dev: [`CLAUDE.md`](CLAUDE.md)
> Schema + seed: [`database/schema.sql`](database/schema.sql)

---

## 1. ระบบทำอะไร (สรุปสั้น)

- **Admin** — จัดการ users / fiscal year / activity types, สร้างกิจกรรม, เช็คชื่อ, อัปโหลดเอกสาร + เกียรติบัตร
- **Director** — read-only ทั้งระบบ (ดู report / calendar / กิจกรรม organization scope)
- **Employee** — ดูกิจกรรมที่ตนเข้าร่วม, สร้าง **personal activity** ส่วนตัว (private 100%), สมัครเข้ากิจกรรมที่เปิดรับ, รับเกียรติบัตร

**3 scope ของกิจกรรม:**
- `organization` — Admin สร้าง, Director/Employee เห็นได้ (ตามสิทธิ์)
- `personal` — Employee สร้างเอง, **Admin/Director ห้ามเห็น**

**Email notification** มี 2 trigger เท่านั้น: เพิ่ม user เข้ากิจกรรม + อัปโหลดเกียรติบัตรใหม่ (ส่งผ่าน queue + cron)

---

## 2. Tech Stack

| Layer | Stack |
|---|---|
| Frontend | HTML5 / CSS3 / Vanilla JS + jQuery, Bootstrap 5 + Bootstrap Icons |
| Calendar | FullCalendar.js |
| Backend | **Pure PHP 8.x** (no framework) |
| DB | MySQL 8.x ผ่าน PDO |
| Email | PHPMailer + SMTP (queue + cron) |
| Config | vlucas/phpdotenv |

> Tech stack ตายตัวตามข้อกำหนดลูกค้า — ดู [CLAUDE.md §3](CLAUDE.md)

---

## 3. โครงสร้างโปรเจกต์ (ย่อ)

```
activitydms/
├── admin/  director/  employee/   # หน้าแยกตาม role
├── api/                            # calendar_events.php, download.php
├── assets/                         # CSS/JS/img
├── config/                         # database.php, mailer.php, config.php
├── cron/send_emails.php            # cron ทุก 5 นาที
├── includes/                       # auth.php, csrf.php, upload.php, header/footer
├── templates/emails/               # HTML email templates
├── uploads/                        # ไฟล์อัปโหลด (ห้ามอยู่ใน document root จริง)
├── docs/                           # สเปคเต็ม + summary แต่ละ phase
├── database/                       # schema + seed (ห้าม serve ผ่าน web)
│   ├── schema.sql                  # 14 tables + seed STAFF_ARIT
│   └── seed_mock_data.sql
├── seed_mock_data.sql              # ข้อมูลทดสอบเพิ่มเติม
└── .env.example                    # template ของ .env
```

---

## 4. การติดตั้ง (Dev Setup)

### 4.1 Requirement
- PHP **8.1+** (แนะนำ 8.2) พร้อม extension: `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `gd` หรือ `imagick`
- MySQL **8.x** (หรือ MariaDB 10.6+)
- Composer 2.x
- (Optional แต่แนะนำ) XAMPP / Laragon บน Windows

### 4.2 ขั้นตอน (Windows)

```powershell
# 1) Clone & เข้าโฟลเดอร์
git clone <repo-url> activitydms
cd activitydms

# 2) เปิด OpenSSL extension ใน PHP
#    หาตำแหน่ง php.ini:
php -r "echo php_ini_loaded_file();"

#    เปิดไฟล์ php.ini แล้วหา `;extension=openssl` 
#    เปลี่ยนเป็น `extension=openssl` (เอา ; ออก)
#    Save แล้ว restart terminal

# 3) ติดตั้ง dependencies via Composer
composer install

# 4) สร้าง .env ไฟล์ (copy จาก template)
copy .env.example .env
# แก้ค่า:
#   DB_HOST=127.0.0.1
#   DB_PASSWORD=<รหัส MySQL ของคุณ>
#   UPLOAD_PATH=D:/activitydms-uploads  (หรือ absolute path ที่คุณต้องการ)

# 5) สร้าง database + import schema
mysql -u root -p -e "CREATE DATABASE activitydms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p activitydms < database/schema.sql
# (optional) เพิ่มข้อมูลทดสอบ:
mysql -u root -p activitydms < seed_mock_data.sql

# 6) สร้างโฟลเดอร์ uploads (นอก document root)
mkdir D:\activitydms-uploads
mkdir D:\activitydms-uploads\meetings
mkdir D:\activitydms-uploads\documents
mkdir D:\activitydms-uploads\activities
mkdir D:\activitydms-uploads\certificates

# 7) รัน dev server
php -S localhost:8000
```

เปิด **`http://localhost:8000`** ในเบราว์เซอร์

### 4.3 Test Accounts (dev เท่านั้น)

| Role | Username | Password |
|---|---|---|
| Admin | `admin` | `Admin@2026` |
| Director | `director` | `Director@2026` |
| Employee | `kobsak.na` | `Skru@2026` |

> ทุกบัญชี seed มี `must_change_password = 1` → ครั้งแรก login จะถูกบังคับเปลี่ยนรหัส

### 4.4 Cron (Production)

```cron
*/5 * * * * /usr/bin/php /path/to/activitydms/cron/send_emails.php >> /var/log/activitydms-mail.log 2>&1
```

บน Windows ใช้ **Task Scheduler** สั่ง `php.exe` ตัวเดียวกันทุก 5 นาที

---

## 5. Troubleshooting (เมื่อใช้งานไม่ได้)

### 🔴 5.1 หน้าเว็บขาว / 500 Internal Server Error
1. ตั้ง `APP_DEBUG=true` ใน `.env` แล้ว reload
2. ดู log:
   - PHP built-in server → terminal ที่รัน `php -S`
   - Apache → `logs/error.log` ของ XAMPP/Laragon
   - แอป → [`errors/`](errors/) (ถ้ามี custom error handler)
3. ถ้า error ชี้ที่ `vendor/autoload.php` → ลืมรัน `composer install`

### 🔴 5.1a OpenSSL ไม่เปิด / "composer install" ล้มเหลว
**Error:** `The openssl extension is required for SSL/TLS protection but is not available`

**วิธีแก้:**
1. หาตำแหน่ง `php.ini`:
   ```powershell
   php -r "echo php_ini_loaded_file();"
   ```
2. เปิดไฟล์ `php.ini` แล้วหา `;extension=openssl` (มีเซมิโคลอน)
3. เปลี่ยนเป็น `extension=openssl` (เอา `;` ออก)
4. Save และ restart terminal
5. เช็ค:
   ```powershell
   php -r "echo extension_loaded('openssl') ? 'ENABLED' : 'DISABLED';"
   ```
6. รัน `composer install` อีกครั้ง

### 🔴 5.1b ไม่มี `.env` ไฟล์ / "Unable to read any of the environment file(s)"
**Error:** `Dotenv\Exception\InvalidPathException: Unable to read any of the environment file(s) at [D:\activitydms\config/..\.env]`

**วิธีแก้:**
1. ตรวจว่าไฟล์ `.env.example` มี ✓
2. Copy ไฟล์:
   ```powershell
   copy .env.example .env
   ```
3. เปิด `.env` แล้วแก้:
   ```ini
   DB_HOST=127.0.0.1
   DB_PASSWORD=<รหัส MySQL ของคุณ>
   UPLOAD_PATH=D:/activitydms-uploads
   ```
4. Save

### 🔴 5.1c CSS/JS/Font ไม่โหลด (404 error) / หน้าขาวหรือ unstyled
**Error:** `[404]: GET /assets/vendor/bootstrap/css/bootstrap.min.css - No such file or directory`

**วิธีแก้:** ระบบใช้ **CDN** ทั้งหมดสำหรับ Bootstrap, Icons, Kanit font, Chart.js, FullCalendar
- ไม่ต้องดาวน์โหลด vendor libraries ด้วยตัวเอง
- ไฟล์ CSS/JS โหลดจากที่นี่: [`includes/header.php`](includes/header.php) + [`includes/footer.php`](includes/footer.php)
- ถ้าโหลดไม่ได้ → เช็คการเชื่อมต่ออินเทอร์เน็ต (CDN ต้องเข้าถึงได้)

### 🔴 5.2 Login ไม่ได้ / "Invalid credentials"
- ตรวจว่า import `database/schema.sql` แล้ว (ตาราง `users` ควรมี 31 แถว)
- เช็ค `users.password_hash` ต้องขึ้นต้นด้วย `$2y$` (bcrypt)
- ถ้าเปลี่ยน password seed เอง → ต้อง hash ด้วย `password_hash($pwd, PASSWORD_BCRYPT)` ห้าม md5/plain

### 🔴 5.3 "could not find driver" (PDO)
เปิด extension ใน `php.ini`:
```ini
extension=pdo_mysql
extension=mbstring
extension=fileinfo
```
แล้ว restart PHP/Apache

### 🔴 5.4 ตัวอักษรไทยเป็น `???` หรือเพี้ยน
- DB ต้องเป็น `utf8mb4` + collation `utf8mb4_unicode_ci` (ห้ามใช้ utf8/latin1)
- PDO connection ต้องมี `charset=utf8mb4` ใน DSN ([`config/database.php`](config/database.php))
- HTML header: `<meta charset="UTF-8">` (อยู่ใน [`includes/header.php`](includes/header.php) แล้ว)

### 🔴 5.5 Upload fail / "ขนาดไฟล์เกิน"
- เช็ค `php.ini`: `upload_max_filesize`, `post_max_size`, `memory_limit` ต้องใหญ่กว่าค่าใน `.env`
- โฟลเดอร์ `UPLOAD_PATH` ต้อง **เขียนได้** (Linux: `chmod 755` + owner เป็น user ที่รัน PHP)
- ถ้า "Mime type not allowed" → ระบบใช้ `finfo_file()` ตรวจ MIME จริง ไม่เชื่อ extension — ตรวจว่าไฟล์เป็นชนิดที่อนุญาตจริง

### 🔴 5.6 ดาวน์โหลดไฟล์/เกียรติบัตรไม่ได้ (404)
- ทุกไฟล์ serve ผ่าน [`api/download.php`](api/download.php) — **ห้ามลิงก์ `/uploads/...` ตรง ๆ**
- เช็คว่า `UPLOAD_PATH` ใน `.env` ชี้ตรงกับที่ไฟล์อยู่จริง
- เช็คสิทธิ์ผู้ใช้ (Employee ดาวน์โหลดได้แค่ของตัวเอง)

### 🔴 5.7 Email ไม่ส่ง
1. INSERT เข้า `email_queue` สำเร็จไหม? → `SELECT * FROM email_queue ORDER BY id DESC LIMIT 5;`
2. รัน cron ด้วยมือ: `php cron/send_emails.php` แล้วดู output
3. ดู `email_logs` — `error_message` จะบอกว่า SMTP fail เพราะอะไร
4. Gmail ต้องใช้ **App Password 16 หลัก** (ไม่ใช่รหัสผ่านปกติ) — เปิด 2FA ก่อน
5. `status='failed'` หลัง retry 3 ครั้ง → fix ปัญหาแล้ว `UPDATE email_queue SET status='pending', retry_count=0 WHERE id=...`

### 🔴 5.8 CSRF token mismatch
- Session หมดอายุ (30 นาที) → login ใหม่
- ลืมใส่ `<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">` ในฟอร์ม
- Cookie ถูกบล็อก (เช็ค `SESSION_SAMESITE` ใน `.env`)

### 🔴 5.9 Director กดอะไรก็ขึ้น "Director is read-only"
- ถูกต้องแล้ว — Director **read-only ทั้งระบบ**
- ยกเว้น `change_password.php` + `logout.php` (ดู [CLAUDE.md §6](CLAUDE.md))
- ถ้าต้องเพิ่ม endpoint อื่นให้ Director POST → ใส่ใน whitelist `$allow_post_for_director` ของ [`includes/auth.php`](includes/auth.php)

### 🔴 5.10 Calendar ไม่ขึ้น event
- เปิด DevTools → Network → ดู [`api/calendar_events.php`](api/calendar_events.php) ตอบ JSON ปกติไหม
- Personal scope ไม่โผล่ในหน้า Admin/Director — **เป็นพฤติกรรมที่ถูกต้อง** (ดู [CLAUDE.md §2 ข้อ 1](CLAUDE.md))

---

## 6. กฎห้ามแตะ (สรุปจาก CLAUDE.md §2)

- ❌ ห้ามให้ Admin/Director เห็น personal activity ของคนอื่น
- ❌ ห้าม auto-generate certificate (upload only)
- ❌ ห้ามใช้ md5/sha1/`==` เปรียบเทียบรหัสผ่าน — ใช้ bcrypt + `password_verify()` เท่านั้น
- ❌ ห้าม SQL string concat — ใช้ PDO prepared statement
- ❌ ห้าม echo ค่าจาก DB ตรง ๆ — ผ่าน `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`
- ❌ ห้าม Director POST/PUT/DELETE (ยกเว้น whitelist 2 endpoint)
- ❌ ห้ามส่ง email synchronous — INSERT `email_queue` ให้ cron ส่ง

อ่านเต็มที่ [CLAUDE.md](CLAUDE.md) ก่อนแก้ code เสมอ

---

## 7. Phase Roadmap

ดู [`docs/Phase6-12_Summary.md`](docs/Phase6-12_Summary.md) และสเปค §10
ทำตามลำดับ phase 1 → 12 อย่ากระโดดข้าม

---

## 8. License / Internal use

โปรเจกต์ภายในของ ARIT — ไม่เผยแพร่ภายนอก
