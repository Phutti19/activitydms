# สรุปสิ่งที่ต้องเพิ่ม/แก้ไข — ประชุม 2026-05-14

> เอกสารนี้สรุปรายการที่ต้องเพิ่มหลังประชุม เพื่อให้ทีมพัฒนาดำเนินการต่อ
> Spec หลัก: [ActivityDMS_Spec_v1.1.md](ActivityDMS_Spec_v1.1.md)
> กฎหลัก / ข้อห้าม: [CLAUDE.md](../CLAUDE.md)

---

## สรุปย่อ (TL;DR)

| # | หัวข้อ | ระดับผลกระทบ | ส่วนที่กระทบ |
|:-:|---|:-:|---|
| 1 | ตั้งค่าปีงบประมาณ 2569 (1 ต.ค. 68 – 30 ก.ย. 69) | Data | `fiscal_years`, seed |
| 2 | เพิ่ม "รูปแบบกิจกรรม" ออนไลน์ / ออนไซต์ | Schema + UI | `activities`, form add/edit, list |
| 3 | แสดงผลแบบเรียลไทม์ในหน้าเว็บ | Frontend | dashboard, list, calendar |
| 4 | In-app notification (แจ้งเตือนฝังในผู้ใช้) | Schema + UI | ตารางใหม่ + bell icon ใน header |
| 5 | ปีงบประมาณปัจจุบันเป็น default ในฟอร์ม | UX | add user / add activity |
| 6 | แนบรูป: เลือกอัปโหลด **หรือ** ใส่ลิงก์ Google Drive | Schema + UI | `activity_photos`, upload form |
| 7 | หน้าสรุป: เลือกดูประวัติการเข้าร่วมรายบุคคล | Report | admin/director report |
| 8 | รายงานแบบเลือก "เดือน ถึง เดือน" ของไตรมาส | Report | filter ในรายงาน |

---

## 1. ปีงบประมาณ 2569

- ช่วง: **1 ตุลาคม 2568 – 30 กันยายน 2569**
- ต้องมี record ในตาราง `fiscal_years`:
  ```sql
  INSERT INTO fiscal_years (year_be, start_date, end_date, is_active)
  VALUES (2569, '2025-10-01', '2026-09-30', 1);
  ```
- ต้องตั้ง `is_active = 1` สำหรับ FY 2569 และ `is_active = 0` กับปีอื่น ๆ (ถ้ามี)
- ใช้ปีนี้เป็น default ในข้อ 5

---

## 2. รูปแบบกิจกรรม (Activity Format)

**เพิ่ม field ใหม่ใน `activities`:**

```sql
ALTER TABLE activities
  ADD COLUMN format ENUM('onsite','online','hybrid') NOT NULL DEFAULT 'onsite'
  AFTER activity_type_id;
```

> เริ่มจาก 2 ค่าหลัก (`onsite` / `online`) — `hybrid` เผื่ออนาคต ไม่ต้องแสดงในตอนแรกก็ได้

**UI ที่ต้องแก้:**
- ฟอร์มเพิ่ม/แก้กิจกรรม (admin + employee personal) — radio/select รูปแบบ
- หน้า list / detail — แสดง badge รูปแบบ (เช่น `🟢 ออนไลน์` / `🔵 ออนไซต์`)
- ถ้าเป็น `online` → field "ลิงก์เข้าร่วม (URL)" ควรเป็น required หรืออย่างน้อยแนะนำให้ใส่
- ถ้าเป็น `onsite` → field "สถานที่" required

**Schema sync:** อัปเดต [database/schema.sql](../database/schema.sql) ตามกฎ §5 ใน [CLAUDE.md](../CLAUDE.md)

---

## 3. การแสดงผลแบบเรียลไทม์

**ขอบเขต:** หน้าที่ data เปลี่ยนบ่อย ต้องสะท้อนสถานะใหม่โดยไม่ต้อง refresh manual

