# รายงานตรวจสอบระบบ ActivityDMS (ยังไม่แก้ไขโค้ด)

วันที่ตรวจ: 2026-05-01  
สถานะ: ตรวจพบจุดผิดพลาดหลายประเด็น (syntax ผ่านทุกไฟล์)

## สรุปประเด็นที่พบ

| ระดับ | ประเด็น | หลักฐาน |
|---|---|---|
| **สูง** | **SQL ไม่ใช้ prepared statement ทุกจุด** | พบ `->query()`/`db()->query()` หลายจุด เช่น `director/dashboard.php` (L10,18,23,35,42,63), `admin/reports.php` (L17-19,27), `director/reports.php` (L16-17,21), `director/activities.php` (L17-18,21), `admin/manage_users.php` (L27) |
| **สูง** | **มี SQL ต่อสตริงเงื่อนไขโดยตรง** | `director/dashboard.php` ใช้ `$fy_filter` แล้ว concat เข้า SQL (`L15` และ query ถัดไปหลายจุด) |
| **สูง** | **Email rate limit ตามสเปคยังไม่ครบ** (`<= 50/ชั่วโมง/trigger`) | `includes/mailer.php` ไม่มี throttle ต่อ trigger; `cron/send_emails.php` กำหนด `MAIL_BATCH_SIZE=50` ทุก 5 นาที (`L33`, `L46-57`) ซึ่งเทียบเชิงระบบเป็น 600/ชม. และไม่แยก per-trigger |
| **สูง** | **DOM XSS risk ใน Calendar tooltip** | ใช้ `innerHTML` กับค่าจาก DB/API โดยตรง: `admin/calendar.php` (L70-77), `director/calendar.php` (L67-73), `employee/calendar.php` (L72-79) |
| **กลาง** | **ข้อความสถานะส่งอีเมลคลาดเคลื่อน** | `admin/activity_view.php` (L155) ตรวจ `enqueue_new_activity_email(...) !== null` ทั้งที่ฟังก์ชันเป็น `void` ทำให้ `email_count` ไม่เพิ่มตามจริง |
| **กลาง** | **อัปโหลดเกียรติบัตรให้ผู้ที่ไม่ใช่ participant ได้** | `admin/manage_certificates.php` และ `admin/activity_view.php` ตรวจเพียงว่า user active แต่ไม่บังคับว่ามีรายการใน `activity_registrations` ของกิจกรรมนั้น |
| **กลาง** | **เผยรายละเอียด error ภายในให้ผู้ใช้** | `admin/activity_view.php` ใน catch แสดง `$e->getMessage()` ตรงๆ (`L98-100`) |
| **ต่ำ** | **โค้ดซ้ำ/logic ไม่จำเป็น** | `employee/dashboard.php` (L11-15) มีการคำนวณ `$reg_count` แบบซ้ำและถูกทับค่าทีหลัง |

## หมายเหตุ

- การตรวจ syntax ผ่านทั้งหมด (`php -l` ผ่านทุกไฟล์)
- รายงานนี้เป็นการตรวจสอบเท่านั้น ยังไม่มีการแก้ไขโค้ด
