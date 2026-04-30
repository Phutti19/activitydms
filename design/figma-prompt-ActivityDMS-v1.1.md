# 🎨 Figma Design Prompt — ActivityDMS v1.1
## ระบบจัดการกิจกรรมและเอกสารองค์กร (สำนักวิทยบริการและเทคโนโลยีสารสนเทศ — ARIT)

> **Version 1.1** | Pure PHP 8.x + MySQL 8.x + Bootstrap 5 (jQuery + FullCalendar)
> **Mobile-first** — ใช้งานจริงบนมือถือได้ ไม่ใช่แค่เปิดดูได้
> อิงสเปครวม `ActivityDMS_Spec_v1_1.md` + ข้อมูลจริง `STAFF_ARIT.xlsx` (29 คน) + schema จริง `database.sql`
> **เปลี่ยนแปลงสำคัญจาก v1.0:** มีหน้า Login (ไม่ใช่ SSO) / Mobile-first / สีประเภทกิจกรรมตรงตาม schema / 4 ประเภท / scope organization+personal / photo max 5 / email notification / fiscal year / calendar

---

## 1. PROJECT OVERVIEW

ออกแบบ Web Application ที่ render ฝั่ง server ด้วย **Pure PHP** ใช้ **Bootstrap 5** เป็น UI framework โดยตั้งใจให้รองรับการใช้งานจริงบนมือถือเป็นหลัก (Mobile-first) แล้ว scale ขึ้นไปยัง tablet / desktop

| Property | Value |
|---|---|
| **ชื่อระบบ** | ActivityDMS — Activity & Document Management System |
| **องค์กร** | สำนักวิทยบริการและเทคโนโลยีสารสนเทศ (ARIT) |
| **บุคลากร** | 29 คน + Admin 1 + Director 1 = 31 บัญชี |
| **แผนก** | 3 แผนก (งานบริหารทั่วไป / IT ดิจิทัล / ทรัพยากรสารสนเทศและภาษาฯ) |
| **Role** | Admin (สิทธิ์เต็ม) / Director (read-only) / Employee (ส่วนตัว) |
| **Layout หลัก** | Mobile: Top Navbar + Off-canvas Sidebar + Single column<br>Desktop ≥ 992px: Fixed Left Sidebar 240px + Main Content |

---

## 2. DESIGN SYSTEM

### 🎨 Color Palette

#### พื้นฐานระบบ
```
Primary Background  : #F8FAFC   พื้นหลังหลัก
Card / Surface      : #FFFFFF
Sidebar BG (Desktop): #0F172A   Navy Dark
Sidebar Hover       : rgba(56,189,248,0.10)
Sidebar Active      : rgba(56,189,248,0.15)
Sidebar Border      : rgba(255,255,255,0.06)

Accent Blue         : #0EA5E9   Primary action
Accent Blue Dark    : #0284C7   Hover state
Border Light        : #E2E8F0
Border Subtle       : #F1F5F9

Text Primary        : #0F172A
Text Secondary      : #64748B
Text Muted          : #94A3B8
Text White          : #FFFFFF
```

#### Role Colors (Avatar ring + Badge)
```
Admin               : #F59E0B   Amber
Director            : #A78BFA   Violet
Employee            : #34D399   Emerald
```

#### **Activity Type Colors — ตรงตาม `database.sql` ห้ามเปลี่ยน**
| ประเภท | HEX | BG (10% opacity) | Text |
|---|:---:|:---:|:---:|
| ประชุม | `#185FA5` | `#185FA51A` | `#0B3A6B` |
| อบรม | `#0F6E56` | `#0F6E561A` | `#0A4A3A` |
| สัมมนา | `#993C1D` | `#993C1D1A` | `#6B2A14` |
| อื่นๆ | `#5F5E5A` | `#5F5E5A1A` | `#3F3E3A` |

#### Scope Indicator (กิจกรรม)
```
Organization Badge  : BG #DBEAFE  Text #1E40AF  Label "องค์กร"  Icon: building
Personal Badge      : BG #F3E8FF  Text #6B21A8  Label "ส่วนตัว"  Icon: lock
```

#### Status Badge (Activity Registration)
```
Registered          : BG #FEF3C7  Text #92400E  Label "ลงทะเบียนแล้ว"
Attended            : BG #D1FAE5  Text #065F46  Label "เข้าร่วม"
Absent              : BG #FEE2E2  Text #991B1B  Label "ขาด"
```

#### Activity Time Status (จาก start/end datetime)
```
Upcoming            : BG #DBEAFE  Text #1E40AF  Label "กำลังจะมาถึง"
Ongoing             : BG #FEF3C7  Text #92400E  Label "กำลังดำเนินอยู่"
Completed           : BG #D1FAE5  Text #065F46  Label "เสร็จสิ้น"
```

#### Certificate Accent (Gold theme)
```
Gold BG Gradient    : Linear(#FFFBEB → #FEF3C7)
Gold Border         : #FDE68A
Gold Text           : #78350F
Gold Icon           : #F59E0B
Gold Button         : #F59E0B → hover #D97706
```

#### Email / Notification
```
Notification Dot    : #EF4444  (สีแดง บน bell icon เมื่อมี unread)
Email Status — Pending : BG #FEF3C7  Text #92400E
Email Status — Sent    : BG #D1FAE5  Text #065F46
Email Status — Failed  : BG #FEE2E2  Text #991B1B
```

---

### 🔤 Typography

```
Font Family (Thai)  : Sarabun (Google Fonts)
Font Family (EN)    : IBM Plex Sans / Inter (fallback)
```

#### ขนาด Mobile-first (เริ่มจาก mobile แล้วขยายบน desktop)

| Token | Mobile (< 768px) | Desktop (≥ 992px) |
|---|---|---|
| H1 Page Title | Sarabun SemiBold 20px / lh 1.3 | Sarabun SemiBold 24px / lh 1.3 |
| H2 Section | Sarabun SemiBold 16px | Sarabun SemiBold 18px |
| H3 Card Title | Sarabun SemiBold 14px | Sarabun SemiBold 15px |
| Body | Sarabun Regular 14px | Sarabun Regular 14px |
| Body Small | Sarabun Regular 13px | Sarabun Regular 13px |
| Label / Caption | Sarabun Medium 12px | Sarabun Medium 12px |
| Badge / Tag | Sarabun Bold 11px / letter-spacing 0.3px | (เท่าเดิม) |
| Number Display | IBM Plex Sans Bold 24px | IBM Plex Sans Bold 30px |

> ⚠️ **ห้ามใช้ font size ต่ำกว่า 12px บน mobile** — อ่านยากบนหน้าจอเล็ก

---

### 📐 Spacing & Grid (Mobile-first)

```
Sidebar Width Desktop  : 240px (Fixed, ≥ 992px)
Sidebar Mobile         : Off-canvas slide from left (full or 280px)
Top Navbar Height      : 56px (Mobile + Tablet, ซ่อนบน Desktop)

Main Content Padding   : 16px (Mobile) → 24px (Tablet) → 32px (Desktop)

Card Border Radius     : 12px (Mobile) / 14px (Desktop)
Button Border Radius   : 10px
Input Border Radius    : 9px
Badge Border Radius    : 999px (Pill)
Avatar Border Radius   : 50%

Base Spacing Unit      : 4px
Gap between Cards      : 12px (Mobile) → 16px (Desktop)
Gap between Sections   : 20px (Mobile) → 28px (Desktop)
Form Field Gap         : 16px (vertical)

Touch Target (button)  : minimum 44 × 44 px (ทุกขนาดหน้าจอ)
```

#### Bootstrap 5 Breakpoints (อ้างอิง)
```
xs  : < 576px      Mobile (เป้าหมายหลัก iPhone SE 375px)
sm  : ≥ 576px      Phone (large)
md  : ≥ 768px      Tablet
lg  : ≥ 992px      Desktop (Sidebar เริ่มแสดง)
xl  : ≥ 1200px     Desktop (large)
```

---

