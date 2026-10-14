<?php
/** @var array $ssoDefaults @var array $ssoOverride @var array $ssoEffective @var string $ssoStartUrl @var array $syncInfo */
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

<section class="card pad" style="display:flex;flex-direction:column;gap:8px">
  <strong style="font-size:14px">URL เริ่ม SSO ของแอป</strong>
  <p class="muted" style="font-size:12.5px;margin:0">
    ให้ผู้ดูแล RVC-ONE นำ URL นี้ไปกรอกในช่อง “URL เริ่ม SSO ของแอป” ที่หน้าแก้ไขแอป เพื่อให้ไทล์ในหน้า Workspace พาผู้ใช้เข้าระบบนี้ได้ในคลิกเดียว
  </p>
  <input type="text" value="<?= e($ssoStartUrl) ?>" readonly onclick="this.select()">
</section>

<section class="card pad" style="display:flex;flex-direction:column;gap:10px">
  <strong style="font-size:15px">ซิงก์รายชื่อผู้ใช้จาก RVC-ONE</strong>
  <p class="muted" style="font-size:12.5px;margin:0">
    ดึงรายชื่อครูและบุคลากร (ประเภท <code><?= e($syncInfo['type']) ?></code>) ผ่าน User-sync API มาอัปเดตบัญชีในระบบนี้ —
    จับคู่ด้วยเลขบัตรประชาชนก่อน แล้วด้วย id ของ RVC-ONE (เป็นค่าเดียวกับที่ใช้ล็อกอินผ่าน SSO), ผู้ที่ถูกระงับฝั่ง RVC-ONE
    จะถูกปิดใช้งาน (ยกเว้นผู้ดูแลระบบ) — ตั้ง cron ให้รัน <code>php bin/oa-sync.php</code> เป็นระยะ
    (เช่น <code>*/30 * * * * php /var/www/web/bin/oa-sync.php</code>)
  </p>

  <table class="data">
    <tr><td style="width:220px">Endpoint ที่ใช้</td><td><?= e($syncInfo['endpoint'] ?: '-') ?></td></tr>
    <tr><td>Client secret</td><td><?= $syncInfo['secret_source'] ? 'ตั้งไว้แล้ว (จาก ' . e($syncInfo['secret_source']) . ')' : '<span class="pill warn">ยังไม่ได้ตั้ง</span>' ?></td></tr>
    <tr><td>since ถัดไป</td><td><?= e($syncInfo['since'] ?: '— (รอบถัดไปดึงทั้งหมด)') ?></td></tr>
    <tr><td>ซิงก์สำเร็จล่าสุด</td><td>
      <?php if ($syncInfo['last_at']): $lr = $syncInfo['last_result'] ?? []; ?>
        <?= e($syncInfo['last_at']) ?> · <?= e($lr['mode'] ?? '') ?> ·
        ดึง <?= (int)($lr['fetched'] ?? 0) ?> · เพิ่ม <?= (int)($lr['created'] ?? 0) ?> · อัปเดต <?= (int)($lr['updated'] ?? 0) ?>
        · ระงับ <?= (int)($lr['suspended'] ?? 0) ?> · เปิดคืน <?= (int)($lr['reactivated'] ?? 0) ?> · ข้าม <?= (int)($lr['skipped'] ?? 0) ?>
      <?php else: ?>ยังไม่เคยซิงก์<?php endif; ?>
    </td></tr>
    <?php if ($syncInfo['last_error']): ?>
    <tr><td>ข้อผิดพลาดล่าสุด</td><td style="color:var(--danger)"><?= e($syncInfo['last_error']) ?></td></tr>
    <?php endif; ?>
    <tr><td>ผู้ใช้ที่ผูกกับ RVC-ONE</td><td><?= (int)$syncInfo['linked'] ?> บัญชี</td></tr>
  </table>

  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <form method="post" action="index.php?r=sso">
      <?= csrf_field() ?><input type="hidden" name="action" value="sync_now">
      <button class="btn" type="submit">ซิงก์ตอนนี้ (เฉพาะที่เปลี่ยน)</button>
    </form>
    <form method="post" action="index.php?r=sso" onsubmit="return confirm('ดึงรายชื่อผู้ใช้ทั้งหมดจาก RVC-ONE ใหม่?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="sync_full">
      <button class="btn sec" type="submit">ซิงก์ทั้งหมดใหม่</button>
    </form>
  </div>

  <form method="post" action="index.php?r=sso" style="display:flex;flex-direction:column;gap:14px;margin-top:6px">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_sync">
    <label class="field">
      <span class="lbl">User-sync endpoint <span class="pill warn" style="margin-left:6px">เซิร์ฟเวอร์นี้เรียกเอง</span></span>
      <input type="text" name="oa_sync_endpoint" value="<?= e($syncInfo['endpoint_override']) ?>" placeholder="<?= e($syncInfo['endpoint_default']) ?>">
      <span class="muted" style="font-size:11.5px">เว้นว่างเพื่อใช้ค่าเริ่มต้น — ใส่ Private IP ได้เช่นเดียวกับ Verify endpoint</span>
    </label>
    <label class="field">
      <span class="lbl">Client secret</span>
      <input type="password" name="oa_client_secret" value="" autocomplete="new-password" placeholder="<?= $syncInfo['secret_source'] ? 'ตั้งไว้แล้ว — เว้นว่างเพื่อคงค่าเดิม' : 'วาง client_secret ที่ได้จาก RVC-ONE' ?>">
      <span class="muted" style="font-size:11.5px">
        ระบบไม่แสดงค่าที่บันทึกไว้ · ถ้าตั้ง env <code>OA_CLIENT_SECRET</code> หรือ <code>user_sync.client_secret</code> ใน config/config.php ไว้ จะใช้ค่านั้นก่อน
      </span>
    </label>
    <label style="display:flex;gap:6px;align-items:center;font-size:12.5px"><input type="checkbox" name="clear_secret" value="1"> ลบ client secret ที่บันทึกในหน้านี้</label>
    <div><button class="btn" type="submit">บันทึกการตั้งค่าซิงก์</button></div>
  </form>
</section>
