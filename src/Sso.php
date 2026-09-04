<?php
declare(strict_types=1);

/**
 * เข้าสู่ระบบผ่าน ONE-RVC (SSO ภายนอก)
 *
 *   1. redirect ผู้ใช้ไป authorize_endpoint พร้อม client_id/redirect_uri/state
 *   2. ONE-RVC ให้ผู้ใช้ล็อกอิน (รหัสผ่าน + OTP) แล้ว POST token_id/token_key/state กลับมาที่ redirect_uri
 *   3. เว็บฝั่งนี้ verify token_id/token_key กับ verify_endpoint (ฝั่งเซิร์ฟเวอร์เท่านั้น) ก่อนเชื่อถือ
 *
 * ⚠️ ห้าม trust token_id/token_key จากฝั่ง client และห้ามเขียนค่าทั้งสองนี้ลง log ใด ๆ
 */
final class Sso
{
    /**
     * ค่าตั้งต้นมาจาก config/config.sample.php แต่ผู้ดูแลปรับ endpoint ได้จากหน้า "ตั้งค่า SSO"
     * (เก็บใน settings) — จำเป็นเพราะบางเครือข่ายเข้าโดเมนสาธารณะจากฝั่งเซิร์ฟเวอร์ไม่ได้
     * (เช่น เซิร์ฟเวอร์ ONE-RVC อยู่ bridge เดียวกัน ต้องเรียกผ่าน private IP แทน) ในขณะที่
     * authorize_endpoint ต้องเป็นโดเมนสาธารณะเสมอเพราะเป็น URL ที่เบราว์เซอร์ผู้ใช้ redirect ไป
     * redirect_uri ไม่ให้แก้ผ่าน UI เพราะต้องตรงกับที่ลงทะเบียนไว้เป๊ะ ๆ เท่านั้น
     */
    public static function config(): array
    {
        $cfg = app_config()['sso'] ?? [];
        $override = [
            'authorize_endpoint' => get_setting('sso_authorize_endpoint'),
            'verify_endpoint'    => get_setting('sso_verify_endpoint'),
            'client_id'          => get_setting('sso_client_id'),
        ];
        foreach ($override as $k => $v) {
            if ($v !== null && $v !== '') { $cfg[$k] = $v; }
        }
        return $cfg;
    }

    public static function enabled(): bool
    {
        $c = self::config();
        return !empty($c['authorize_endpoint']) && !empty($c['client_id']) && !empty($c['redirect_uri']);
    }

    /** สร้าง URL สำหรับเริ่ม flow ที่ authorize_endpoint ของ ONE-RVC */
    public static function authorizeUrl(string $state): string
    {
        $c = self::config();
        $qs = http_build_query([
            'client_id'    => $c['client_id'],
            'redirect_uri' => $c['redirect_uri'],
            'state'        => $state,
        ]);
        $base = $c['authorize_endpoint'];
        return $base . (str_contains($base, '?') ? '&' : '?') . $qs;
    }

