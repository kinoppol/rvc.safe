-- 0006 เข้าสู่ระบบผ่าน ONE-RVC (SSO) — เก็บรหัสผู้ใช้ฝั่ง IdP เพื่อจับคู่กับบัญชีในระบบนี้

ALTER TABLE users ADD COLUMN IF NOT EXISTS sso_user_id VARCHAR(40) NULL AFTER people_id;
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uk_users_sso (sso_user_id);