### 🔳 Elevation / Shadow

```
Card Default          : 0px 1px 4px rgba(0,0,0,0.04)
Card Hover (Desktop)  : 0px 8px 30px rgba(0,0,0,0.10)
Top Navbar (Mobile)   : 0px 2px 8px rgba(0,0,0,0.06)
Modal Overlay         : 0px 25px 60px rgba(0,0,0,0.20)
Off-canvas Sidebar    : 0px 12px 40px rgba(0,0,0,0.18)
Sidebar Brand Icon    : 0px 10px 40px rgba(56,189,248,0.30)
Toast / Alert         : 0px 10px 25px rgba(0,0,0,0.10)
```

---

## 3. RESPONSIVE LAYOUT BEHAVIOR

ทุกหน้าต้องออกแบบทั้ง **Mobile** และ **Desktop** อย่างน้อย — Tablet ใช้ค่ากลาง

| Element | Mobile (< 768px) | Tablet (768–991px) | Desktop (≥ 992px) |
|---|---|---|---|
| Sidebar | Off-canvas (slide-in) | Off-canvas | Fixed left 240px |
| Top Navbar | แสดง (hamburger + brand + bell + avatar) | แสดง | ซ่อน |
| Main Padding | 16px | 24px | 32px |
| Card Grid | 1 column | 2 columns | 3-4 columns (auto-fill 320-340px) |
| Stat Card | 2 cols (grid 2×2) | 4 cols | 4 cols |
| Form | Stack vertical | 2 col grid | 2 col grid |
| Table | **Card list view** (transform tbody → cards) | `.table-responsive` (scroll) | Full table |
| Modal | **Full-screen** (`fullscreen-sm-down`) | Centered 600px | Centered 600-720px |
| Calendar | `listMonth` view | `dayGridMonth` | `dayGridMonth` |
| File Upload | `<input ... capture="environment">` | normal | normal |
| Tabs | Horizontal scroll-x | Horizontal | Horizontal |
| Buttons | Block / full-width on form | inline | inline |

> 🎯 **ทดสอบจริงบน iPhone SE (375 × 667)** — ทุกหน้าต้องไม่มี horizontal scroll

---

## 4. COMPONENT LIBRARY

### 4.1 Top Navbar (Mobile / Tablet)
```
┌──────────────────────────────────────────┐
│ [☰]  ActivityDMS    [🔔]  [Avatar]       │  height 56px
└──────────────────────────────────────────┘
BG: White, Border-bottom 1px #E2E8F0
- ☰ Hamburger (toggle off-canvas sidebar) — touch 44×44
- Brand text 14px Bold (ซ่อน logo บน xs)
- 🔔 Bell icon + red dot ถ้ามี notification ใหม่
- Avatar 32px (tap → dropdown: profile / change password / logout)
```

### 4.2 Off-canvas Sidebar (Mobile)
```
Slide from left, width 280px, BG #0F172A
ใช้ Bootstrap `.offcanvas-start`
โครงสร้างเหมือน Desktop Sidebar (4.3) — ลบ User Profile section ออก
เพราะแสดงบน Top Navbar แล้ว
[× Close button] อยู่บนสุด
```

### 4.3 Sidebar (Desktop ≥ 992px — Fixed Left)

**ขนาด:** 240px × 100vh | Background: #0F172A

```
┌─────────────────────────┐
│  [Brand Section]        │  padding: 22px 20px 18px
│  🛡 Icon (34×34)        │  Gradient: #38BDF8→#0EA5E9
│  ActivityDMS (Bold 13px)│  Border-bottom rgba(255,255,255,0.06)
│  ARIT (12px #94A3B8)    │
├─────────────────────────┤
│  [User Profile]         │  padding: 16px 20px
│  Avatar 36px            │  Border 2px = role color
│  ชื่อ-สกุล (12px Bold)   │  ตัด overflow ellipsis
│  Role Badge Pill        │
│  แผนก (11px #94A3B8)     │
├─────────────────────────┤
│  [Navigation Section]   │  padding: 12px 10px
│  Section Label (10px)   │  "หลัก" / "จัดการ" / "รายงาน"
│  Nav Item × N           │
├─────────────────────────┤
│  [Logout]               │  Border-top rgba(255,255,255,0.06)
└─────────────────────────┘
```

**Nav Item:**
- Default: Color `#94A3B8`, padding 11px 14px, gap 12px
- Hover: BG `rgba(56,189,248,0.10)`, Color `#E2E8F0`
- Active: BG `rgba(56,189,248,0.15)`, Color `#FFFFFF`, Left-border 2px solid `#38BDF8`
- Icon (Bootstrap Icons) + Label

#### Nav Items ตาม Role

**Admin** (10 items)
1. หน้าหลัก (`bi-speedometer2`)
2. กิจกรรม (`bi-calendar-event`)
3. เอกสาร (`bi-folder2-open`)
4. เกียรติบัตร (`bi-award`)
5. ปฏิทิน (`bi-calendar3`)
6. รายงานสรุป (`bi-graph-up`)
7. **— จัดการ —** (section label)
8. ผู้ใช้งาน (`bi-people`)
9. ปีงบประมาณ (`bi-calendar-range`)
10. ตั้งค่าการแจ้งเตือน (`bi-bell-fill`)

**Director** (4 items — read-only)
1. หน้าหลัก
2. กิจกรรม
3. ปฏิทิน
4. รายงานสรุป

**Employee** (7 items)
1. หน้าหลัก
2. กิจกรรมของฉัน (`bi-person-check`) — ที่ตัวเองเข้าร่วม
3. กิจกรรมส่วนตัว (`bi-journal-bookmark`)
4. กิจกรรมที่เปิดรับ (`bi-megaphone`)
5. เกียรติบัตรของฉัน (`bi-award`)
6. ปฏิทินของฉัน
7. รายงานของฉัน

---

### 4.4 Badge / Tag

```
Padding: 2px 10px | Radius 999px | Font: 11px Bold

Activity Type:
  ประชุม   : BG #185FA51A  Text #0B3A6B  + dot #185FA5
  อบรม     : BG #0F6E561A  Text #0A4A3A  + dot #0F6E56
  สัมมนา   : BG #993C1D1A  Text #6B2A14  + dot #993C1D
  อื่นๆ    : BG #5F5E5A1A  Text #3F3E3A  + dot #5F5E5A

Scope:
  Organization : BG #DBEAFE  Text #1E40AF  Icon building
  Personal     : BG #F3E8FF  Text #6B21A8  Icon lock-fill

Time Status:
  Completed  : BG #D1FAE5  Text #065F46
  Upcoming   : BG #DBEAFE  Text #1E40AF
  Ongoing    : BG #FEF3C7  Text #92400E

Registration Status:
  Registered : BG #FEF3C7  Text #92400E
  Attended   : BG #D1FAE5  Text #065F46
  Absent     : BG #FEE2E2  Text #991B1B

Role:
  Admin     : BG #FEF3C7  Text #92400E
  Director  : BG #EDE9FE  Text #5B21B6
  Employee  : BG #D1FAE5  Text #065F46
```

---

### 4.5 Stat Card

```
┌─────────────────────────────────┐
│  Label (12px Medium #64748B)    │  padding: 16-20px
│  Value (24-30px Bold #0F172A)   │  Border 1px #E2E8F0
│  Sub-text (11px #94A3B8)        │  Radius 12-14px
│                    [Icon Box]   │  BG White
│                    40 × 40 px   │
└─────────────────────────────────┘
Icon Box: BG = ActivityType color + 18 (10% opacity)
         Border-radius 10px
         Icon color = ActivityType color

Mobile: 2 cols (col-6) — number 24px
Desktop: 4 cols (col-lg-3) — number 30px
```

---

### 4.6 Activity Card (Grid View)

