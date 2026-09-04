-- 0001 โครงสร้างหลักของระบบเยี่ยมบ้านนักเรียน
-- ปลอดภัยต่อการติดตั้งซ้ำ: ใช้ CREATE TABLE IF NOT EXISTS ทั้งหมด

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(160) NOT NULL,
    email         VARCHAR(160) NULL,
    role          ENUM('admin','teacher','head','exec') NOT NULL DEFAULT 'teacher',
    department    VARCHAR(120) NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(20)  NOT NULL UNIQUE,
    prefix       VARCHAR(20)  NULL,
    full_name    VARCHAR(160) NOT NULL,
    level        VARCHAR(40)  NULL,
    department   VARCHAR(120) NULL,
    room         VARCHAR(40)  NULL,
    advisor_name VARCHAR(160) NULL,
    address      VARCHAR(400) NULL,
    lat          DECIMAL(10,7) NULL,
    lng          DECIMAL(10,7) NULL,
    phone        VARCHAR(40)  NULL,
    risk_group   ENUM('กลุ่มปกติ','กลุ่มเสี่ยง','กลุ่มมีปัญหา') NOT NULL DEFAULT 'กลุ่มปกติ',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visits (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id   INT UNSIGNED NOT NULL,
    term         VARCHAR(20)  NULL,
    round        VARCHAR(40)  NULL,
    visit_date   DATE         NULL,
    visit_time   TIME         NULL,
    method       VARCHAR(120) NULL,
    advisor      VARCHAR(160) NULL,
    status       ENUM('ฉบับร่าง','รอเยี่ยม','บันทึกแล้ว','รอตรวจสอบ','ผ่านหัวหน้างาน','ลงนามแล้ว','เกินกำหนด') NOT NULL DEFAULT 'ฉบับร่าง',
    screen_result ENUM('กลุ่มปกติ','กลุ่มเสี่ยง','กลุ่มมีปัญหา') NULL,
    urgent       TINYINT(1)   NOT NULL DEFAULT 0,
    help_amount  DECIMAL(10,2) NULL,
    summary      TEXT         NULL,
    data_json    LONGTEXT     NULL,
    lat          DECIMAL(10,7) NULL,
    lng          DECIMAL(10,7) NULL,
    current_step TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_visits_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_photos (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visit_id    INT UNSIGNED NOT NULL,
    kind        VARCHAR(40)  NOT NULL,
    path        VARCHAR(300) NOT NULL,
    uploaded_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_photos_visit FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visit_approvals (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    visit_id   INT UNSIGNED NOT NULL,
    actor_id   INT UNSIGNED NULL,
    action     VARCHAR(40)  NOT NULL,
    note       VARCHAR(500) NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_appr_visit FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    text       VARCHAR(500) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(80) NOT NULL PRIMARY KEY,
    v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
