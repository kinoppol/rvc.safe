<?php
declare(strict_types=1);

final class Auth
{
    private static ?PDO $pdo = null;

    public const ROLES = [
        'admin'   => 'ผู้ดูแลระบบ',
        'teacher' => 'ครูที่ปรึกษา',
        'head'    => 'หัวหน้างานครูที่ปรึกษาและการแนะแนว',
        'exec'    => 'ผู้บริหาร',
    ];

    public static function init(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function attempt(string $username, string $password): bool
    {
        $st = self::$pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
        $st->execute([$username]);
        $u = $st->fetch();
        if (!$u || !password_verify($password, $u['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        unset($_SESSION['impersonator']);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['role'] = $u['role'];
        $_SESSION['name'] = $u['full_name'];
        self::$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
        return true;
    }

    /** ตั้ง session จากแถวผู้ใช้ที่ยืนยันตัวตนแล้วโดยไม่ต้องใช้รหัสผ่าน (เช่น ผ่าน SSO) */
    public static function loginAs(array $u): void
    {
        session_regenerate_id(true);
        unset($_SESSION['impersonator']);
        $_SESSION['uid']  = (int)$u['id'];
        $_SESSION['role'] = $u['role'];
        $_SESSION['name'] = $u['full_name'];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    /** ผู้ดูแลสวมสิทธิ์เป็นผู้ใช้อื่น — เก็บตัวตนเดิมไว้ใน session เพื่อคืนสิทธิ์ตอนออกจากระบบ */
    public static function impersonate(int $targetUserId): bool
    {
        if (self::isImpersonating()) {
            return false; // ต้องกลับเป็นผู้ดูแลก่อนจึงจะสวมสิทธิ์คนใหม่ได้
        }
        $st = self::$pdo->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$targetUserId]);
        $u = $st->fetch();
        if (!$u) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['impersonator'] = [
            'uid'  => $_SESSION['uid'],
            'role' => $_SESSION['role'],
            'name' => $_SESSION['name'],
        ];
        $_SESSION['uid']  = (int)$u['id'];
        $_SESSION['role'] = $u['role'];
        $_SESSION['name'] = $u['full_name'];
        return true;
    }

    public static function isImpersonating(): bool
    {
        return !empty($_SESSION['impersonator']);
    }

    public static function impersonatorName(): ?string
    {
        return $_SESSION['impersonator']['name'] ?? null;
    }

    /** คืนสิทธิ์เดิม (ผู้ดูแล) — เรียกแทน logout เมื่อกำลังสวมสิทธิ์อยู่ */
    public static function stopImpersonating(): bool
    {
        if (!self::isImpersonating()) {
            return false;
        }
        $orig = $_SESSION['impersonator'];
        session_regenerate_id(true);
        $_SESSION['uid']  = $orig['uid'];
        $_SESSION['role'] = $orig['role'];
        $_SESSION['name'] = $orig['name'];
        unset($_SESSION['impersonator']);
        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    public static function user(): ?array
    {
        if (!self::check()) { return null; }
        static $cache = [];
        $uid = (int)$_SESSION['uid'];
        if (!array_key_exists($uid, $cache)) {
            $st = self::$pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            $cache[$uid] = $st->fetch() ?: null;
        }
        return $cache[$uid];
    }

    public static function role(): ?string
    {
        return $_SESSION['role'] ?? null;
    }

    public static function is(string ...$roles): bool
    {
        return in_array(self::role(), $roles, true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: index.php?r=login');
            exit;
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!self::is(...$roles)) {
            http_response_code(403);
            echo '<div style="font-family:sans-serif;padding:40px">ไม่มีสิทธิ์เข้าถึงส่วนนี้</div>';
            exit;
        }
    }
}
