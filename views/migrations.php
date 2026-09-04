<?php /** @var array $status @var int $pendingCount @var array $log */ ?>
<section class="card pad" style="display:flex;flex-direction:column;gap:12px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      <strong style="font-size:15px">Migration ฐานข้อมูล</strong><br>
      <span class="muted" style="font-size:12.5px">
        ทั้งหมด <?= count($status) ?> รายการ ·
        <?php if ($pendingCount): ?><span style="color:var(--warn);font-weight:600">ค้าง <?= $pendingCount ?> รายการ</span><?php else: ?><span style="color:var(--ok);font-weight:600">เป็นปัจจุบัน</span><?php endif; ?>
      </span>
    </div>
    <form method="post" action="index.php?r=migrations">
      <?= csrf_field() ?>
      <button class="btn" name="action" value="run_pending" <?= $pendingCount ? '' : 'disabled' ?>
        onclick="return confirm('รัน migration ที่ค้างทั้งหมด?')">รัน migration ที่ค้าง (<?= $pendingCount ?>)</button>
    </form>
  </div>

  <?php if ($log): ?>
    <div class="flash ok" style="display:flex;flex-direction:column;gap:4px">
      <?php foreach ($log as $l): ?>
        <span><?= $l['ok'] ? '✓' : '✗' ?> <?= e($l['filename']) ?>
          <?= $l['ok'] ? '(' . (int)($l['exec_ms'] ?? 0) . ' ms)' : '— ' . e($l['error'] ?? 'ผิดพลาด') ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <div class="tablewrap">
    <table class="data" style="min-width:760px">
      <thead><tr>
        <th>เวอร์ชัน</th><th>ไฟล์</th><th>สถานะ</th><th>ดำเนินการเมื่อ</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($status as $m): ?>
          <tr>
            <td style="font-family:monospace"><?= e($m['version']) ?></td>
            <td><?= e($m['filename']) ?>
              <?php if (!empty($m['orphan'])): ?><span class="pill warn" style="margin-left:6px">ไม่พบไฟล์</span><?php endif; ?>
              <?php if (!empty($m['changed'])): ?><span class="pill danger" style="margin-left:6px">ไฟล์เปลี่ยนหลัง apply</span><?php endif; ?>
            </td>
            <td>
              <?php if ($m['applied']): ?><span class="pill ok">apply แล้ว</span>
              <?php else: ?><span class="pill warn">ค้าง</span><?php endif; ?>
            </td>
            <td class="muted"><?= e($m['applied_at'] ?? '—') ?><?= $m['exec_ms'] !== null ? ' · ' . (int)$m['exec_ms'] . ' ms' : '' ?></td>
            <td style="text-align:right">
              <?php if (!$m['applied'] && empty($m['orphan'])): ?>
                <form method="post" action="index.php?r=migrations" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="version" value="<?= e($m['version']) ?>">
                  <button class="btn sec sm" name="action" value="run_one">รันเฉพาะรายการนี้</button>
                </form>
              <?php endif; ?>
              <?php if ($m['sql']): ?>
                <button class="btn sec sm" type="button" onclick="var d=document.getElementById('sql-<?= e($m['version']) ?>');d.hidden=!d.hidden">ดู SQL</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($m['sql']): ?>
            <tr id="sql-<?= e($m['version']) ?>" hidden>
              <td colspan="5"><pre style="margin:0;white-space:pre-wrap;font-size:12px;background:var(--surface2);padding:12px;border-radius:8px;overflow-x:auto"><?= e($m['sql']) ?></pre></td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<p class="muted" style="font-size:12px">
  วิธีเพิ่ม migration ใหม่: สร้างไฟล์ <code>migrations/NNNN_ชื่อ.sql</code> (เช่น <code>0004_add_column.sql</code>)
  โดยใช้คำสั่งแบบ idempotent เช่น <code>CREATE TABLE IF NOT EXISTS</code>, <code>ALTER TABLE ... ADD COLUMN IF NOT EXISTS</code>
  แล้วกลับมากดปุ่ม "รัน migration ที่ค้าง"
</p>
