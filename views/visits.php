<?php /** @var array $visits @var string $filter */
$tabs = ['ทั้งหมด', 'รอเยี่ยม', 'ฉบับร่าง', 'บันทึกแล้ว', 'เกินกำหนด', 'รอตรวจสอบ']; ?>
<div class="chips">
  <?php foreach ($tabs as $t): ?>
    <a class="chip <?= $filter === $t ? 'on' : '' ?>" href="index.php?r=visits&status=<?= urlencode($t) ?>"><?= e($t) ?></a>
  <?php endforeach; ?>
</div>

<div class="grid cards">
  <?php foreach ($visits as $v):
    $lat = $v['lat'] ?: $v['s_lat']; $lng = $v['lng'] ?: $v['s_lng']; ?>
    <article class="card pad" style="display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;gap:11px;align-items:flex-start">
        <span class="avatar" style="width:42px;height:42px;border-radius:12px"><?= e(th_initial($v['full_name'])) ?></span>
        <div style="flex:1;min-width:0">
          <strong style="font-size:14.5px"><?= e($v['prefix'] . $v['full_name']) ?></strong><br>
          <span class="muted" style="font-size:12px"><?= e(($v['level'] ?? '') . '/' . ($v['room'] ?? '') . ' ' . ($v['department'] ?? '')) ?> · <?= e($v['code']) ?></span>
        </div>
        <span class="pill <?= status_pill($v['status']) ?>"><?= e($v['status']) ?></span>
      </div>
      <div style="display:flex;flex-direction:column;gap:5px;font-size:12.5px" class="muted">
        <span>🏠 <?= e($v['address'] ?? '-') ?></span>
        <span>📅 <?= e($v['visit_date'] ?? 'ยังไม่กำหนด') ?> · 👤 <?= e($v['advisor_name'] ?? '-') ?></span>
      </div>
      <div style="display:flex;gap:8px">
        <?php if ($lat && $lng): ?>
          <a class="btn sec sm" style="flex:1" href="<?= e(maps_url($lat, $lng)) ?>" target="_blank" rel="noopener">🧭 นำทาง</a>
        <?php endif; ?>
        <a class="btn sm" style="flex:1" href="index.php?r=visit&id=<?= (int)$v['id'] ?>">บันทึกการเยี่ยม</a>
      </div>
    </article>
  <?php endforeach; ?>
  <?php if (!$visits): ?><p class="muted">ไม่มีรายการในกลุ่มนี้</p><?php endif; ?>
</div>