```
┌────────────────────────────────────┐
│ [Top Color Bar 5px gradient]       │  ◄── color = activity type
├────────────────────────────────────┤
│ [Type Badge] [Scope Badge] [Status]│  padding 16px (M) / 20px (D)
│                                    │
│ Title (14px Bold) — clamp 2 lines  │
│ Description (12px #64748B) clamp 2 │
│                                    │
│ 📅 15 มี.ค. 2568, 09:00–12:00      │  Font 12px #64748B
│ 📍 ห้องประชุมใหญ่ ชั้น 3 อาคาร 50  │  ตัด overflow
│                                    │
├────────────────────────────────────┤
│ 👥 12 คน  📁 3 ไฟล์  🏆 8         │  Border-top #F1F5F9
│ 📷 5 ภาพ  [ดูรายละเอียด →]        │  Footer 12px
└────────────────────────────────────┘

Hover (Desktop): translateY(-2px) + shadow escalation
Tap (Mobile): subtle press effect (bg-darker)

Top color bar (gradient เปลี่ยนตามประเภทกิจกรรม):
  ประชุม   : Linear(90deg, #185FA5 → #4A8DD4)
  อบรม     : Linear(90deg, #0F6E56 → #2EA681)
  สัมมนา   : Linear(90deg, #993C1D → #C56945)
  อื่นๆ    : Linear(90deg, #5F5E5A → #8A8985)
```

---

### 4.7 File Row Item

```
┌────────────────────────────────────────────────────┐
│ [FileIcon]  File Name (13px Bold) — clamp 1 line   │
│  40 × 48    1.2 MB · 15 มี.ค. 2568 · อัปโหลดโดย ...│
│             [⬇ Download] [🗑 Delete (Admin only)]   │
└────────────────────────────────────────────────────┘
padding: 12px (Mobile) / 14px 18px (Desktop)
Border-bottom 1px #F1F5F9 (กรณีอยู่ใน list)

File Icon Color (ตาม MIME):
  PDF  : #EF4444    XLSX : #22C55E    DOCX : #3B82F6
  PPTX : #F97316    JPG/PNG : #A855F7    Other : #64748B
Icon BG: color + "1A" (10%)  Border: color + "40" (25%)

Mobile: ปุ่ม Download/Delete ย้ายลงมาแถวล่างแบบ inline
Desktop: ปุ่มอยู่ขวาสุด
```

---

### 4.8 Photo Gallery (Activity — Max 5)

```
Mobile (single col):
┌─────────────────┐
│   Hero Photo    │  aspect 16:9 หรือ 4:3
│    (large)      │
└─────────────────┘
[1][2][3][4][+]   ◄── Thumbnails 64px scroll-x
                       กดเพื่อสลับ Hero
+ = empty placeholder ถ้ายังไม่ครบ 5 (Admin form เท่านั้น)

Desktop (Grid 5):
┌──────────────┬──────┬──────┐
│              │  2   │  3   │
│      1       ├──────┼──────┤
│              │  4   │  5   │
└──────────────┴──────┴──────┘
รูปแรกใหญ่ (2 col rowspan 2) + 4 รูปขนาดเท่ากัน
Click เปิด Lightbox / Modal

Admin Edit Mode:
- Drag-drop เพื่อจัดเรียง sort_order (1-5)
- ปุ่ม [×] ลบรูปแต่ละใบ
- Drop zone "เพิ่มภาพ" จนครบ 5 (ปุ่ม disabled เมื่อครบ)
```

---

### 4.9 Certificate Card (Employee View)

```
┌──────────────────────────────────────┐
│  BG: Linear(135deg, #FFFBEB→#FEF3C7) │
│  Border: 2px #FDE68A                 │
│  ╭─────────╮                         │
│  │  🏆     │  Decorative circles      │
│  │  48px   │  opacity 0.1             │
│  ╰─────────╯                         │
│                                      │
│  เกียรติบัตรการเข้าร่วม (14px Bold)   │
│  ชื่อกิจกรรม (13px #92400E) clamp 2  │
│  📅 15 มี.ค. 2568                     │
│  ออกเมื่อ 20 มี.ค. 2568              │
│                                      │
│  [⬇ ดาวน์โหลดเกียรติบัตร]   ←full-w │
└──────────────────────────────────────┘
Button: BG #F59E0B  Text White  Radius 10px
Padding: 18px (Mobile) / 22px (Desktop)
Mobile: full-width  Desktop: card grid 2-3 cols
```

---

### 4.10 Modal / Dialog

```
Overlay: rgba(15,23,42,0.6) + backdrop-blur(4px)

Mobile (xs):
- Full-screen modal (fullscreen-sm-down)
- Header: Back arrow [←] + Title + [×]
- Body: scroll independently
- Footer: sticky bottom, buttons full-width

Tablet/Desktop:
- Centered card
- Width: 420px (small) / 600px (medium) / 720px (large form)
- Max-height: 85vh, overflow-y scroll
- Header: Title 16-17px Bold + [×]
- Footer: actions right-aligned
- BG White, Radius 16px, Padding 20-28px
- Shadow: 0px 25px 60px rgba(0,0,0,0.20)
```

---

### 4.11 Buttons

```
Primary
  BG: Linear(135deg, #0EA5E9 → #0284C7)
  Text: White  Radius: 10px  Padding: 11px 18px (Desktop)
  Mobile: padding 12px 16px, ขั้นต่ำ 44px height
  Hover: brightness 0.95 + slight rise

Secondary
  BG: White  Border: 1px #D1D5DB  Text: #374151
  Hover: BG #F9FAFB

Danger
  BG: #FEF2F2  Border: #FECACA  Text: #B91C1C
  Hover: BG #FEE2E2
  ใช้สำหรับลบ — มี confirm modal เสมอ

Success
  BG: #F0FDF4  Border: #BBF7D0  Text: #15803D

Gold (Certificate only)
  BG: #F59E0B  Text: White  Radius: 10px
  Hover: BG #D97706

Icon-only Button
  Square 36×36 (Desktop) / 40×40 (Mobile)
  ใช้ใน toolbar / row action

Mobile-specific:
- ปุ่มสำคัญ (submit, primary action) full-width ภายใน form
- ปุ่ม secondary action vertical stack ใต้ primary
- Touch target ≥ 44 × 44 px เสมอ
```

---

### 4.12 Tab Navigation (In-page)

```
Mobile: horizontal scroll-x with snap, ซ่อน scrollbar
Desktop: full row

Tab Default : Color #64748B  Border-bottom 2px transparent  padding 10px 14px
Tab Active  : Color #0EA5E9  Border-bottom 2px #0EA5E9  Bold
Icon + Label gap 7px  Font 13px

ในหน้า Activity Detail ใช้ 5 tabs:
  [ภาพรวม] [ภาพถ่าย] [เอกสาร] [ผู้เข้าร่วม] [เกียรติบัตร]
```

---

### 4.13 Input / Select / Textarea

```
Border         : 1px solid #D1D5DB
Border-radius  : 9px
Padding        : 12px (Mobile) / 10px 12px (Desktop) — touch friendly
Font           : 14px Sarabun (Mobile = ไม่เล็กกว่านี้ ป้องกัน iOS zoom)
Min Height     : 44px (Mobile)
Focus          : Border #0EA5E9, Box-shadow 0 0 0 3px rgba(14,165,233,0.15)
Error          : Border #DC2626, Text-feedback #B91C1C 12px ใต้ field
Disabled       : BG #F3F4F6, Color #9CA3AF
Placeholder    : Color #9CA3AF

Label          : 13px Medium #374151  margin-bottom 6px
Required *     : Color #DC2626  margin-left 4px
Help text      : 12px #6B7280  margin-top 4px
```

#### Date / Time Picker
- ใช้ `<input type="datetime-local">` — เปิด native picker บน mobile (UX ดีที่สุด)
- Desktop optional: เพิ่ม flatpickr widget ถ้าต้องการ UI สวยกว่า

