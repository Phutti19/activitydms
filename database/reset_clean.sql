-- ActivityDMS — reset_clean.sql
-- ล้างข้อมูลทั้งหมด เหลือเฉพาะ admin (id=1) + director (id=2)
-- เก็บ master data ไว้: departments, fiscal_years, activity_types, notification_settings
-- Reset password ของ admin/director กลับเป็น default + must_change_password=1
--
-- วิธีใช้:
--   mysql -u root -p activitydms < database/reset_clean.sql
--
-- หลังรันต้องล้างไฟล์ใน UPLOAD_PATH ด้วย (รัน scripts/clean_uploads.ps1)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================
-- 1) ล้าง transaction tables ทั้งหมด
-- (TRUNCATE = เร็ว + reset AUTO_INCREMENT กลับเป็น 1)
-- ============================================
TRUNCATE TABLE `email_logs`;
TRUNCATE TABLE `email_queue`;
TRUNCATE TABLE `audit_logs`;
TRUNCATE TABLE `certificates`;
TRUNCATE TABLE `documents`;
TRUNCATE TABLE `activity_registrations`;
TRUNCATE TABLE `activity_attachments`;
TRUNCATE TABLE `activity_photos`;
TRUNCATE TABLE `activities`;

-- ============================================
-- 2) ลบ user ทุกคนยกเว้น admin + director
-- ============================================
DELETE FROM `users` WHERE `role` = 'employee';

-- ============================================
-- 3) Reset password ของ admin/director กลับเป็น default
--    Admin@2026     → bcrypt
--    Director@2026  → bcrypt
--    must_change_password = 1 (บังคับเปลี่ยนรหัสครั้งแรก)
-- ============================================
UPDATE `users`
SET `password_hash` = '$2b$10$SJHahaCFPGSb1/cowyDsoOje3Q8YZv72SNBq1p4qJTjtduzohRbhy',
    `must_change_password` = 1,
    `is_active` = 1
WHERE `username` = 'admin';

UPDATE `users`
SET `password_hash` = '$2b$10$S6dzpmZ1rJYL1mzlsSgEEeVZObqozgu2mdzUSTuCO0AZlFAHrZEDm',
    `must_change_password` = 1,
    `is_active` = 1
WHERE `username` = 'director';

-- ============================================
-- 4) Reset master data ที่อาจถูกแก้ไป (ทางเลือก)
--    เปิด/ปิด notification กลับเป็นค่า default
-- ============================================
UPDATE `notification_settings` SET `setting_value` = '1';

SET FOREIGN_KEY_CHECKS = 1;

-- ตรวจผลลัพธ์
SELECT id, username, role, is_active, must_change_password FROM `users`;
SELECT 'activities' AS tbl, COUNT(*) AS cnt FROM `activities`
UNION ALL SELECT 'registrations', COUNT(*) FROM `activity_registrations`
UNION ALL SELECT 'photos',        COUNT(*) FROM `activity_photos`
UNION ALL SELECT 'attachments',   COUNT(*) FROM `activity_attachments`
UNION ALL SELECT 'documents',     COUNT(*) FROM `documents`
UNION ALL SELECT 'certificates',  COUNT(*) FROM `certificates`
UNION ALL SELECT 'email_queue',   COUNT(*) FROM `email_queue`
UNION ALL SELECT 'email_logs',    COUNT(*) FROM `email_logs`
UNION ALL SELECT 'audit_logs',    COUNT(*) FROM `audit_logs`;

-- End of reset_clean.sql
