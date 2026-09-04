<?php /** @var array $pending @var array $counts */
$role = Auth::role();
$cards = [
    ['label' => 'รอตรวจสอบ (หัวหน้างาน)', 'value' => $counts['review'], 'tag' => 'ขั้นหัวหน้างาน', 'c' => 'primary'],
    ['label' => 'รอลงนามผู้บริหาร', 'value' => $counts['sign'], 'tag' => 'ขั้นผู้บริหาร', 'c' => 'warn'],
    ['label' => 'ลงนามแล้ว', 'value' => $counts['done'], 'tag' => 'เสร็จสิ้น', 'c' => 'ok'],
];
?>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
  <?php foreach ($cards as $a): ?>
    <div class="stat">
      <span class="muted" style="font-size:12.5px"><?= e($a['label']) ?></span>
      <strong style="font-size:26px"><?= (int)$a['value'] ?></strong>
      <span class="pill <?= e($a['c']) ?>"><?= e($a['tag']) ?></span>
    </div>
  <?php endforeach; ?>
</div>

<section class="card">
  <div style="padding:16px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between">
    <strong style="font-size:14.5px">รายงานรอตรวจสอบ / ลงนาม</strong>
    <span class="muted" style="font-size:11.5px"><?= e(Auth::ROLES[$role] ?? '') ?></span>
  </div>
  <div>
    <?php foreach ($pending as $r): ?>
      <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:14px 18px;border-top:1px solid var(--border)">
        <span class="avatar" style="width:34px;height:34px;border-radius:10px"><?= e(th_initial($r['full_name'])) ?></span>
        <div style="flex:1;min-width:170px">
          <strong style="font-size:13.5px"><?= e($r['prefix'] . $r['full_name']) ?></strong><br>
          <span class="muted" style="font-size:11.5px"><?= e(($r['level'] ?? '') . '/' . ($r['room'] ?? '') . ' ' . ($r['department'] ?? '')) ?> · ครูที่ปรึกษา <?= e($r['advisor_name'] ?? '-') ?> · <?= e($r['status']) ?></span>
        </div>
        <?php if ($r['urgent']): ?><span class="pill danger">เร่งด่วน</span><?php endif; ?>
        <span class="pill <?= risk_pill($r['risk_group']) ?>"><?= e($r['risk_group']) ?></span>
        <form method="post" action="index.php?r=reports" style="display:flex;gap:7px">
          <?= csrf_field() ?>
          <input type="hidden" name="visit_id" value="<?= (int)$r['id'] ?>">
          <a class="btn sec sm" href="index.php?r=visit&id=<?= (int)$r['id'] ?>">ดูรายงาน</a>
          <?php if ($r['status'] === 'รอตรวจสอบ' && in_array($role, ['head', 'admin'], true)): ?>
            <button class="btn ok sm" name="action" value="head_ok">หัวหน้างานรับทราบ</button>
          <?php elseif ($r['status'] === 'ผ่านหัวหน้างาน' && in_array($role, ['exec', 'admin'], true)): ?>
            <button class="btn ok sm" name="action" value="exec_ok">ผู้บริหารลงนาม</button>
          <?php endif; ?>
          <button class="btn sec sm" name="action" value="return">ตีกลับ</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (!$pending): ?><div style="padding:18px" class="muted">ไม่มีรายงานที่รอดำเนินการ</div><?php endif; ?>
  </div>
</section>
