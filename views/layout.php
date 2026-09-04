<?php
/** @var string $__view @var string $__title */
$cfg  = app_config();
$user = Auth::check() ? Auth::user() : null;
$role = Auth::role();
$cur  = $_GET['r'] ?? 'dashboard';

$navBadges = ['visits' => 0, 'reports' => 0, 'migrations' => 0];
if ($user) {
    try {
        $pdo = Database::pdo();
        $scopeIds = teacher_scope_ids($pdo);
        [$scopeCond, $scopeArgs] = scope_where('s.id', $scopeIds);
        $bst = $pdo->prepare(
            "SELECT COUNT(*) FROM visits v JOIN students s ON s.id = v.student_id
             WHERE v.status IN ('รอเยี่ยม','ฉบับร่าง','เกินกำหนด')" . ($scopeCond !== '' ? " AND $scopeCond" : '')
        );
        $bst->execute($scopeArgs);
        $navBadges['visits'] = (int)$bst->fetchColumn();
        $navBadges['reports'] = (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE status IN ('รอตรวจสอบ','ผ่านหัวหน้างาน')")->fetchColumn();
        if ($role === 'admin') {
            $navBadges['migrations'] = count((new Migrator($pdo))->pending());
        }
    } catch (Throwable) {}
}

$groups = [
    'การปฏิบัติงาน' => [
        ['r' => 'dashboard', 'icon' => '▦', 'label' => 'แดชบอร์ด'],
        ['r' => 'visits',    'icon' => '☰', 'label' => 'รายการเยี่ยมบ้าน', 'badge' => $navBadges['visits']],
    ],
    'กำกับติดตาม' => [
        ['r' => 'reports', 'icon' => '✓', 'label' => 'ตรวจสอบ/ลงนาม', 'badge' => $navBadges['reports'], 'roles' => ['head', 'exec', 'admin']],
    ],
    'ผู้ดูแลระบบ' => [
        ['r' => 'users',      'icon' => '👤', 'label' => 'ผู้ใช้ระบบ', 'roles' => ['admin']],
        ['r' => 'rms',        'icon' => '⇄', 'label' => 'โอนข้อมูลจาก RMS', 'roles' => ['admin']],
        ['r' => 'migrations', 'icon' => '⇅', 'label' => 'Migration ฐานข้อมูล', 'badge' => $navBadges['migrations'], 'roles' => ['admin']],
        ['r' => 'system',     'icon' => '⚙', 'label' => 'สถานะระบบ', 'roles' => ['admin']],
    ],
];

$titles = [
    'dashboard' => ['ภาพรวมการเยี่ยมบ้านนักเรียนนักศึกษา', 'วิทยาลัยอาชีวศึกษาร้อยเอ็ด'],
    'visits'    => ['รายการเยี่ยมบ้าน', 'เตรียมข้อมูล นำทาง และบันทึกผลการเยี่ยม'],
    'visit'     => ['บันทึกรายงานการเยี่ยมบ้าน', '8 ขั้นตอน'],
    'reports'   => ['ตรวจสอบและลงนามรายงาน', 'ติดตามการดำเนินการแบบ Realtime'],
    'migrations'=> ['Migration ฐานข้อมูล', 'จัดการการปรับปรุงโครงสร้างฐานข้อมูล'],
    'rms'       => ['โอนข้อมูลจาก RMS', 'บุคลากร · ภาคเรียน · กลุ่มเรียน · นักเรียน (สำหรับงานเยี่ยมบ้าน)'],
    'users'     => ['ผู้ใช้ระบบ', 'จัดการผู้ใช้ และสวมสิทธิ์เพื่อดูมุมมองของผู้ใช้'],
    'system'    => ['สถานะระบบ', 'ตรวจสอบแพ็กเกจและสิทธิ์ไฟล์'],
];
[$pgTitle, $pgSub] = $titles[$__view] ?? [$__title ?: 'ระบบเยี่ยมบ้านนักเรียน', ''];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pgTitle) ?> · ระบบเยี่ยมบ้านนักเรียน</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css">
<script>
  (function(){ var t = localStorage.getItem('rvc-theme'); if (t) document.documentElement.setAttribute('data-theme', t); })();
