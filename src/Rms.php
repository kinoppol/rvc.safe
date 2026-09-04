<?php
declare(strict_types=1);

/**
 * การโอนข้อมูลจากระบบ RMS (อาชีวศึกษา) — เฉพาะส่วนที่ใช้กับงานเยี่ยมบ้านโดยครูที่ปรึกษา
 *
 *   host  → เก็บใน settings.rms_base_url (ผู้ดูแลแก้ได้)
 *   path  → hardcode /api_connection.php (กัน SSRF)
 *   app_name → 'nutty' (คงที่ ฝั่ง RMS)
 *
 * ชุดข้อมูลที่เกี่ยวข้อง:
 *   people               → users
 *   dateedu              → semesters
 *   std2018_studentgroup → student_groups
 *   std2018_student      → students   (แบ่งท่อน + count)
 *
 * กฎ: ทุก sync ต้อง idempotent — กดซ้ำได้โดยข้อมูลไม่ซ้ำ และห้ามทับค่าที่มนุษย์กรอก
 *     (role, password_hash, is_current, ที่อยู่/พิกัด/กลุ่มคัดกรอง ของนักเรียน)
 */
final class Rms
{
    private const APP_NAME = 'nutty';
    private const PATH     = '/api_connection.php';

    public static function baseUrl(): string
    {
        return rtrim((string)get_setting('rms_base_url', ''), '/');
    }

