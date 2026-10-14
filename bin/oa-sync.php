<?php
declare(strict_types=1);

/**
 * ซิงก์รายชื่อผู้ใช้จาก RVC-ONE (สำหรับ cron) — ดู OaSync
 *
 *   php bin/oa-sync.php          ซิงก์เฉพาะที่เปลี่ยนตั้งแต่รอบก่อน (incremental)
 *   php bin/oa-sync.php --full   ดึงทั้งหมดใหม่
 */
// ตัวอย่าง crontab (ทุก 30 นาที):
//   */30 * * * * php /var/www/web/bin/oa-sync.php > /dev/null 2>&1

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

if (!installation_complete()) {
    fwrite(STDERR, "ระบบยังไม่ได้ติดตั้ง\n");
    exit(1);
}

$cfg = app_config();
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
$pdo = Database::connect($cfg['db']);

$full = in_array('--full', $argv, true);
try {
    $r = OaSync::run($pdo, $full, static fn(string $m) => fwrite(STDOUT, $m . "\n"));
    activity_log($pdo, "ซิงก์ผู้ใช้จาก RVC-ONE ({$r['mode']}): เพิ่ม {$r['created']} อัปเดต {$r['updated']} ระงับ {$r['suspended']} เปิดคืน {$r['reactivated']} ข้าม {$r['skipped']}");
    fwrite(STDOUT, "เสร็จ: เพิ่ม {$r['created']} อัปเดต {$r['updated']} ระงับ {$r['suspended']} เปิดคืน {$r['reactivated']} ข้าม {$r['skipped']} (since ถัดไป {$r['server_time']})\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ซิงก์ไม่สำเร็จ: ' . $e->getMessage() . "\n");
    exit(1);
}
