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

function render(string $name, array $vars = [], string $title = ''): void
{
    $__title = $title;
    $__view  = $name;
    // ตั้งใจใช้ชื่อพารามิเตอร์ $vars ไม่ใช่ $data — กัน extract(EXTR_SKIP) ข้ามคีย์ 'data'
    // เมื่อ view ถูกเรียกด้วย compact(...,'data',...) เช่นเดียวกับ ?r=visit (8 ขั้นตอน)
    extract($vars, EXTR_SKIP);
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

/** ชนิดไฟล์ที่รับได้: MIME (ตรวจจริงด้วย finfo ไม่ใช่นามสกุลที่ผู้ใช้ตั้ง) => นามสกุลที่ใช้บันทึก */
const RVC_IMAGE_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const RVC_DOC_MIMES = RVC_IMAGE_MIMES + ['application/pdf' => 'pdf'];

/**
 * บันทึกไฟล์แนบของรายการเยี่ยมบ้านหนึ่งไฟล์ลง storage/uploads/visits/{visitId}/ และบันทึกแถวใน visit_photos
 *   - $label === null: ภาพ 3 ประเภทตายตัว (kind='home'/'family'/'teacher') — ไฟล์ใหม่แทนที่ไฟล์เดิมของ kind เดียวกัน
 *   - $label !== null: เอกสารแนบทั่วไป (kind='doc') — แนบเพิ่มได้หลายไฟล์ ไม่ทับของเดิม
 * คืนข้อความ error หรือ null เมื่อสำเร็จ (หรือไม่มีไฟล์ถูกเลือกเลย — ไม่ถือเป็น error)
 */
function save_visit_upload(PDO $pdo, int $visitId, string $kind, array $file, array $allowedMimes, int $maxBytes, ?string $label = null): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) { return null; }
    if ($file['error'] !== UPLOAD_ERR_OK) { return 'อัปโหลดไฟล์ไม่สำเร็จ'; }
    if (!is_uploaded_file($file['tmp_name'])) { return 'อัปโหลดไฟล์ไม่สำเร็จ'; }
    if ($file['size'] > $maxBytes) { return 'ไฟล์ "' . ($label ?? $kind) . '" มีขนาดใหญ่เกินกำหนด'; }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowedMimes[$mime])) { return 'ไฟล์ "' . ($label ?? $kind) . '" เป็นชนิดที่ไม่รองรับ'; }
    $ext = $allowedMimes[$mime];

    $dir = BASE_PATH . '/storage/uploads/visits/' . $visitId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return 'สร้างที่จัดเก็บไฟล์ไม่สำเร็จ'; }

    if ($label === null) {
        foreach (glob($dir . "/{$kind}.*") ?: [] as $stale) { @unlink($stale); }
        $filename = "{$kind}.{$ext}";
    } else {
        $filename = $kind . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    }
    $rel = "storage/uploads/visits/{$visitId}/{$filename}";
    if (!move_uploaded_file($file['tmp_name'], BASE_PATH . '/' . $rel)) { return 'บันทึกไฟล์ไม่สำเร็จ'; }

    if ($label === null) {
        $pdo->prepare('DELETE FROM visit_photos WHERE visit_id = ? AND kind = ?')->execute([$visitId, $kind]);
    }
    $pdo->prepare('INSERT INTO visit_photos (visit_id, kind, label, path, uploaded_at) VALUES (?,?,?,?,NOW())')
        ->execute([$visitId, $kind, $label, $rel]);
    return null;
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

/**
 * ครูที่ปรึกษาเยี่ยมบ้านได้เฉพาะนักเรียนในกลุ่มที่ตนเองเป็นที่ปรึกษาเท่านั้น
 * คืน array id นักเรียนที่อยู่ในความดูแล (อาจว่างเปล่า) หรือ null เมื่อไม่จำกัด (head/exec/admin — เห็นภาพรวมทั้งหมด
 * จึงครอบคลุมกรณีหัวหน้างานแนะแนวที่รับหน้าที่เป็นครูที่ปรึกษาเองด้วย)
 *
 * จับคู่ด้วย "เลขบัตรประชาชน 13 หลัก" เท่านั้น (users.people_id === student_groups.teacher_idcard)
 * ไม่ใช้ชื่อเป็นตัวเชื่อม เพราะชื่อใน users อาจมีคำนำหน้า/ยศที่ไม่ตรงกับ student_groups.teacher_name เป๊ะ ๆ
 * ถ้าบัญชียังไม่ได้ตั้งเลขบัตรประชาชน (ผู้ดูแลตั้งค่าได้ที่หน้า "ผู้ใช้ระบบ") จะยังไม่เห็นนักเรียนคนใดเลย
 */
function teacher_scope_ids(PDO $pdo): ?array
{
    $u = Auth::user();
    if (!$u || $u['role'] !== 'teacher') { return null; }
    if (empty($u['people_id'])) { return []; }

    $st = $pdo->prepare(
        'SELECT DISTINCT s.id FROM students s
         JOIN student_groups sg ON sg.group_code = s.group_code
         WHERE sg.teacher_idcard = ?'
    );
    $st->execute([$u['people_id']]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * สร้างเงื่อนไข SQL "$col IN (...)" จาก scope ids ของ teacher_scope_ids()
 * คืน [sql, args] — sql เป็น '' เมื่อไม่จำกัด (null), เป็นเงื่อนไขที่ไม่ตรงกับแถวใดเลยเมื่อครูไม่มีนักเรียนในดูแล ([])
 */
function scope_where(string $col, ?array $ids): array
{
    if ($ids === null) { return ['', []]; }
    if (!$ids) { return ["$col = -1", []]; }
    return ["$col IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
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

/** ไอคอนโปรไฟล์: ใช้รูปที่ดาวน์โหลดจาก RMS ถ้ามี ไม่งั้น fallback เป็นชื่อย่อ */
function avatar_html(array $u): string
{
    $path = $u['avatar_path'] ?? null;
    if ($path && is_file(BASE_PATH . '/' . $path)) {
        return '<img src="' . e($path) . '" alt="" class="avatar-img">';
    }
    return e(th_initial($u['full_name'] ?? '?'));
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