#### File Upload
```
Drop Zone (Desktop):
┌──────────────────────────────────┐
│   ⬆ Upload Icon (32px #94A3B8)  │
│   คลิกหรือลากไฟล์มาวาง            │
│   PDF / DOCX / XLSX / PPTX ≤ 10MB│  Border: 2px dashed #CBD5E1
│   [เลือกไฟล์]                    │  BG: #F8FAFC  Radius 12px
└──────────────────────────────────┘  Padding: 32px

Mobile:
- ปุ่มเดียว full-width "เลือกไฟล์"
- สำหรับภาพกิจกรรม: เพิ่ม `accept="image/*" capture="environment"`
  → เปิดกล้องโดยตรง
```

---

### 4.14 Calendar View (FullCalendar.js)

```
Desktop (≥ 992px):
- View: dayGridMonth (default), timeGridWeek, listWeek (toolbar switch)
- Events: BG = activity type color, Text white
- Click event → modal popup (รายละเอียดย่อ + ปุ่มไปหน้า detail)

Mobile (< 768px):
- AUTOMATIC: switch to `listMonth` view
- Toolbar: title + ปุ่ม [< >] เท่านั้น (ลบปุ่ม view switcher)
- Events แสดงแบบ list item: Date · Title · Type-dot · Time
- Tap → ไปหน้า activity detail โดยตรง

Filter Bar (เหนือ calendar):
- ประเภท (multi-checkbox / dropdown chips)
- Scope: ทั้งหมด / องค์กร / ส่วนตัว (Employee เท่านั้น)
- ปีงบประมาณ
```

---

### 4.15 Toast / Alert (Notification)

```
Position: bottom-center (Mobile) / top-right (Desktop)
Width: full-bleed minus 16px (Mobile) / 380px (Desktop)
Auto-dismiss: 4 sec  +  ปุ่ม [×] ปิด
Radius: 12px  Padding: 14px 16px  Shadow medium

Variants:
  Success : BG White  Border-left 4px #10B981  Icon ✓
  Info    : BG White  Border-left 4px #0EA5E9  Icon ⓘ
  Warning : BG White  Border-left 4px #F59E0B  Icon ⚠
  Danger  : BG White  Border-left 4px #EF4444  Icon !
```

---

### 4.16 Empty State

```
Center-aligned in card:
  [Illustration / Icon ขนาด 80-120px stroke-only มี opacity 0.6]
  หัวข้อ (14px Bold Color #374151)
  ข้อความรอง (13px Color #6B7280) — describe situation
  [ปุ่ม CTA ถ้ามี action ทำได้]

ตัวอย่าง:
- "ยังไม่มีกิจกรรมที่กำลังจะมาถึง"
- "ยังไม่มีเอกสารในกิจกรรมนี้"
- "ยังไม่ได้รับเกียรติบัตร" + ปุ่ม "ไปดูกิจกรรม"
```

---

### 4.17 Pagination (สำหรับ list ยาว)

```
Mobile: ปุ่ม [← ก่อนหน้า] [ถัดไป →] เท่านั้น + page indicator "หน้า 2 / 8"
Desktop: numbered pagination (1, 2, ..., last) + first/last buttons
```

---

## 5. SCREENS TO DESIGN

> **ทุกหน้าต้องออกแบบ 2 viewport:** Mobile (375 × 812) + Desktop (1440 × 900)
> **Frame Naming:** `[Role] — [Page Name] — [M / D]`
> เช่น `Admin — Dashboard — M` / `Admin — Dashboard — D`

---

### 🌐 PUBLIC SCREENS (ไม่ต้อง login)

#### SCREEN 0.1: Login
**Frame:** Public — Login

**Layout (Mobile):**
```
Full screen centered
├── Brand Logo (gradient circle 64px) + "ActivityDMS"
├── Subtitle "ระบบจัดการกิจกรรม สำนักวิทยบริการฯ"
├── Card (Radius 16px, Padding 24px)
│     ├── Input: Username (icon prefix bi-person)
│     ├── Input: Password (icon prefix bi-lock + toggle eye)
│     ├── Checkbox: จดจำการเข้าสู่ระบบ
│     └── [เข้าสู่ระบบ] Primary Button (full-width)
├── Error message (ถ้า login fail)
└── Footer: "© 2026 สำนักวิทยบริการฯ มหาวิทยาลัย..."

Desktop: Card 420px center | BG อาจเพิ่ม pattern หรือ gradient อ่อน
```

> ⚠️ ห้ามมีปุ่ม "ลงทะเบียน" — บัญชีสร้างโดย Admin เท่านั้น

---

#### SCREEN 0.2: Change Password (บังคับครั้งแรก + เลือกใช้)
**Frame:** Public — Change Password

```
Card (เหมือน Login):
├── Title "ตั้งรหัสผ่านใหม่"
├── Subtitle "เพื่อความปลอดภัย กรุณาตั้งรหัสผ่านใหม่"
├── Input: รหัสผ่านปัจจุบัน
├── Input: รหัสผ่านใหม่ (พร้อม strength meter)
├── Input: ยืนยันรหัสผ่านใหม่
├── Help text: "อย่างน้อย 8 ตัวอักษร, ผสมตัวเลข"
└── [บันทึก] Primary Button
```

---

### 👑 ADMIN SCREENS

#### SCREEN A1: Dashboard — Admin
**Frame:** Admin — Dashboard

```
[Top Navbar (M) / Sidebar (D)]
└── Main Content
    ├── Hero Banner (Dark gradient #0F172A → #1E293B)
    │     • "สวัสดี, ผู้ดูแลระบบ"
    │     • วันที่ปัจจุบัน + ปีงบประมาณที่ active
    │     • Subtle decorative circles
    │     Mobile: padding 20px, font slightly smaller
    │
    ├── Stat Cards Row
    │     Mobile: 2×2 grid (col-6)
    │     Desktop: 4 cols (col-lg-3)
    │     • กิจกรรมทั้งหมด: N
    │     • เอกสารทั้งหมด: N
    │     • เกียรติบัตรที่ออก: N
    │     • กำลังจะมาถึง (7 วัน): N
    │
    ├── 2-Column Grid (Desktop) / Stack (Mobile)
    │     Left:  กิจกรรมล่าสุด (5 รายการ) — list with Activity row
    │     Right: เกียรติบัตรล่าสุด (5 รายการ) — gold accent rows
    │
    └── Email Queue Status (เล็ก)
          • Pending: N  Sent today: N  Failed: N (lifetime)
          • [ดูรายละเอียด →]
```

---

#### SCREEN A2: Manage Users
**Frame:** Admin — จัดการผู้ใช้งาน

```
├── Page Header: "จัดการผู้ใช้งาน" + count "31 บัญชี"
├── Toolbar
│     • Search input (ชื่อ / username / email)
│     • Filter: แผนก / Role / Status
│     • [+ เพิ่มผู้ใช้] Primary
│
└── Mobile: Card list (each user as a card)
   Desktop: Table
   ┌────────────────────────────────────────────────────────────┐
   │ ผู้ใช้   │ Username │ แผนก       │ Role    │ Status │ จัดการ│
   ├────────────────────────────────────────────────────────────┤
   │ [นาย กอบศักดิ์ ณ นคร] │ kobsak.na │ ทรัพยากรฯ │ Employee │ Active │ ⋯ │
   │ [นาย ธนภัทร เจิมขวัญ] │ thanapat.je │ IT ดิจิทัล │ Employee │ Active │ ⋯ │
   │ ...                                                          │
   └────────────────────────────────────────────────────────────┘
   
   ⋯ Action menu: แก้ไข | รีเซ็ตรหัสผ่าน | ระงับ/เปิดใช้งาน | ลบ

Mobile Card Variant:
┌─────────────────────────────────────┐
│ [Avatar(role-color)] นายกอบศักดิ์... │
│                       [Role Badge]  │
│ kobsak.na · kobsak.na@skru.ac.th    │
│ แผนก: ทรัพยากรสารสนเทศและภาษาฯ      │
│ ตำแหน่ง: นักวิชาการคอมพิวเตอร์       │
│         [แก้ไข] [⋯ More]            │
└─────────────────────────────────────┘
```

