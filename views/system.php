<?php /** @var array $checks @var array $cfg */ ?>
<section class="card pad">
  <strong style="font-size:15px">ข้อมูลระบบ</strong>
  <table class="data" style="margin-top:10px">
    <tr><td style="width:30%">แอปพลิเคชัน</td><td><?= e($cfg['app']['name'] ?? '-') ?></td></tr>
    <tr><td>โหมด</td><td><?= e($cfg['app']['env'] ?? '-') ?></td></tr>
    <tr><td>ติดตั้งเมื่อ</td><td><?= e($cfg['app']['installed_at'] ?? '-') ?></td></tr>
    <tr><td>PHP</td><td><?= e(PHP_VERSION) ?></td></tr>
    <tr><td>ฐานข้อมูล</td><td><?= e($cfg['db']['name'] . ' @ ' . $cfg['db']['host'] . ':' . $cfg['db']['port']) ?></td></tr>
  </table>
</section>

<section class="card">
  <div style="padding:16px 18px;border-bottom:1px solid var(--border)">
    <strong style="font-size:14.5px">ตรวจสอบแพ็กเกจซอฟต์แวร์ และสิทธิ์อ่าน/เขียนไฟล์</strong>
  </div>
  <div class="tablewrap">
    <table class="data">
      <thead><tr><th>รายการ</th><th>ต้องการ</th><th>ปัจจุบัน</th><th>ผล</th></tr></thead>
      <tbody>
        <?php foreach ($checks as $c): ?>
          <tr>
            <td><strong><?= e($c['label']) ?></strong></td>
            <td class="muted"><?= e($c['need']) ?></td>
            <td><?= e($c['current']) ?></td>
            <td><span class="pill <?= $c['ok'] ? 'ok' : 'danger' ?>"><?= $c['ok'] ? 'ผ่าน' : 'ไม่ผ่าน' ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<p class="muted" style="font-size:12px">หากต้องการตั้งค่าการเชื่อมต่อฐานข้อมูลใหม่ หรือรีเซ็ตผู้ดูแล ให้เปิด <code>install.php</code> อีกครั้ง (รองรับการติดตั้งซ้ำ)</p>
