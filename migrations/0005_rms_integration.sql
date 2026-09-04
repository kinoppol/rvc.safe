-- 0005 การเชื่อมต่อ/โอนข้อมูลจากระบบ RMS (เฉพาะส่วนที่ใช้กับงานเยี่ยมบ้านโดยครูที่ปรึกษา)
--   people                → users        (ครู/บุคลากร = ผู้ใช้ระบบ)
--   dateedu               → semesters    (ภาคเรียน)
--   std2018_studentgroup  → student_groups (กลุ่มเรียน + ครูที่ปรึกษาประจำกลุ่ม)
--   std2018_student       → students     (นักเรียนที่ต้องเยี่ยม)

-- ผู้ใช้: อ้างอิงรหัสบุคลากรจาก RMS
ALTER TABLE users ADD COLUMN IF NOT EXISTS people_id VARCHAR(30) NULL AFTER username;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_people (people_id);

-- ภาคเรียน
CREATE TABLE IF NOT EXISTS semesters (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    year       SMALLINT UNSIGNED NOT NULL,
    semester   TINYINT UNSIGNED NOT NULL,
    name       VARCHAR(80)  NULL,
    start_date DATE NULL,
    end_date   DATE NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_semester (year, semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- กลุ่มเรียน (ใช้ map นักเรียน → ครูที่ปรึกษา)
CREATE TABLE IF NOT EXISTS student_groups (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    academic_year  SMALLINT UNSIGNED NOT NULL,
    semester       TINYINT UNSIGNED NOT NULL,
    group_code     VARCHAR(50)  NOT NULL,
    grade          VARCHAR(80)  NULL,
    group_name     VARCHAR(120) NULL,
    group_abbr     VARCHAR(120) NULL,
    teacher_idcard VARCHAR(20)  NULL,
    teacher_name   VARCHAR(160) NULL,
    classroom_id   VARCHAR(50)  NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_group (academic_year, semester, group_code),
    KEY idx_group_code (group_code),
    KEY idx_group_teacher (teacher_idcard)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- นักเรียน: เพิ่มฟิลด์อ้างอิง RMS (คงฟิลด์ที่กรอกระหว่างเยี่ยมบ้านไว้ไม่แตะต้อง)
ALTER TABLE students ADD COLUMN IF NOT EXISTS student_id    VARCHAR(30)  NULL AFTER id;
ALTER TABLE students ADD COLUMN IF NOT EXISTS student_code  VARCHAR(30)  NULL AFTER code;
ALTER TABLE students ADD COLUMN IF NOT EXISTS idcard        VARCHAR(20)  NULL AFTER student_code;
ALTER TABLE students ADD COLUMN IF NOT EXISTS email         VARCHAR(160) NULL AFTER phone;
ALTER TABLE students ADD COLUMN IF NOT EXISTS group_code    VARCHAR(50)  NULL AFTER room;
ALTER TABLE students ADD COLUMN IF NOT EXISTS group_name    VARCHAR(120) NULL AFTER group_code;
ALTER TABLE students ADD COLUMN IF NOT EXISTS status_name   VARCHAR(120) NULL AFTER risk_group;
ALTER TABLE students ADD COLUMN IF NOT EXISTS gpax          DECIMAL(4,2) NULL AFTER status_name;
ALTER TABLE students ADD COLUMN IF NOT EXISTS rms_synced_at DATETIME     NULL;
ALTER TABLE students ADD UNIQUE INDEX IF NOT EXISTS uk_students_sid (student_id);
ALTER TABLE students ADD INDEX IF NOT EXISTS idx_students_group (group_code);

-- ค่าตั้งต้น: URL ของ RMS (แก้ได้จากหน้า "โอนข้อมูลจาก RMS")
INSERT IGNORE INTO settings (k, v) VALUES ('rms_base_url', 'http://rms.rvc.ac.th');