**Modal: เพิ่ม/แก้ไขผู้ใช้**
- Username (unique), staff_code, prefix_name
- first_name, last_name
- email (unique), position_name, staff_type
- department_id (dropdown 3 แผนก)
- role (admin / director / employee)
- is_active toggle
- ปุ่ม [รีเซ็ตรหัสผ่าน] (เฉพาะ edit) → set must_change_password = 1

---

#### SCREEN A3: Manage Fiscal Year
**Frame:** Admin — ปีงบประมาณ

```
├── Page Header: "ปีงบประมาณ"
├── Active Fiscal Year Card (ใหญ่บนสุด)
│     "ปีงบประมาณ 2568"
│     เริ่ม: ตุลาคม 2024 (พ.ย. 2567)
│     สิ้นสุด: กันยายน 2025 (ก.ย. 2568)
│     [ตั้งเป็นปีปัจจุบัน] disabled (เพราะ active อยู่)
│
├── [+ เพิ่มปีงบประมาณ] Button
│
└── Table / Card list ของปีงบประมาณทั้งหมด
      ชื่อ | ช่วงเวลา | จำนวนกิจกรรม | Active? | จัดการ

Modal (Add/Edit):
- ชื่อปีงบประมาณ (เช่น "2569")
- เดือนเริ่ม (1-12 dropdown) + ปี
- เดือนสิ้นสุด (1-12) + ปี
- Toggle: ตั้งเป็นปีปัจจุบัน
```

---

#### SCREEN A4: Manage Activities List
**Frame:** Admin — กิจกรรมทั้งหมด

```
├── Page Header
├── Toolbar
│     Mobile: 1 row หลัก + filters collapse panel
│     • Search
│     • Filter button [⚙ ตัวกรอง] → bottom sheet (Mobile) / inline (Desktop)
│     • View toggle: [Grid / List / Calendar]
│     • [+ เพิ่มกิจกรรม] Primary
│
├── Filter Panel (เปิด-ปิดได้)
│     • ประเภทกิจกรรม (multi-chip)
│     • ปีงบประมาณ
│     • Scope: organization / personal (Admin เห็นแค่ org เท่านั้นจริงๆ)
│     • ช่วงวันที่ (from-to)
│     • Status: upcoming / ongoing / completed
│
└── Grid View (default)
      Mobile: 1 col   Tablet: 2 col   Desktop: 3-4 col (auto-fill 320px)
      Activity Cards × 6+
```

---

#### SCREEN A5: Add / Edit Activity
**Frame:** Admin — แบบฟอร์มกิจกรรม

```
Mobile: Full-screen modal / dedicated page
Desktop: Wide modal 720px

├── Header: "เพิ่มกิจกรรมใหม่" / "แก้ไขกิจกรรม"
├── Section: ข้อมูลพื้นฐาน
│     • ชื่อกิจกรรม * (max 255)
│     • รายละเอียด (textarea, optional)
│     • สถานที่
│     • ลิงก์ภายนอก (URL — optional)
│
├── Section: เวลา
│     • เริ่ม (datetime-local) *
│     • สิ้นสุด (datetime-local) *
│     • ปีงบประมาณ (auto จาก start datetime แต่แก้ได้)
│
├── Section: ประเภทและขอบเขต
│     • ประเภท * (4 ปุ่ม chip select: ประชุม/อบรม/สัมมนา/อื่นๆ)
│     • Scope: Organization (default ถ้า Admin สร้าง — read-only)
│     • Toggle: เปิดให้พนักงานสมัครเข้าร่วมเอง
│
├── Section: ภาพถ่าย (Max 5)
│     • Photo gallery component (4.8) แบบ edit mode
│     • drag-drop reorder
│     • ปุ่มเพิ่ม + counter "3 / 5"
│
├── Section: ไฟล์/ลิงก์แนบ
│     • Repeater: type (file/url) | label | file/url input | [×]
│     • [+ เพิ่มไฟล์/ลิงก์]
│
├── Section: ผู้เข้าร่วมเริ่มต้น (optional)
│     • Multi-select user (search + chip) — Admin เพิ่มล่วงหน้าได้
│     • หรือเว้นว่างไว้ใช้หน้า attendance เพิ่มทีหลัง
│
└── Footer: [ยกเลิก] [บันทึก] (Mobile: full-width stack)
```

---

#### SCREEN A6: Activity Detail (5 tabs)
**Frame:** Admin — รายละเอียดกิจกรรม

```
├── Hero Banner (BG = activity type color, gradient 90deg dark→light)
│     • [← กลับ]
│     • [Type Badge] [Scope Badge] [Status Badge]
│     • Title 20-22px Bold White, clamp 2 lines
│     • 📅 วันที่ 09:00–12:00 · 📍 สถานที่
│     • Action buttons: [แก้ไข] [ลบ] (Admin)
│
├── Tabs (4.12)
│     [ภาพรวม] [ภาพถ่าย] [เอกสาร] [ผู้เข้าร่วม] [เกียรติบัตร]
│
└── Tab content (เปลี่ยนตาม tab ที่เลือก)
```

**Tab 1: ภาพรวม**
- รายละเอียด (description)
- ลิงก์ภายนอก (ถ้ามี)
- สรุปสถิติ: ลงทะเบียน N · เข้าร่วม N · ขาด N · เกียรติบัตร N
- ผู้สร้าง / วันที่สร้าง / ปีงบประมาณ

**Tab 2: ภาพถ่าย** — Photo gallery (4.8) edit mode

**Tab 3: เอกสาร** — File row list (4.7) + ปุ่ม [+ อัปโหลดเอกสาร]

**Tab 4: ผู้เข้าร่วม**
```
Mobile: Card list
Desktop: Table (Avatar | ชื่อ | แผนก | สถานะ | เช็คชื่อ | จัดการ)

Toolbar:
  • [+ เพิ่มผู้เข้าร่วม] (multi-select user modal)
  • [📋 เช็คชื่อทั้งหมด] (bulk attended)
  • Filter status

Each row:
  • Avatar + ชื่อ + แผนก
  • Status dropdown: registered / attended / absent
  • วันที่เช็คชื่อ + ผู้เช็ค (ถ้ามี)
  • [×] ลบออก
```

**Tab 5: เกียรติบัตร**
```
List of certificates issued for this activity
- ผู้ที่มีเกียรติบัตรแล้ว: row gold-accent + [ดาวน์โหลด] + [ลบ]
- ผู้ที่เข้าร่วม (attended) ยังไม่มี: row default + [+ อัปโหลดเกียรติบัตร]
- Bulk: [อัปโหลดหลายไฟล์]
```

---

#### SCREEN A7: Attendance Check-in
**Frame:** Admin — เช็คชื่อ

ใช้ที่ tab "ผู้เข้าร่วม" แต่ดีไซน์ให้ใช้บนมือถือสะดวก
```
Mobile-optimized:
- Search box (ใส่ชื่อหรือ scan?)
- รายชื่อผู้ลงทะเบียน
  ┌─────────────────────────────────────┐
  │ [Avatar] นายกอบศักดิ์ ณ นคร          │
  │          ทรัพยากรฯ · นักวิชาการคอม.. │
  │  [✓ เข้า] [✗ ขาด]   ← ปุ่มใหญ่ touch│
  └─────────────────────────────────────┘
- Floating bottom bar: "เข้าร่วม X / Y · บันทึก"
```

---

#### SCREEN A8: Manage Documents (general)
**Frame:** Admin — จัดการเอกสาร

```
├── Page Header
├── Toolbar: search + filter (กิจกรรม / ผู้อัปโหลด / ประเภทไฟล์) + [+ อัปโหลด]
└── File Row List (4.7) — แสดงเอกสารทั้งหมดในระบบ
      ผูกกับ activity (badge link) หรือเอกสารทั่วไป (no activity)
```

---

#### SCREEN A9: Manage Certificates
**Frame:** Admin — จัดการเกียรติบัตร

