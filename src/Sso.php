<?php
declare(strict_types=1);

/**
 * เข้าสู่ระบบผ่าน ONE-RVC (SSO ภายนอก)
 *
 *   1. redirect ผู้ใช้ไป authorize_endpoint พร้อม client_id/redirect_uri/state
 *   2. ONE-RVC ให้ผู้ใช้ล็อกอิน (รหัสผ่าน + OTP) แล้ว POST token_id/token_key/state กลับมาที่ redirect_uri
 *   3. เว็บฝั่งนี้ verify token_id/token_key กับ verify_endpoint (ฝั่งเซิร์ฟเวอร์เท่านั้น) ก่อนเชื่อถือ
 *      โดยยืนยันตัวแอปด้วย client_id + client_secret (ใช้ secret ชุดเดียวกับ User-sync — ดู OaSync::config())
 *
 * ⚠️ ห้าม trust token_id/token_key จากฝั่ง client และห้ามเขียน token / client_secret ลง log ใด ๆ
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
        return !empty($c['authorize_endpoint']) && !empty($c['client_id']) && !empty($c['redirect_uri'])
            && OaSync::config()['client_secret'] !== ''; // verify_token ต้องใช้ client_secret
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

        // ONE-RVC บังคับให้แอปยืนยันตัวเองด้วย client_id + client_secret ก่อน verify token (ไม่งั้นตอบ invalid_client)
        // ส่งทั้ง HTTP Basic และใน POST body — เผื่อเว็บเซิร์ฟเวอร์ฝั่ง ONE-RVC ตัด Authorization header ทิ้ง
        $clientId = (string)($c['client_id'] ?? '');
        $secret   = OaSync::config()['client_secret'];
        if ($clientId === '' || $secret === '') {
            throw new RuntimeException('ยังไม่ได้ตั้งค่า Client secret ของ RVC-ONE — ผู้ดูแลระบบต้องกรอกที่หน้า "ตั้งค่า SSO" แท็บ Client secret');
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'token_id' => $tokenId, 'token_key' => $tokenKey,
                'client_id' => $clientId, 'client_secret' => $secret,
            ]),
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $clientId . ':' . $secret,
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
            $error = (string)($data['error'] ?? '');
            throw new RuntimeException(match ($error) {
                'invalid_client'                 => 'ระบบ RVC-ONE ไม่ยอมรับ Client ID / Client secret ของแอปนี้ (ค่าไม่ถูกต้องหรือแอปถูกปิดใช้งาน) — แจ้งผู้ดูแลระบบให้ตรวจสอบที่หน้า "ตั้งค่า SSO"',
                'token not issued to this client' => 'โทเคนนี้ไม่ได้ออกให้แอปนี้ (Client ID ไม่ตรงกับที่ใช้ตอนลงชื่อเข้าใช้)',
                ''                               => 'โทเคนไม่ถูกต้องหรือหมดอายุ',
                default                          => $error,
            });
        }
        return $data;
    }

    /**
     * เลขบัตรประชาชน 13 หลัก (national_id) เป็นข้อมูลบังคับสำหรับเข้าสู่ระบบนี้ผ่าน ONE-RVC —
     * ปฏิเสธถ้าไม่มี ส่วนการจับคู่/สร้างบัญชีใช้ resolveUser() ร่วมกับการซิงก์รายชื่อผู้ใช้ (OaSync)
     * บัญชีที่จับคู่ได้จะถูกเปิดใช้งาน (ผู้ใช้ที่ถูกระงับฝั่ง ONE-RVC ล็อกอินที่ ONE-RVC ไม่ได้อยู่แล้ว)
     */
    public static function findOrCreateUser(PDO $pdo, array $ssoUser): array
    {
        if (self::nationalId($ssoUser) === '') {
            throw new RuntimeException(
                'บัญชี ONE-RVC นี้ไม่มีเลขบัตรประชาชน 13 หลักส่งมาให้ระบบนี้ ซึ่งจำเป็นสำหรับเข้าสู่ระบบ ' .
                'กรุณาติดต่อผู้ดูแล ONE-RVC ให้เปิดสิทธิ์ส่งข้อมูลเลขบัตรประชาชน (national_id) ให้แอปนี้'
            );
        }
        [$u] = self::resolveUser($pdo, $ssoUser, true);
        if (empty($u['is_active'])) {
            $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$u['id']]);
            $u['is_active'] = 1;
        }
        return $u;
    }

    /**
     * จับคู่ผู้ใช้ ONE-RVC (id จาก verify_token = id จาก User-sync API) กับบัญชีในระบบนี้ แล้วอัปเดตชื่อ/แผนก/อีเมล
     *   1. users.people_id = เลขบัตรประชาชน (ค่าเดียวกับที่ RMS sync/แอดมินตั้งไว้) — ตัวเทียบหลัก กันบัญชีซ้ำ
     *   2. users.sso_user_id = id ของ ONE-RVC — กรณีไม่ได้รับสิทธิ์ national_id หรือบัญชีที่ผูกไว้แล้ว
     *   3. ไม่เจอ และ $allowCreate และมีเลขบัตร → สร้างบัญชีใหม่ role=teacher (ไม่มีรหัสผ่านที่ใช้ได้จริง — เข้าทาง SSO เท่านั้น)
     * ไม่แตะ role / username / is_active ของบัญชีที่มีอยู่แล้ว (ผู้เรียกจัดการสถานะเอง)
     * sso_user_id เป็น unique — ถ้า id นี้ไปผูกกับแถวอื่นอยู่ (เช่น บัญชีซ้ำ onerdc_ จากบั๊กเดิม) จะย้ายมาผูกกับแถวที่จับคู่ได้
     *
     * @return array{0: ?array, 1: string} [แถว users หรือ null, 'matched'|'created'|'skipped']
     */
    public static function resolveUser(PDO $pdo, array $oaUser, bool $allowCreate): array
    {
        $oaId = trim((string)($oaUser['id'] ?? ''));
        if ($oaId === '') {
            throw new RuntimeException('ข้อมูลผู้ใช้จาก ONE-RVC ไม่สมบูรณ์ (ไม่มี id)');
        }
        $nationalId = self::nationalId($oaUser);
        $name  = trim(trim((string)($oaUser['first_name'] ?? '')) . ' ' . trim((string)($oaUser['last_name'] ?? '')));
        $dept  = self::nz($oaUser['department'] ?? null);
        $email = self::nz($oaUser['email'] ?? null);
        $uname = self::nz($oaUser['username'] ?? null);

        $u = false;
        if ($nationalId !== '') {
            $st = $pdo->prepare('SELECT * FROM users WHERE people_id = ? ORDER BY id LIMIT 1');
            $st->execute([$nationalId]);
            $u = $st->fetch();
        }
        if (!$u) {
            $st = $pdo->prepare('SELECT * FROM users WHERE sso_user_id = ? LIMIT 1');
            $st->execute([$oaId]);
            $u = $st->fetch();
        }

        if ($u) {
            if ((string)$u['sso_user_id'] !== $oaId) {
                $pdo->prepare('UPDATE users SET sso_user_id = NULL WHERE sso_user_id = ? AND id <> ?')->execute([$oaId, $u['id']]);
            }
            $pdo->prepare(
                'UPDATE users SET sso_user_id = ?, people_id = COALESCE(people_id, ?), full_name = ?,
                    department = COALESCE(?, department), email = COALESCE(?, email) WHERE id = ?'
            )->execute([$oaId, $nationalId !== '' ? $nationalId : null, $name !== '' ? $name : $u['full_name'], $dept, $email, $u['id']]);
            return [self::findById($pdo, (int)$u['id']), 'matched'];
        }

        if (!$allowCreate || $nationalId === '') {
            return [null, 'skipped'];
        }

        $base = $uname ?: $nationalId;
        $candidate = $base;
        $i = 1;
        $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        do {
            $chk->execute([$candidate]);
            if ((int)$chk->fetchColumn() === 0) { break; }
            $candidate = $base . '_' . $i++;
        } while (true);

        $lockedPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $pdo->prepare(
            'INSERT INTO users (username, people_id, sso_user_id, password_hash, full_name, email, department, role, is_active, created_at)
             VALUES (?,?,?,?,?,?,?,\'teacher\',1,NOW())'
        )->execute([$candidate, $nationalId, $oaId, $lockedPassword, $name !== '' ? $name : $candidate, $email, $dept]);

        return [self::findById($pdo, (int)$pdo->lastInsertId()), 'created'];
    }

    /** URL เริ่ม SSO ของแอปนี้ (route sso_login) — ไว้ให้ผู้ดูแล ONE-RVC กรอกในช่อง "URL เริ่ม SSO ของแอป" */
    public static function startUrl(): string
    {
        $redirect = (string)(self::config()['redirect_uri'] ?? '');
        return preg_replace('#api/callback\.php$#', 'index.php?r=sso_login', $redirect) ?? '';
    }

    private static function nationalId(array $oaUser): string
    {
        $nid = preg_replace('/\D/', '', (string)($oaUser['national_id'] ?? ''));
        return strlen($nid) === 13 ? $nid : '';
    }

    private static function findById(PDO $pdo, int $id): array
    {
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
