-- ActivityDMS — database.sql
-- Schema + Seed Data (generated from STAFF_ARIT.xlsx)
-- PHP password_verify() compatible (bcrypt)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `departments` (
  `id`         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(150)     NOT NULL,
  `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='แผนก/กลุ่มงานภายในองค์กร seed จาก STAFF_ARIT.xlsx (3 แผนก)';

CREATE TABLE IF NOT EXISTS `users` (
  `id`                   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `staff_code`           VARCHAR(20)      NOT NULL DEFAULT ''  COMMENT 'รหัสพนักงาน (STAFFID จาก STAFF_ARIT)',
  `username`             VARCHAR(50)      NOT NULL             COMMENT 'ชื่อเข้าสู่ระบบ (LOGIN จาก STAFF_ARIT)',
  `password_hash`        VARCHAR(255)     NOT NULL             COMMENT 'bcrypt hash — ใช้ password_verify() เท่านั้น',
  `prefix_name`          VARCHAR(20)      NOT NULL DEFAULT ''  COMMENT 'คำนำหน้าชื่อ เช่น นาย / นางสาว / Mr.',
  `first_name`           VARCHAR(100)     NOT NULL             COMMENT 'ชื่อ',
  `last_name`            VARCHAR(100)     NOT NULL             COMMENT 'นามสกุล',
  `position_name`        VARCHAR(150)     NOT NULL DEFAULT ''  COMMENT 'ตำแหน่งงาน เช่น นักวิชาการคอมพิวเตอร์',
  `staff_type`           VARCHAR(100)     NOT NULL DEFAULT ''  COMMENT 'ประเภทบุคลากร เช่น พนักงานมหาวิทยาลัย / พนักงานราชการ',
  `email`                VARCHAR(150)     NOT NULL             COMMENT 'อีเมลองค์กร ใช้รับ notification',
  `department_id`        TINYINT UNSIGNED NOT NULL DEFAULT 1   COMMENT 'FK → departments.id',
  `role`                 ENUM('admin','director','employee') NOT NULL DEFAULT 'employee' COMMENT 'admin=ผู้ดูแลระบบ, director=ผู้อำนวยการ(read-only), employee=พนักงาน',
  `is_active`            TINYINT(1)       NOT NULL DEFAULT 1   COMMENT '1=ใช้งานได้ 0=ระงับบัญชี',
  `must_change_password` TINYINT(1)       NOT NULL DEFAULT 1   COMMENT '1=บังคับเปลี่ยน password ครั้งแรก login',
  `created_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email`    (`email`),
  KEY `idx_department`     (`department_id`),
  KEY `idx_role`           (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='บัญชีผู้ใช้งานระบบ 3 roles: admin / director / employee — seed 31 คน (29+admin+director)';

CREATE TABLE IF NOT EXISTS `fiscal_years` (
  `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)       NOT NULL COMMENT 'ชื่อปีงบประมาณ เช่น 2568 (พ.ศ.)',
  `start_month` TINYINT UNSIGNED  NOT NULL DEFAULT 10 COMMENT 'เดือนเริ่มต้น (1-12) default=10 ตุลาคม',
  `start_year`  YEAR              NOT NULL             COMMENT 'ปี ค.ศ. ที่เริ่มต้น',
  `end_month`   TINYINT UNSIGNED  NOT NULL DEFAULT 9  COMMENT 'เดือนสิ้นสุด (1-12) default=9 กันยายน',
  `end_year`    YEAR              NOT NULL             COMMENT 'ปี ค.ศ. ที่สิ้นสุด',
  `is_active`   TINYINT(1)        NOT NULL DEFAULT 1   COMMENT '1=ปีงบประมาณที่ใช้งานอยู่ปัจจุบัน (ควรมีแค่ 1 record ที่ active)',
  `created_at`  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ปีงบประมาณ ใช้จัดกลุ่มกิจกรรมและกรองรายงาน — Admin ตั้งค่าเดือนเริ่ม/จบเองได้';

