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

  <?php if (Sso::enabled()): ?>
    <a href="index.php?r=sso_login" class="btn" style="width:100%;display:flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;box-sizing:border-box">
      🔐 ลงชื่อเข้าใช้ผ่านระบบ ONE-RVC
    </a>
    <div style="display:flex;align-items:center;gap:10px;margin:18px 0;color:var(--faint);font-size:11.5px">
      <span style="flex:1;height:1px;background:var(--border)"></span>หรือเข้าสู่ระบบด้วยบัญชีในระบบนี้<span style="flex:1;height:1px;background:var(--border)"></span>
    </div>
  <?php endif; ?>

  <form method="post" action="index.php?r=login">
    <?= csrf_field() ?>
    <label class="field" style="margin-bottom:12px">
      <span class="lbl">ชื่อผู้ใช้</span>
      <input type="text" name="username" autofocus required autocomplete="username">
    </label>
    <label class="field" style="margin-bottom:18px">
      <span class="lbl">รหัสผ่าน</span>
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn sec" style="width:100%">เข้าสู่ระบบด้วยบัญชีในระบบนี้</button>
  </form>
  <p class="muted" style="font-size:11.5px;margin-top:16px;text-align:center">
    ยังไม่ได้ติดตั้ง? <a href="install.php">เปิดตัวติดตั้ง</a>
  </p>
</div>
