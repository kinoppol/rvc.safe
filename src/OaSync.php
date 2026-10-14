<?php
declare(strict_types=1);

/**
 * ซิงก์รายชื่อผู้ใช้ (ครู/บุคลากร) จาก RVC-ONE ผ่าน User-sync API แบบ server-to-server
 *
 *   1. อ่าน since ของรอบก่อน (settings.oa_sync_since) — ไม่มี/สั่ง full = ดึงทั้งหมด
 *   2. GET {endpoint}?type=&since=&limit= → ถ้า has_more เรียกต่อด้วย cursor=next_cursor จนหมด
 *   3. ทุกหน้า upsert ด้วย id ของ RVC-ONE (users.sso_user_id — id เดียวกับ user.id จาก verify_token ของ SSO)
 *      status=suspended → ปิดใช้งานบัญชี (oa_status='suspended'), กลับมา active → เปิดคืนเฉพาะที่ถูกปิดเพราะระงับ
 *   4. ครบทุกหน้าแล้วเท่านั้นจึงบันทึก server_time ของรอบนี้เป็น since รอบถัดไป — ล้มกลางทางไม่ขยับ since
 *
 * ยืนยันตัวตนด้วย HTTP Basic (client_id:client_secret) — ⚠️ ห้ามเขียน client_secret ลง log/ข้อความ error ใด ๆ
 * รันจาก cron ด้วย bin/oa-sync.php หรือปุ่มในหน้า "ตั้งค่า SSO"
 */
final class OaSync
{
    private const LOCK_NAME   = 'rvcsafe_oa_user_sync';
    private const MAX_RETRIES = 3;
    private const MAX_PAGES   = 10000;

    public static function config(): array
    {
        $cfg = app_config()['user_sync'] ?? [];
        $endpoint = get_setting('oa_sync_endpoint');
        if ($endpoint !== null && $endpoint !== '') { $cfg['endpoint'] = $endpoint; }

        $secret = (string)(getenv('OA_CLIENT_SECRET') ?: '');
        if ($secret === '') { $secret = (string)($cfg['client_secret'] ?? ''); }
        if ($secret === '') { $secret = (string)get_setting('oa_client_secret', ''); }

        return [
            'endpoint'      => (string)($cfg['endpoint'] ?? ''),
            'client_id'     => (string)(Sso::config()['client_id'] ?? ''),
            'client_secret' => $secret,
            'type'          => (string)($cfg['type'] ?? 'staff'),
            'limit'         => max(1, min(1000, (int)($cfg['limit'] ?? 500))),
        ];
    }

    /** แหล่งที่มาของ client_secret ปัจจุบัน (ไว้แสดงในหน้าตั้งค่า — ไม่คืนค่าจริง) */
    public static function secretSource(): ?string
    {
        if ((string)(getenv('OA_CLIENT_SECRET') ?: '') !== '') { return 'env OA_CLIENT_SECRET'; }
        if ((string)(app_config()['user_sync']['client_secret'] ?? '') !== '') { return 'config/config.php'; }
        if ((string)get_setting('oa_client_secret', '') !== '') { return 'หน้าตั้งค่า (settings)'; }
        return null;
    }

    public static function configured(): bool
    {
        $c = self::config();
        return $c['endpoint'] !== '' && $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    /**
     * รันซิงก์หนึ่งรอบ — $full = true ไม่ส่ง since (ดึงทั้งหมดใหม่) แต่ upsert แบบเดียวกัน (idempotent)
     * @param callable|null $out รับข้อความความคืบหน้า (เช่น พิมพ์ออก CLI)
     */
    public static function run(PDO $pdo, bool $full = false, ?callable $out = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('เซิร์ฟเวอร์นี้ไม่มีส่วนขยาย PHP curl ซึ่งจำเป็นสำหรับการซิงก์ผู้ใช้จาก RVC-ONE');
        }
        if (!self::configured()) {
            throw new RuntimeException('ยังไม่ได้ตั้งค่า client_secret / endpoint สำหรับซิงก์ผู้ใช้จาก RVC-ONE');
        }
        $got = (int)$pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 0)")->fetchColumn();
        if ($got !== 1) {
            throw new RuntimeException('มีการซิงก์ผู้ใช้จาก RVC-ONE กำลังทำงานอยู่แล้ว');
        }
        @set_time_limit(0);

