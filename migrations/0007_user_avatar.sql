-- 0007 รูปโปรไฟล์ผู้ใช้ที่ดาวน์โหลดมาจาก RMS (people_pic)

ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(255) NULL AFTER email;
