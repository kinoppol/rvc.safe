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

if ($r === 'sso_login') {
    if (Auth::check()) { redirect('index.php?r=dashboard'); }
    if (!Sso::enabled()) {
        flash('ยังไม่ได้ตั้งค่าระบบเข้าสู่ระบบผ่าน ONE-RVC', 'err');
        redirect('index.php?r=login');
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['sso_state'] = $state;
    $_SESSION['sso_state_at'] = time();
    redirect(Sso::authorizeUrl($state));
}

if ($r === 'logout') {
    if (Auth::isImpersonating()) {
        // กำลังสวมสิทธิ์อยู่ → "ออกจากระบบ" หมายถึงคืนสิทธิ์ผู้ดูแลเดิม ไม่ใช่ปิด session
        $impName = Auth::impersonatorName();
        Auth::stopImpersonating();
        activity_log($pdo, 'ผู้ดูแล ' . $impName . ' กลับสู่สิทธิ์ผู้ดูแลระบบ');
        flash('กลับสู่บัญชีผู้ดูแลระบบแล้ว', 'ok');
        redirect('index.php?r=dashboard');
    }
    activity_log($pdo, 'ออกจากระบบ');
    Auth::logout();
    redirect('index.php?r=login');
}

Auth::requireLogin();
$me = Auth::user();

/* ---------------- Dashboard ---------------- */
if ($r === 'dashboard') {
    // ครูที่ปรึกษาเห็นเฉพาะนักเรียนในกลุ่มที่ตนเองเป็นที่ปรึกษา; head/exec/admin เห็นภาพรวมทั้งหมด
    $scopeIds = teacher_scope_ids($pdo);
    [$scopeCond, $scopeArgs] = scope_where('s.id', $scopeIds);
    $andScope = $scopeCond !== '' ? " AND $scopeCond" : '';

    $st = $pdo->prepare('SELECT COUNT(*) FROM students s WHERE 1=1' . $andScope);
    $st->execute($scopeArgs);
    $totalStudents = (int)$st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM visits v JOIN students s ON s.id = v.student_id
         WHERE v.status IN ('บันทึกแล้ว','รอตรวจสอบ','ผ่านหัวหน้างาน','ลงนามแล้ว')" . $andScope
    );
    $st->execute($scopeArgs);
    $doneCount = (int)$st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM visits v JOIN students s ON s.id = v.student_id
         WHERE v.status IN ('ฉบับร่าง','รอเยี่ยม')" . $andScope
    );
    $st->execute($scopeArgs);
    $pendingCount = (int)$st->fetchColumn();

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM visits v JOIN students s ON s.id = v.student_id
         WHERE v.urgent = 1" . $andScope
    );
    $st->execute($scopeArgs);
    $urgentCount = (int)$st->fetchColumn();

    $stats = ['total' => $totalStudents, 'done' => $doneCount, 'pending' => $pendingCount, 'urgent' => $urgentCount];

    $st = $pdo->prepare(
        "SELECT s.department AS name,
                ROUND(100 * SUM(v.status IN ('บันทึกแล้ว','รอตรวจสอบ','ผ่านหัวหน้างาน','ลงนามแล้ว')) / NULLIF(COUNT(*),0)) AS pct
         FROM students s LEFT JOIN visits v ON v.student_id = s.id
         WHERE s.department IS NOT NULL" . $andScope . "
         GROUP BY s.department ORDER BY pct DESC"
    );
    $st->execute($scopeArgs);
    $depts = $st->fetchAll();

    $feed = $pdo->query('SELECT a.*, u.full_name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8')->fetchAll();

    $st = $pdo->prepare(
        "SELECT v.*, s.full_name, s.prefix, s.level, s.department, s.room, s.advisor_name, s.risk_group
         FROM visits v JOIN students s ON s.id = v.student_id"
        . ($scopeCond !== '' ? " WHERE $scopeCond" : '') . "
         ORDER BY FIELD(v.status,'เกินกำหนด','รอเยี่ยม','ฉบับร่าง','รอตรวจสอบ','บันทึกแล้ว','ลงนามแล้ว'), v.visit_date
         LIMIT 8"
    );
    $st->execute($scopeArgs);
    $visits = $st->fetchAll();

    render('dashboard', compact('stats', 'depts', 'feed', 'visits'), 'ภาพรวมการเยี่ยมบ้าน');
    exit;
}

/* ---------------- Visit list ---------------- */
if ($r === 'visits') {
    $filter = $_GET['status'] ?? 'ทั้งหมด';
    $scopeIds = teacher_scope_ids($pdo); // ครูที่ปรึกษาเห็นเฉพาะนักเรียนในกลุ่มที่ตนเองเป็นที่ปรึกษา
    [$scopeCond, $scopeArgs] = scope_where('s.id', $scopeIds);
    $sql = "SELECT v.*, s.full_name, s.prefix, s.code, s.level, s.department, s.room, s.advisor_name, s.address, s.lat AS s_lat, s.lng AS s_lng, s.risk_group
            FROM visits v JOIN students s ON s.id = v.student_id";
    $where = [];
    $args  = [];
    if ($filter !== 'ทั้งหมด') { $where[] = 'v.status = ?'; $args[] = $filter; }
    if ($scopeCond !== '')    { $where[] = $scopeCond; array_push($args, ...$scopeArgs); }
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
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

    // ครูที่ปรึกษาเปิด/แก้ไขได้เฉพาะนักเรียนในกลุ่มที่ตนเองเป็นที่ปรึกษา
    $scopeIds = teacher_scope_ids($pdo);
    if ($scopeIds !== null && !in_array((int)$visit['student_id'], $scopeIds, true)) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์เข้าถึงรายการเยี่ยมบ้านของนักเรียนคนนี้ (ไม่ได้อยู่ในกลุ่มที่คุณเป็นครูที่ปรึกษา)');
    }

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