**หน้าเป้าหมาย:**
- Admin dashboard — จำนวนกิจกรรม / pending registrations
- Activity detail — รายชื่อผู้สมัคร / สถานะ attended
- Calendar — กิจกรรมใหม่ที่เพิ่งสร้าง
- Bell notification (ดูข้อ 4)

**แนวทาง (เลือกตามความเหมาะสม):**
- **Polling (แนะนำสำหรับเริ่มต้น):** `setInterval` เรียก API ทุก 15–30 วินาที — ง่าย ไม่ต้องตั้ง server เพิ่ม เข้ากับ pure PHP
- **Server-Sent Events (SSE):** ถ้าต้องการ near-real-time จริง ๆ (เช่น bell แจ้งเตือน)
- ❌ **ไม่แนะนำ WebSocket** — ต้องใช้ Node/Ratchet จะหลุดจาก stack pure PHP

**ข้อควรระวัง:**
- API endpoint ที่เป็น polling ต้อง cheap (LIMIT, index ครบ, return เฉพาะ delta ถ้าเป็นไปได้)
- เคารพ permission เหมือนเดิม — endpoint ต้อง `require_role()` และ filter `scope` ตามกฎข้อ 1 ใน CLAUDE.md
- มี throttle/debounce ฝั่ง client

---

## 4. In-app Notification (แจ้งเตือนฝังในผู้ใช้)

**เป้าหมาย:** มี 🔔 bell icon ใน header — เด้งเลขเมื่อมีกิจกรรมใหม่ที่ user เป็นผู้เข้าร่วม

**Schema ใหม่:**

```sql
CREATE TABLE notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type ENUM('new_activity','new_certificate','system') NOT NULL,
  title VARCHAR(255) NOT NULL,
  message TEXT,
  link_url VARCHAR(500),         -- คลิกแล้วไปไหน (เช่น /employee/activity_detail.php?id=...)
  ref_type VARCHAR(50),          -- 'activity' | 'certificate'
  ref_id INT UNSIGNED,           -- FK แบบ loose (ไม่ผูก hard เพื่อให้ลบ activity ได้)
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_unread (user_id, is_read, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Trigger (ทำคู่กับ email queue เดิม):**
- เมื่อ admin เพิ่ม user เข้า `activity_registrations` → INSERT `notifications` + INSERT `email_queue`
- เมื่อ admin upload `certificates` → INSERT `notifications` + INSERT `email_queue`

> **ความสัมพันธ์กับ email:** in-app notification = real-time, อ่านใน UI / email = นอกระบบ ทั้งสองทำงานคู่กัน ไม่แทนกัน (เคารพ `notification_settings` เหมือนกัน)

**Endpoint:**
- `GET /api/notifications.php?unread_only=1` — ดึงรายการ (polling จากข้อ 3)
- `POST /api/notifications.php?action=mark_read` — mark as read (CSRF token + role check)

**UI:**
- Bell icon ใน `includes/header.php` พร้อม badge เลข unread
- Dropdown แสดงล่าสุด 10 รายการ + ลิงก์ "ดูทั้งหมด"
- รองรับ Director ด้วย (เป็น participant ได้)

---

## 5. ปีงบประมาณปัจจุบันเป็น Default

**หน้าที่กระทบ:**
- `admin/add_user.php` — field `fiscal_year_id` (ถ้ามี) default = active fiscal year
- `admin/add_activity.php` — default = active fiscal year
- `employee/add_personal_activity.php` — default = active fiscal year

**Pattern:**
```php
// ดึง active fiscal year ครั้งเดียวใน auth.php หรือ helper
$stmt = $pdo->query("SELECT id FROM fiscal_years WHERE is_active = 1 LIMIT 1");
$active_fy_id = $stmt->fetchColumn();
```
แล้วใน form:
```html
<select name="fiscal_year_id">
  <?php foreach ($fiscal_years as $fy): ?>
    <option value="<?= (int)$fy['id'] ?>"
      <?= $fy['id'] == $active_fy_id ? 'selected' : '' ?>>
      <?= htmlspecialchars($fy['year_be'], ENT_QUOTES, 'UTF-8') ?>
    </option>
  <?php endforeach; ?>
