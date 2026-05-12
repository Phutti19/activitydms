-- =============================================================================
-- seed_employees.sql — Seed รายชื่อ Employee จาก STAFF_ARIT (29 คน)
-- =============================================================================
-- วัตถุประสงค์: เพิ่ม employee เข้า DB โดยเฉพาะ (ไม่รวม admin / director)
-- ใช้ INSERT IGNORE → ถ้า username/email ซ้ำ จะ skip ปลอดภัยรันซ้ำได้
--
-- เงื่อนไขก่อนรัน:
--   1. ต้อง import schema.sql ก่อน (มี departments + ตาราง users แล้ว)
--   2. departments ต้องมี id 1, 2, 3 ครบ
--
-- รหัสผ่านเริ่มต้น: Skru@2026  (bcrypt hash ด้านล่าง)
-- must_change_password = 1  → บังคับเปลี่ยนรหัสครั้งแรก login
--
-- รัน:  mysql -u root -p activitydms < database/seed_employees.sql
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO `users`
  (`staff_code`,`username`,`password_hash`,`prefix_name`,`first_name`,`last_name`,
   `position_name`,`staff_type`,`email`,`department_id`,`role`,`is_active`,`must_change_password`)
VALUES
  -- แผนก 3: งานทรัพยากรสารสนเทศและภาษาต่างประเทศ
  ('3197','gary.li',         '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','Mr.',    'Gary',          'Linton',         'อาจารย์',                          'พนักงานประจำตามสัญญา','gary.li@skru.ac.th',          3,'employee',1,1),
  ('3620','robert.st',       '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','Mr.',    'Robert Steven', 'Judge',          'อาจารย์',                          'พนักงานประจำตามสัญญา','robert.st@skru.ac.th',        3,'employee',1,1),
  ('2304','tatik.ek',        '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','Mrs.',   'Tatik',         'Ekawati',        'อาจารย์',                          'พนักงานประจำตามสัญญา','tatik.ek@skru.ac.th',         3,'employee',1,1),
  ('482', 'kobsak.na',       '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'กอบศักดิ์',     'ณ นคร',          'นักวิชาการคอมพิวเตอร์',            'พนักงานมหาวิทยาลัย',  'kobsak.na@skru.ac.th',        3,'employee',1,1),
  ('550', 'charun.su',       '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'จรุณ',          'สังข์สวัสดิ์',   'บรรณารักษ์',                       'พนักงานประจำตามสัญญา','charun.su@skru.ac.th',        3,'employee',1,1),
  ('553', 'jaruk.ko',        '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาง',     'จารึก',         'คงขวัญ',         'บรรณารักษ์',                       'พนักงานราชการ',       'jaruk.ko@skru.ac.th',         3,'employee',1,1),
  ('644', 'piya.ma',         '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'ปิยะ',          'มาส่ง',          'นักวิชาการโสตทัศนศึกษา',           'พนักงานประจำตามสัญญา','piya.ma@skru.ac.th',          3,'employee',1,1),
  ('170', 'pornpimon.ko',    '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว',  'พรพิมล',        'ภักดีวงศ์ธรรม',  'พนักงานห้องสมุด',                  'ลูกจ้างประจำ',        'pornpimon.ko@skru.ac.th',     3,'employee',1,1),
  ('686', 'ratchanee.je',    '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาง',     'รัชณี',         'จารุธรรม',       'บรรณารักษ์ชำนาญการ',               'พนักงานมหาวิทยาลัย',  'ratchanee.je@skru.ac.th',     3,'employee',1,1),
  ('698', 'wandee.en',       '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว',  'วันดี',         'เอ้งเถี้ยว',     'นักวิเทศสัมพันธ์',                 'พนักงานมหาวิทยาลัย',  'wandee.en@skru.ac.th',        3,'employee',1,1),
  ('709', 'saranya.ro',      '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว',  'ศรัญญา',        'โรจนวงศ์ชัย',    'นักเอกสารสนเทศชำนาญการ',           'พนักงานมหาวิทยาลัย',  'saranya.ro@skru.ac.th',       3,'employee',1,1),
  ('719', 'somsri.wh',       '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว',  'สมศรี',         'หวันชิตนาย',     'บรรณารักษ์ชำนาญการ',               'พนักงานมหาวิทยาลัย',  'somsri.wh@skru.ac.th',        3,'employee',1,1),
  ('713', 'somchok.na',      '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'สมโชค',         'ณ ถลาง',         'บรรณารักษ์',                       'พนักงานประจำตามสัญญา','somchok.na@skru.ac.th',       3,'employee',1,1),
  ('609', 'nakool.kh',       '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'นกูล',          'ขุนแผน',         'นักวิชาการคอมพิวเตอร์ชำนาญการ',    'พนักงานมหาวิทยาลัย',  'nakool.kh@skru.ac.th',        3,'employee',1,1),
  ('169', 'plern.ch',        '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว',  'เพลิน',         'จันทวงศ์',       'พนักงานห้องสมุด',                  'ลูกจ้างประจำ',        'plern.ch@skru.ac.th',         3,'employee',1,1),

  -- แผนก 2: งานเทคโนโลยีสารสนเทศดิจิทัล
  ('190', 'patcharapon.sa',  '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'ชยพล',          'สันสาคร',        'นักวิชาการคอมพิวเตอร์',            'พนักงานราชการ',       'patcharapon.sa@skru.ac.th',   2,'employee',1,1),
  ('595', 'thanapat.je',     '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'ธนภัทร',        'เจิมขวัญ',       'นักวิชาการคอมพิวเตอร์ชำนาญการ',    'พนักงานมหาวิทยาลัย',  'thanapat.je@skru.ac.th',      2,'employee',1,1),
  ('596', 'thanasak.sa',     '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'ธนศักดิ์',      'สง่า',           'นักวิชาการคอมพิวเตอร์ชำนาญการ',    'พนักงานมหาวิทยาลัย',  'thanasak.sa@skru.ac.th',      2,'employee',1,1),
  ('623', 'boonthung.ko',    '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'บุญถึง',        'คงแก้ว',         'นักวิชาการคอมพิวเตอร์',            'พนักงานราชการ',       'boonthung.ko@skru.ac.th',     2,'employee',1,1),
  ('520', 'somsak.lo',       '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'สมศักดิ์',      'เหลาะเหม',       'นักวิชาการคอมพิวเตอร์ชำนาญการ',    'พนักงานมหาวิทยาลัย',  'somsak.lo@skru.ac.th',        2,'employee',1,1),
  ('722', 'sarayut.ku',      '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'สรายุทธ',       'กูลเกื้อ',       'นักวิชาการคอมพิวเตอร์ชำนาญการ',    'พนักงานมหาวิทยาลัย',  'sarayut.ku@skru.ac.th',       2,'employee',1,1),
  ('2725','anaphat.kh',      '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'อนพัช',         'คุโณดม',         'นักวิชาการคอมพิวเตอร์',            'พนักงานราชการ',       'anaphat.kh@skru.ac.th',       2,'employee',1,1),
  ('769', 'arun.da',         '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาย',     'อรุณ',          'แดงประดา',       'นักวิชาการคอมพิวเตอร์',            'พนักงานราชการ',       'arun.da@skru.ac.th',          2,'employee',1,1),
  ('661', 'penprapa.bi',     '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาง',     'เพ็ญประภา',     'ยีเจ๊ะ',         'นักวิชาการคอมพิวเตอร์ชำนาญการ',    'พนักงานมหาวิทยาลัย',  'penprapa.bi@skru.ac.th',      2,'employee',1,1),

  -- แผนก 1: งานบริหารทั่วไป
  ('487', 'jaruwan.ph',      '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นาง',     'จารุวรรณ',      'เพชรรักษ์',      'เจ้าหน้าที่บริหารงานทั่วไปชำนาญการ','พนักงานมหาวิทยาลัย',  'jaruwan.ph@skru.ac.th',       1,'employee',1,1),
  ('493', 'chaweewan.ka',    '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว',  'ฉวีวรรณ',       'แก้วฤดี',        'นักวิชาการศึกษาชำนาญการ',          'พนักงานมหาวิทยาลัย',  'chaweewan.ka@skru.ac.th',     1,'employee',1,1),
  ('640', 'predawan.si',     '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว',  'ปรีดาวรรณ',     'สินจรูญศักดิ์',  'บรรณารักษ์ชำนาญการ',               'พนักงานมหาวิทยาลัย',  'predawan.si@skru.ac.th',      1,'employee',1,1),
  ('695', 'watita.ri',       '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว',  'วทิตา',         'ฤทธิโชค',        'เจ้าหน้าที่บริหารงานทั่วไปชำนาญการ','พนักงานมหาวิทยาลัย',  'watita.ri@skru.ac.th',        1,'employee',1,1),
  ('3432','wannakorn.ya',    '$2b$10$uWhI7ZNasSUX2PCifp.kl.JlYvJrVQqsuqB3vQKacDWSy.MsEm6ya','นางสาว',  'วรรณกร',        'ยางประยงค์',     'เจ้าหน้าที่บริหารงานทั่วไป',       'พนักงานมหาวิทยาลัย',  'wannakorn.ya@skru.ac.th',     1,'employee',1,1);

-- ตรวจสอบผลลัพธ์
SELECT
  d.name AS department,
  COUNT(*) AS total
FROM users u
JOIN departments d ON d.id = u.department_id
WHERE u.role = 'employee'
GROUP BY d.id, d.name
ORDER BY d.id;

-- คาดหวัง: dept 1 = 5, dept 2 = 9, dept 3 = 15  (รวม 29)
