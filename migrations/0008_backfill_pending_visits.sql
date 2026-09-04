-- 0008 กำหนดสถานะเริ่มต้น "รอเยี่ยม" ให้นักเรียนที่มีอยู่แล้วในระบบแต่ยังไม่เคยมีรายการเยี่ยมบ้านเลย
-- (ก่อนหน้านี้การโอนข้อมูลจาก RMS สร้างแค่ระเบียนนักเรียน ไม่ได้สร้างรายการเยี่ยมบ้านตั้งต้นให้)
-- ปลอดภัยต่อการรันซ้ำ: เติมเฉพาะนักเรียนที่ "ยังไม่มีรายการเยี่ยมบ้านรายการใดเลย" เท่านั้น

INSERT INTO visits (student_id, term, round, status, advisor, current_step, created_at)
SELECT s.id,
       COALESCE((SELECT v FROM settings WHERE k = 'current_term'), '1/2569'),
       'ครั้งที่ 1',
       'รอเยี่ยม',
       s.advisor_name,
       1,
       NOW()
FROM students s
WHERE NOT EXISTS (SELECT 1 FROM visits v WHERE v.student_id = s.id);