        $c = self::config();
        $since = $full ? null : (get_setting('oa_sync_since') ?: null);
        $stats = ['mode' => $since === null ? 'full' : 'incremental', 'since' => $since, 'pages' => 0, 'fetched' => 0,
                  'created' => 0, 'updated' => 0, 'suspended' => 0, 'reactivated' => 0, 'skipped' => 0];
        $say = $out ?? static fn(string $m) => null;

        try {
            $say("เริ่มซิงก์แบบ {$stats['mode']}" . ($since ? " ตั้งแต่ {$since}" : ''));
            $restarted = false;
            $cursor = null;
            $serverTime = null;

            while (true) {
                if ($stats['pages'] >= self::MAX_PAGES) {
                    throw new RuntimeException('จำนวนหน้าเกินขีดจำกัด — หยุดเพื่อความปลอดภัย');
                }
                $params = $cursor !== null
                    ? ['cursor' => $cursor, 'limit' => $c['limit']]
                    : array_filter(['type' => $c['type'], 'since' => $since, 'limit' => $c['limit']], static fn($v) => $v !== null && $v !== '');

                [$status, $data] = self::request($c, $params);

                if ($status === 400 && ($data['error'] ?? '') === 'bad_cursor' && !$restarted) {
                    // cursor ใช้ไม่ได้แล้ว → เริ่มรอบใหม่จากหน้าแรก (since เดิม) ครั้งเดียว — upsert ซ้ำได้ไม่มีผลเสีย
                    $say('cursor หมดอายุ เริ่มรอบใหม่จากหน้าแรก');
                    $restarted = true;
                    $cursor = null;
                    $serverTime = null;
                    continue;
                }
                if ($status !== 200 || empty($data['ok'])) {
                    $code = (string)($data['error'] ?? "http_{$status}");
                    $msg  = (string)($data['message'] ?? '');
                    throw new RuntimeException(self::describeError($code, $status) . ($msg !== '' ? " ({$msg})" : ''));
                }

                $serverTime ??= (string)($data['server_time'] ?? '');
                $users = is_array($data['users'] ?? null) ? $data['users'] : [];
                $stats['pages']++;
                $stats['fetched'] += count($users);

                $pdo->beginTransaction();
                try {
                    foreach ($users as $u) {
                        if (is_array($u)) { $stats[self::upsert($pdo, $u)]++; }
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                $say("หน้า {$stats['pages']}: " . count($users) . ' รายการ');

                if (empty($data['has_more'])) { break; }
                $cursor = (string)($data['next_cursor'] ?? '');
                if ($cursor === '') {
                    throw new RuntimeException('API แจ้งว่ามีหน้าถัดไปแต่ไม่ส่ง next_cursor มา');
                }
            }

            // ครบทุกหน้าแล้วเท่านั้น จึงขยับ since เป็นเวลาของเซิร์ฟเวอร์ RVC-ONE (ไม่ใช้นาฬิกาเครื่องนี้)
            if ($serverTime !== '') {
                set_setting('oa_sync_since', $serverTime);
            }
            $stats['server_time'] = $serverTime;
            set_setting('oa_sync_last_at', date('Y-m-d H:i:s'));
            set_setting('oa_sync_last_result', (string)json_encode($stats, JSON_UNESCAPED_UNICODE));
            set_setting('oa_sync_last_error', '');
            self::log('OK ' . json_encode($stats, JSON_UNESCAPED_UNICODE));
            return $stats;
        } catch (Throwable $e) {
            set_setting('oa_sync_last_error', date('Y-m-d H:i:s') . ' ' . $e->getMessage());
            self::log('FAIL ' . $e->getMessage() . ' ' . json_encode($stats, JSON_UNESCAPED_UNICODE));
            throw $e;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    /** upsert ผู้ใช้หนึ่งรายการ — คืนชื่อตัวนับใน $stats */
    private static function upsert(PDO $pdo, array $oa): string
    {
        $type = (string)($oa['user_type'] ?? '');
        if ($type === 'student') {
            return 'skipped'; // ผู้ใช้ระบบนี้คือครู/บุคลากร นักเรียนมาจาก RMS
        }
        $suspended = ($oa['status'] ?? 'active') === 'suspended';

        // ไม่สร้างบัญชีใหม่ให้ผู้ที่ถูกระงับ
        [$u, $action] = Sso::resolveUser($pdo, $oa, !$suspended);
        if ($u === null) {
            return 'skipped';
        }

        $result = $action === 'created' ? 'created' : 'updated';
        if ($suspended) {
            // ไม่ปิดบัญชีผู้ดูแลระบบ กันล็อกตัวเองออกจากระบบ
            if (!empty($u['is_active']) && $u['role'] !== 'admin') {
                $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$u['id']]);
                $result = 'suspended';
            }
            $pdo->prepare("UPDATE users SET oa_status = 'suspended', oa_synced_at = NOW() WHERE id = ?")->execute([$u['id']]);
        } else {
            // เปิดคืนเฉพาะบัญชีที่ถูกปิดเพราะระงับฝั่ง RVC-ONE — ไม่ทับการปิดใช้งานที่ผู้ดูแลระบบนี้ทำเอง
            if (empty($u['is_active']) && ($u['oa_status'] ?? null) === 'suspended') {
                $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$u['id']]);
                $result = 'reactivated';
            }
            $pdo->prepare("UPDATE users SET oa_status = 'active', oa_synced_at = NOW() WHERE id = ?")->execute([$u['id']]);
        }
        return $result;
    }

    /**
     * เรียก API หนึ่งครั้ง (retry สูงสุด MAX_RETRIES เมื่อเครือข่ายล้ม/timeout/5xx) — คืน [http status, json array]
     * secret ส่งทาง HTTP Basic เท่านั้น ไม่อยู่ใน query string
     */
    private static function request(array $c, array $params): array
    {
        $url = $c['endpoint'] . (str_contains($c['endpoint'], '?') ? '&' : '?') . http_build_query($params);
        $lastErr = '';
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
                CURLOPT_USERPWD        => $c['client_id'] . ':' . $c['client_secret'],
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw    = curl_exec($ch);
            $errno  = curl_errno($ch);
            $err    = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($raw !== false && !$errno && $status > 0 && $status < 500) {
                $data = json_decode((string)$raw, true);
                if (!is_array($data)) {
                    throw new RuntimeException("ข้อมูลตอบกลับจาก RVC-ONE ไม่ใช่ JSON (HTTP {$status})");
                }
                return [$status, $data];
            }
            $lastErr = $errno ? $err : "HTTP {$status}";
            if ($attempt < self::MAX_RETRIES) {
                sleep(2 ** ($attempt - 1)); // 1s, 2s
            }
        }
        throw new RuntimeException('เชื่อมต่อ User-sync API ของ RVC-ONE ไม่สำเร็จ (' . $lastErr . ')');
    }

    private static function describeError(string $code, int $status): string
    {
        return match ($code) {
            'invalid_client'   => 'client_id / client_secret ไม่ถูกต้อง หรือแอปถูกปิดใช้งานที่ RVC-ONE',
            'sync_disabled'    => 'ผู้ดูแล RVC-ONE ยังไม่ได้เปิดสิทธิ์ "API ซิงค์ข้อมูลผู้ใช้" ให้แอปนี้',
            'type_not_allowed' => 'แอปนี้ไม่ได้รับอนุญาตให้ดึงผู้ใช้ประเภทที่ขอ',
            'bad_type', 'bad_since', 'bad_cursor' => "พารามิเตอร์ไม่ถูกต้อง ({$code})",
            default            => "User-sync API ตอบกลับผิดพลาด ({$code}, HTTP {$status})",
        };
    }

    private static function log(string $line): void
    {
        @file_put_contents(BASE_PATH . '/storage/logs/oa-sync.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
    }
}
