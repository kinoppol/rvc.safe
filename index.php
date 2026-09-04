<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

// ยังติดตั้งไม่ครบ → ไปหน้าติดตั้งอัตโนมัติ
if (!installation_complete()) {
    redirect('install.php');
}

[$cfg, $pdo] = boot_app();

$r = $_GET['r'] ?? 'dashboard';
$method = $_SERVER['REQUEST_METHOD'];

/* ---------------- Auth ---------------- */
if ($r === 'login') {
    if (Auth::check()) { redirect('index.php?r=dashboard'); }
    if ($method === 'POST') {
        csrf_verify();
        if (Auth::attempt(trim($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
            activity_log($pdo, 'เข้าสู่ระบบ');
            redirect('index.php?r=dashboard');
        }
        flash('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 'err');
    }
    render('login', [], 'เข้าสู่ระบบ');
    exit;
}

if ($r === 'logout') {
    Auth::logout();
    redirect('index.php?r=login');
}

Auth::requireLogin();
$me = Auth::user();

/* ---------------- Dashboard ---------------- */
if ($r === 'dashboard') {
    $stats = [
        'total'   => (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
        'done'    => (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE status IN ('บันทึกแล้ว','รอตรวจสอบ','ผ่านหัวหน้างาน','ลงนามแล้ว')")->fetchColumn(),
        'pending' => (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE status IN ('ฉบับร่าง','รอเยี่ยม')")->fetchColumn(),
        'urgent'  => (int)$pdo->query('SELECT COUNT(*) FROM visits WHERE urgent = 1')->fetchColumn(),
    ];
    $depts = $pdo->query(
        "SELECT s.department AS name,
                ROUND(100 * SUM(v.status IN ('บันทึกแล้ว','รอตรวจสอบ','ผ่านหัวหน้างาน','ลงนามแล้ว')) / NULLIF(COUNT(*),0)) AS pct
         FROM students s LEFT JOIN visits v ON v.student_id = s.id
         WHERE s.department IS NOT NULL
         GROUP BY s.department ORDER BY pct DESC"
    )->fetchAll();
    $feed = $pdo->query('SELECT a.*, u.full_name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8')->fetchAll();
    $visits = $pdo->query(
        "SELECT v.*, s.full_name, s.prefix, s.level, s.department, s.room, s.advisor_name, s.risk_group
         FROM visits v JOIN students s ON s.id = v.student_id
         ORDER BY FIELD(v.status,'เกินกำหนด','รอเยี่ยม','ฉบับร่าง','รอตรวจสอบ','บันทึกแล้ว','ลงนามแล้ว'), v.visit_date
         LIMIT 8"
    )->fetchAll();
    render('dashboard', compact('stats', 'depts', 'feed', 'visits'), 'ภาพรวมการเยี่ยมบ้าน');
    exit;
}

/* ---------------- Visit list ---------------- */
if ($r === 'visits') {
    $filter = $_GET['status'] ?? 'ทั้งหมด';
    $sql = "SELECT v.*, s.full_name, s.prefix, s.code, s.level, s.department, s.room, s.advisor_name, s.address, s.lat AS s_lat, s.lng AS s_lng, s.risk_group
            FROM visits v JOIN students s ON s.id = v.student_id";
    $args = [];
    if ($filter !== 'ทั้งหมด') { $sql .= ' WHERE v.status = ?'; $args[] = $filter; }
    $sql .= ' ORDER BY v.visit_date, s.full_name';
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $visits = $st->fetchAll();
    render('visits', compact('visits', 'filter'), 'รายการเยี่ยมบ้าน');
    exit;
}

/* ---------------- Visit form (8 steps) ---------------- */
if ($r === 'visit') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare(
        'SELECT v.*, s.full_name, s.prefix, s.code, s.level, s.department, s.room, s.advisor_name, s.address, s.lat AS s_lat, s.lng AS s_lng
         FROM visits v JOIN students s ON s.id = v.student_id WHERE v.id = ?'
    );
    $st->execute([$id]);
    $visit = $st->fetch();
    if (!$visit) { http_response_code(404); exit('ไม่พบรายการเยี่ยม'); }

    $steps = visit_steps();
    $data  = json_decode($visit['data_json'] ?? '[]', true) ?: [];
    $step  = max(1, min(8, (int)($_GET['step'] ?? $visit['current_step'] ?? 1)));

    if ($method === 'POST') {
        csrf_verify();
        $act = $_POST['act'] ?? 'next';
        $posted = $_POST['f'] ?? [];
        foreach ($steps[$step - 1]['fields'] as $f) {
            if (in_array($f['type'], ['map', 'photos'], true)) { continue; }
            $val = $posted[$f['id']] ?? ($f['type'] === 'chips' ? [] : '');
            $data[$f['id']] = is_array($val) ? array_values($val) : trim((string)$val);
        }
        // ฟิลด์แผนที่
        if (isset($_POST['lat'], $_POST['lng']) && $_POST['lat'] !== '') {
            $pdo->prepare('UPDATE visits SET lat = ?, lng = ? WHERE id = ?')
                ->execute([(float)$_POST['lat'], (float)$_POST['lng'], $id]);
        }

        $newStep = $step;
        if ($act === 'next')  { $newStep = min(8, $step + 1); }
        if ($act === 'prev')  { $newStep = max(1, $step - 1); }
        if ($act === 'goto')  { $newStep = max(1, min(8, (int)($_POST['step_to'] ?? $step))); }

        $status = $visit['status'];
        $screen = $data['screen'] ?? $visit['screen_result'];
        $urgent = (isset($data['urgent']) && in_array('จำเป็น', (array)$data['urgent'], true)) ? 1 : 0;

        if ($act === 'submit') {
            $status = 'รอตรวจสอบ';
            $newStep = 8;
            activity_log($pdo, 'ส่งรายงานการเยี่ยมบ้าน ' . $visit['full_name']);
        } elseif ($act === 'draft') {
            $status = $status === 'รอเยี่ยม' || $status === 'ฉบับร่าง' ? 'ฉบับร่าง' : $status;
        } elseif ($status === 'รอเยี่ยม' || $status === 'ฉบับร่าง') {
            $status = 'ฉบับร่าง';
        }

        $pdo->prepare(
            'UPDATE visits SET data_json = ?, current_step = ?, status = ?, screen_result = ?, urgent = ?,
                 term = ?, round = ?, visit_date = ?, visit_time = ?, method = ?, advisor = ?,
                 help_amount = ?, summary = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([
            json_encode($data, JSON_UNESCAPED_UNICODE),
            $newStep, $status, $screen, $urgent,
            $data['term'] ?? null, $data['round'] ?? null,
            !empty($data['vdate']) ? $data['vdate'] : null,
            !empty($data['vtime']) ? $data['vtime'] : null,
            is_array($data['method'] ?? null) ? implode(', ', $data['method']) : ($data['method'] ?? null),
            $data['advisor'] ?? null,
            isset($data['amount']) && $data['amount'] !== '' ? (float)$data['amount'] : null,
            $data['summary'] ?? null,
            $id,
        ]);

        redirect('index.php?r=visit&id=' . $id . '&step=' . $newStep . ($act === 'submit' ? '&sent=1' : ''));
    }

    render('visit_form', compact('visit', 'steps', 'data', 'step'), 'บันทึกการเยี่ยมบ้าน');
    exit;
}

/* ---------------- Reports / approval ---------------- */
if ($r === 'reports') {
    Auth::requireRole('head', 'exec', 'admin');
    if ($method === 'POST') {
        csrf_verify();
        $vid = (int)($_POST['visit_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $map = [
            'head_ok' => 'ผ่านหัวหน้างาน',
            'exec_ok' => 'ลงนามแล้ว',
            'return'  => 'บันทึกแล้ว',
        ];
        if (isset($map[$action]) && $vid) {
            $pdo->prepare('UPDATE visits SET status = ? WHERE id = ?')->execute([$map[$action], $vid]);
            $pdo->prepare('INSERT INTO visit_approvals (visit_id, actor_id, action, note, created_at) VALUES (?,?,?,?,NOW())')
                ->execute([$vid, $me['id'], $action, $_POST['note'] ?? null]);
            activity_log($pdo, 'อัปเดตสถานะรายงาน #' . $vid . ' → ' . $map[$action]);
        }
        redirect('index.php?r=reports');
    }
    $pending = $pdo->query(
        "SELECT v.*, s.full_name, s.prefix, s.level, s.department, s.room, s.advisor_name, s.risk_group
         FROM visits v JOIN students s ON s.id = v.student_id
         WHERE v.status IN ('รอตรวจสอบ','ผ่านหัวหน้างาน')
         ORDER BY v.urgent DESC, v.updated_at DESC"
    )->fetchAll();
    $counts = [
        'review' => (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE status = 'รอตรวจสอบ'")->fetchColumn(),
        'sign'   => (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE status = 'ผ่านหัวหน้างาน'")->fetchColumn(),
        'done'   => (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE status = 'ลงนามแล้ว'")->fetchColumn(),
    ];
    render('reports', compact('pending', 'counts'), 'ตรวจสอบและลงนามรายงาน');
    exit;
}

/* ---------------- Migration (admin) ---------------- */
if ($r === 'migrations') {
    Auth::requireRole('admin');
    $migrator = new Migrator($pdo);
    $log = [];
    if ($method === 'POST') {
        csrf_verify();
        if (($_POST['action'] ?? '') === 'run_pending') {
            $log = $migrator->migrate();
            activity_log($pdo, 'รัน migration ที่ค้าง ' . count($log) . ' รายการ');
        } elseif (($_POST['action'] ?? '') === 'run_one') {
            $all = $migrator->available();
            $v = $_POST['version'] ?? '';
            if (isset($all[$v])) {
                $log = [$migrator->runOne($all[$v])];
                activity_log($pdo, 'รัน migration ' . $all[$v]['filename']);
            }
        }
        flash('ดำเนินการ migration เรียบร้อย', 'ok');
        $_SESSION['migration_log'] = $log;
        redirect('index.php?r=migrations');
    }
    $log = $_SESSION['migration_log'] ?? [];
    unset($_SESSION['migration_log']);
    $status = $migrator->status();
    $pendingCount = count($migrator->pending());
    render('migrations', compact('status', 'pendingCount', 'log'), 'Migration ฐานข้อมูล');
    exit;
}

/* ---------------- RMS data transfer (admin) ---------------- */
if ($r === 'rms') {
    Auth::requireRole('admin');
    $action = $_POST['action'] ?? '';

    if ($action !== '') {
        csrf_verify();
        try {
            switch ($action) {
                case 'save_url':
                    $u = rtrim(trim($_POST['rms_base_url'] ?? ''), '/');
                    if (!preg_match('#^https?://#i', $u)) {
                        json_err('URL ต้องขึ้นต้นด้วย http:// หรือ https://');
                    }
                    set_setting('rms_base_url', $u);
                    activity_log($pdo, 'ตั้งค่า URL ของ RMS เป็น ' . $u);
                    json_ok(['rms_base_url' => $u], 'บันทึก URL เรียบร้อย');

                case 'sync_people':
                    $res = Rms::syncPeople($pdo);
                    activity_log($pdo, "โอนบุคลากรจาก RMS: เพิ่ม {$res['created']} อัปเดต {$res['updated']} ปิดใช้งาน {$res['deactivated']}");
                    json_ok($res, 'โอนข้อมูลบุคลากรเสร็จสิ้น');

                case 'sync_semesters':
                    json_ok(Rms::syncSemesters($pdo), 'โอนข้อมูลภาคเรียนเสร็จสิ้น');

                case 'sync_groups':
                    json_ok(Rms::syncGroups($pdo), 'โอนข้อมูลกลุ่มเรียนเสร็จสิ้น');

                case 'count_students':
                    json_ok(['total' => Rms::countStudents()]);

                case 'sync_students':
                    $off = max(0, (int)($_POST['offset'] ?? 0));
                    $rw  = (int)($_POST['row'] ?? 100);
                    $rw  = $rw < 1 ? 100 : min($rw, 500);   // clamp ฝั่งเซิร์ฟเวอร์
                    json_ok(Rms::syncStudentBatch($pdo, $off, $rw));

                default:
                    json_err('ไม่รู้จักคำสั่ง: ' . $action);
            }
        } catch (Throwable $e) {
            json_err($e->getMessage(), 500);
        }
    }

    $counts = [
        'users'     => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE people_id IS NOT NULL')->fetchColumn(),
        'semesters' => (int)$pdo->query('SELECT COUNT(*) FROM semesters')->fetchColumn(),
        'groups'    => (int)$pdo->query('SELECT COUNT(*) FROM student_groups')->fetchColumn(),
        'students'  => (int)$pdo->query('SELECT COUNT(*) FROM students WHERE student_id IS NOT NULL')->fetchColumn(),
    ];
    $rmsUrl = get_setting('rms_base_url', '');
    $lastSync = $pdo->query('SELECT MAX(rms_synced_at) FROM students')->fetchColumn();
    render('rms', compact('counts', 'rmsUrl', 'lastSync'), 'โอนข้อมูลจาก RMS');
    exit;
}

/* ---------------- System check (admin) ---------------- */
if ($r === 'system') {
    Auth::requireRole('admin');
    $checks = Support::all($cfg['db']);
    render('system', compact('checks', 'cfg'), 'สถานะระบบ');
    exit;
}

http_response_code(404);
render('login', [], 'ไม่พบหน้า');