```
├── Page Header + count
├── Toolbar
│     • Search ชื่อผู้รับ
│     • Filter: กิจกรรม / ปีงบประมาณ
│     • [+ อัปโหลดเกียรติบัตร] / [Bulk Upload]
│
└── Table / Card list
      ผู้รับ (avatar + ชื่อ + แผนก) | กิจกรรม | วันออก | ผู้อัปโหลด | จัดการ

Modal: อัปโหลดเกียรติบัตร
  • เลือกผู้รับ (search user)
  • เลือกกิจกรรม (search activity)
  • Upload file (PDF/JPG/PNG ≤ 5MB)
  • Toggle: ส่งอีเมลแจ้งเตือนทันที (default on)
```

---

#### SCREEN A10: Notification Settings
**Frame:** Admin — ตั้งค่าการแจ้งเตือน

```
├── Page Header
├── Section: SMTP Configuration (read-only display + เครื่องหมาย "config in .env")
│     • Host / Port / Encryption / From email / From name
│
├── Section: Notification Triggers
│     ┌─────────────────────────────────────────────┐
│     │ [Toggle] แจ้งเมื่อมีกิจกรรมใหม่              │
│     │           ส่งเมื่อ Admin เพิ่มผู้เข้าร่วม     │
│     │           [ดูตัวอย่างอีเมล]                  │
│     ├─────────────────────────────────────────────┤
│     │ [Toggle] แจ้งเมื่อออกเกียรติบัตรใหม่          │
│     │           ส่งเมื่อ Admin อัปโหลดเกียรติบัตร   │
│     │           [ดูตัวอย่างอีเมล]                  │
│     └─────────────────────────────────────────────┘
│
├── Section: Email Queue Status
│     • Pending: N | Sending: N | Sent (7d): N | Failed: N
│     • Recent Logs Table (queue_id | to | status | attempt_at | error)
│     • [Retry All Failed]
│
└── Section: Test Email
      • Input: ส่งทดสอบไปที่... (email)
      • [ส่งทดสอบ] button
```

---

#### SCREEN A11: Reports
**Frame:** Admin — รายงานสรุป

```
├── Page Header + filter bar (ปีงบประมาณ / แผนก / ช่วงเวลา)
│
├── Stat Cards Row (4)
│     กิจกรรมทั้งหมด · ผู้เข้าร่วม (รวม) · เอกสาร · เกียรติบัตร
│
├── 2-Column (Desktop) / Stack (Mobile)
│     Left:  Bar chart "กิจกรรมแยกตามประเภท"
│            ประชุม   8 ████████░░ (#185FA5)
│            อบรม    12 ███████████░ (#0F6E56)
│            สัมมนา   3 ███░░░░░░░ (#993C1D)
│            อื่นๆ    5 █████░░░░░ (#5F5E5A)
│
│     Right: "การเข้าร่วมรายแผนก"
│            แผนก 1: avg 4.2 กิจกรรม/คน
│            แผนก 2: avg 6.8 กิจกรรม/คน
│            แผนก 3: avg 3.1 กิจกรรม/คน
│
├── Top 10 ผู้เข้าร่วมมากสุด (table/card)
│     Avatar + ชื่อ + แผนก + จำนวนกิจกรรม + 🏆 เกียรติบัตร
│
└── Export buttons: [Excel] [PDF] (อนาคต — disabled ใน v1.1)
```

---

#### SCREEN A12: Calendar (Admin/Director shared)
**Frame:** Admin — ปฏิทิน / Director — ปฏิทิน

```
├── Page Header + Filter Bar
│     ปีงบประมาณ · ประเภท (multi-chip) · สถานที่ (search)
│
├── Calendar Component (FullCalendar)
│     Desktop: dayGridMonth + view switcher (เดือน/สัปดาห์/รายการ)
│     Mobile:  listMonth (auto)
│     Events styled with activity type color
│
└── Event tap/click → modal popup ย่อ
      • Title + Type Badge
      • Date / Location / Attendees count
      • [ดูรายละเอียด →] ไปหน้า Activity Detail
```

---

### 🎩 DIRECTOR SCREENS (Read-only)

#### SCREEN D1: Dashboard — Director
เหมือน A1 Admin Dashboard แต่:
- ลบ Email Queue Status ออก
- ลบปุ่ม actions ทั้งหมด
- เพิ่ม "ภาพรวมการเข้าร่วมรายแผนก" mini-chart

#### SCREEN D2: Activities List (View Only)
เหมือน A4 แต่:
- ไม่มีปุ่ม [+ เพิ่ม]
- ไม่มี toolbar action ที่แก้ไขได้
- ทุก card click → ดูรายละเอียด (read-only)

#### SCREEN D3: Activity Detail (View Only)
เหมือน A6 แต่:
- ไม่มีปุ่ม [แก้ไข] [ลบ]
- Tab ผู้เข้าร่วม: list อย่างเดียว (no add / no status change)
- Tab เกียรติบัตร: ดู + download อย่างเดียว
- Tab เอกสาร: download อย่างเดียว

#### SCREEN D4: Reports — เหมือน A11

#### SCREEN D5: Calendar — เหมือน A12

> ⚠️ Director **ไม่เห็น** กิจกรรม scope = `personal` ของพนักงาน

---

### 👤 EMPLOYEE SCREENS

#### SCREEN E1: Dashboard — Employee
**Frame:** Employee — Dashboard

```
├── Hero Banner
│     "สวัสดี, นายกอบศักดิ์ ณ นคร"
│     แผนก: งานทรัพยากรสารสนเทศและภาษาต่างประเทศ
│     ตำแหน่ง: นักวิชาการคอมพิวเตอร์
│
├── Stat Cards (3 — Mobile 1×3 หรือ 3 col / Desktop 3 cols)
│     • กิจกรรมที่เข้าร่วม: N
│     • เกียรติบัตรที่ได้รับ: N
│     • กำลังจะมาถึง (7 วัน): N
│
├── Section: กิจกรรมที่กำลังจะมาถึง
│     Activity Cards × 3 (เฉพาะที่ตัวเองลงทะเบียน + กำลังจะมาถึง)
│
├── Section: กิจกรรมที่เปิดให้สมัคร
│     Cards × 3 (กิจกรรมองค์กรที่ is_open_registration = 1
│                  และตัวเองยังไม่ลงทะเบียน)
│     แต่ละ card มีปุ่ม [✓ สมัครเข้าร่วม]
│
└── Section: เกียรติบัตรล่าสุด (3 ใบ) — gold cards
```

---

#### SCREEN E2: My Activities (กิจกรรมที่เข้าร่วม)
**Frame:** Employee — กิจกรรมของฉัน

```
├── Page Header + count
├── Toolbar: search + filter (ประเภท / status / ปีงบประมาณ) + view toggle
└── Activity Cards Grid
      เฉพาะกิจกรรม scope = organization ที่ตัวเองมี registration
      แต่ละ card: รายละเอียด + status badge ของตัวเอง (registered/attended/absent)
```

---

#### SCREEN E3: Personal Activities (กิจกรรมส่วนตัว)
**Frame:** Employee — กิจกรรมส่วนตัว

```
├── Info Banner (อ่อน):
│     "🔒 กิจกรรมส่วนตัวเห็นได้เฉพาะตัวคุณเท่านั้น
│      ไม่มีใครรวมถึงผู้ดูแลระบบเห็น"
│
├── Toolbar
│     • Search + Filter
│     • [+ เพิ่มกิจกรรมส่วนตัว] Primary
│
└── Activity Cards Grid (เหมือน A4 แต่ scope = personal)
      Card มี Personal Badge (purple) เน้นชัด
      Click → Detail แบบเต็มสิทธิ์ (เจ้าของ = แก้ไข/ลบได้)
```

**Modal เพิ่ม/แก้ไข Personal Activity** (เหมือน A5 แต่):
- Scope = personal (read-only, default)
- ไม่มี is_open_registration (มีแค่ตัวเอง)
- ไม่มีฟิลด์ "ผู้เข้าร่วม"
- ภาพ + ไฟล์แนบยังอัปโหลดได้

