<div class="login-card">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
    <div class="logo" style="width:44px;height:44px">ยบ</div>
    <div style="line-height:1.3">
      <strong style="font-size:16px">ระบบเยี่ยมบ้านนักเรียน</strong><br>
      <span class="muted" style="font-size:12px">วิทยาลัยอาชีวศึกษาร้อยเอ็ด</span>
    </div>
  </div>

  <?php foreach (flash() as $f): ?>
    <div class="flash <?= e($f['type']) ?>" style="margin-bottom:14px"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>

<?php
  /** @var bool $adminFallback */
  $adminFallback = !empty($adminFallback);
  $showSso = Auth::ssoAllowed() && !$adminFallback;
  $showPassword = Auth::passwordAllowed() || $adminFallback;
?>
  <?php if ($adminFallback): ?>
    <div class="flash warn" style="margin-bottom:14px">
      ช่องทางสำรองสำหรับ<strong>ผู้ดูแลระบบ</strong>เท่านั้น (ใช้เมื่อระบบ RVC-ONE ใช้งานไม่ได้) —
      <a href="index.php?r=login">กลับไปลงชื่อเข้าใช้ผ่าน RVC-ONE</a>
    </div>
  <?php endif; ?>

  <?php if ($showSso): ?>
    <a href="index.php?r=sso_login" class="btn" style="width:100%;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;box-sizing:border-box">
      🔐 ลงชื่อเข้าใช้ผ่านระบบ RVC-ONE
    </a>
    <?php if ($showPassword): ?>
    <div style="display:flex;align-items:center;gap:10px;margin:18px 0;color:var(--faint);font-size:11.5px">
      <span style="flex:1;height:1px;background:var(--border)"></span>หรือเข้าสู่ระบบด้วยบัญชีในระบบนี้<span style="flex:1;height:1px;background:var(--border)"></span>
    </div>
    <?php else: ?>
    <p class="muted" style="font-size:11.5px;margin:16px 0 0;text-align:center">
      <a href="index.php?r=login&amp;admin=1">ผู้ดูแลระบบ: ลงชื่อเข้าใช้ด้วยรหัสผ่าน</a>
    </p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($showPassword): ?>
  <form method="post" action="index.php?r=login<?= $adminFallback ? '&amp;admin=1' : '' ?>">
    <?= csrf_field() ?>
    <?php if ($adminFallback): ?><input type="hidden" name="admin" value="1"><?php endif; ?>
    <label class="field" style="margin-bottom:12px">
      <span class="lbl">ชื่อผู้ใช้</span>
      <input type="text" name="username" autofocus required autocomplete="username">
    </label>
    <label class="field" style="margin-bottom:18px">
      <span class="lbl">รหัสผ่าน</span>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn <?= $showSso ? 'sec' : '' ?>" style="width:100%"><?= $adminFallback ? 'เข้าสู่ระบบ (ผู้ดูแลระบบ)' : 'เข้าสู่ระบบด้วยบัญชีในระบบนี้' ?></button>
  </form>
  <?php endif; ?>
  <p class="muted" style="font-size:11.5px;margin-top:16px;text-align:center">
    ยังไม่ได้ติดตั้ง? <a href="install.php">เปิดตัวติดตั้ง</a>
  </p>
</div>