</script>
</head>
<body>
<?php if ($__view === 'login'): ?>
  <div class="login-wrap"><?php require BASE_PATH . '/views/login.php'; ?></div>
<?php else: ?>
<div class="shell">
  <aside class="sidebar">
    <div class="sb-head">
      <div class="logo">ยบ</div>
      <div style="line-height:1.25">
        <strong style="font-size:14.5px">ระบบเยี่ยมบ้าน</strong><br>
        <span style="font-size:11.5px" class="muted">วอศ.ร้อยเอ็ด</span>
      </div>
    </div>
    <nav class="sb-nav">
      <?php foreach ($groups as $glabel => $items):
        $visible = array_filter($items, fn($it) => empty($it['roles']) || in_array($role, $it['roles'], true));
        if (!$visible) continue; ?>
        <div class="sb-group-label"><?= e($glabel) ?></div>
        <?php foreach ($visible as $it): ?>
          <a class="navbtn <?= $cur === $it['r'] ? 'active' : '' ?>" href="index.php?r=<?= e($it['r']) ?>">
            <span class="ico"><?= $it['icon'] ?></span>
            <span style="flex:1"><?= e($it['label']) ?></span>
            <?php if (!empty($it['badge'])): ?><span class="badge"><?= (int)$it['badge'] ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div style="padding:14px;border-top:1px solid var(--border);font-size:12px" class="muted">
      <?= e($user['full_name'] ?? '') ?><br>
      <?= e(Auth::ROLES[$role] ?? '') ?> · <a href="index.php?r=logout"><?= Auth::isImpersonating() ? 'กลับเป็นผู้ดูแล' : 'ออกจากระบบ' ?></a>
    </div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="title">
        <strong><?= e($pgTitle) ?></strong>
        <span><?= e($pgSub) ?></span>
      </div>
      <span class="realtime"><span class="dot-live"></span>Realtime</span>
      <button class="iconbtn" onclick="rvcTheme()" title="สลับธีม">🌓</button>
      <div class="avatar"><?= $user ? avatar_html($user) : '?' ?></div>
    </header>

    <div class="content">
      <?php if (Auth::isImpersonating()): ?>
        <div class="flash warn" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span style="flex:1">🎭 <strong><?= e(Auth::impersonatorName()) ?></strong> กำลังสวมสิทธิ์เป็น <strong><?= e($user['full_name'] ?? '') ?></strong> (<?= e(Auth::ROLES[$role] ?? '') ?>)</span>
          <a class="btn sec sm" href="index.php?r=logout">กลับเป็นผู้ดูแลระบบ</a>
        </div>
      <?php endif; ?>
      <?php foreach (flash() as $f): ?>
        <div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
      <?php require BASE_PATH . '/views/' . $__view . '.php'; ?>
    </div>

    <nav class="bottomnav">
      <a class="<?= $cur === 'dashboard' ? 'active' : '' ?>" href="index.php?r=dashboard"><span style="font-size:19px">▦</span>แดชบอร์ด</a>
      <a class="<?= $cur === 'visits' ? 'active' : '' ?>" href="index.php?r=visits"><span style="font-size:19px">☰</span>รายการ</a>
      <a class="<?= $cur === 'reports' ? 'active' : '' ?>" href="index.php?r=reports"><span style="font-size:19px">✓</span>ติดตาม</a>
      <?php if ($role === 'admin'): ?>
        <a class="<?= $cur === 'migrations' ? 'active' : '' ?>" href="index.php?r=migrations"><span style="font-size:19px">⇅</span>Migration</a>
      <?php else: ?>
        <a href="index.php?r=dashboard"><span style="font-size:19px">☺</span>ฉัน</a>
      <?php endif; ?>
      <a href="index.php?r=logout"><span style="font-size:19px">⎋</span>ออก</a>
    </nav>
  </main>
</div>
<?php endif; ?>
<script>
function rvcTheme(){
  var el = document.documentElement;
  var cur = el.getAttribute('data-theme') || 'light';
  var next = cur === 'light' ? 'dark' : 'light';
  el.setAttribute('data-theme', next);
  try { localStorage.setItem('rvc-theme', next); } catch(e){}
}
</script>
</body>
</html>
