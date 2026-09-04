<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function old(string $key, $default = ''): string
{
    return e((string)($_SESSION['_old'][$key] ?? $default));
}

function flash(?string $msg = null, string $type = 'ok'): array
{
    if ($msg !== null) {
        $_SESSION['_flash'] = ['msg' => $msg, 'type' => $type];
        return [];
    }
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $f ? [$f] : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('คำขอไม่ถูกต้อง (CSRF token หมดอายุ) กรุณาลองใหม่');
    }
}

function view(string $name, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $file = BASE_PATH . '/views/' . $name . '.php';
    require $file;
}

function render(string $name, array $data = [], string $title = ''): void
{
    $__title = $title;
    $__view  = $name;
    extract($data, EXTR_SKIP);
    require BASE_PATH . '/views/layout.php';
}

function get_setting(string $key, ?string $default = null): ?string
{
    try {
        $st = Database::pdo()->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable) {
        return $default;
    }
}

function set_setting(string $key, string $value): void
{
    Database::pdo()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$key, $value]);
}

function json_ok(array $data = [], string $message = ''): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'data' => null, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function activity_log(PDO $pdo, string $text, ?int $uid = null): void
{
    try {
        $pdo->prepare('INSERT INTO activity_log (user_id, text, created_at) VALUES (?,?,NOW())')
            ->execute([$uid ?? ($_SESSION['uid'] ?? null), $text]);
    } catch (Throwable) {
        // เงียบไว้ ไม่ให้ล้มทั้งคำขอ
    }
}

function status_pill(string $s): string
{
    $m = [
        'ฉบับร่าง' => 'muted', 'รอเยี่ยม' => 'warn', 'บันทึกแล้ว' => 'ok',
        'เกินกำหนด' => 'danger', 'รอตรวจสอบ' => 'primary',
        'ผ่านหัวหน้างาน' => 'primary', 'ลงนามแล้ว' => 'ok',
    ];
    return $m[$s] ?? 'muted';
}

function risk_pill(string $r): string
{
    return ['กลุ่มปกติ' => 'ok', 'กลุ่มเสี่ยง' => 'warn', 'กลุ่มมีปัญหา' => 'danger'][$r] ?? 'muted';
}

function th_initial(string $name): string
{
    return mb_substr(preg_replace('/^(นางสาว|นาย|นาง|เด็กชาย|เด็กหญิง)/u', '', $name), 0, 1) ?: '?';
}

function maps_url($lat, $lng): string
{
    return 'https://www.google.com/maps/dir/?api=1&destination=' . urlencode((string)$lat) . ',' . urlencode((string)$lng) . '&travelmode=driving';
}

/** โหลดนิยาม 8 ขั้นตอนของแบบฟอร์มเยี่ยมบ้าน */
function visit_steps(): array
{
    static $s = null;
    if ($s === null) {
        $s = require BASE_PATH . '/config/steps.php';
    }
    return $s;
}
