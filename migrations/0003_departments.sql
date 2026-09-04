-- 0003 ตารางแผนกวิชา และค่าตั้งต้นของระบบ

CREATE TABLE IF NOT EXISTS departments (
    id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name   VARCHAR(120) NOT NULL UNIQUE,
    sort   INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO departments (name, sort) VALUES
    ('ช่างยนต์', 10),
    ('ช่างไฟฟ้ากำลัง', 20),
    ('ช่างกลโรงงาน', 30),
    ('การบัญชี', 40),
    ('การตลาด', 50),
    ('คอมพิวเตอร์ธุรกิจ', 60);

INSERT IGNORE INTO settings (k, v) VALUES
    ('school_name', 'วิทยาลัยอาชีวศึกษาร้อยเอ็ด'),
    ('current_term', '1/2569'),
    ('photos_required', '3');
