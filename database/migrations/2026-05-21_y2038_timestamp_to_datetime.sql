-- Migration: 2026-05-21 — แก้ Y2038 problem
-- เปลี่ยน TIMESTAMP → DATETIME ทุกตาราง
--
-- เหตุผล: MySQL TIMESTAMP เก็บเป็น 32-bit Unix timestamp จะ overflow
--         วันที่ 2038-01-19 03:14:07 UTC — DATETIME เก็บถึงปี 9999
--
-- วิธีรัน:
--   mysql -u root -p activitydms < database/migrations/2026-05-21_y2038_timestamp_to_datetime.sql
--
-- หมายเหตุ: ALTER TABLE ... MODIFY COLUMN จะ rewrite ตารางทั้งใบ
--           ถ้ามีข้อมูลเยอะ ควรทำตอน maintenance window
--           ทดสอบบน staging ก่อนเสมอ

SET NAMES utf8mb4;

-- departments
ALTER TABLE `departments`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- users
ALTER TABLE `users`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- fiscal_years
ALTER TABLE `fiscal_years`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- activities
ALTER TABLE `activities`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  MODIFY `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- activity_photos
ALTER TABLE `activity_photos`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- activity_attachments
ALTER TABLE `activity_attachments`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- activity_registrations
ALTER TABLE `activity_registrations`
  MODIFY `registered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'วันเวลาที่เข้าร่วม/ถูกเพิ่ม';

-- documents
ALTER TABLE `documents`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- certificates
ALTER TABLE `certificates`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- email_queue
ALTER TABLE `email_queue`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- email_logs
ALTER TABLE `email_logs`
  MODIFY `attempt_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'เวลาที่พยายามส่ง';

-- notification_settings
ALTER TABLE `notification_settings`
  MODIFY `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- notifications
ALTER TABLE `notifications`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- audit_logs
ALTER TABLE `audit_logs`
  MODIFY `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- ตรวจสอบหลัง migrate: ทุกคอลัมน์ควรเป็น datetime
-- SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND DATA_TYPE IN ('timestamp','datetime')
-- ORDER BY TABLE_NAME, COLUMN_NAME;
