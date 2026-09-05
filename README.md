# ระบบเยี่ยมบ้านนักเรียน · วิทยาลัยอาชีวศึกษาร้อยเอ็ด

เว็บแอปสำหรับบันทึกและติดตามการเยี่ยมบ้านนักเรียนนักศึกษา (8 ขั้นตอนตามแบบฟอร์มราชการ)
พร้อมระบบตรวจสอบ/ลงนามตามลำดับชั้น และเครื่องมือ Migration ฐานข้อมูลสำหรับผู้ดูแล

## ความต้องการของระบบ

| รายการ | ขั้นต่ำ |
|--------|---------|
| PHP | 8.1 (ทดสอบบน 8.2) — ต้องมีส่วนขยาย `pdo_mysql`, `mbstring`, `json`, `openssl`, `fileinfo`, `gd`, `ctype` |
| ฐานข้อมูล | MariaDB 10.4 ขึ้นไป (รองรับ MySQL 8 ด้วย) |
| เว็บเซิร์ฟเวอร์ | Apache/Nginx ที่รองรับ `.htaccess` (ตั้งค่ามาให้แล้วสำหรับ XAMPP) |

## การติดตั้ง

1. วางโปรเจกต์ไว้ใต้ web root แล้วเปิด `install.php` ผ่านเบราว์เซอร์
   - `http://localhost/rvc.safe/install.php`
2. ตัวติดตั้งมี 4 ขั้นตอน
   1. **ตรวจสอบความพร้อม** — เวอร์ชัน PHP, ส่วนขยายที่ต้องการ และสิทธิ์อ่าน/เขียนโฟลเดอร์ `config/`, `storage/`
   2. **ตั้งค่าฐานข้อมูล** — กรอกข้อมูล MariaDB (ระบบสร้างฐานข้อมูลให้อัตโนมัติ), เขียน `config/config.php`, รัน migration
   3. **ผู้ดูแลระบบ** — แบบฟอร์มระบุ username / ชื่อ-นามสกุล / อีเมล / รหัสผ่าน (ยืนยัน 2 ครั้ง) + เลือกเพิ่มข้อมูลตัวอย่าง
   4. **เสร็จสิ้น**
3. หลังติดตั้งเสร็จ แนะนำให้ลบหรือเปลี่ยนชื่อ `install.php`

> ทุกหน้าของแอปจะตรวจ `installation_complete()` (มี config + เชื่อมต่อ DB ได้ + มีบัญชี admin ที่ใช้งานได้)
> หากยังไม่ครบจะ **redirect ไป `install.php` อัตโนมัติ** และข้ามไปยังขั้นตอนที่ค้างอยู่ให้เอง
> (เช่น ตั้งค่า DB แล้วแต่ยังไม่มี admin → เข้าขั้นตอนที่ 3 ทันที)

### รองรับการติดตั้งซ้ำ

เปิด `install.php` ซ้ำเมื่อใดก็ได้ — ระบบจะตรวจพบการติดตั้งเดิม และ

- ปรับปรุงโครงสร้างฐานข้อมูลแบบไม่ทำลายข้อมูล (ใช้ migration ที่เขียนแบบ idempotent)
- คงคีย์ความปลอดภัย (`app.key`) เดิมไว้
- เลือกได้ว่าจะคงบัญชีผู้ดูแลเดิม หรือรีเซ็ตรหัสผ่านใหม่

## เมนู Migration (เฉพาะผู้ดูแล)

`index.php?r=migrations` — แสดงรายการ migration ทั้งหมด สถานะ (apply แล้ว / ค้าง),
เวลาที่ใช้ดำเนินการ, การตรวจจับไฟล์ที่ถูกแก้ไขหลัง apply, ดู SQL ของแต่ละไฟล์
และปุ่มรัน migration ที่ค้างทั้งหมดหรือทีละรายการ

### เพิ่ม migration ใหม่

สร้างไฟล์ `migrations/NNNN_ชื่อ.sql` (เรียงตามชื่อ เช่น `0004_add_guardian_email.sql`)
ใช้คำสั่งที่ปลอดภัยต่อการรันซ้ำ:

```sql
ALTER TABLE students ADD COLUMN IF NOT EXISTS guardian_email VARCHAR(160) NULL;
ALTER TABLE visits   ADD INDEX  IF NOT EXISTS idx_visits_created (created_at);
```

จากนั้นเข้าเมนู Migration แล้วกด "รัน migration ที่ค้าง"
(ตัว Migrator ยังข้ามข้อผิดพลาดประเภท "มีอยู่แล้ว" อัตโนมัติเพื่อความปลอดภัยบน MariaDB รุ่นเก่า)

## เข้าสู่ระบบผ่าน ONE-RVC (SSO)

หน้าล็อกอินมีปุ่ม **"ลงชื่อเข้าใช้ผ่านระบบ ONE-RVC"** ควบคู่กับการล็อกอินด้วยบัญชีในระบบนี้เอง (ไว้ใช้กับบัญชี `admin`)

