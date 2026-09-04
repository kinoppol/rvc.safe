-- 0004 (ตัวอย่าง) เพิ่มช่องข้อมูลติดต่อผู้ปกครอง — ยังไม่ถูก apply ตอนติดตั้ง
-- ทดลองกด "รัน migration ที่ค้าง" ในเมนู Migration เพื่อ apply

ALTER TABLE students ADD COLUMN IF NOT EXISTS guardian_name  VARCHAR(160) NULL AFTER phone;
ALTER TABLE students ADD COLUMN IF NOT EXISTS guardian_phone VARCHAR(40)  NULL AFTER guardian_name;
ALTER TABLE students ADD COLUMN IF NOT EXISTS guardian_line  VARCHAR(80)  NULL AFTER guardian_phone;