---

#### SCREEN E4: Available Activities (เปิดรับสมัคร)
**Frame:** Employee — กิจกรรมที่เปิดรับ

```
├── Page Header
├── Filter: ประเภท / ปีงบประมาณ / ช่วงเวลา
└── Activity Cards
      • ทุก card scope = organization และ is_open_registration = 1
      • ที่ตัวเองยังไม่ได้ลงทะเบียน
      • Footer card: ปุ่ม [✓ สมัครเข้าร่วม] (full-width Primary)
      • หลังกด: card เปลี่ยนเป็น disable + Status "ลงทะเบียนแล้ว"
        และย้ายไป My Activities
```

---

#### SCREEN E5: My Certificates
**Frame:** Employee — เกียรติบัตรของฉัน

```
├── Page Header + count "คุณมี N ใบ"
├── Filter: ปีงบประมาณ / ประเภทกิจกรรม
└── Certificate Cards (4.9) — gold theme
      Mobile: 1 col   Tablet: 2 col   Desktop: 3 col
      Click ใบ → preview modal + ปุ่ม [⬇ ดาวน์โหลด]
```

---

#### SCREEN E6: My Reports
**Frame:** Employee — รายงานของฉัน

```
├── Filter Bar: ปีงบประมาณ / ช่วงเวลา (รายเดือน/รายไตรมาส/รายปี)
│
├── Stat Cards (3-4)
│     กิจกรรมเข้าร่วม · ขาด · เกียรติบัตร · ชม. (ถ้าคำนวณได้)
│
├── Chart "กิจกรรมแยกตามประเภท" (สีตาม activity type)
│
├── Chart "กิจกรรมรายเดือน" (line/bar)
│
└── Activity History List
      Date | Title | Type | Status | Cert (ถ้ามี)
```

---

#### SCREEN E7: My Calendar
**Frame:** Employee — ปฏิทินของฉัน

เหมือน A12 แต่ events ที่แสดงประกอบด้วย:
- กิจกรรม organization ที่ตัวเองลงทะเบียน
- กิจกรรม personal ของตัวเอง (เห็นได้เฉพาะตัวเอง)
Filter เพิ่ม: Scope (ทั้งหมด / องค์กร / ส่วนตัว)
Event color = activity type color, ส่วน scope ดูจาก border-style:
- Organization: solid
- Personal: dashed border (ให้แยกชัด)

---

### 📋 OVERLAY MODALS (สำคัญที่ต้องออกแบบ)

#### M1: Add/Edit Activity (Admin/Employee)
ดู A5 / E3

#### M2: Upload Document
```
Header: "อัปโหลดเอกสารใหม่"
- Title input *
- ผูกกับกิจกรรม (search dropdown — optional)
- Drop zone (4.13 file upload)
- Preview ไฟล์ที่เลือก + ขนาด
[ยกเลิก] [อัปโหลด]
```

#### M3: Upload Certificate
ดู A9

#### M4: Add Participant (in Activity Detail)
```
- Search user (multi-select chip)
  Filter: แผนก, role
- รายชื่อที่จะเพิ่ม (chip list)
- Toggle: ส่งอีเมลแจ้งเตือนทันที (default on)
[ยกเลิก] [เพิ่มผู้เข้าร่วม N คน]
```

#### M5: Confirm Delete (generic)
```
[⚠ Icon Red 48px]
"ยืนยันการลบ"
"คุณแน่ใจหรือไม่ว่าต้องการลบ '<ชื่อ>' ?
 การกระทำนี้ไม่สามารถย้อนกลับได้"
[ยกเลิก] [ลบถาวร] (Danger button)
```

#### M6: Email Preview
```
แสดง preview HTML ของ email template
- Subject
- HTML rendered iframe
- Plain text fallback (collapsible)
[ปิด]
```

---

## 6. USER FLOW DIAGRAM

```
[Login] ──→ [must_change_password?]
              │  Yes → [Change Password] ──┐
              │  No                         │
              ▼                             ▼
              [Dashboard] ◄─────────────────┘
                    │
        ┌───────────┼─────────────────────────────┐
        ▼           ▼                             ▼
  [กิจกรรม]   [เกียรติบัตรของฉัน]           [Reports / Calendar]
        │           (Employee)
        │
   ┌────┴────────────┐
   ▼                 ▼
[Activity      [+ Add Activity]
 Detail]         (Admin / Employee personal)
   │
   ├── Tab: ภาพรวม
   ├── Tab: ภาพถ่าย ──→ [Upload Photos × 5]
   ├── Tab: เอกสาร ──→ [Upload Document]
   ├── Tab: ผู้เข้าร่วม ──→ [Add Participant] → email
   └── Tab: เกียรติบัตร ──→ [Upload Certificate] → email

[Admin Settings]
   ├── Manage Users
   ├── Manage Fiscal Year
   └── Notification Settings
```

---

## 7. FIGMA FILE STRUCTURE

```
📁 ActivityDMS — Figma File
│
├── 📄 Cover Page
│     • โลโก้ / ชื่อ / version 1.1 / date
│
├── 📑 Design System
│   ├── 🎨 Colors (System + Activity Type + Role + Status)
│   ├── 🔤 Typography (Mobile + Desktop scales)
│   ├── 📐 Spacing & Grid
│   ├── 🔳 Effects (Shadows / Radius)
│   └── 🧩 Components (Auto Layout + Variants)
│       ├── Buttons (Primary/Secondary/Danger/Success/Gold/Icon)
│       ├── Badges (Type ×4, Scope ×2, Status ×3, Role ×3)
│       ├── Input Fields (Default/Focus/Error/Disabled)
│       ├── Top Navbar (Mobile)
│       ├── Sidebar (Off-canvas Mobile + Fixed Desktop)
│       │   variants: Admin / Director / Employee
│       ├── Stat Card (Mobile / Desktop)
│       ├── Activity Card (4 type variants)
│       ├── File Row
│       ├── Photo Gallery (Edit / View)
│       ├── Certificate Card (Gold)
│       ├── Modal Frame (Mobile fullscreen + Desktop centered)
│       ├── Toast / Alert (4 variants)
│       ├── Empty State (3 variations)
│       ├── Calendar Toolbar
│       └── Pagination
│
├── 📑 User Flow
│   └── Flow Diagram (jpg + frame)
│
├── 📑 Public Screens
│   ├── Login — M / D
│   └── Change Password — M / D
│
├── 📑 Admin Screens
│   ├── A1 Dashboard — M / D
│   ├── A2 Manage Users — M / D + Modal
│   ├── A3 Manage Fiscal Year — M / D + Modal
│   ├── A4 Activities List — M / D
│   ├── A5 Activity Form — M / D
│   ├── A6 Activity Detail (5 tabs) — M / D
│   ├── A7 Attendance Check-in — M / D
│   ├── A8 Manage Documents — M / D
│   ├── A9 Manage Certificates — M / D + Upload Modal
│   ├── A10 Notification Settings — M / D
│   ├── A11 Reports — M / D
│   └── A12 Calendar — M / D
│
├── 📑 Director Screens (read-only)
│   ├── D1 Dashboard — M / D
│   ├── D2 Activities List — M / D
│   ├── D3 Activity Detail — M / D
│   ├── D4 Reports — M / D
│   └── D5 Calendar — M / D
│
├── 📑 Employee Screens
│   ├── E1 Dashboard — M / D
│   ├── E2 My Activities — M / D
│   ├── E3 Personal Activities — M / D + Modal
│   ├── E4 Available Activities — M / D
│   ├── E5 My Certificates — M / D
│   ├── E6 My Reports — M / D
│   └── E7 My Calendar — M / D
│
└── 📑 Modals & Overlays
    ├── M1 Activity Form
    ├── M2 Upload Document
    ├── M3 Upload Certificate
    ├── M4 Add Participant
    ├── M5 Confirm Delete
    └── M6 Email Preview
```

---

## 8. INTERACTION NOTES (สำหรับ Figma Prototype)