**Flow**
1. `index.php?r=sso_login` — สุ่ม `state` เก็บใน session แล้ว redirect ไปที่ authorize endpoint ของ ONE-RVC พร้อม `client_id`, `redirect_uri`, `state`
2. ผู้ใช้ล็อกอินที่ ONE-RVC (รหัสผ่าน + OTP ถ้าเปิดใช้) แล้วถูก POST กลับมาที่ **`api/callback.php`** — URL คงที่ตรงกับ `redirect_uri` ที่ลงทะเบียนไว้ ห้ามย้ายไฟล์นี้
3. `api/callback.php` ตรวจ `state` ให้ตรงกับที่เก็บไว้ (กัน CSRF) — ถ้าไม่ได้เก็บ state ไว้เลยและ POST มาไม่มี state ถือเป็น IdP-initiated login ที่ยอมรับได้ (ด่านความปลอดภัยหลักอยู่ที่ข้อ 4)
4. เรียก `Sso::verifyToken()` → POST `token_id`/`token_key` ไปที่ verify endpoint ของ ONE-RVC **จากฝั่งเซิร์ฟเวอร์เท่านั้น** ไม่เชื่อค่าจาก client เด็ดขาด
5. ได้ `{"valid":true}` → `Sso::findOrCreateUser()` จับคู่/สร้างบัญชีในตาราง `users` (ผูกด้วย `sso_user_id`, เชื่อมบัญชีเดิมด้วยอีเมลถ้ามี, บัญชีใหม่เริ่มเป็น `role=teacher` และไม่มีรหัสผ่านที่ใช้ล็อกอินตรงได้ — เข้าได้ทาง SSO เท่านั้น) แล้ว `Auth::loginAs()` สร้าง session ของระบบนี้เอง

**การตั้งค่า** ค่าเริ่มต้นอยู่ใน `config/sso` (ผสานจาก `config/config.sample.php` เข้ากับ `config/config.php` อัตโนมัติ
ไม่ต้องติดตั้งซ้ำ) ส่วน `authorize_endpoint`, `verify_endpoint`, `client_id` ผู้ดูแลปรับได้จากเมนู **"ตั้งค่า SSO"**
ในระบบ (เก็บ override ไว้ใน `settings`) — มีประโยชน์เมื่อเซิร์ฟเวอร์นี้เข้าโดเมนสาธารณะของ ONE-RVC จากฝั่งเซิร์ฟเวอร์
ไม่ได้ (เช่นอยู่เครือข่ายภายในเดียวกัน) ต้องเรียก `verify_endpoint` ด้วย private IP แทน ในขณะที่ `authorize_endpoint`
ต้องเป็นโดเมนสาธารณะเสมอเพราะเบราว์เซอร์ผู้ใช้เป็นคน redirect ไป — ส่วน `redirect_uri` แก้ผ่าน UI ไม่ได้โดยเจตนา
เพราะต้องตรงกับที่ลงทะเบียนไว้เป๊ะทุกตัวอักษร

> ⚠️ **หมายเหตุการ deploy**: เซิร์ฟเวอร์ production นี้ deploy ด้วย `git reset --hard` ทั้ง repo ไปที่โฟลเดอร์
> `/var/www/web` ซึ่งตัวโฟลเดอร์นี้เองถูกเสิร์ฟที่ `https://safe.rvc.ac.th/web/` (ไม่ใช่ที่ root ของโดเมน)
> ดังนั้น path บน URL จริง = `web/` + path สัมพัทธ์จาก root ของ repo เสมอ — ไฟล์ที่ตำแหน่ง repo-relative
> `api/callback.php` จึงไปโผล่ที่ `https://safe.rvc.ac.th/web/api/callback.php` พอดี **ห้ามสร้างโฟลเดอร์ `web/`
> ซ้ำในตัว repo เอง** เพราะจะกลายเป็น `.../web/web/...` ผิดตำแหน่งกับ `redirect_uri` ที่ลงทะเบียนไว้

**เคสที่จัดการ:** ผู้ใช้กด "ไม่อนุญาต" (`GET ?error=...` → กลับหน้าล็อกอินอย่างสุภาพ), `state` ไม่ตรง (`400`), โทเคนหมดอายุ/ไม่ถูกต้อง (`401`), verify endpoint ล่มหรือ timeout (`401`, ไม่ค้างหน้าเว็บเกิน 10 วินาที) — ไม่มีจุดใดเขียน `token_id`/`token_key` ลง log

## โอนข้อมูลจากระบบ RMS (เฉพาะผู้ดูแล)

`index.php?r=rms` — โอนเฉพาะชุดข้อมูลที่งานเยี่ยมบ้านโดยครูที่ปรึกษาต้องใช้

