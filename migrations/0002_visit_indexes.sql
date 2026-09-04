-- 0002 ดัชนีสำหรับการค้นหา/รายงาน
-- ใช้ ALTER TABLE ... ADD INDEX IF NOT EXISTS (รองรับ MariaDB 10.0.2+) ปลอดภัยต่อการรันซ้ำ

ALTER TABLE visits      ADD INDEX IF NOT EXISTS idx_visits_status (status);
ALTER TABLE visits      ADD INDEX IF NOT EXISTS idx_visits_term (term);
ALTER TABLE visits      ADD INDEX IF NOT EXISTS idx_visits_date (visit_date);
ALTER TABLE visits      ADD INDEX IF NOT EXISTS idx_visits_urgent (urgent);
ALTER TABLE students    ADD INDEX IF NOT EXISTS idx_students_dept (department);
ALTER TABLE students    ADD INDEX IF NOT EXISTS idx_students_risk (risk_group);
ALTER TABLE activity_log ADD INDEX IF NOT EXISTS idx_activity_created (created_at);
