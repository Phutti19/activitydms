-- ActivityDMS — Migration 2026-05-14
-- สรุปการเปลี่ยนแปลงจากประชุม (docs/Meeting_Updates_2026-05-14.md):
--   1. เพิ่ม activities.format (onsite/online)
--   2. รองรับรูปแบบ drive_link ใน activity_photos
--   3. ตาราง notifications (in-app bell)
--   4. seed fiscal_year 2569 + switch active
--   5. seed notification_settings ของ in-app bell
--
-- วิธีใช้:
--   mysql -u root -p activitydms < database/migration_2026-05-14.sql
--
-- ปลอดภัยรันซ้ำได้ (ใช้ IF NOT EXISTS / INSERT IGNORE / ON DUPLICATE KEY)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1) activities.format — onsite / online
-- ============================================================
-- ใช้ procedure ครอบเพื่อไม่ error ถ้า column มีอยู่แล้ว
DROP PROCEDURE IF EXISTS `add_col_if_missing_2026_05_14`;
DELIMITER //
CREATE PROCEDURE `add_col_if_missing_2026_05_14`(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_ddl    VARCHAR(1024)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL add_col_if_missing_2026_05_14(
    'activities',
    'format',
    "`format` ENUM('onsite','online') NOT NULL DEFAULT 'onsite'
       COMMENT 'รูปแบบกิจกรรม onsite=ออนไซต์ | online=ออนไลน์'
       AFTER `activity_type_id`"
);

-- ============================================================
-- 2) activity_photos — รองรับ drive_link (ลิงก์ Google Drive)
-- ============================================================
CALL add_col_if_missing_2026_05_14(
    'activity_photos',
    'source',
    "`source` ENUM('upload','drive_link') NOT NULL DEFAULT 'upload'
       COMMENT 'upload=ไฟล์อัปโหลด (จำกัด ≤5 ต่อกิจกรรม) | drive_link=ลิงก์ Drive (ไม่จำกัด)'
       AFTER `activity_id`"
);
CALL add_col_if_missing_2026_05_14(
    'activity_photos',
    'drive_url',
    "`drive_url` VARCHAR(500) DEFAULT NULL
       COMMENT 'URL อัลบั้ม/รูปจาก Google Drive — ใช้เมื่อ source=drive_link'
       AFTER `original_name`"
);

-- ทำ filename / original_name ให้ NULL ได้ (drive_link ไม่มีไฟล์จริง)
ALTER TABLE `activity_photos`
    MODIFY COLUMN `filename`      VARCHAR(255) DEFAULT NULL
        COMMENT 'ชื่อไฟล์จริง (UUID) — ใช้เมื่อ source=upload',
    MODIFY COLUMN `original_name` VARCHAR(255) DEFAULT NULL
        COMMENT 'ชื่อไฟล์ต้นฉบับ — ใช้เมื่อ source=upload',
    MODIFY COLUMN `sort_order`    TINYINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'ลำดับภาพ — สำหรับ upload จำกัด 1-5 (ดู chk_photos_source), drive_link เก็บลำดับการแสดงผลทั่วไป';

-- เปลี่ยน CHECK constraint: ให้ upload จำกัด 1-5 ส่วน drive_link ไม่จำกัด + บังคับ field ตรงตาม source
ALTER TABLE `activity_photos` DROP CHECK `chk_photos_order`;
ALTER TABLE `activity_photos`
    ADD CONSTRAINT `chk_photos_source` CHECK (
        (`source` = 'upload'
            AND `filename`  IS NOT NULL
            AND `drive_url` IS NULL
            AND `sort_order` BETWEEN 1 AND 5)
        OR
        (`source` = 'drive_link'
            AND `drive_url` IS NOT NULL
            AND `filename`  IS NULL)
    );

-- ============================================================
-- 3) ตาราง notifications (in-app bell)
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED     NOT NULL             COMMENT 'FK → users.id ผู้รับ',
    `type`       ENUM('new_activity','new_certificate','system') NOT NULL
                                                      COMMENT 'ประเภท — ใช้เลือก icon/สี + เคารพ notification_settings',
    `title`      VARCHAR(255)     NOT NULL             COMMENT 'หัวข้อสั้น แสดงใน bell dropdown',
    `message`    TEXT                                   COMMENT 'รายละเอียดเพิ่มเติม (optional)',
    `link_url`   VARCHAR(500)              DEFAULT NULL COMMENT 'URL ที่จะเปิดเมื่อคลิก notification',
    `ref_type`   VARCHAR(50)              DEFAULT NULL COMMENT 'ประเภท ref เช่น activity / certificate',
    `ref_id`     INT UNSIGNED             DEFAULT NULL COMMENT 'ID ของ ref (loose FK — ไม่ผูก ON DELETE)',
    `is_read`    TINYINT(1)       NOT NULL DEFAULT 0   COMMENT '1=อ่านแล้ว 0=ยังไม่อ่าน',
    `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `read_at`    DATETIME                  DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_unread` (`user_id`, `is_read`, `created_at`)
        COMMENT 'ใช้ดึง unread นับ badge และ list ใน bell dropdown',
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='In-app notification (bell) — ทำงานคู่ email_queue เคารพ notification_settings เดียวกัน';

-- ============================================================
-- 4) Seed: fiscal_years 2569 (1 ต.ค. 2568 – 30 ก.ย. 2569)
-- ============================================================
-- ปิด is_active ของ FY เก่าทั้งหมดก่อน
UPDATE `fiscal_years` SET `is_active` = 0;

-- INSERT เฉพาะเมื่อยังไม่มี (fiscal_years.name ไม่มี UNIQUE — เช็คเอง)
INSERT INTO `fiscal_years` (`name`, `start_month`, `start_year`, `end_month`, `end_year`, `is_active`)
SELECT '2569', 10, 2025, 9, 2026, 1
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT * FROM `fiscal_years`) AS fy
    WHERE fy.`name` = '2569'
);

-- ถ้ามีอยู่แล้ว → mark active
UPDATE `fiscal_years` SET `is_active` = 1 WHERE `name` = '2569';

-- ============================================================
-- 5) Seed: notification_settings สำหรับ in-app bell (เคารพ key เดียวกับ email)
-- ============================================================
-- ไม่ต้องเพิ่ม key ใหม่ — bell เคารพ notify_new_activity / notify_new_certificate เดียวกัน
-- (decision จากประชุม 2026-05-14)

-- ============================================================
-- Cleanup helper procedure
-- ============================================================
DROP PROCEDURE IF EXISTS `add_col_if_missing_2026_05_14`;

SET FOREIGN_KEY_CHECKS = 1;
-- End of migration_2026-05-14.sql
