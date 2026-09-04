-- 0009 รองรับเอกสารแนบทั่วไป (ไม่ใช่แค่ภาพ 3 ประเภทตายตัว) ในรายการเยี่ยมบ้าน
-- label เก็บชื่อไฟล์ที่อ่านได้ (ใช้กับเอกสารแนบเพิ่มเติมที่แนบได้หลายไฟล์); ภาพ 3 ประเภทตายตัวยังไม่ต้องใช้ label

ALTER TABLE visit_photos ADD COLUMN IF NOT EXISTS label VARCHAR(160) NULL AFTER kind;
