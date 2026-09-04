<?php
/** @var array $visit @var array $steps @var array $data @var int $step */
$s = $steps[$step - 1];
$val = fn($id) => $data[$id] ?? null;
$lat = $visit['lat'] ?: $visit['s_lat'] ?: 16.0538;
$lng = $visit['lng'] ?: $visit['s_lng'] ?: 103.6531;
$photos = [
    ['icon' => '🏠', 'label' => 'ภาพบ้านนักเรียนนักศึกษา', 'hint' => 'เห็นตัวบ้านและสภาพแวดล้อม'],
    ['icon' => '👨‍👩‍👧', 'label' => 'ภาพนักเรียนพร้อมครอบครัว', 'hint' => 'ถ่ายที่บ้านพักอาศัย'],
    ['icon' => '🧑‍🏫', 'label' => 'ภาพครอบครัวพร้อมครูที่ปรึกษา', 'hint' => 'หลักฐานการเยี่ยมบ้าน'],
];
?>
<?php if (!empty($_GET['sent'])): ?>
  <div class="flash ok">ส่งรายงานการเยี่ยมบ้านเรียบร้อย — สถานะเปลี่ยนเป็น "รอตรวจสอบ"</div>
<?php endif; ?>

<div class="card pad" style="display:flex;flex-direction:column;gap:12px">
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <span class="avatar" style="width:44px;height:44px;border-radius:12px"><?= e(th_initial($visit['full_name'])) ?></span>
    <div style="flex:1;min-width:180px">
      <strong style="font-size:15px"><?= e($visit['prefix'] . $visit['full_name']) ?></strong><br>
      <span class="muted" style="font-size:12px"><?= e(($visit['level'] ?? '') . '/' . ($visit['room'] ?? '') . ' ' . ($visit['department'] ?? '')) ?> · ครูที่ปรึกษา <?= e($visit['advisor_name'] ?? '-') ?></span>
    </div>
    <span class="muted" style="font-size:12.5px">ขั้นตอน <?= $step ?>/8 · <span class="pill <?= status_pill($visit['status']) ?>"><?= e($visit['status']) ?></span></span>
  </div>
  <div class="progress"><i style="width:<?= round($step / 8 * 100) ?>%;background:linear-gradient(90deg,var(--primary),var(--primary-dark))"></i></div>
  <form method="post" id="gotoForm" action="index.php?r=visit&id=<?= (int)$visit['id'] ?>&step=<?= $step ?>">
    <?= csrf_field() ?><input type="hidden" name="act" value="goto"><input type="hidden" name="step_to" id="step_to">
  </form>
  <div class="stepchips">
    <?php foreach ($steps as $i => $st): $n = $i + 1;
      $cls = $n === $step ? 'active' : ($n < $step ? 'done' : ''); ?>
      <button type="button" class="stepchip <?= $cls ?>" onclick="rvcGoto(<?= $n ?>)">
        <span class="n"><?= $n ?></span><?= e($st['short']) ?>
      </button>
    <?php endforeach; ?>
  </div>
</div>