| ชุดข้อมูล RMS | ปลายทาง | กลยุทธ์ |
|---|---|---|
| `people` | `users` | upsert ตาม `people_id`/`username` · ผู้ใช้ใหม่ = `role=teacher` · ไม่ทับ role/รหัสผ่านที่แก้เอง · ผู้ที่ออกแล้ว → `is_active=0` (ไม่ลบ) · ถ้ามี `people_pic` ดาวน์โหลดรูปจาก `{rms_base_url}/files/{people_pic}` มาเก็บที่ `assets/avatars/u{id}.{ext}` ใช้เป็นรูปโปรไฟล์ — ไม่มีรูปยังใช้ชื่อย่อตามเดิม |
| `dateedu` | `semesters` | upsert ตาม `(year, semester)` · ไม่แตะ `is_current` |
| `std2018_studentgroup` | `student_groups` | upsert ตาม `(academic_year, semester, group_code)` — ใช้ map นักเรียน → ครูที่ปรึกษา |
| `std2018_student` | `students` | **แบ่งท่อน 100 + นับก่อน** · upsert ตาม `student_id` (match `code` เดิมได้) · **ไม่ทับ** ที่อยู่/พิกัด/กลุ่มคัดกรองที่กรอกระหว่างเยี่ยม |

- URL ของ RMS เก็บใน `settings.rms_base_url` (แก้ผ่านหน้า UI, validate `^https?://`); path `/api_connection.php` และ `app_name=nutty` กำหนดตายตัวในโค้ด (กัน SSRF)
- ทุก endpoint เข้าผ่าน helper กลาง `Rms::fetch()` เพียงจุดเดียว, คืน JSON `{success, data, message}`, ผ่าน `Auth::requireRole('admin')` + CSRF
- ทุก sync **idempotent** — กดซ้ำได้ (ทดสอบด้วยการกด 2 ครั้งติด), คืนสถิติ `added/updated/skipped/deactivated/fetched` ทุกครั้ง
- ปุ่ม "โหลดทั้งหมดตามลำดับ" เรียงตาม dependency: บุคลากร → ภาคเรียน → กลุ่มเรียน → นักเรียน
- ไม่รวม `stopday` (วันหยุด) และ `studing` (ตารางเรียน) เพราะไม่เกี่ยวกับงานเยี่ยมบ้าน

## บทบาทผู้ใช้

| บทบาท | สิทธิ์ |
|-------|--------|
| `admin` | ทุกเมนู + Migration + สถานะระบบ |
| `teacher` | แดชบอร์ด, รายการเยี่ยมบ้าน, บันทึกการเยี่ยม 8 ขั้นตอน |
| `head` | + ตรวจสอบและรับทราบรายงาน (ขั้นหัวหน้างาน) |
| `exec` | + ลงนามรายงาน (ขั้นผู้บริหาร) |

บัญชีทดสอบ (เมื่อเลือกเพิ่มข้อมูลตัวอย่าง) — รหัสผ่าน `password123`:
`teacher1`, `head1`, `exec1`

> การติดตั้งบนเครื่องนี้ตั้งบัญชีผู้ดูแลไว้เป็น `admin` / `Admin@2569` (เปลี่ยนได้โดยเปิด `install.php` ใหม่)

## โครงสร้างโปรเจกต์

```
install.php              ตัวติดตั้ง (รองรับติดตั้งซ้ำ + ตรวจแพ็กเกจ/สิทธิ์ไฟล์)
index.php                front controller + router (index.php?r=...)
config/
  config.sample.php      แม่แบบการตั้งค่า
  config.php             สร้างโดยตัวติดตั้ง (ไม่ commit)
  steps.php              นิยาม 8 ขั้นตอนของแบบฟอร์มเยี่ยมบ้าน
src/
  Support.php            ตรวจสอบความพร้อมของระบบ
  Database.php           การเชื่อมต่อ PDO
  Migrator.php           ตัวจัดการ migration
  Auth.php               การยืนยันตัวตน + สิทธิ์ตามบทบาท
  Rms.php                โอนข้อมูลจากระบบ RMS (people / dateedu / studentgroup / student)
  Sso.php                เข้าสู่ระบบผ่าน ONE-RVC (authorize URL, verify token, จับคู่/สร้างผู้ใช้)
  bootstrap.php / helpers.php
api/callback.php         redirect_uri ของ ONE-RVC SSO (URL คงที่ ห้ามย้าย — ดูหมายเหตุเรื่อง deploy ด้านล่าง)
migrations/
  0001_core_schema.sql   ตารางหลัก
  0002_visit_indexes.sql ดัชนี
  0003_departments.sql   แผนกวิชา + ค่าตั้งต้น
  0004_guardian_contact.sql   (ตัวอย่าง) ข้อมูลติดต่อผู้ปกครอง
  0005_rms_integration.sql    ตาราง/คอลัมน์สำหรับเชื่อม RMS
  0006_sso_onerdc.sql         คอลัมน์ sso_user_id สำหรับเชื่อม ONE-RVC
views/                   เทมเพลต (layout + หน้าแต่ละหน้า)
assets/app.css           สไตล์ (โทนสีจากแบบต้นฉบับ Claude Design, รองรับ light/dark)
storage/uploads|logs     ต้องเขียนได้
```

## หมายเหตุความปลอดภัย

- โฟลเดอร์ `config/`, `src/`, `migrations/`, `storage/`, `views/` มี `.htaccess` ปฏิเสธการเข้าถึงผ่านเว็บ
- ทุกฟอร์มมีการตรวจ CSRF token
- รหัสผ่านเก็บด้วย `password_hash()` (bcrypt)
- คิวรีทั้งหมดใช้ prepared statement
