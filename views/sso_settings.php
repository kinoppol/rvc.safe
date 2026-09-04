<?php
/** @var array $ssoDefaults @var array $ssoOverride @var array $ssoEffective */
?>
<section class="card pad" style="display:flex;flex-direction:column;gap:10px">
  <strong style="font-size:15px">ตั้งค่าการเชื่อมต่อ ONE-RVC (SSO)</strong>
  <p class="muted" style="font-size:12.5px;margin:0">
    บางเครือข่ายเข้าโดเมนสาธารณะของ ONE-RVC จากฝั่งเซิร์ฟเวอร์ไม่ได้ (เช่น เซิร์ฟเวอร์นี้อยู่ bridge/เครือข่ายภายใน
    เดียวกันกับเซิร์ฟเวอร์ ONE-RVC) ทำให้ต้องเรียกด้วย <strong>Private IP</strong> แทนโดเมนสำหรับการเชื่อมต่อฝั่งเซิร์ฟเวอร์
    หน้านี้ให้ผู้ดูแลปรับ endpoint ได้โดยไม่ต้องแก้โค้ด — เว้นว่างช่องไหนไว้จะใช้ค่าเริ่มต้นของระบบ
  </p>

  <form method="post" action="index.php?r=sso" style="display:flex;flex-direction:column;gap:14px;margin-top:6px">
    <?= csrf_field() ?>

    <label class="field">
      <span class="lbl">Authorize endpoint <span class="pill primary" style="margin-left:6px">เบราว์เซอร์ผู้ใช้เรียก</span></span>
      <input type="text" name="authorize_endpoint" value="<?= e($ssoOverride['authorize_endpoint']) ?>" placeholder="<?= e($ssoDefaults['authorize_endpoint'] ?? '') ?>">
      <span class="muted" style="font-size:11.5px">
        URL หน้าล็อกอินของ ONE-RVC ที่ผู้ใช้จะถูก redirect ไป — ต้องเป็น<strong>โดเมนสาธารณะ</strong>เสมอ
        (เบราว์เซอร์ของผู้ใช้เข้าถึงตรงนี้ ไม่ใช่เซิร์ฟเวอร์)
      </span>
    </label>

    <label class="field">
      <span class="lbl">Verify endpoint <span class="pill warn" style="margin-left:6px">เซิร์ฟเวอร์นี้เรียกเอง</span></span>
      <input type="text" name="verify_endpoint" value="<?= e($ssoOverride['verify_endpoint']) ?>" placeholder="<?= e($ssoDefaults['verify_endpoint'] ?? '') ?>">
      <span class="muted" style="font-size:11.5px">
        URL ตรวจสอบ token_id/token_key ที่<strong>เซิร์ฟเวอร์นี้เรียกเอง</strong> (ไม่ผ่านเบราว์เซอร์) —
        ใส่ <strong>Private IP</strong> ตรงนี้ได้ถ้าเซิร์ฟเวอร์เข้าโดเมนสาธารณะไม่ได้ เช่น <code>http://192.168.10.109/oa/api/verify_token.php</code>
      </span>
    </label>

    <label class="field">
      <span class="lbl">Client ID</span>
      <input type="text" name="client_id" value="<?= e($ssoOverride['client_id']) ?>" placeholder="<?= e($ssoDefaults['client_id'] ?? '') ?>">
      <span class="muted" style="font-size:11.5px">ปกติไม่ต้องแก้ เว้นแต่ได้รับ client_id ใหม่จากผู้ดูแล ONE-RVC</span>
    </label>

    <label class="field">
      <span class="lbl">Redirect URI <span class="pill muted" style="margin-left:6px">คงที่ แก้ไม่ได้</span></span>
      <input type="text" value="<?= e($ssoDefaults['redirect_uri'] ?? '') ?>" disabled style="opacity:.7">
      <span class="muted" style="font-size:11.5px">ต้องตรงกับ URL ที่ลงทะเบียนไว้กับ ONE-RVC เป๊ะทุกตัวอักษร แก้ที่นี่ไม่ได้โดยเจตนา — เปลี่ยนต้องแก้ config/config.sample.php และลงทะเบียนใหม่</span>
    </label>

    <div>
      <button class="btn" type="submit">บันทึกการตั้งค่า</button>
    </div>
  </form>
</section>

<section class="card pad" style="display:flex;flex-direction:column;gap:8px">
  <strong style="font-size:14px">ค่าที่ใช้งานจริงตอนนี้</strong>
  <table class="data">
    <tr><td style="width:220px">Authorize endpoint</td><td><?= e($ssoEffective['authorize_endpoint'] ?? '-') ?></td></tr>
    <tr><td>Verify endpoint</td><td><?= e($ssoEffective['verify_endpoint'] ?? '-') ?></td></tr>
    <tr><td>Client ID</td><td><?= e($ssoEffective['client_id'] ?? '-') ?></td></tr>
    <tr><td>Redirect URI</td><td><?= e($ssoEffective['redirect_uri'] ?? '-') ?></td></tr>
  </table>
</section>
