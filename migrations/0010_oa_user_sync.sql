-- 0010 ซิงก์รายชื่อผู้ใช้จาก RVC-ONE (User-sync API) — ผูกด้วย users.sso_user_id (= id ของ RVC-ONE)
--   oa_status: สถานะล่าสุดจาก RVC-ONE ('active' | 'suspended') — ใช้แยกบัญชีที่ถูกปิดเพราะระงับฝั่ง RVC-ONE
--              ออกจากบัญชีที่ผู้ดูแลระบบนี้ปิดเอง (เปิดคืนอัตโนมัติเฉพาะกรณีแรก)

ALTER TABLE users ADD COLUMN IF NOT EXISTS oa_status VARCHAR(12) NULL AFTER sso_user_id;
ALTER TABLE users ADD COLUMN IF NOT EXISTS oa_synced_at DATETIME NULL AFTER oa_status;
