<?php
declare(strict_types=1);
/**
 * ตัวติดตั้งระบบเยี่ยมบ้านนักเรียน (รองรับการติดตั้งซ้ำ)
 *
 *  ขั้นตอน
 *   1. ตรวจสอบแพ็กเกจซอฟต์แวร์ + สิทธิ์อ่าน/เขียนไฟล์
 *   2. ตั้งค่าฐานข้อมูล MariaDB → สร้างฐานข้อมูล + เขียน config + รัน migration
 *   3. แบบฟอร์มระบุข้อมูลผู้ดูแลระบบ (admin) สำหรับจัดการระบบ
 *   4. เสร็จสิ้น
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Bangkok');

define('BASE_PATH', __DIR__);
define('CONFIG_FILE', BASE_PATH . '/config/config.php');
require BASE_PATH . '/src/Support.php';
require BASE_PATH . '/src/Database.php';
require BASE_PATH . '/src/Migrator.php';

session_name('RVCSAFE_INSTALL');
session_start();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$alreadyInstalled = is_file(CONFIG_FILE);
$existing = $alreadyInstalled ? (require CONFIG_FILE) : null;

/** สถานะปัจจุบันของการติดตั้ง เพื่อกำหนดขั้นตอนเริ่มต้น */
function detect_state(?array $cfg): array
{
    if (!$cfg) { return ['schema' => false, 'admin' => false, 'connect' => false]; }
    try {
        $pdo = Database::connect($cfg['db']);
        $hasUsers = (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetch();
        $hasAdmin = $hasUsers && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn() > 0;
        return ['schema' => $hasUsers, 'admin' => $hasAdmin, 'connect' => true];
    } catch (Throwable) {
        return ['schema' => false, 'admin' => false, 'connect' => false];
    }
}

$state = detect_state($existing);
$fullyInstalled = $state['schema'] && $state['admin'];

// กำหนดขั้นตอนเริ่มต้นถ้าไม่ได้ระบุมา
if (isset($_GET['step'])) {
    $step = max(1, min(4, (int)$_GET['step']));
} elseif ($fullyInstalled) {
    $step = 1;                       // ติดตั้งครบแล้ว — แสดงหน้าแรกพร้อมตัวเลือกติดตั้งซ้ำ
} elseif ($state['schema']) {
    $step = 3;                       // มี schema แล้วแต่ยังไม่มี admin → ไปกรอกข้อมูล admin
} elseif ($alreadyInstalled) {
    $step = 2;
} else {
    $step = 1;
}

$errors = [];

/* ================= จัดการ POST ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ---- ขั้นที่ 2: ตั้งค่าฐานข้อมูล ---- */
    if ($action === 'save_db' || $action === 'db_test') {
        $db = [
            'host'    => trim($_POST['host'] ?? '127.0.0.1'),
            'port'    => (int)($_POST['port'] ?? 3306),
            'name'    => trim($_POST['name'] ?? 'rvc_safe'),
            'user'    => trim($_POST['user'] ?? 'root'),
            'pass'    => (string)($_POST['pass'] ?? ''),
            'charset' => 'utf8mb4',
        ];
        $_SESSION['db'] = $db;

        $chk = Support::checkDatabase($db);
        if (empty($chk['connect'])) {
            $errors[] = 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $chk['current'];
        } elseif (empty($chk['ok'])) {
            $errors[] = 'เวอร์ชันฐานข้อมูลต่ำกว่าที่กำหนด: ' . $chk['current'] . ' (ต้องการ ' . $chk['need'] . ')';
        }

        if ($action === 'db_test' && !$errors) {
            header('Location: install.php?step=2&tested=1');
            exit;
        }

        if ($action === 'save_db' && !$errors) {
            try {
                // สร้างฐานข้อมูลถ้ายังไม่มี
                Database::server($db)->exec(sprintf(
                    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                    str_replace('`', '', $db['name'])
                ));

                // เขียนไฟล์ config
                $sample = require BASE_PATH . '/config/config.sample.php';
                $cfg = $sample;
                $cfg['db'] = $db;
                $cfg['app']['name'] = $existing['app']['name'] ?? $sample['app']['name'];
                $cfg['app']['key']  = $existing['app']['key'] ?? bin2hex(random_bytes(32));
                $cfg['app']['env']  = $_POST['env'] ?? ($existing['app']['env'] ?? 'production');
                $cfg['app']['installed_at'] = $existing['app']['installed_at'] ?? date('c');

                if (!is_dir(BASE_PATH . '/config')) { mkdir(BASE_PATH . '/config', 0775, true); }
                $export = "<?php\n// สร้างโดยตัวติดตั้งเมื่อ " . date('Y-m-d H:i:s') . "\nreturn " . var_export($cfg, true) . ";\n";
                if (file_put_contents(CONFIG_FILE, $export) === false) {
                    throw new RuntimeException('เขียนไฟล์ config/config.php ไม่ได้ — ตรวจสอบสิทธิ์โฟลเดอร์ config/');
                }

                // รัน migration
                $pdo = Database::connect($db);
                $mlog = (new Migrator($pdo))->migrate();
                foreach ($mlog as $r) {
                    if (empty($r['ok'])) {
                        throw new RuntimeException('Migration ' . $r['filename'] . ' ล้มเหลว: ' . ($r['error'] ?? ''));
                    }
                }
                $_SESSION['install_log'] = $mlog;
                header('Location: install.php?step=3');
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }

    /* ---- ขั้นที่ 3: แบบฟอร์มผู้ดูแลระบบ ---- */
    if ($action === 'save_admin') {
        if (!is_file(CONFIG_FILE)) {
            $errors[] = 'ยังไม่ได้ตั้งค่าฐานข้อมูล กรุณาทำขั้นตอนที่ 2 ก่อน';
        } else {
            $cfg = require CONFIG_FILE;
            $adminUser = trim($_POST['admin_user'] ?? 'admin');
            $adminName = trim($_POST['admin_name'] ?? 'ผู้ดูแลระบบ');
            $adminMail = trim($_POST['admin_email'] ?? '');
            $adminPass = (string)($_POST['admin_pass'] ?? '');
            $adminPass2 = (string)($_POST['admin_pass2'] ?? '');
            $keepAdmin = isset($_POST['keep_admin']) && $state['admin'];
            $demo      = isset($_POST['demo']);

            if ($adminUser === '' || !preg_match('/^[A-Za-z0-9._-]{3,32}$/', $adminUser)) {
                $errors[] = 'ชื่อผู้ใช้ต้องเป็น a-z, 0-9, . _ - ยาว 3–32 ตัวอักษร';
            }
            if ($adminName === '') { $errors[] = 'กรุณาระบุชื่อ-นามสกุลผู้ดูแล'; }
            if (!$keepAdmin) {
                if (strlen($adminPass) < 8) { $errors[] = 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร'; }
                if ($adminPass !== $adminPass2) { $errors[] = 'ยืนยันรหัสผ่านไม่ตรงกัน'; }
            }

            if (!$errors) {
                try {
                    $pdo = Database::connect($cfg['db']);
                    (new Migrator($pdo))->migrate(); // กันกรณีเข้ามาขั้นนี้ตรง ๆ

                    if (!$keepAdmin) {
                        $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                        // เพิ่มคอลัมน์อีเมลถ้ายังไม่มี (ไม่บังคับใน schema หลัก)
                        try { $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(160) NULL"); } catch (Throwable) {}
                        $pdo->prepare(
                            'INSERT INTO users (username, password_hash, full_name, email, role, is_active)
                             VALUES (?,?,?,?,\'admin\',1)
                             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
                                full_name = VALUES(full_name), email = VALUES(email),
                                role = \'admin\', is_active = 1'
                        )->execute([$adminUser, $hash, $adminName, $adminMail ?: null]);
                    }

                    if ($demo) { seed_demo($pdo); }

                    unset($_SESSION['db']);
                    header('Location: install.php?step=4');
                    exit;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

function seed_demo(PDO $pdo): void
{
    $students = [
        ['6721010001','นางสาว','กมลชนก แสงทอง','ปวช.2','การบัญชี','2/1','ครูสุนิสา','ต.ในเมือง อ.เมืองร้อยเอ็ด',16.0538,103.6531,'081-000-0001','กลุ่มเสี่ยง'],
        ['6721030014','นาย','ธนกร ศรีวิไล','ปวช.3','ช่างยนต์','3/2','ครูอนุชา','ต.เหนือเมือง อ.เมืองร้อยเอ็ด',16.0723,103.6412,'081-000-0002','กลุ่มปกติ'],
        ['6721020008','นางสาว','ปิยะดา บุญมา','ปวส.1','คอมพิวเตอร์ธุรกิจ','1/1','ครูวราภรณ์','ต.ดงลาน อ.เมืองร้อยเอ็ด',16.0301,103.6789,'081-000-0003','กลุ่มมีปัญหา'],
        ['6721040022','นาย','วีระชัย พรมดี','ปวช.1','ช่างไฟฟ้ากำลัง','1/3','ครูสมพงษ์','ต.รอบเมือง อ.เมืองร้อยเอ็ด',16.0455,103.6215,'081-000-0004','กลุ่มปกติ'],
        ['6721010045','นางสาว','ศิริพร คำแก้ว','ปวส.2','การตลาด','2/2','ครูจิราพร','ต.สีแก้ว อ.เมืองร้อยเอ็ด',16.1012,103.5987,'081-000-0005','กลุ่มเสี่ยง'],
        ['6721030037','นาย','ภูวดล ทองใบ','ปวช.2','ช่างกลโรงงาน','2/4','ครูอนุชา','ต.นิเวศน์ อ.ธวัชบุรี',16.0899,103.7321,'081-000-0006','กลุ่มปกติ'],
    ];
    $st = $pdo->prepare(
        'INSERT IGNORE INTO students (code,prefix,full_name,level,department,room,advisor_name,address,lat,lng,phone,risk_group)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($students as $s) { $st->execute($s); }

    $pw = password_hash('password123', PASSWORD_DEFAULT);
    $users = [
        ['teacher1','สุนิสา ทองสุข','teacher','การบัญชี'],
        ['head1','ประเสริฐ ภูมิเพ็ง','head',null],
        ['exec1','วิไลวรรณ ศรีสวัสดิ์','exec',null],
    ];
    $us = $pdo->prepare(
        'INSERT IGNORE INTO users (username,password_hash,full_name,role,department,is_active)
         VALUES (?,?,?,?,?,1)'
    );
    foreach ($users as $u) { $us->execute([$u[0], $pw, $u[1], $u[2], $u[3]]); }

    $ids = $pdo->query('SELECT id FROM students ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $statuses = ['รอเยี่ยม','บันทึกแล้ว','เกินกำหนด','รอเยี่ยม','รอตรวจสอบ','บันทึกแล้ว'];
    foreach ($ids as $i => $sid) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM visits WHERE student_id = ?');
        $c->execute([$sid]);
        if ($c->fetchColumn() > 0) { continue; }
        $pdo->prepare(
            'INSERT INTO visits (student_id, term, round, visit_date, status, advisor, current_step, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW())'
        )->execute([$sid, '1/2569', 'ครั้งที่ 1', date('Y-m-d', strtotime("+$i days")), $statuses[$i] ?? 'รอเยี่ยม', 'ครูที่ปรึกษา']);
    }
}

/* ================= แสดงผล ================= */
$db = $_SESSION['db'] ?? ($existing['db'] ?? ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'rvc_safe', 'user' => 'root', 'pass' => '']);
$reqChecks = Support::all();
$reqOk = Support::allPassed($reqChecks);
$stepNames = [1 => 'ตรวจสอบความพร้อม', 2 => 'ตั้งค่าฐานข้อมูล', 3 => 'ผู้ดูแลระบบ', 4 => 'เสร็จสิ้น'];
?>
<!doctype html>
<html lang="th" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ติดตั้งระบบเยี่ยมบ้านนักเรียน</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
<style>
  body{background:var(--bg);margin:0;font-family:'IBM Plex Sans Thai',system-ui,sans-serif;color:var(--text)}
  .wrap{max-width:820px;margin:0 auto;padding:32px 20px 80px}
  .card{background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);padding:26px;margin-bottom:18px}
  .brand{display:flex;align-items:center;gap:12px;margin-bottom:22px}
  .logo{width:44px;height:44px;border-radius:12px;background:linear-gradient(150deg,var(--primary),var(--primary-dark));display:grid;place-items:center;color:#fff;font-weight:700}
  .steps{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
  .steps div{flex:1;min-width:120px;padding:10px 12px;border-radius:10px;border:1px solid var(--border);font-size:12.5px;color:var(--muted)}
  .steps div.active{border-color:var(--primary);background:var(--primary-weak);color:var(--primary-dark);font-weight:600}
  .steps div.past{border-color:var(--ok);color:var(--ok)}
  table.chk{width:100%;border-collapse:collapse;font-size:13.5px}
  table.chk td{padding:9px 10px;border-top:1px solid var(--border);vertical-align:top}
  .ok{color:var(--ok);font-weight:600}
  .bad{color:var(--danger);font-weight:600}
  label{display:block;font-size:13px;font-weight:600;margin:12px 0 5px}
  input[type=text],input[type=password],input[type=number],input[type=email],select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface2)}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
  .btn{display:inline-block;padding:11px 20px;border-radius:11px;border:none;background:var(--primary);color:#fff;font-weight:700;cursor:pointer;font-size:14px;font-family:inherit}
  .btn.sec{background:var(--surface2);color:var(--text);border:1px solid var(--border)}
  .alert{padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:13.5px}
  .alert.err{background:var(--danger-weak);color:var(--danger)}
  .alert.warn{background:var(--warn-weak);color:var(--warn)}
  .alert.ok{background:var(--ok-weak);color:var(--ok)}
  .muted{color:var(--muted);font-size:13px}
  code{background:var(--surface2);padding:2px 6px;border-radius:6px;font-size:12.5px}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <div class="logo">ยบ</div>
    <div>
      <strong style="font-size:16px">ติดตั้งระบบเยี่ยมบ้านนักเรียน</strong><br>
      <span class="muted">วิทยาลัยอาชีวศึกษาร้อยเอ็ด · PHP <?= h(PHP_VERSION) ?></span>
    </div>
  </div>

  <div class="steps">
    <?php foreach ($stepNames as $i => $nm): ?>
      <div class="<?= $i === $step ? 'active' : ($i < $step ? 'past' : '') ?>"><?= $i ?> · <?= h($nm) ?></div>
    <?php endforeach; ?>
  </div>

  <?php if ($fullyInstalled && $step < 4): ?>
    <div class="alert warn">
      ระบบติดตั้งครบถ้วนแล้ว — คุณสามารถ <strong>ติดตั้งซ้ำ / ปรับปรุง</strong> ได้
      โครงสร้างฐานข้อมูลจะถูกปรับปรุงแบบไม่ทำลายข้อมูลเดิม และคีย์ความปลอดภัยเดิมจะถูกเก็บไว้ ·
      <a href="index.php">ไปหน้าเข้าสู่ระบบ</a>
    </div>
  <?php elseif ($state['schema'] && !$state['admin'] && $step === 3): ?>
    <div class="alert ok">ตั้งค่าฐานข้อมูลเรียบร้อยแล้ว — ขั้นตอนสุดท้าย: ระบุข้อมูลผู้ดูแลระบบ</div>
  <?php endif; ?>

  <?php foreach ($errors as $er): ?>
    <div class="alert err"><?= h($er) ?></div>
  <?php endforeach; ?>

  <?php if ($step === 1): ?>
    <div class="card">
      <h3 style="margin-top:0">1 · ตรวจสอบแพ็กเกจซอฟต์แวร์ และสิทธิ์ไฟล์</h3>
      <table class="chk">
        <?php foreach ($reqChecks as $c): ?>
          <tr>
            <td style="width:34%"><strong><?= h($c['label']) ?></strong></td>
            <td style="width:30%" class="muted"><?= h($c['need']) ?></td>
            <td style="width:26%"><?= h($c['current']) ?></td>
            <td style="width:10%" class="<?= $c['ok'] ? 'ok' : 'bad' ?>"><?= $c['ok'] ? 'ผ่าน' : 'ไม่ผ่าน' ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <p class="muted" style="margin-top:16px">
        หากมีรายการไม่ผ่านในส่วนสิทธิ์ไฟล์ ให้กำหนดสิทธิ์เขียนแก่โฟลเดอร์
        <code>config/</code>, <code>storage/</code> แล้วรีเฟรชหน้านี้
      </p>
      <div style="margin-top:18px">
        <?php if ($reqOk): ?>
          <a class="btn" href="install.php?step=2">ถัดไป: ตั้งค่าฐานข้อมูล</a>
        <?php else: ?>
          <a class="btn sec" href="install.php?step=1">ตรวจสอบอีกครั้ง</a>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($step === 2): ?>
    <?php if (!empty($_GET['tested'])): ?>
      <div class="alert ok">เชื่อมต่อฐานข้อมูลสำเร็จ และเวอร์ชันผ่านเกณฑ์</div>
    <?php endif; ?>
    <form method="post" class="card">
      <h3 style="margin-top:0">2 · การเชื่อมต่อ MariaDB</h3>
      <div class="row">
        <div><label>โฮสต์</label><input type="text" name="host" value="<?= h((string)$db['host']) ?>"></div>
        <div><label>พอร์ต</label><input type="number" name="port" value="<?= h((string)$db['port']) ?>"></div>
      </div>
      <div class="row">
        <div><label>ชื่อฐานข้อมูล</label><input type="text" name="name" value="<?= h((string)$db['name']) ?>"></div>
        <div><label>ผู้ใช้</label><input type="text" name="user" value="<?= h((string)$db['user']) ?>"></div>
      </div>
      <label>รหัสผ่านฐานข้อมูล</label>
      <input type="password" name="pass" value="<?= h((string)$db['pass']) ?>">
      <label>โหมดการทำงาน</label>
      <select name="env">
        <option value="production">production (ใช้งานจริง)</option>
        <option value="development" <?= ($existing['app']['env'] ?? '') === 'development' ? 'selected' : '' ?>>development (แสดง error)</option>
      </select>
      <p class="muted">ระบบจะสร้างฐานข้อมูลนี้ให้อัตโนมัติหากยังไม่มี แล้วรัน migration เพื่อสร้าง/ปรับปรุงตาราง</p>
      <div style="margin-top:14px">
        <button class="btn sec" name="action" value="db_test">ทดสอบการเชื่อมต่อ</button>
        <button class="btn" name="action" value="save_db">บันทึกและสร้างฐานข้อมูล → ถัดไป</button>
        <a class="btn sec" href="install.php?step=1">ย้อนกลับ</a>
      </div>
    </form>

  <?php elseif ($step === 3): ?>
    <?php $log = $_SESSION['install_log'] ?? []; ?>
    <?php if ($log): ?>
      <div class="alert ok">
        Migration ที่ดำเนินการ:
        <?php foreach ($log as $r): ?><br>· <?= h($r['filename']) ?> — <?= $r['ok'] ? 'สำเร็จ' : h($r['error'] ?? 'ล้มเหลว') ?><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="post" class="card">
      <h3 style="margin-top:0">3 · ข้อมูลผู้ดูแลระบบ</h3>
      <p class="muted">บัญชีนี้จะใช้เข้าสู่ระบบเพื่อจัดการผู้ใช้ ตรวจสอบสถานะระบบ และจัดการ Migration ฐานข้อมูล</p>

      <?php if ($state['admin']): ?>
        <label style="font-weight:500">
          <input type="checkbox" name="keep_admin" checked onchange="document.getElementById('pwfields').style.display=this.checked?'none':'block'">
          คงบัญชีผู้ดูแลเดิมไว้ (ไม่เปลี่ยนรหัสผ่าน)
        </label>
      <?php endif; ?>

      <div class="row">
        <div><label>ชื่อผู้ใช้ (username)</label><input type="text" name="admin_user" value="<?= h($_POST['admin_user'] ?? 'admin') ?>" required></div>
        <div><label>ชื่อ-นามสกุล</label><input type="text" name="admin_name" value="<?= h($_POST['admin_name'] ?? 'ผู้ดูแลระบบ') ?>" required></div>
      </div>
      <label>อีเมล (ไม่บังคับ)</label>
      <input type="email" name="admin_email" value="<?= h($_POST['admin_email'] ?? '') ?>">

      <div id="pwfields" style="<?= $state['admin'] ? 'display:none' : '' ?>">
        <div class="row">
          <div><label>รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)</label><input type="password" name="admin_pass" autocomplete="new-password"></div>
          <div><label>ยืนยันรหัสผ่าน</label><input type="password" name="admin_pass2" autocomplete="new-password"></div>
        </div>
      </div>

      <label style="font-weight:500;margin-top:14px">
        <input type="checkbox" name="demo" <?= $state['schema'] && !$fullyInstalled ? 'checked' : '' ?>>
        เพิ่มข้อมูลตัวอย่าง (นักเรียน 6 คน + ผู้ใช้ทดสอบตามบทบาท รหัสผ่าน <code>password123</code>)
      </label>

      <div style="margin-top:20px">
        <button class="btn" name="action" value="save_admin">บันทึกผู้ดูแล → เสร็จสิ้น</button>
        <a class="btn sec" href="install.php?step=2">ย้อนกลับ</a>
      </div>
    </form>

  <?php else: ?>
    <?php unset($_SESSION['install_log']); ?>
    <div class="card">
      <div class="alert ok" style="font-size:15px">✓ ติดตั้งเสร็จสมบูรณ์</div>
      <p>ระบบพร้อมใช้งานแล้ว เข้าสู่ระบบด้วยบัญชีผู้ดูแลที่กำหนดไว้</p>
      <p style="margin-top:14px">
        เพื่อความปลอดภัย แนะนำให้ <strong>ลบหรือเปลี่ยนชื่อไฟล์ <code>install.php</code></strong> หลังใช้งานเสร็จ
        (ผู้ดูแลรัน migration ต่อได้จากเมนู "Migration ฐานข้อมูล" ในระบบ)
      </p>
      <a class="btn" href="index.php">เข้าสู่ระบบ</a>
    </div>
  <?php endif; ?>

  <p class="muted" style="text-align:center">ต้องการ PHP <?= h(Support::MIN_PHP) ?>+ และ MariaDB <?= h(Support::MIN_MARIADB) ?>+</p>
</div>
</body>
</html>
