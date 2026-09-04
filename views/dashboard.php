<?php
/** @var array $stats @var array $depts @var array $feed @var array $visits */
$pct = fn($a, $b) => $b > 0 ? round($a / $b * 100) : 0;
$cards = [
    ['icon' => '👥', 'label' => 'นักเรียนที่ต้องเยี่ยม', 'value' => number_format($stats['total']), 'unit' => 'คน', 'p' => 100, 'c' => 'var(--primary)'],
    ['icon' => '✔', 'label' => 'เยี่ยม/บันทึกครบ', 'value' => number_format($stats['done']), 'unit' => 'คน', 'p' => $pct($stats['done'], $stats['total']), 'c' => 'var(--ok)'],
    ['icon' => '⏱', 'label' => 'รอดำเนินการ', 'value' => number_format($stats['pending']), 'unit' => 'คน', 'p' => $pct($stats['pending'], $stats['total']), 'c' => 'var(--warn)'],
    ['icon' => '⚠', 'label' => 'ช่วยเหลือเร่งด่วน', 'value' => number_format($stats['urgent']), 'unit' => 'ราย', 'p' => $pct($stats['urgent'], max(1, $stats['total'])), 'c' => 'var(--danger)'],
];
?>
<div class="grid stats">
  <?php foreach ($cards as $s): ?>
    <div class="stat">
      <div style="display:flex;align-items:center;gap:8px">
        <span class="ico"><?= $s['icon'] ?></span>
        <span style="font-size:12.5px" class="muted"><?= e($s['label']) ?></span>
      </div>
      <div style="display:flex;align-items:baseline;gap:7px">
        <strong class="num"><?= e($s['value']) ?></strong><span class="muted" style="font-size:12px"><?= e($s['unit']) ?></span>
      </div>
      <div class="progress"><i style="width:<?= (float)$s['p'] ?>%;background:<?= $s['c'] ?>"></i></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid two">
  <section class="card pad">
    <div style="display:flex;justify-content:space-between;margin-bottom:14px">
      <strong style="font-size:14.5px">ความคืบหน้ารายแผนกวิชา</strong>
      <span class="muted" style="font-size:11.5px">ภาคเรียน 1/2569</span>
    </div>
    <div style="display:flex;flex-direction:column;gap:12px">
      <?php foreach ($depts as $d): $p = (float)($d['pct'] ?? 0);
        $col = $p >= 85 ? 'var(--ok)' : ($p >= 70 ? 'var(--primary)' : 'var(--warn)'); ?>
        <div style="display:flex;flex-direction:column;gap:5px">
          <div style="display:flex;justify-content:space-between;font-size:12.5px">
            <span><?= e($d['name']) ?></span><span class="muted"><?= (int)$p ?>%</span>
          </div>
          <div class="progress" style="height:9px"><i style="width:<?= $p ?>%;background:<?= $col ?>"></i></div>
        </div>
      <?php endforeach; ?>
      <?php if (!$depts): ?><span class="muted" style="font-size:12.5px">ยังไม่มีข้อมูล</span><?php endif; ?>
    </div>
  </section>

  <section class="card pad">
    <div style="display:flex;justify-content:space-between;margin-bottom:10px">
      <strong style="font-size:14.5px">กิจกรรมล่าสุด</strong>
      <span style="font-size:11.5px;color:var(--ok);font-weight:600">อัปเดตสด</span>
    </div>
    <div>
      <?php foreach ($feed as $f): ?>
        <div style="display:flex;gap:11px;padding:10px 0;border-bottom:1px solid var(--border)">
          <span style="width:9px;height:9px;border-radius:50%;background:var(--primary);margin-top:6px;flex:0 0 9px"></span>
          <div style="min-width:0">
            <div style="font-size:13px;line-height:1.45"><?= e($f['text']) ?></div>
            <div style="font-size:11px;color:var(--faint)"><?= e($f['full_name'] ?? 'ระบบ') ?> · <?= e($f['created_at']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$feed): ?><span class="muted" style="font-size:12.5px">ยังไม่มีกิจกรรม</span><?php endif; ?>
    </div>
  </section>
</div>

<section class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--border)">
    <strong style="font-size:14.5px">รายการเยี่ยมบ้านที่ต้องติดตาม</strong>
    <a class="btn sec sm" href="index.php?r=visits">ดูทั้งหมด</a>
  </div>
  <div class="tablewrap">
    <table class="data" style="min-width:720px">
      <thead><tr>
        <th>นักเรียน/นักศึกษา</th><th>ระดับชั้น/แผนก</th><th>ครูที่ปรึกษา</th><th>กำหนดเยี่ยม</th><th>สถานะ</th><th>กลุ่มคัดกรอง</th>
      </tr></thead>
      <tbody>
        <?php foreach ($visits as $v): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <span class="avatar" style="width:30px;height:30px;font-size:12px"><?= e(th_initial($v['full_name'])) ?></span>
                <a href="index.php?r=visit&id=<?= (int)$v['id'] ?>" style="font-weight:500"><?= e($v['prefix'] . $v['full_name']) ?></a>
              </div>
            </td>
            <td class="muted"><?= e(trim(($v['level'] ?? '') . '/' . ($v['room'] ?? '') . ' ' . ($v['department'] ?? ''))) ?></td>
            <td class="muted"><?= e($v['advisor_name'] ?? '-') ?></td>
            <td class="muted"><?= e($v['visit_date'] ?? '-') ?></td>
            <td><span class="pill <?= status_pill($v['status']) ?>"><?= e($v['status']) ?></span></td>
            <td><span class="pill <?= risk_pill($v['risk_group']) ?>"><?= e($v['risk_group']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