CREATE TABLE IF NOT EXISTS `activity_types` (
  `id`        TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(100)     NOT NULL             COMMENT 'ชื่อประเภท เช่น ประชุม / อบรม / สัมมนา / อื่นๆ',
  `color`     CHAR(7)          NOT NULL DEFAULT '#6c757d' COMMENT 'รหัสสี HEX ใช้แสดงใน FullCalendar',
  `is_active` TINYINT(1)       NOT NULL DEFAULT 1   COMMENT '0=ซ่อนจากตัวเลือก (ไม่ลบข้อมูลเก่า)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ประเภท/Tag ของกิจกรรม seed: ประชุม(#185FA5) / อบรม(#0F6E56) / สัมมนา(#993C1D) / อื่นๆ(#5F5E5A)';

CREATE TABLE IF NOT EXISTS `activities` (
  `id`                   INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `title`                VARCHAR(255)     NOT NULL             COMMENT 'ชื่อกิจกรรม',
  `description`          TEXT                                  COMMENT 'รายละเอียดกิจกรรม (HTML หรือ plain text)',
  `location`             VARCHAR(255)     NOT NULL DEFAULT ''  COMMENT 'สถานที่จัดกิจกรรม',
  `activity_type_id`     TINYINT UNSIGNED NOT NULL             COMMENT 'FK → activity_types.id (ประชุม/อบรม/สัมมนา/อื่นๆ)',
  `fiscal_year_id`       SMALLINT UNSIGNED NOT NULL            COMMENT 'FK → fiscal_years.id ปีงบประมาณที่กิจกรรมนี้สังกัด',
  `scope`                ENUM('organization','personal') NOT NULL DEFAULT 'organization' COMMENT 'organization=Admin สร้าง เห็นทั้งองค์กร | personal=Employee สร้างให้ตัวเอง ไม่มีใครเห็น',
  `is_open_registration` TINYINT(1)       NOT NULL DEFAULT 0   COMMENT '1=เปิดให้พนักงานสมัครเข้าร่วมเองได้',
  `start_datetime`       DATETIME         NOT NULL             COMMENT 'วันเวลาเริ่มกิจกรรม',
  `end_datetime`         DATETIME         NOT NULL             COMMENT 'วันเวลาสิ้นสุดกิจกรรม',
  `external_url`         VARCHAR(500)              DEFAULT NULL COMMENT 'ลิงก์ภายนอก เช่น Google Meet / เว็บไซต์งาน',
  `created_by`           INT UNSIGNED     NOT NULL             COMMENT 'FK → users.id ผู้สร้างกิจกรรม',
  `created_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type`        (`activity_type_id`),
  KEY `idx_fiscal_year` (`fiscal_year_id`),
  KEY `idx_scope`       (`scope`),
  KEY `idx_created_by`  (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='กิจกรรมหลักของระบบ แบ่ง scope: organization=กิจกรรมองค์กร(Admin สร้าง) / personal=บันทึกส่วนตัว(Employee)';

CREATE TABLE IF NOT EXISTS `activity_photos` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id`   INT UNSIGNED NOT NULL             COMMENT 'FK → activities.id (CASCADE DELETE)',
  `filename`      VARCHAR(255) NOT NULL             COMMENT 'ชื่อไฟล์ที่เก็บจริง (UUID-based) ห้ามใช้ชื่อเดิมจาก client',
  `original_name` VARCHAR(255) NOT NULL             COMMENT 'ชื่อไฟล์ต้นฉบับ ใช้แสดงผลเท่านั้น',
  `sort_order`    TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ลำดับภาพ 1-5 (CHECK constraint บังคับไม่เกิน 5 ใบ)',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity` (`activity_id`),
  CONSTRAINT `chk_photos_order` CHECK (`sort_order` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ภาพถ่ายกิจกรรม จำกัดสูงสุด 5 ภาพต่อกิจกรรม — validate ทั้ง CHECK constraint และ PHP application';

CREATE TABLE IF NOT EXISTS `activity_attachments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id` INT UNSIGNED NOT NULL             COMMENT 'FK → activities.id (CASCADE DELETE)',
  `type`        ENUM('file','url') NOT NULL DEFAULT 'file' COMMENT 'file=ไฟล์อัปโหลด | url=ลิงก์ภายนอก',
  `label`       VARCHAR(255) NOT NULL DEFAULT ''  COMMENT 'ชื่อที่แสดง เช่น "สไลด์การอบรม" / "รายงานการประชุม"',
  `filename`    VARCHAR(255)          DEFAULT NULL COMMENT 'ชื่อไฟล์ที่เก็บจริง (UUID) — ใช้เมื่อ type=file',
  `url`         VARCHAR(500)          DEFAULT NULL COMMENT 'URL ภายนอก — ใช้เมื่อ type=url',
  `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity` (`activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ไฟล์แนบและลิงก์ประกอบกิจกรรม เช่น วาระประชุม สรุปการอบรม สไลด์ — รองรับทั้ง file upload และ URL';

CREATE TABLE IF NOT EXISTS `activity_registrations` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id`     INT UNSIGNED NOT NULL             COMMENT 'FK → activities.id (CASCADE DELETE)',
  `user_id`         INT UNSIGNED NOT NULL             COMMENT 'FK → users.id ผู้เข้าร่วม (CASCADE DELETE)',
  `status`          ENUM('registered','attended','absent') NOT NULL DEFAULT 'registered' COMMENT 'registered=สมัครแล้วรอเช็คชื่อ | attended=เข้าร่วม | absent=ขาด',
  `registered_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'วันเวลาที่สมัคร/ถูกเพิ่ม',
  `checked_by`      INT UNSIGNED          DEFAULT NULL COMMENT 'FK → users.id Admin ที่เช็คชื่อ (NULL=ยังไม่เช็ค)',
  `checked_at`      DATETIME              DEFAULT NULL COMMENT 'วันเวลาที่เช็คชื่อ',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_activity_user` (`activity_id`, `user_id`) COMMENT '1 คน สมัครได้ 1 ครั้งต่อกิจกรรม',
  KEY `idx_user`    (`user_id`),
  KEY `idx_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='การเข้าร่วมกิจกรรม ทั้งที่ Admin เพิ่มเองและพนักงานสมัครเอง — Admin เช็คชื่อเปลี่ยน status ได้';

CREATE TABLE IF NOT EXISTS `documents` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id`   INT UNSIGNED          DEFAULT NULL COMMENT 'FK → activities.id — NULL หมายถึงเอกสารทั่วไปไม่ผูกกับกิจกรรม',
  `title`         VARCHAR(255) NOT NULL             COMMENT 'ชื่อเอกสารที่แสดงผล',
  `filename`      VARCHAR(255) NOT NULL             COMMENT 'ชื่อไฟล์ที่เก็บจริง (UUID) เก็บนอก document root',
  `original_name` VARCHAR(255) NOT NULL             COMMENT 'ชื่อไฟล์ต้นฉบับ ใช้ตอน download',
  `file_size`     INT UNSIGNED NOT NULL DEFAULT 0   COMMENT 'ขนาดไฟล์ (bytes)',
  `mime_type`     VARCHAR(100) NOT NULL DEFAULT ''  COMMENT 'MIME type จริงจาก finfo_file() เช่น application/pdf',
  `uploaded_by`   INT UNSIGNED NOT NULL             COMMENT 'FK → users.id ผู้อัปโหลด',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity` (`activity_id`),
  KEY `idx_uploader` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='เอกสารทั่วไปและเอกสารประกอบกิจกรรม เช่น วาระ รายงาน สไลด์ — serve ผ่าน PHP script ตรวจสิทธิ์ก่อนดาวน์โหลด';

CREATE TABLE IF NOT EXISTS `certificates` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `activity_id`   INT UNSIGNED NOT NULL COMMENT 'FK → activities.id (CASCADE DELETE)',
  `user_id`       INT UNSIGNED NOT NULL COMMENT 'FK → users.id เจ้าของเกียรติบัตร (CASCADE DELETE)',
  `filename`      VARCHAR(255) NOT NULL COMMENT 'ชื่อไฟล์ที่เก็บจริง (UUID) รองรับ PDF / JPG / PNG',
  `original_name` VARCHAR(255) NOT NULL COMMENT 'ชื่อไฟล์ต้นฉบับ ใช้ตอน download',
  `uploaded_by`   INT UNSIGNED NOT NULL COMMENT 'FK → users.id Admin ที่อัปโหลด',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cert` (`activity_id`, `user_id`) COMMENT '1 คน รับได้ 1 ใบต่อกิจกรรม',
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='เกียรติบัตรรายบุคคล Admin อัปโหลดไฟล์ PDF/JPG/PNG — Employee เห็นและดาวน์โหลดได้เฉพาะของตัวเอง';

CREATE TABLE IF NOT EXISTS `email_queue` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email`     VARCHAR(150) NOT NULL             COMMENT 'อีเมลผู้รับ',
  `to_name`      VARCHAR(200) NOT NULL DEFAULT ''  COMMENT 'ชื่อผู้รับ ใช้ใน greeting',
  `subject`      VARCHAR(255) NOT NULL             COMMENT 'หัวเรื่องอีเมล',
  `body_html`    LONGTEXT                          COMMENT 'เนื้อหา HTML จาก template',
  `body_text`    TEXT                              COMMENT 'เนื้อหา plain text (fallback)',
  `status`       ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending' COMMENT 'pending=รอส่ง | sending=กำลังส่ง | sent=ส่งแล้ว | failed=ล้มเหลวเกิน retry limit',
  `retry_count`  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จำนวนครั้งที่พยายามส่ง (สูงสุด 3 ครั้ง)',
  `scheduled_at` DATETIME NOT NULL DEFAULT (NOW()) COMMENT 'เวลาที่ต้องการส่ง — cron จะดึงเฉพาะ record ที่ scheduled_at <= NOW()',
  `sent_at`      DATETIME          DEFAULT NULL    COMMENT 'วันเวลาที่ส่งสำเร็จจริง',
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_scheduled` (`status`, `scheduled_at`) COMMENT 'Index หลักที่ cron ใช้ดึง pending emails'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='คิวอีเมลรอส่ง — cron/send_emails.php ดึงไปส่งทุก 5 นาที retry สูงสุด 3 ครั้ง';

CREATE TABLE IF NOT EXISTS `email_logs` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue_id`      INT UNSIGNED NOT NULL COMMENT 'FK → email_queue.id (CASCADE DELETE)',
  `status`        ENUM('success','failed') NOT NULL COMMENT 'ผลการส่งแต่ละครั้ง',
  `error_message` TEXT          DEFAULT NULL COMMENT 'ข้อผิดพลาดจาก PHPMailer (เก็บไว้ debug)',
  `attempt_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'เวลาที่พยายามส่ง',
  PRIMARY KEY (`id`),
  KEY `idx_queue` (`queue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ประวัติการส่งอีเมลแต่ละครั้ง (1 queue → หลาย log) ใช้ debug และตรวจสอบสาเหตุที่ส่งไม่สำเร็จ';

CREATE TABLE IF NOT EXISTS `notification_settings` (
  `id`            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(100)     NOT NULL             COMMENT 'ชื่อ key เช่น notify_new_activity / notify_new_certificate',
  `setting_value` VARCHAR(255)     NOT NULL DEFAULT '1' COMMENT '1=เปิด 0=ปิด (Admin เปลี่ยนได้ในหน้า settings)',
  `label`         VARCHAR(255)     NOT NULL DEFAULT ''  COMMENT 'ข้อความอธิบายสำหรับแสดงในหน้า settings',
  `updated_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ตั้งค่าการแจ้งเตือนอีเมล Admin เปิด/ปิดแต่ละ trigger ได้ — seed 2 records (กิจกรรม + เกียรติบัตร)';

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED     DEFAULT NULL COMMENT 'FK → users.id ผู้กระทำ (NULL=ระบบ/cron)',
  `action`     VARCHAR(100)    NOT NULL     COMMENT 'การกระทำ เช่น create_activity / delete_user / upload_certificate',
  `table_name` VARCHAR(100)    NOT NULL     COMMENT 'ตารางที่ถูกกระทำ',
  `record_id`  INT UNSIGNED     DEFAULT NULL COMMENT 'id ของ record ที่ถูกกระทำ',
  `old_values` JSON             DEFAULT NULL COMMENT 'ข้อมูลก่อนแก้ไข (JSON) — ใช้ตรวจสอบย้อนหลัง',
  `new_values` JSON             DEFAULT NULL COMMENT 'ข้อมูลหลังแก้ไข (JSON)',
  `ip_address` VARCHAR(45)     NOT NULL DEFAULT '' COMMENT 'IP ผู้ใช้งาน (รองรับ IPv6)',
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user`   (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_table`  (`table_name`, `record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='บันทึกการใช้งานระบบ (Audit Trail) เก็บ old/new values เป็น JSON ใช้ตรวจสอบย้อนหลังและรับผิดชอบ';

-- Foreign Keys
ALTER TABLE `users`                  ADD CONSTRAINT `fk_users_dept`          FOREIGN KEY (`department_id`)    REFERENCES `departments`    (`id`) ON UPDATE CASCADE;
ALTER TABLE `activities`             ADD CONSTRAINT `fk_act_type`            FOREIGN KEY (`activity_type_id`) REFERENCES `activity_types` (`id`) ON UPDATE CASCADE;
ALTER TABLE `activities`             ADD CONSTRAINT `fk_act_fiscal`          FOREIGN KEY (`fiscal_year_id`)   REFERENCES `fiscal_years`   (`id`) ON UPDATE CASCADE;
ALTER TABLE `activities`             ADD CONSTRAINT `fk_act_creator`         FOREIGN KEY (`created_by`)       REFERENCES `users`          (`id`);
ALTER TABLE `activity_photos`        ADD CONSTRAINT `fk_photos_act`          FOREIGN KEY (`activity_id`)      REFERENCES `activities`     (`id`) ON DELETE CASCADE;
ALTER TABLE `activity_attachments`   ADD CONSTRAINT `fk_attach_act`          FOREIGN KEY (`activity_id`)      REFERENCES `activities`     (`id`) ON DELETE CASCADE;
ALTER TABLE `activity_registrations` ADD CONSTRAINT `fk_reg_act`             FOREIGN KEY (`activity_id`)      REFERENCES `activities`     (`id`) ON DELETE CASCADE;
ALTER TABLE `activity_registrations` ADD CONSTRAINT `fk_reg_user`            FOREIGN KEY (`user_id`)          REFERENCES `users`          (`id`) ON DELETE CASCADE;
ALTER TABLE `activity_registrations` ADD CONSTRAINT `fk_reg_checker`         FOREIGN KEY (`checked_by`)       REFERENCES `users`          (`id`) ON DELETE SET NULL;
ALTER TABLE `documents`              ADD CONSTRAINT `fk_docs_act`            FOREIGN KEY (`activity_id`)      REFERENCES `activities`     (`id`) ON DELETE SET NULL;
ALTER TABLE `documents`              ADD CONSTRAINT `fk_docs_uploader`       FOREIGN KEY (`uploaded_by`)      REFERENCES `users`          (`id`);
ALTER TABLE `certificates`           ADD CONSTRAINT `fk_cert_act`            FOREIGN KEY (`activity_id`)      REFERENCES `activities`     (`id`) ON DELETE CASCADE;
ALTER TABLE `certificates`           ADD CONSTRAINT `fk_cert_user`           FOREIGN KEY (`user_id`)          REFERENCES `users`          (`id`) ON DELETE CASCADE;
ALTER TABLE `certificates`           ADD CONSTRAINT `fk_cert_uploader`       FOREIGN KEY (`uploaded_by`)      REFERENCES `users`          (`id`);
ALTER TABLE `email_logs`             ADD CONSTRAINT `fk_emaillog_queue`      FOREIGN KEY (`queue_id`)         REFERENCES `email_queue`    (`id`) ON DELETE CASCADE;

-- Seed: departments
INSERT INTO `departments` (`id`, `name`) VALUES
  (1, 'งานบริหารทั่วไป'),
  (2, 'งานเทคโนโลยีสารสนเทศดิจิทัล'),
  (3, 'งานทรัพยากรสารสนเทศและภาษาต่างประเทศ');

-- Seed: fiscal_years (2568 = ต.ค. 2567 – ก.ย. 2568)
INSERT INTO `fiscal_years` (`name`, `start_month`, `start_year`, `end_month`, `end_year`, `is_active`) VALUES
  ('2568', 10, 2024, 9, 2025, 1);

-- Seed: activity_types
INSERT INTO `activity_types` (`name`, `color`) VALUES
  ('ประชุม',  '#185FA5'),
  ('อบรม',   '#0F6E56'),
  ('สัมมนา', '#993C1D'),
  ('อื่นๆ',  '#5F5E5A');

-- Seed: notification_settings
INSERT INTO `notification_settings` (`setting_key`, `setting_value`, `label`) VALUES
  ('notify_new_activity',    '1', 'แจ้งเตือนเมื่อมีกิจกรรมใหม่'),
  ('notify_new_certificate', '1', 'แจ้งเตือนเมื่อออกเกียรติบัตรใหม่');

-- Seed: users (29 from STAFF_ARIT + 1 admin + 1 director = 31 records)
-- Default passwords: Admin@2026 | Director@2026 | Skru@2026
-- All must_change_password = 1 (force reset on first login)
INSERT INTO `users` (`staff_code`,`username`,`password_hash`,`prefix_name`,`first_name`,`last_name`,`position_name`,`staff_type`,`email`,`department_id`,`role`,`is_active`,`must_change_password`) VALUES
  ('SYSTEM','admin','$2b$10$SJHahaCFPGSb1/cowyDsoOje3Q8YZv72SNBq1p4qJTjtduzohRbhy','','ผู้ดูแล','ระบบ','System Administrator','ระบบ','admin@skru.ac.th',2,'admin',1,1),
  ('DIR001','director','$2b$10$S6dzpmZ1rJYL1mzlsSgEEeVZObqozgu2mdzUSTuCO0AZlFAHrZEDm','','ผู้อำนวยการ','สำนัก','ผู้อำนวยการ','ผู้บริหาร','director@skru.ac.th',1,'director',1,1),
  ('3197','gary.li','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','Mr.','Gary','Linton','อาจารย์','พนักงานประจำตามสัญญา','gary.li@skru.ac.th',3,'employee',1,1),
  ('3620','robert.st','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','Mr.','Robert Steven','Judge','อาจารย์','พนักงานประจำตามสัญญา','robert.st@skru.ac.th',3,'employee',1,1),
  ('2304','tatik.ek','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','Mrs.','Tatik','Ekawati','อาจารย์','พนักงานประจำตามสัญญา','tatik.ek@skru.ac.th',3,'employee',1,1),
  ('482','kobsak.na','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','กอบศักดิ์','ณ นคร','นักวิชาการคอมพิวเตอร์','พนักงานมหาวิทยาลัย','kobsak.na@skru.ac.th',3,'employee',1,1),
  ('550','charun.su','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','จรุณ','สังข์สวัสดิ์','บรรณารักษ์','พนักงานประจำตามสัญญา','charun.su@skru.ac.th',3,'employee',1,1),
  ('553','jaruk.ko','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาง','จารึก','คงขวัญ','บรรณารักษ์','พนักงานราชการ','jaruk.ko@skru.ac.th',3,'employee',1,1),
  ('487','jaruwan.ph','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาง','จารุวรรณ','เพชรรักษ์','เจ้าหน้าที่บริหารงานทั่วไปชำนาญการ','พนักงานมหาวิทยาลัย','jaruwan.ph@skru.ac.th',1,'employee',1,1),
  ('493','chaweewan.ka','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว','ฉวีวรรณ','แก้วฤดี','นักวิชาการศึกษาชำนาญการ','พนักงานมหาวิทยาลัย','chaweewan.ka@skru.ac.th',1,'employee',1,1),
  ('190','patcharapon.sa','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','ชยพล','สันสาคร','นักวิชาการคอมพิวเตอร์','พนักงานราชการ','patcharapon.sa@skru.ac.th',2,'employee',1,1),
  ('595','thanapat.je','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','ธนภัทร','เจิมขวัญ','นักวิชาการคอมพิวเตอร์ชำนาญการ','พนักงานมหาวิทยาลัย','thanapat.je@skru.ac.th',2,'employee',1,1),
  ('596','thanasak.sa','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','ธนศักดิ์','สง่า','นักวิชาการคอมพิวเตอร์ชำนาญการ','พนักงานมหาวิทยาลัย','thanasak.sa@skru.ac.th',2,'employee',1,1),
  ('609','nakool.kh','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','นกูล','ขุนแผน','นักวิชาการคอมพิวเตอร์ชำนาญการ','พนักงานมหาวิทยาลัย','nakool.kh@skru.ac.th',3,'employee',1,1),
  ('623','boonthung.ko','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','บุญถึง','คงแก้ว','นักวิชาการคอมพิวเตอร์','พนักงานราชการ','boonthung.ko@skru.ac.th',2,'employee',1,1),
  ('640','predawan.si','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว','ปรีดาวรรณ','สินจรูญศักดิ์','บรรณารักษ์ชำนาญการ','พนักงานมหาวิทยาลัย','predawan.si@skru.ac.th',1,'employee',1,1),
  ('644','piya.ma','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','ปิยะ','มาส่ง','นักวิชาการโสตทัศนศึกษา','พนักงานประจำตามสัญญา','piya.ma@skru.ac.th',3,'employee',1,1),
  ('170','pornpimon.ko','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว','พรพิมล','ภักดีวงศ์ธรรม','พนักงานห้องสมุด','ลูกจ้างประจำ','pornpimon.ko@skru.ac.th',3,'employee',1,1),
  ('686','ratchanee.je','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาง','รัชณี','จารุธรรม','บรรณารักษ์ชำนาญการ','พนักงานมหาวิทยาลัย','ratchanee.je@skru.ac.th',3,'employee',1,1),
  ('695','watita.ri','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว','วทิตา','ฤทธิโชค','เจ้าหน้าที่บริหารงานทั่วไปชำนาญการ','พนักงานมหาวิทยาลัย','watita.ri@skru.ac.th',1,'employee',1,1),
  ('3432','wannakorn.ya','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว','วรรณกร','ยางประยงค์','เจ้าหน้าที่บริหารงานทั่วไป','พนักงานมหาวิทยาลัย','wannakorn.ya@skru.ac.th',1,'employee',1,1),
  ('698','wandee.en','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว','วันดี','เอ้งเถี้ยว','นักวิเทศสัมพันธ์','พนักงานมหาวิทยาลัย','wandee.en@skru.ac.th',3,'employee',1,1),
  ('709','saranya.ro','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว','ศรัญญา','โรจนวงศ์ชัย','นักเอกสารสนเทศชำนาญการ','พนักงานมหาวิทยาลัย','saranya.ro@skru.ac.th',3,'employee',1,1),
  ('719','somsri.wh','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว','สมศรี','หวันชิตนาย','บรรณารักษ์ชำนาญการ','พนักงานมหาวิทยาลัย','somsri.wh@skru.ac.th',3,'employee',1,1),
  ('520','somsak.lo','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','สมศักดิ์','เหลาะเหม','นักวิชาการคอมพิวเตอร์ชำนาญการ','พนักงานมหาวิทยาลัย','somsak.lo@skru.ac.th',2,'employee',1,1),
  ('713','somchok.na','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','สมโชค','ณ ถลาง','บรรณารักษ์','พนักงานประจำตามสัญญา','somchok.na@skru.ac.th',3,'employee',1,1),
  ('722','sarayut.ku','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','สรายุทธ','กูลเกื้อ','นักวิชาการคอมพิวเตอร์ชำนาญการ','พนักงานมหาวิทยาลัย','sarayut.ku@skru.ac.th',2,'employee',1,1),
  ('2725','anaphat.kh','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','อนพัช','คุโณดม','นักวิชาการคอมพิวเตอร์','พนักงานราชการ','anaphat.kh@skru.ac.th',2,'employee',1,1),
  ('769','arun.da','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย','อรุณ','แดงประดา','นักวิชาการคอมพิวเตอร์','พนักงานราชการ','arun.da@skru.ac.th',2,'employee',1,1),
  ('169','plern.ch','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว','เพลิน','จันทวงศ์','พนักงานห้องสมุด','ลูกจ้างประจำ','plern.ch@skru.ac.th',3,'employee',1,1),
  ('661','penprapa.bi','$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาง','เพ็ญประภา','ยีเจ๊ะ','นักวิชาการคอมพิวเตอร์ชำนาญการ','พนักงานมหาวิทยาลัย','penprapa.bi@skru.ac.th',2,'employee',1,1);

SET FOREIGN_KEY_CHECKS = 1;
-- End of database.sql