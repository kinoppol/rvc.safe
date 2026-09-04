<?php
declare(strict_types=1);

/**
 * ฟังก์ชันตรวจสอบความพร้อมของระบบ (packages / สิทธิ์ไฟล์)
 * ใช้ร่วมกันโดย install.php และหน้า "ระบบ" ของผู้ดูแล
 */
final class Support
{
    public const MIN_PHP = '8.1.0';
    public const MIN_MARIADB = '10.4.0';

    /** รายการส่วนขยาย PHP ที่ต้องการ */
    public static function requiredExtensions(): array
    {
        return [
            'pdo'        => 'เชื่อมต่อฐานข้อมูล (PDO)',
            'pdo_mysql'  => 'ไดรเวอร์ MariaDB / MySQL',
            'mbstring'   => 'ประมวลผลข้อความภาษาไทย',
            'json'       => 'อ่าน/เขียนข้อมูล JSON',
            'openssl'    => 'สร้างคีย์ความปลอดภัย / แฮชรหัสผ่าน',
            'fileinfo'   => 'ตรวจสอบชนิดไฟล์ที่อัปโหลด',
            'gd'         => 'ประมวลผลรูปภาพประกอบการเยี่ยม',
            'ctype'      => 'ตรวจสอบชนิดข้อมูล',
        ];
    }

    /** ไดเรกทอรีที่ต้องอ่าน/เขียนได้ (relative จาก base path) */
    public static function writablePaths(): array
    {
        return [
            'config',
            'storage',
            'storage/uploads',
            'storage/logs',
            'assets/avatars',
        ];
    }

    public static function basePath(): string
    {
        return dirname(__DIR__);
    }

    /** ตรวจ PHP version */
    public static function checkPhp(): array
    {
        $ok = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        return [
            'label'   => 'PHP เวอร์ชัน',
            'need'    => '>= ' . self::MIN_PHP,
            'current' => PHP_VERSION,
            'ok'      => $ok,
        ];
    }

    /** ตรวจส่วนขยายทั้งหมด */
    public static function checkExtensions(): array
    {
        $rows = [];
        foreach (self::requiredExtensions() as $ext => $why) {
            $loaded = extension_loaded($ext);
            $rows[] = [
                'label'   => 'ส่วนขยาย: ' . $ext,
                'need'    => $why,
                'current' => $loaded ? 'ติดตั้งแล้ว' : 'ไม่พบ',
                'ok'      => $loaded,
            ];
        }
        return $rows;
    }

    /** ตรวจสิทธิ์อ่าน/เขียนไดเรกทอรี พร้อมสร้างให้หากยังไม่มี */
    public static function checkWritable(): array
    {
        $rows = [];
        $base = self::basePath();
        foreach (self::writablePaths() as $rel) {
            $path = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            $readable = is_dir($path) && is_readable($path);
            $writable = is_dir($path) && is_writable($path);
            // ทดสอบเขียนไฟล์จริง
            $probe = $path . DIRECTORY_SEPARATOR . '.probe_' . bin2hex(random_bytes(3));
            $realWrite = @file_put_contents($probe, 'x') !== false;
            if ($realWrite) { @unlink($probe); }
            $rows[] = [
                'label'   => $rel . '/',
                'need'    => 'อ่านและเขียนได้',
                'current' => ($readable ? 'อ่านได้ ' : 'อ่านไม่ได้ ') . ($realWrite ? '· เขียนได้' : '· เขียนไม่ได้'),
                'ok'      => $readable && $writable && $realWrite,
            ];
        }
        return $rows;
    }

    /** ตรวจการเชื่อมต่อฐานข้อมูล + เวอร์ชัน MariaDB */
    public static function checkDatabase(array $db): array
    {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], (int)$db['port'], $db['charset'] ?? 'utf8mb4');
            $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $isMaria = stripos($ver, 'mariadb') !== false;
            $num = preg_match('/(\d+\.\d+\.\d+)/', $ver, $m) ? $m[1] : '0.0.0';
            $ok = $isMaria
                ? version_compare($num, self::MIN_MARIADB, '>=')
                : version_compare($num, '8.0.0', '>='); // ยอมรับ MySQL 8 ด้วย
            return [
                'label'   => 'ฐานข้อมูล',
                'need'    => 'MariaDB >= ' . self::MIN_MARIADB,
                'current' => $ver,
                'ok'      => $ok,
                'connect' => true,
            ];
        } catch (Throwable $e) {
            return [
                'label'   => 'ฐานข้อมูล',
                'need'    => 'MariaDB >= ' . self::MIN_MARIADB,
                'current' => 'เชื่อมต่อไม่ได้: ' . $e->getMessage(),
                'ok'      => false,
                'connect' => false,
            ];
        }
    }

    /** รวมผลตรวจทั้งหมด (ยกเว้น DB ที่ต้องมีค่าคอนฟิก) */
    public static function all(?array $db = null): array
    {
        $checks = array_merge(
            [self::checkPhp()],
            self::checkExtensions(),
            self::checkWritable()
        );
        if ($db) {
            $checks[] = self::checkDatabase($db);
        }
        return $checks;
    }

    public static function allPassed(array $checks): bool
    {
        foreach ($checks as $c) {
            if (empty($c['ok'])) { return false; }
        }
        return true;
    }
}