    /** ดึง JSON array จาก RMS — $query เช่น 'data=std2018_student&count=yes' */
    public static function fetch(string $query): array
    {
        $base = self::baseUrl();
        if ($base === '') {
            json_err('ยังไม่ได้ตั้งค่า URL ของระบบ RMS');
        }
        if (!preg_match('#^https?://#i', $base)) {
            json_err('URL ของระบบ RMS ไม่ถูกต้อง');
        }
        $url = $base . self::PATH . '?app_name=' . self::APP_NAME . '&' . ltrim($query, '&');

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $raw = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                json_err('เชื่อมต่อ RMS ไม่สำเร็จ: ' . $err);
            }
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 60], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                json_err('เชื่อมต่อ RMS ไม่สำเร็จ');
            }
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            json_err('ข้อมูลจาก RMS ไม่อยู่ในรูปแบบ JSON ที่ถูกต้อง');
        }
        return $data;
    }

    private static function nz($v): ?string
    {
        $v = trim((string)$v);
        return $v !== '' && $v !== '0000-00-00' ? $v : null;
    }

    private static function prefixFromGender(string $g): string
    {
        $g = trim($g);
        if ($g === '') { return ''; }
        if (preg_match('/(ญ|หญิง|female|^\s*f\s*$|^\s*2\s*$)/iu', $g)) { return 'นางสาว'; }
        if (preg_match('/(ช|ชาย|male|^\s*m\s*$|^\s*1\s*$)/iu', $g)) { return 'นาย'; }
        return '';
    }

    /* ---------------- people → users ---------------- */
    public static function syncPeople(PDO $pdo): array
    {
        $rows = self::fetch('data=people');
        $created = 0; $updated = 0; $seen = []; $avatars = 0;

        $find = $pdo->prepare('SELECT id FROM users WHERE people_id = ? OR username = ? LIMIT 1');
        $ins  = $pdo->prepare(
            'INSERT INTO users (username, people_id, password_hash, full_name, email, role, is_active, created_at)
             VALUES (?,?,?,?,?,\'teacher\',1,NOW())'
        );
        $upd  = $pdo->prepare(
            'UPDATE users SET people_id = ?, full_name = ?, email = COALESCE(?, email), is_active = 1 WHERE id = ?'
        );

        foreach ($rows as $p) {
            if (trim((string)($p['people_exit'] ?? '')) !== '0') { continue; } // เฉพาะผู้ที่ยังทำงาน
            $pid = trim((string)($p['people_id'] ?? ''));
            if ($pid === '') { continue; }
            $name = trim(trim((string)($p['people_name'] ?? '')) . ' ' . trim((string)($p['people_surname'] ?? '')));
            $email = self::nz($p['people_email'] ?? '');
            $seen[] = $pid;

            $find->execute([$pid, $pid]);
            $id = $find->fetchColumn();
            if ($id) {
                $upd->execute([$pid, $name, $email, $id]);
                $updated++;
            } else {
                $pass = trim((string)($p['ath_pass'] ?? '')) ?: $pid;
                $ins->execute([$pid, $pid, password_hash($pass, PASSWORD_DEFAULT), $name, $email]);
                $id = (int)$pdo->lastInsertId();
                $created++;
            }

            // รูปโปรไฟล์: {rms_base_url}/files/{people_pic} — ผู้ใช้ที่ไม่มีรูปยังคงใช้ชื่อย่อตามเดิม
            $pic = trim((string)($p['people_pic'] ?? ''));
            if ($pic !== '' && $id) {
                $path = self::downloadAvatar($pic, (int)$id);
                if ($path !== null) {
                    $pdo->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')->execute([$path, $id]);
                    $avatars++;
                }
            }
        }

        // soft delete: ผู้ที่หายจากต้นทาง (ยกเว้น admin และผู้ใช้ที่สร้างมือ ไม่มี people_id)
        $deactivated = 0;
        if ($seen) {
            $ph = implode(',', array_fill(0, count($seen), '?'));
            $st = $pdo->prepare(
                "UPDATE users SET is_active = 0
                 WHERE people_id IS NOT NULL AND role <> 'admin' AND people_id NOT IN ($ph)"
            );
            $st->execute($seen);
            $deactivated = $st->rowCount();
        }

        return ['created' => $created, 'updated' => $updated, 'deactivated' => $deactivated, 'avatars' => $avatars, 'fetched' => count($rows)];
    }

    /**
     * ดาวน์โหลดรูปโปรไฟล์จาก {rms_base_url}/files/{picName} มาเก็บไว้ในระบบ (assets/avatars/)
     * คืน path แบบสัมพัทธ์เมื่อสำเร็จ หรือ null เมื่อดาวน์โหลด/ตรวจสอบไฟล์ไม่ผ่าน (ไม่ทำให้ sync ทั้งชุดล้มเหลว)
     */
    private static function downloadAvatar(string $picName, int $userId): ?string
    {
        $base = self::baseUrl();
        if ($base === '') { return null; }
        $url = $base . '/files/' . ltrim($picName, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RANGE          => '0-5242879', // จำกัดขนาดไม่เกิน ~5MB
        ]);
        $bin  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ok   = $bin !== false && curl_errno($ch) === 0;
        curl_close($ch);

        if (!$ok || !is_string($bin) || $bin === '' || $http >= 400 || strlen($bin) > 5 * 1024 * 1024) {
            return null;
        }

        $info = @getimagesizefromstring($bin);
        if (!$info) { return null; } // ไม่ใช่ไฟล์ภาพจริง
        $ext = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => null,
        };
        if ($ext === null) { return null; }

        $dir = BASE_PATH . '/assets/avatars';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { return null; }
        foreach (glob($dir . "/u{$userId}.*") ?: [] as $old) { @unlink($old); } // ล้างรูปเก่าของผู้ใช้คนนี้ก่อนบันทึกใหม่

        $rel = "assets/avatars/u{$userId}.{$ext}";
        if (@file_put_contents(BASE_PATH . '/' . $rel, $bin) === false) { return null; }
        return $rel;
    }

    /* ---------------- dateedu → semesters ---------------- */
    public static function syncSemesters(PDO $pdo): array
    {
        $rows = self::fetch('data=dateedu');
        $added = 0; $updated = 0; $skipped = 0;

        $find = $pdo->prepare('SELECT id FROM semesters WHERE year = ? AND semester = ? LIMIT 1');
        $ins  = $pdo->prepare('INSERT INTO semesters (year, semester, name, start_date, end_date, is_current) VALUES (?,?,?,?,?,0)');
        $upd  = $pdo->prepare('UPDATE semesters SET name = ?, start_date = ?, end_date = ? WHERE id = ?'); // ไม่แตะ is_current

        foreach ($rows as $d) {
            $ey = trim((string)($d['dateedu_eduyear'] ?? ''));
            if (!str_contains($ey, '/')) { $skipped++; continue; }
            [$sem, $year] = array_map('trim', explode('/', $ey, 2));
            if (!ctype_digit($sem) || !ctype_digit($year)) { $skipped++; continue; }
            $name = "ภาคเรียนที่ {$sem}/{$year}";
            $start = self::nz($d['dateedu_start'] ?? '');
            $end   = self::nz($d['dateedu_end'] ?? '');

            $find->execute([$year, $sem]);
            $id = $find->fetchColumn();
            if ($id) { $upd->execute([$name, $start, $end, $id]); $updated++; }
            else     { $ins->execute([$year, $sem, $name, $start, $end]); $added++; }
        }
        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'fetched' => count($rows)];
    }

    /* ---------------- std2018_studentgroup → student_groups ---------------- */
    public static function syncGroups(PDO $pdo): array
    {
        $rows = self::fetch('data=std2018_studentgroup');
        $added = 0; $updated = 0; $skipped = 0;

        $find = $pdo->prepare('SELECT id FROM student_groups WHERE academic_year = ? AND semester = ? AND group_code = ? LIMIT 1');
        $ins  = $pdo->prepare(
            'INSERT INTO student_groups (academic_year, semester, group_code, grade, group_name, group_abbr, teacher_idcard, teacher_name, classroom_id)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $upd  = $pdo->prepare(
            'UPDATE student_groups SET grade = ?, group_name = ?, group_abbr = ?, teacher_idcard = ?, teacher_name = ?, classroom_id = ? WHERE id = ?'
        );

        foreach ($rows as $g) {
            $ay  = trim((string)($g['academicYear'] ?? ''));
            $sem = trim((string)($g['semester'] ?? ''));
            $gc  = trim((string)($g['groupCode'] ?? ''));
            if ($ay === '' || $sem === '' || $gc === '') { $skipped++; continue; }
            $tname = trim(trim((string)($g['teacherFirstname'] ?? '')) . ' ' . trim((string)($g['teacherLastname'] ?? '')));
            $args = [
                self::nz($g['grade'] ?? ''),
                self::nz($g['groupName'] ?? ''),
                self::nz($g['groupAbbr'] ?? ''),
                self::nz($g['teacherIdcard'] ?? ''),
                $tname !== '' ? $tname : null,
                self::nz($g['ClassRoomID'] ?? ''),
            ];

            $find->execute([$ay, $sem, $gc]);
            $id = $find->fetchColumn();
            if ($id) { $upd->execute([...$args, $id]); $updated++; }
            else     { $ins->execute([$ay, $sem, $gc, ...$args]); $added++; }
        }
        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'fetched' => count($rows)];
    }

    /* ---------------- std2018_student → students (แบ่งท่อน) ---------------- */
    public static function countStudents(): int
    {
        $r = self::fetch('data=std2018_student&count=yes');
        return (int)($r[0]['c'] ?? $r[0]['count'] ?? 0);
    }

    public static function syncStudentBatch(PDO $pdo, int $offset, int $row): array
    {
        $rows = self::fetch("data=std2018_student&limit={$offset},{$row}");

        // map กลุ่ม → ชื่อครูที่ปรึกษา (สร้างครั้งเดียวก่อนวนลูป)
        $advisor = [];
        foreach ($pdo->query('SELECT group_code, teacher_name FROM student_groups WHERE teacher_name IS NOT NULL') as $g) {
            $advisor[$g['group_code']] = $g['teacher_name'];
        }

        $added = 0; $updated = 0; $skipped = 0;

        $find = $pdo->prepare('SELECT id FROM students WHERE student_id = ? OR (student_id IS NULL AND code = ?) LIMIT 1');
        $ins  = $pdo->prepare(
            'INSERT INTO students
                (student_id, code, student_code, idcard, prefix, full_name, level, department, room,
                 group_code, group_name, advisor_name, phone, email, risk_group, status_name, gpax, rms_synced_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, \'กลุ่มปกติ\', ?, ?, NOW(), NOW())'
        );
        $upd = $pdo->prepare(
            'UPDATE students SET
                student_id   = ?,
                student_code = ?,
                idcard       = COALESCE(NULLIF(?,\'\'), idcard),
                prefix       = COALESCE(NULLIF(?,\'\'), prefix),
                full_name    = ?,
                level        = COALESCE(NULLIF(?,\'\'), level),
                department   = COALESCE(NULLIF(?,\'\'), department),
                room         = COALESCE(NULLIF(?,\'\'), room),
                group_code   = ?,
                group_name   = COALESCE(NULLIF(?,\'\'), group_name),
                advisor_name = COALESCE(NULLIF(?,\'\'), advisor_name),
                phone        = COALESCE(NULLIF(?,\'\'), phone),
                email        = COALESCE(NULLIF(?,\'\'), email),
                status_name  = ?,
                gpax         = ?,
                rms_synced_at = NOW()
             WHERE id = ?'
        );

        foreach ($rows as $s) {
            $sid = trim((string)($s['studentID'] ?? ''));
            if ($sid === '') { $skipped++; continue; }
            $code   = trim((string)($s['studentCode'] ?? '')) ?: $sid;
            $gcode  = trim((string)($s['groupCode'] ?? ''));
            $name   = trim(trim((string)($s['firstname'] ?? '')) . ' ' . trim((string)($s['surname'] ?? '')));
            $prefix = self::prefixFromGender((string)($s['gender'] ?? ''));
            $level  = (string)($s['gradeNameTh'] ?? '');
            $major  = (string)($s['majorNameTh'] ?? '');
            $room   = trim((string)($s['groupAbbr'] ?? '')) ?: (string)($s['groupName'] ?? '');
            $gname  = (string)($s['groupName'] ?? '');
            $adv    = $advisor[$gcode] ?? '';
            $phone  = (string)($s['tel'] ?? '');
            $email  = (string)($s['email'] ?? '');
            $status = self::nz($s['studentStatusName'] ?? '');
            $gpaxRaw = trim((string)($s['gpax'] ?? ''));
            $gpax   = ($gpaxRaw !== '' && is_numeric($gpaxRaw)) ? round((float)$gpaxRaw, 2) : null;

            $find->execute([$sid, $code]);
            $id = $find->fetchColumn();
            if ($id) {
                $upd->execute([$sid, $code, (string)($s['idcard'] ?? ''), $prefix, $name, $level, $major, $room,
                    $gcode, $gname, $adv, $phone, $email, $status, $gpax, $id]);
                $updated++;
            } else {
                $ins->execute([$sid, $code, $code, self::nz($s['idcard'] ?? ''), $prefix, $name, $level ?: null, $major ?: null,
                    $room ?: null, $gcode ?: null, $gname ?: null, $adv ?: null, self::nz($phone), self::nz($email), $status, $gpax]);
                $added++;
            }
        }

        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'fetched' => count($rows)];
    }
}