</select>
```

---

## 6. แนบรูป: อัปโหลด หรือ ลิงก์ Google Drive

**เป้าหมาย:** กิจกรรมที่มีรูปเยอะ ๆ ไม่ต้องอัปโหลดทุกใบ — แนบลิงก์อัลบั้ม Drive ได้

**Schema เปลี่ยน — ทางเลือก A (แนะนำ):** เพิ่ม field ใน `activity_photos`
```sql
ALTER TABLE activity_photos
  ADD COLUMN source ENUM('upload','drive_link') NOT NULL DEFAULT 'upload' AFTER activity_id,
  ADD COLUMN drive_url VARCHAR(500) NULL AFTER file_path,
  MODIFY COLUMN file_path VARCHAR(500) NULL;  -- null ได้ถ้าเป็น drive_link
```

**Constraint:** ต้องมีอย่างน้อยหนึ่งของ `file_path` หรือ `drive_url`:
```sql
ALTER TABLE activity_photos
  ADD CONSTRAINT chk_photo_source
  CHECK (
    (source = 'upload'     AND file_path IS NOT NULL AND drive_url IS NULL) OR
    (source = 'drive_link' AND drive_url IS NOT NULL AND file_path IS NULL)
  );
```

**Validation ของ drive_url (ใน PHP):**
- ต้องขึ้นต้นด้วย `https://drive.google.com/` หรือ `https://docs.google.com/`
- ใช้ `filter_var($url, FILTER_VALIDATE_URL)` ก่อน
- เก็บลงตามเดิมหลังผ่าน — output ผ่าน `htmlspecialchars()`

**UI:**
- ในฟอร์มเพิ่มรูป มี toggle: `( ) อัปโหลด  ( ) แนบลิงก์ Drive`
- ถ้าเป็น drive_link → ไม่นับใน chk_photos_order (5 ภาพ) **หรือ** นับด้วย? → **decide:** ทีมตกลงว่านับรวมกัน ≤ 5

> **ห้ามลืม:** กฎ §2 ข้อ 8 ใน [CLAUDE.md](../CLAUDE.md) — สูงสุด 5 ภาพต่อกิจกรรม ทั้ง upload + drive_link รวมกัน

---

## 7. หน้าสรุป: เลือกดูประวัติเข้าร่วมรายบุคคล

**Spec:**
- ในหน้ารายงาน/สรุปของ admin (และ director) เพิ่ม dropdown / search "เลือกผู้ใช้"
- แสดงผล:
  - รายการกิจกรรมที่ user คนนั้นเข้าร่วม (เฉพาะ `scope = 'organization'` — ห้าม personal!)
  - สถานะ: registered / attended / absent
  - จำนวนรวม + อัตราเข้าร่วม %
  - เกียรติบัตรที่ได้รับ

**Query pattern:**
```php
// admin/director เห็นเฉพาะ organization
$stmt = $pdo->prepare("
  SELECT a.id, a.title, a.start_date, ar.status
  FROM activity_registrations ar
  JOIN activities a ON a.id = ar.activity_id
  WHERE ar.user_id = :uid
    AND a.scope = 'organization'   -- กฎข้อ 1 ใน CLAUDE.md
  ORDER BY a.start_date DESC
");
$stmt->execute([':uid' => $selected_user_id]);
```

**Permission:**
- Admin: เลือก user ใดก็ได้
- Director: เลือก user ใดก็ได้ (read-only)
- Employee: **ไม่มีหน้านี้** — มีแต่ "กิจกรรมของฉัน" เห็นเฉพาะตัวเอง

**Export:** ถ้าเป็นไปได้ ทำปุ่ม export Excel/CSV ของรายงานนี้

---

## 8. รายงานแบบเลือกเดือนถึงเดือน (รายไตรมาส)

