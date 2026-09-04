<?php
declare(strict_types=1);

/**
 * จุดเริ่มต้นร่วมของทุกหน้า (ยกเว้น install.php ก่อนติดตั้งเสร็จ)
 */

error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Bangkok');

define('BASE_PATH', dirname(__DIR__));
define('CONFIG_FILE', BASE_PATH . '/config/config.php');

require BASE_PATH . '/src/Support.php';
require BASE_PATH . '/src/Database.php';
require BASE_PATH . '/src/Migrator.php';
require BASE_PATH . '/src/Auth.php';
require BASE_PATH . '/src/helpers.php';
require BASE_PATH . '/src/Rms.php';
require BASE_PATH . '/src/Sso.php';

function app_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        if (!is_file(CONFIG_FILE)) {
            header('Location: install.php');
            exit;
        }
        $installed = require CONFIG_FILE;
        $sample    = require BASE_PATH . '/config/config.sample.php';
        // เติมค่าตั้งต้นที่ขาด (เช่น ฟีเจอร์ใหม่อย่าง sso) ให้ config เดิม โดยไม่ทับค่าที่ตั้งไว้แล้ว
        $cfg = array_replace_recursive($sample, $installed);
    }
    return $cfg;
}

/**
 * ตรวจว่าติดตั้งระบบครบถ้วนหรือยัง
 *  - มีไฟล์ config
 *  - เชื่อมต่อฐานข้อมูลได้ และมีตารางหลัก
 *  - มีบัญชีผู้ดูแล (admin) ที่ใช้งานได้อย่างน้อย 1 คน
 */
function installation_complete(): bool
{
    if (!is_file(CONFIG_FILE)) {
        return false;
    }
    try {
        $cfg = require CONFIG_FILE;
        $pdo = Database::connect($cfg['db']);
        $n = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        return (int)$n > 0;
    } catch (Throwable) {
        return false;
    }
}

function boot_app(): array
{
    $cfg = app_config();
    ini_set('display_errors', ($cfg['app']['env'] ?? 'production') === 'development' ? '1' : '0');

    // บันทึก error ลงไฟล์เสมอ (ไม่ขึ้นกับ display_errors) เพื่อวินิจฉัยปัญหาบน production ได้
    // โดยไม่เปิดเผยรายละเอียดให้ผู้ใช้เห็น — อ่านได้จาก storage/logs/php-error.log
    ini_set('log_errors', '1');
    ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');

    $pdo = Database::connect($cfg['db']);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('RVCSAFE');
        session_start();
    }
    Auth::init($pdo);
    return [$cfg, $pdo];
}
