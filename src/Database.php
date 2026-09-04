<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(array $db): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'], (int)$db['port'], $db['name'], $db['charset'] ?? 'utf8mb4'
        );
        self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return self::$pdo;
    }

    /** เชื่อมต่อไปที่เซิร์ฟเวอร์โดยไม่เลือกฐานข้อมูล (ใช้ตอนติดตั้งเพื่อ CREATE DATABASE) */
    public static function server(array $db): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], (int)$db['port'], $db['charset'] ?? 'utf8mb4');
        return new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            throw new RuntimeException('ยังไม่ได้เชื่อมต่อฐานข้อมูล');
        }
        return self::$pdo;
    }
}