<form method="post" action="index.php?r=visit&id=<?= (int)$visit['id'] ?>&step=<?= $step ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="lat" id="lat" value="<?= e((string)$lat) ?>">
  <input type="hidden" name="lng" id="lng" value="<?= e((string)$lng) ?>">

  <section class="card pad" style="display:flex;flex-direction:column;gap:18px">
    <div>
      <span style="font-size:11.5px;letter-spacing:.08em;text-transform:uppercase;color:var(--primary-dark);font-weight:600">ขั้นตอนที่ <?= $step ?></span><br>
      <strong style="font-size:19px"><?= e($s['title']) ?></strong><br>
      <span class="muted" style="font-size:12.5px"><?= e($s['desc']) ?></span>
    </div>

    <div class="grid fields">
      <?php foreach ($s['fields'] as $f):
        $v = $val($f['id']);
        $full = !empty($f['full']) || in_array($f['type'], ['area', 'chips', 'map', 'photos'], true); ?>
        <label class="field <?= $full ? 'full' : '' ?>">
          <span class="lbl"><?= e($f['label']) ?><?php if (!empty($f['required'])): ?><span style="color:var(--danger)">*</span><?php endif; ?></span>

          <?php if (in_array($f['type'], ['text', 'number', 'date', 'time', 'tel'], true)): ?>
            <input type="<?= $f['type'] === 'text' ? 'text' : e($f['type']) ?>" name="f[<?= e($f['id']) ?>]"
                   value="<?= e(is_array($v) ? implode(', ', $v) : (string)$v) ?>" placeholder="<?= e($f['placeholder'] ?? '') ?>">

          <?php elseif ($f['type'] === 'area'): ?>
            <textarea name="f[<?= e($f['id']) ?>]" rows="4" placeholder="<?= e($f['placeholder'] ?? '') ?>"><?= e((string)$v) ?></textarea>

          <?php elseif ($f['type'] === 'select'): ?>
            <select name="f[<?= e($f['id']) ?>]">
              <option value="">— เลือก —</option>
              <?php foreach ($f['options'] as $o): ?>
                <option value="<?= e($o) ?>" <?= (string)$v === $o ? 'selected' : '' ?>><?= e($o) ?></option>
              <?php endforeach; ?>
            </select>

          <?php elseif ($f['type'] === 'chips'): $arr = is_array($v) ? $v : ($v !== null && $v !== '' ? [$v] : []); ?>
            <div class="chips">
              <?php foreach ($f['chips'] as $c): ?>
                <label class="chip <?= in_array($c, $arr, true) ? 'on' : '' ?>">
                  <input type="checkbox" name="f[<?= e($f['id']) ?>][]" value="<?= e($c) ?>" style="display:none"
                         <?= in_array($c, $arr, true) ? 'checked' : '' ?> onchange="this.parentNode.classList.toggle('on',this.checked)">
                  <?= e($c) ?>
                </label>
              <?php endforeach; ?>
            </div>

          <?php elseif ($f['type'] === 'map'): ?>
            <div style="display:flex;flex-direction:column;gap:10px">
              <div style="height:150px;border-radius:12px;border:1px solid var(--border);background:linear-gradient(160deg,var(--primary-weak),var(--surface2));display:grid;place-items:center;color:var(--muted);font-size:12.5px">
                📍 พิกัดบ้าน: <span id="coordText"><?= e(number_format((float)$lat, 5)) ?>, <?= e(number_format((float)$lng, 5)) ?></span>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn sec sm" style="flex:1;min-width:150px" onclick="rvcLocate()">📍 ปักหมุดตำแหน่งปัจจุบัน</button>
                <a class="btn sm" style="flex:1;min-width:150px" id="mapLink" href="<?= e(maps_url($lat, $lng)) ?>" target="_blank" rel="noopener">🧭 เปิด Google Maps</a>
              </div>
            </div>

          <?php elseif ($f['type'] === 'photos'): ?>
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
              <?php foreach ($photos as $p): ?>
                <div style="height:120px;border-radius:12px;border:1.5px dashed var(--border);background:var(--surface2);display:grid;place-items:center;text-align:center;padding:10px;font-size:12px" class="muted">
                  <div><?= $p['icon'] ?><br><strong><?= e($p['label']) ?></strong><br><span style="font-size:10.5px"><?= e($p['hint']) ?></span></div>
                </div>
              <?php endforeach; ?>
            </div>
            <span class="muted" style="font-size:11.5px">อัปโหลดภาพจริงได้ในหน้าแก้ไขรายงาน (ต้องเปิดสิทธิ์เขียนโฟลเดอร์ storage/uploads)</span>
          <?php endif; ?>

          <?php if (!empty($f['help'])): ?><span class="muted" style="font-size:11.5px"><?= e($f['help']) ?></span><?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;padding-top:6px;border-top:1px solid var(--border)">
      <button type="submit" name="act" value="prev" class="btn sec" <?= $step === 1 ? 'disabled' : '' ?>>ย้อนกลับ</button>
      <button type="submit" name="act" value="draft" class="btn sec">บันทึกฉบับร่าง</button>
      <?php if ($step === 8): ?>
        <button type="submit" name="act" value="submit" class="btn" style="flex:1;min-width:180px">ส่งรายงานการเยี่ยมบ้าน</button>
      <?php else: ?>
        <button type="submit" name="act" value="next" class="btn" style="flex:1;min-width:180px">ถัดไป: <?= e($steps[$step]['short']) ?></button>
      <?php endif; ?>
    </div>
  </section>
</form>

<script>
function rvcGoto(n){ document.getElementById('step_to').value = n; document.getElementById('gotoForm').submit(); }
function rvcLocate(){
  if(!navigator.geolocation) return alert('อุปกรณ์ไม่รองรับ GPS');
  navigator.geolocation.getCurrentPosition(function(p){
    var la = p.coords.latitude, ln = p.coords.longitude;
    document.getElementById('lat').value = la;
    document.getElementById('lng').value = ln;
    var t = document.getElementById('coordText'); if(t) t.textContent = la.toFixed(5)+', '+ln.toFixed(5);
    var l = document.getElementById('mapLink'); if(l) l.href = 'https://www.google.com/maps/dir/?api=1&destination='+la+','+ln+'&travelmode=driving';
  }, function(){ alert('ไม่สามารถอ่านตำแหน่งได้'); });
}
</script>
