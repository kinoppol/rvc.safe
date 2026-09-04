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
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['role'] = $u['role'];
        $_SESSION['name'] = $u['full_name'];
        self::$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['uid']);
    }

    public static function user(): ?array
    {
        if (!self::check()) { return null; }
        static $cache = null;
        if ($cache === null) {
            $st = self::$pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $st->execute([$_SESSION['uid']]);
            $cache = $st->fetch() ?: null;
        }
        return $cache;
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