**ปัจจุบัน (สมมติ):** report filter เป็นรายปีงบประมาณ
**เพิ่ม:** ตัวกรอง "เดือน ถึง เดือน" ภายในปีงบประมาณ — รองรับรายไตรมาส

**โครงสร้างไตรมาส (ตามปีงบประมาณไทย):**
| ไตรมาส | เดือน |
|:-:|---|
| Q1 | ต.ค. – ธ.ค. |
| Q2 | ม.ค. – มี.ค. |
| Q3 | เม.ย. – มิ.ย. |
| Q4 | ก.ค. – ก.ย. |

**UI:**
- 2 select: "เดือนเริ่มต้น" → "เดือนสิ้นสุด"
- หรือปุ่ม shortcut: `Q1 | Q2 | Q3 | Q4 | ทั้งปี`
- แสดงร่วมกับตัวกรอง fiscal year เดิม

**Query:**
```php
// filter ระดับเดือนภายใน fiscal year ที่เลือก
$stmt = $pdo->prepare("
  SELECT ...
  FROM activities a
  WHERE a.fiscal_year_id = :fy_id
    AND a.start_date BETWEEN :start_date AND :end_date
    AND a.scope = 'organization'
  ORDER BY a.start_date
");
```

ผูก `:start_date` / `:end_date` จาก dropdown เดือน (วันแรก/วันสุดท้ายของเดือน)

---

## ลำดับการทำ (Suggested order)

1. **Schema migration** (ข้อ 1, 2, 4, 6) — กระทบ DB อย่างเดียว ทำก่อนเพื่อให้ field พร้อม
2. **Default fiscal year helper** (ข้อ 5) — ใช้ในข้อต่อ ๆ ไป
3. **Activity format UI** (ข้อ 2) — ฟีเจอร์เล็ก ทดสอบ form flow
4. **Drive link photos** (ข้อ 6) — ขยาย photo upload เดิม
5. **In-app notification** (ข้อ 4) — schema + bell + endpoint
6. **Real-time polling** (ข้อ 3) — ใช้ endpoint จากข้อ 4 เป็นตัวอย่าง pattern
7. **Report enhancements** (ข้อ 7, 8) — ทำที่หน้า report admin/director

---

## Checklist ก่อน merge (ทุกข้อ)

- [ ] อัปเดต [database/schema.sql](../database/schema.sql) — ห้ามแก้เฉพาะ DB จริง (CLAUDE.md §5)
- [ ] ทุก SQL ใช้ PDO prepared statement (CLAUDE.md §2.5)
- [ ] ทุก echo ผ่าน `htmlspecialchars()` (CLAUDE.md §2.6)
- [ ] ทุก POST มี CSRF token (CLAUDE.md §2.7)
- [ ] Personal activity ยังเป็น private 100% — ไม่หลุดในรายงาน / notification (CLAUDE.md §2.1)
- [ ] Director ยัง read-only — endpoint ใหม่ที่เป็น POST ต้องบล็อก director (CLAUDE.md §2.2 + §6)
- [ ] Mobile test ที่ 375×667 — bell dropdown / filter ใหม่ ใช้งานได้จริง (CLAUDE.md §8)
- [ ] Audit log สำหรับ action สำคัญ (CLAUDE.md §7)

---

## เปิดประเด็นที่ต้อง confirm

> ถ้ามีข้อใดทีมยังไม่ตกลง — ใส่ TODO ที่นี่ก่อน อย่าทำเดา

- [ ] ข้อ 2: ต้องมี `hybrid` ตั้งแต่แรกหรือเก็บไว้ก่อน?
- [ ] ข้อ 3: ความถี่ polling — 15s / 30s / 60s?
- [ ] ข้อ 4: ให้ in-app notification เคารพ `notification_settings` เดียวกับ email หรือแยก setting?
- [ ] ข้อ 6: drive_link นับรวม 5 ภาพหรือนับแยก? (default ข้างบน: นับรวม)
- [ ] ข้อ 7: export Excel/CSV ต้องมีตั้งแต่ phase นี้หรือ phase ถัดไป?
