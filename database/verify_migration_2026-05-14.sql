-- รันเพื่อ verify ว่า migration_2026-05-14.sql ทำงานครบทุกข้อ
-- คาดหวัง: ทุก query คืน 1 row (ยกเว้น notifications ที่ยังว่าง)

-- 1) activities.format มีหรือยัง?
SHOW COLUMNS FROM `activities` LIKE 'format';

-- 2) activity_photos.source + drive_url มีหรือยัง?
SHOW COLUMNS FROM `activity_photos` LIKE 'source';
SHOW COLUMNS FROM `activity_photos` LIKE 'drive_url';

-- 3) ตาราง notifications มีหรือยัง?
SHOW TABLES LIKE 'notifications';

-- 4) FY 2569 active ไหม?
SELECT id, name, start_year, end_year, is_active
FROM fiscal_years
ORDER BY start_year DESC;

-- 5) CHECK constraint ใหม่อยู่ไหม?
SELECT CONSTRAINT_NAME
FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME IN ('chk_photos_source','chk_photos_order');