/* ---------------- Users + impersonation (admin) ---------------- */
if ($r === 'users') {
    Auth::requireRole('admin');

    // ตัวกรอง/ค้นหา — เก็บผ่าน query string เพื่อรักษาบริบทได้แม้ทำรายการ (impersonate/เปิด-ปิดใช้งาน) แล้วย้อนกลับมา
    $filterQs = http_build_query(array_filter([
        'q'      => trim((string)($_POST['q'] ?? $_GET['q'] ?? '')),
        'role'   => (string)($_POST['role'] ?? $_GET['role'] ?? ''),
        'status' => (string)($_POST['status'] ?? $_GET['status'] ?? ''),
    ], fn($v) => $v !== ''));
    $backUrl = 'index.php?r=users' . ($filterQs !== '' ? '&' . $filterQs : '');

    if ($method === 'POST') {
        csrf_verify();
        $action = $_POST['action'] ?? '';

        if ($action === 'impersonate') {
            $targetId = (int)($_POST['id'] ?? 0);
            if ($targetId === (int)$me['id']) {
                flash('ไม่สามารถสวมสิทธิ์บัญชีของตัวเองได้', 'err');
                redirect($backUrl);
            }
            $adminName = $me['full_name'];
            $st = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
            $st->execute([$targetId]);
            $targetName = $st->fetchColumn() ?: ('#' . $targetId);

            if (Auth::impersonate($targetId)) {
                activity_log($pdo, "ผู้ดูแล {$adminName} สวมสิทธิ์เป็น {$targetName}");
                redirect('index.php?r=dashboard');
            }
            flash('ไม่สามารถสวมสิทธิ์ผู้ใช้นี้ได้ (อาจถูกปิดใช้งาน หรือไม่พบผู้ใช้)', 'err');
            redirect($backUrl);
        }

        if ($action === 'toggle_active') {
            $targetId = (int)($_POST['id'] ?? 0);
            if ($targetId !== (int)$me['id']) {
                $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$targetId]);
                activity_log($pdo, 'เปลี่ยนสถานะการใช้งานผู้ใช้ #' . $targetId);
            }
            redirect($backUrl);
        }

        // ผู้ดูแลแก้ไขบทบาทผู้ใช้ได้ (เช่น เลื่อนเป็นหัวหน้างานแนะแนว โดยยังทำหน้าที่ครูที่ปรึกษาได้ต่อ
        // เพราะ head/exec/admin เห็นภาพรวมทั้งหมดอยู่แล้ว ครอบคลุมงานครูที่ปรึกษาในตัว)
        if ($action === 'set_role') {
            $targetId = (int)($_POST['id'] ?? 0);
            $newRole = (string)($_POST['new_role'] ?? '');
            if ($targetId !== (int)$me['id'] && isset(Auth::ROLES[$newRole])) {
                $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
                activity_log($pdo, "เปลี่ยนบทบาทผู้ใช้ #{$targetId} เป็น " . Auth::ROLES[$newRole]);
                flash('บันทึกบทบาทเรียบร้อย', 'ok');
            }
            redirect($backUrl);
        }

        // ผูกครูที่ปรึกษากับกลุ่มเรียนด้วยเลขบัตรประชาชน 13 หลัก (namespace เดียวกับ student_groups.teacher_idcard)
        if ($action === 'set_idcard') {
            $targetId = (int)($_POST['id'] ?? 0);
            $pid = trim((string)($_POST['people_id'] ?? ''));
            if ($targetId !== (int)$me['id']) {
                if ($pid === '') {
                    $pdo->prepare('UPDATE users SET people_id = NULL WHERE id = ?')->execute([$targetId]);
                    activity_log($pdo, 'ล้างเลขบัตรประชาชนของผู้ใช้ #' . $targetId);
                    flash('ล้างเลขบัตรประชาชนเรียบร้อย', 'ok');
                } elseif (!preg_match('/^\d{13}$/', $pid)) {
                    flash('เลขบัตรประชาชนต้องเป็นตัวเลข 13 หลัก', 'err');
                } else {
                    $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE people_id = ? AND id <> ?');
                    $chk->execute([$pid, $targetId]);
                    if ((int)$chk->fetchColumn() > 0) {
                        flash('เลขบัตรประชาชนนี้ถูกใช้กับผู้ใช้อื่นในระบบแล้ว', 'err');
                    } else {
                        $pdo->prepare('UPDATE users SET people_id = ? WHERE id = ?')->execute([$pid, $targetId]);
                        activity_log($pdo, 'ตั้งเลขบัตรประชาชนของผู้ใช้ #' . $targetId);
                        flash('บันทึกเลขบัตรประชาชนเรียบร้อย', 'ok');
                    }
                }
            }
            redirect($backUrl);
        }

        redirect($backUrl);
    }

    $q      = trim((string)($_GET['q'] ?? ''));
    $roleF  = (string)($_GET['role'] ?? '');
    $status = (string)($_GET['status'] ?? '');

    $where = [];
    $args  = [];
    if ($q !== '') {
        $where[] = '(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR department LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like);
    }
    if ($roleF !== '' && isset(Auth::ROLES[$roleF])) {
        $where[] = 'role = ?';
        $args[] = $roleF;
    }
    if ($status === 'active') { $where[] = 'is_active = 1'; }
    elseif ($status === 'inactive') { $where[] = 'is_active = 0'; }

    $sql = 'SELECT * FROM users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY FIELD(role,'admin','head','exec','teacher'), full_name";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $users = $st->fetchAll();

    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    render('users', compact('users', 'q', 'roleF', 'status', 'totalUsers'), 'ผู้ใช้ระบบ');
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