| Trigger | Action |
|---|---|
| คลิก ☰ (Mobile) | Toggle off-canvas sidebar (slide from left) |
| คลิก Overlay (off-canvas) | ปิด sidebar |
| คลิก Nav Item | Navigate + ปิด off-canvas (Mobile) |
| คลิก Activity Card | ไป Activity Detail (slide-in transition) |
| คลิก Tab | Switch tab content (fade) |
| คลิก [+ เพิ่ม...] | เปิด Modal (slide-up Mobile / fade Desktop) |
| คลิก [×] หรือ overlay BG | ปิด Modal |
| คลิก [← กลับ] | navigate back (browser history) |
| Hover Card (Desktop) | translateY(-2px) + shadow rise |
| Long-press Card (Mobile) | (optional) แสดง quick action menu |
| Toggle ใน Settings | save inline + toast confirm |
| คลิก 🔔 bell | dropdown แสดง notification 5 รายการล่าสุด |
| Avatar dropdown | profile / change password / logout |

---

## 9. ACCESSIBILITY & MOBILE-FIRST RULES

| Rule | สเปค |
|---|---|
| Touch target | ≥ 44 × 44 px ทุกองค์ประกอบที่กดได้ |
| Input font-size | ≥ 14px (ป้องกัน iOS auto-zoom) |
| Color contrast | ผ่าน WCAG AA (text ≥ 4.5:1, large text ≥ 3:1) |
| Focus state | visible 2-3px outline สี #0EA5E9 |
| Icon-only button | มี `aria-label` หรือ tooltip |
| Form fields | label อยู่นอก input (visible) ทุกช่อง — ไม่ใช้ placeholder แทน label |
| Required indicator | * สีแดง + `aria-required` |
| Error message | สีแดง + ไอคอน + อยู่ใต้ field |
| Modal | trap focus + ESC ปิด + return focus เมื่อปิด |
| Calendar | Keyboard navigable (arrow keys) บน desktop |
| Image | `alt` text ตามบริบท |
| Decorative element | `aria-hidden="true"` |
| Mobile horizontal scroll | ห้ามเกิดยกเว้น `.table-responsive` ที่ตั้งใจ |
| Test devices | iPhone SE (375), iPhone 14 (390), iPad (768), Desktop (1440) |

---

## 10. SAMPLE DATA สำหรับใส่ใน Mockup

ใช้ข้อมูลจริงจาก `STAFF_ARIT.xlsx` + `database.sql` — ไม่สมมติชื่อ

### 👥 Users (ตัวอย่าง 8 จาก 31)

| Role | username | ชื่อ-สกุล | แผนก | ตำแหน่ง |
|---|---|---|---|---|
| Admin | `admin` | ผู้ดูแลระบบ | งาน IT ดิจิทัล | System Administrator |
| Director | `director` | ผู้อำนวยการสำนัก | งานบริหารทั่วไป | ผู้อำนวยการ |
| Employee | `kobsak.na` | นายกอบศักดิ์ ณ นคร | ทรัพยากรสารสนเทศฯ | นักวิชาการคอมพิวเตอร์ |
| Employee | `thanapat.je` | นายธนภัทร เจิมขวัญ | งาน IT ดิจิทัล | นักวิชาการคอมพิวเตอร์ชำนาญการ |
| Employee | `jaruwan.ph` | นางจารุวรรณ เพชรรักษ์ | งานบริหารทั่วไป | จนท.บริหารงานทั่วไปชำนาญการ |
| Employee | `predawan.si` | น.ส.ปรีดาวรรณ สินจรูญศักดิ์ | งานบริหารทั่วไป | บรรณารักษ์ชำนาญการ |
| Employee | `wandee.en` | น.ส.วันดี เอ้งเถี้ยว | ทรัพยากรสารสนเทศฯ | นักวิเทศสัมพันธ์ |
| Employee | `gary.li` | Mr.Gary Linton | ทรัพยากรสารสนเทศฯ | อาจารย์ |

### 📅 Activities (ตัวอย่าง 4)

| # | ชื่อกิจกรรม | ประเภท | Scope | วันที่ | สถานะ |
|---|---|---|---|---|---|
| 1 | ประชุมทบทวนแผนปฏิบัติงานปี 2568 | ประชุม #185FA5 | Organization | 15 มี.ค. 68, 09:00–12:00 | Completed |
| 2 | อบรมการใช้ระบบ ALIST สำหรับบรรณารักษ์ | อบรม #0F6E56 | Organization | 5 เม.ย. 68, 13:00–16:30 | Completed |
| 3 | สัมมนา Open Source for Library 2026 | สัมมนา #993C1D | Organization | 1 พ.ค. 68, 08:30–17:00 | Upcoming |
| 4 | ทำรายงานประจำเดือน — ส่วนตัว | อื่นๆ #5F5E5A | Personal (kobsak.na) | 28 เม.ย. 68 | Upcoming |

### 📁 Sample Files
| ชื่อไฟล์ | ขนาด | ประเภท | กิจกรรม |
|---|---|---|---|
| วาระประชุม-2568-03.pdf | 1.2 MB | PDF | #1 |
| สรุปการประชุม-15มีค68.pdf | 3.4 MB | PDF | #1 |
| งบประมาณ-Q2-2568.xlsx | 2.1 MB | XLSX | #1 |
| คู่มือ-ALIST-เบื้องต้น.pdf | 5.8 MB | PDF | #2 |
| Slide-OpenSource-Library.pptx | 12.3 MB | PPTX | #3 |

### 📷 Sample Photos
- placeholder: ภาพห้องประชุม / ภาพการอบรม / กลุ่มถ่ายรูปร่วมกัน

### 🏆 Sample Certificates
- "เกียรติบัตรการเข้าร่วม ประชุมทบทวนแผนปฏิบัติงานปี 2568" — kobsak.na
- "เกียรติบัตรผู้ผ่านการอบรม ระบบ ALIST" — predawan.si

### 📧 Email Queue (Sample row)
| id | to | subject | status | retry |
|---|---|---|---|---|
| 12 | kobsak.na@skru.ac.th | เพิ่มเข้าร่วมกิจกรรม "ประชุมทบทวน..." | sent | 0 |
| 13 | predawan.si@skru.ac.th | เกียรติบัตรของคุณพร้อมแล้ว | pending | 0 |
| 14 | wandee.en@skru.ac.th | เพิ่มเข้าร่วมกิจกรรม "สัมมนา..." | failed | 2 |

---

## 11. การเปลี่ยนแปลงจาก v1.0 (Changelog)

| ส่วน | v1.0 | v1.1 |
|---|---|---|
| Tech | Implicit React-style | **Pure PHP + Bootstrap 5 + jQuery + FullCalendar** |
| Layout | Desktop-first 1440 | **Mobile-first 375 → 1440** |
| Login | ❌ ไม่มี (SSO) | ✅ Login + Change Password |
| Activity Type | 3 (Meeting/Training/Event) สีต่างชุด | **4 ประเภท สีตรงตาม `database.sql`** |
| Scope | ❌ ไม่มี | ✅ organization vs personal |
| Photo | ❌ ไม่ระบุ | ✅ Max 5 + camera capture |
| Email Notification | ❌ ไม่มี | ✅ 2 trigger + queue + settings |
| Fiscal Year | ❌ ไม่มี | ✅ Admin ตั้งเดือนเริ่ม-จบเอง |
| Calendar | ❌ ไม่มี | ✅ FullCalendar (listMonth บน mobile) |
| Sample Data | สมมติ | **ข้อมูลจริงจาก STAFF_ARIT** |
| Hero Banner | สีเดียวกันทุกที่ | สีตามประเภทกิจกรรม (Activity Detail) |
| Screens | ~12 | **30+ screens × 2 viewport** |

---

*เอกสารนี้แทนไฟล์เดิม `figma-prompt-ActivityDMS-1.md` (v1.0) — Single Source of Truth สำหรับการออกแบบ Figma ของ ActivityDMS v1.1*
