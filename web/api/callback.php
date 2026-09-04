<?php
declare(strict_types=1);

/**
 * Callback ของ ONE-RVC SSO — URL นี้ต้องตรงกับ redirect_uri ที่ลงทะเบียนไว้เป๊ะ ๆ:
 *   https://safe.rvc.ac.th/web/api/callback.php
 * ห้ามย้ายไฟล์นี้ หรือครอบด้วย router อื่น
 *
 * ⚠️ ห้ามเขียน token_id / token_key ลง log หรือแสดงในข้อความ error ใด ๆ
 */

require dirname(__DIR__, 2) . '/src/bootstrap.php';

// เส้นทางกลับสู่แอป (relative จากไฟล์นี้ที่ web/api/callback.php → รากโปรเจกต์)
const APP_ROOT = '../../';

function sso_deny(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<div style="font-family:system-ui,sans-serif;max-width:480px;margin:15vh auto;padding:0 20px;text-align:center;color:#334">'
       . '<h2 style="margin-bottom:8px">เข้าสู่ระบบไม่สำเร็จ</h2>'
       . '<p style="color:#667">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p><a href="' . APP_ROOT . 'index.php?r=login">กลับไปหน้าเข้าสู่ระบบ</a></p></div>';
    exit;
}

if (!installation_complete()) {
    sso_deny(503, 'ระบบยังไม่ได้ติดตั้ง');
}

[$cfg, $pdo] = boot_app();

// ผู้ใช้กดไม่อนุญาตที่หน้า ONE-RVC — จบ flow อย่างสุภาพ กลับไปหน้าเข้าสู่ระบบ
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['error'])) {
        unset($_SESSION['sso_state'], $_SESSION['sso_state_at']);
        flash('ยกเลิกการเข้าสู่ระบบผ่าน ONE-RVC', 'warn');
        header('Location: ' . APP_ROOT . 'index.php?r=login');
        exit;
    }
    sso_deny(405, 'ต้องเข้าถึงด้วยวิธี POST เท่านั้น');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sso_deny(405, 'ต้องเข้าถึงด้วยวิธี POST เท่านั้น');
}

$tokenId  = (string)($_POST['token_id'] ?? '');
$tokenKey = (string)($_POST['token_key'] ?? '');
$state    = (string)($_POST['state'] ?? '');

$expectedState = $_SESSION['sso_state'] ?? null;
unset($_SESSION['sso_state'], $_SESSION['sso_state_at']);

if ($expectedState !== null) {
    // flow เริ่มจากแอปนี้ — state ต้องตรงกันเป๊ะ กัน CSRF
    if ($state === '' || !hash_equals((string)$expectedState, $state)) {
        sso_deny(400, 'state ไม่ถูกต้อง (คำขออาจถูกดักหรือหมดเวลา) กรุณาลองเข้าสู่ระบบใหม่');
    }
} elseif ($state !== '') {
    // ไม่มี state ที่แอปนี้เก็บไว้ แต่มี state ส่งมา — ไม่ใช่ flow ที่แอปนี้เริ่มและไม่ใช่ IdP-initiated (ไม่ควรเกิดขึ้น) → ปฏิเสธ
    sso_deny(400, 'state ไม่ถูกต้อง');
}
// state ว่างและไม่มี state ที่เก็บไว้ = ผู้ใช้เริ่ม flow จากฝั่ง ONE-RVC เอง (IdP-initiated) — อนุญาตให้ผ่านขั้นนี้
// ด่านความปลอดภัยหลักคือการ verify token_id/token_key กับเซิร์ฟเวอร์ ONE-RVC ในขั้นถัดไป

if ($tokenId === '' || $tokenKey === '') {
    sso_deny(400, 'ไม่พบข้อมูลยืนยันตัวตนจาก ONE-RVC');
}

try {
    $result = Sso::verifyToken($tokenId, $tokenKey);
} catch (Throwable $e) {
    sso_deny(401, $e->getMessage());
}

$ssoUser = $result['user'] ?? null;
if (!is_array($ssoUser)) {
    sso_deny(401, 'ไม่พบข้อมูลผู้ใช้จากระบบ ONE-RVC');
}

try {
    $localUser = Sso::findOrCreateUser($pdo, $ssoUser);
} catch (Throwable $e) {
    sso_deny(401, 'ไม่สามารถจับคู่บัญชีผู้ใช้ในระบบนี้ได้: ' . $e->getMessage());
}

if (!$localUser || empty($localUser['is_active'])) {
    sso_deny(401, 'บัญชีผู้ใช้นี้ถูกปิดใช้งานในระบบ');
}

Auth::loginAs($localUser);
$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$localUser['id']]);
activity_log($pdo, 'เข้าสู่ระบบผ่าน ONE-RVC SSO', (int)$localUser['id']);

header('Location: ' . APP_ROOT . 'index.php?r=dashboard');