    /**
     * ตรวจสอบ token_id/token_key กับ ONE-RVC (ฝั่งเซิร์ฟเวอร์เท่านั้น)
     * โยน RuntimeException เมื่อ invalid/expired หรือเรียก API ไม่สำเร็จ (network/timeout/รูปแบบผิด)
     */
    public static function verifyToken(string $tokenId, string $tokenKey): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('เซิร์ฟเวอร์นี้ไม่มีส่วนขยาย PHP curl ซึ่งจำเป็นสำหรับการยืนยันตัวตนผ่าน ONE-RVC');
        }
        $c = self::config();
        $endpoint = $c['verify_endpoint'] ?? '';
        if ($endpoint === '') {
            throw new RuntimeException('ยังไม่ได้ตั้งค่าระบบยืนยันตัวตน ONE-RVC');
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['token_id' => $tokenId, 'token_key' => $tokenKey]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $errno) {
            // ไม่ระบุรายละเอียด token ใด ๆ ใน error/log
            throw new RuntimeException('เชื่อมต่อระบบยืนยันตัวตน ONE-RVC ไม่สำเร็จ' . ($err !== '' ? " ({$err})" : ''));
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('ข้อมูลตอบกลับจากระบบยืนยันตัวตนไม่ถูกต้อง');
        }
        if (empty($data['valid'])) {
            throw new RuntimeException((string)($data['error'] ?? 'โทเคนไม่ถูกต้องหรือหมดอายุ'));
        }
        return $data;
    }

    /**
     * หาบัญชีในระบบนี้ที่ผูกกับผู้ใช้ ONE-RVC นี้อยู่แล้ว, เชื่อมกับบัญชีเดิมด้วยอีเมล, หรือสร้างใหม่
     * ไม่แตะ role ของบัญชีที่มีอยู่แล้ว — บัญชีใหม่เริ่มเป็น 'teacher' เสมอ (ผู้ดูแลปรับสิทธิ์ภายหลังได้)
     */
    public static function findOrCreateUser(PDO $pdo, array $ssoUser): array
    {
        $ssoId = trim((string)($ssoUser['id'] ?? ''));
        if ($ssoId === '') {
            throw new RuntimeException('ข้อมูลผู้ใช้จาก ONE-RVC ไม่สมบูรณ์ (ไม่มี id)');
        }

        $name  = trim(trim((string)($ssoUser['first_name'] ?? '')) . ' ' . trim((string)($ssoUser['last_name'] ?? '')));
        $dept  = self::nz($ssoUser['department'] ?? null);
        $email = self::nz($ssoUser['email'] ?? null);
        $uname = self::nz($ssoUser['username'] ?? null);

        // 1) เคยล็อกอินผ่าน ONE-RVC มาก่อน
        $st = $pdo->prepare('SELECT * FROM users WHERE sso_user_id = ? LIMIT 1');
        $st->execute([$ssoId]);
        if ($u = $st->fetch()) {
            $pdo->prepare(
                'UPDATE users SET full_name = ?, department = COALESCE(?, department), email = COALESCE(?, email) WHERE id = ?'
            )->execute([$name !== '' ? $name : $u['full_name'], $dept, $email, $u['id']]);
            $u['full_name'] = $name !== '' ? $name : $u['full_name'];
            return $u;
        }

        // 2) เชื่อมกับบัญชีที่เคยสร้างด้วยมือ (ยังไม่เคยผูก SSO) ถ้าอีเมลตรงกัน
        if ($email) {
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND sso_user_id IS NULL LIMIT 1');
            $st->execute([$email]);
            if ($u = $st->fetch()) {
                $pdo->prepare(
                    'UPDATE users SET sso_user_id = ?, full_name = ?, department = COALESCE(?, department), is_active = 1 WHERE id = ?'
                )->execute([$ssoId, $name !== '' ? $name : $u['full_name'], $dept, $u['id']]);
                $u['sso_user_id'] = $ssoId;
                return $u;
            }
        }

        // 3) สร้างบัญชีใหม่ — ไม่มีรหัสผ่านที่ใช้ล็อกอินได้จริง (เข้าได้ทาง SSO เท่านั้น)
        $base = $uname ?: ('onerdc_' . $ssoId);
        $candidate = $base;
        $i = 1;
        $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        do {
            $chk->execute([$candidate]);
            if ((int)$chk->fetchColumn() === 0) { break; }
            $candidate = $base . '_' . $i++;
        } while (true);

        $lockedPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $ins = $pdo->prepare(
            'INSERT INTO users (username, sso_user_id, password_hash, full_name, email, department, role, is_active, created_at)
             VALUES (?,?,?,?,?,?,\'teacher\',1,NOW())'
        );
        $ins->execute([$candidate, $ssoId, $lockedPassword, $name !== '' ? $name : $candidate, $email, $dept]);

        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    private static function nz($v): ?string
    {
        $v = trim((string)$v);
        return $v !== '' ? $v : null;
    }
}
