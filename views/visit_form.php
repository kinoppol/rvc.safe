<?php
/** @var array $visit @var array $steps @var array $data @var int $step @var array $photoKinds @var array $docs */
$s = $steps[$step - 1];
$val = fn($id) => $data[$id] ?? null;
$lat = $visit['lat'] ?: $visit['s_lat'] ?: 16.0538;
$lng = $visit['lng'] ?: $visit['s_lng'] ?: 103.6531;
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

<form method="post" action="index.php?r=visit&id=<?= (int)$visit['id'] ?>&step=<?= $step ?>" enctype="multipart/form-data">
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
        $full = !empty($f['full']) || in_array($f['type'], ['area', 'chips', 'map', 'photos', 'files'], true);
        $showIf = $f['showIf'] ?? null;
        $showIfAttr = '';
        if ($showIf) {
            $ctrlVal = $val($showIf[0]);
            $ctrlArr = is_array($ctrlVal) ? $ctrlVal : [$ctrlVal];
            $visible = in_array($showIf[1], $ctrlArr, true);
            $showIfAttr = ' data-show-if="' . e($showIf[0]) . '" data-show-val="' . e($showIf[1]) . '"' . ($visible ? '' : ' hidden');
        }
        ?>
        <label class="field <?= $full ? 'full' : '' ?>"<?= $showIfAttr ?>>
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
            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
            <div style="display:flex;flex-direction:column;gap:10px">
              <div id="leafletMap" style="height:220px;border-radius:12px;border:1px solid var(--border)"></div>
              <span class="muted" style="font-size:11.5px">📍 พิกัดบ้าน: <span id="coordText"><?= e(number_format((float)$lat, 5)) ?>, <?= e(number_format((float)$lng, 5)) ?></span> · แตะบนแผนที่หรือลากหมุดเพื่อปรับตำแหน่ง</span>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn sec sm" style="flex:1;min-width:150px" onclick="rvcLocate()">📍 ปักหมุดตำแหน่งปัจจุบัน</button>
                <a class="btn sm" style="flex:1;min-width:150px" id="mapLink" href="<?= e(maps_url($lat, $lng)) ?>" target="_blank" rel="noopener">🧭 เปิด Google Maps</a>
              </div>
            </div>
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
            <script>
              (function(){
                var lat0 = <?= json_encode((float)$lat) ?>, lng0 = <?= json_encode((float)$lng) ?>;
                var map = L.map('leafletMap').setView([lat0, lng0], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                  maxZoom: 19, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);
                var marker = L.marker([lat0, lng0], { draggable: true }).addTo(map);
                function rvcSetCoord(la, ln){
                  document.getElementById('lat').value = la;
                  document.getElementById('lng').value = ln;
                  var t = document.getElementById('coordText'); if (t) t.textContent = la.toFixed(5) + ', ' + ln.toFixed(5);
                  var l = document.getElementById('mapLink'); if (l) l.href = 'https://www.google.com/maps/dir/?api=1&destination=' + la + ',' + ln + '&travelmode=driving';
                }
                marker.on('dragend', function(){ var p = marker.getLatLng(); rvcSetCoord(p.lat, p.lng); });
                map.on('click', function(e){ marker.setLatLng(e.latlng); rvcSetCoord(e.latlng.lat, e.latlng.lng); });
                window.rvcLocate = function(){
                  if (!navigator.geolocation) { alert('อุปกรณ์ไม่รองรับ GPS'); return; }
                  navigator.geolocation.getCurrentPosition(function(p){
                    var la = p.coords.latitude, ln = p.coords.longitude;
                    marker.setLatLng([la, ln]); map.setView([la, ln], 16);
                    rvcSetCoord(la, ln);
                  }, function(){ alert('ไม่สามารถอ่านตำแหน่งได้'); });
                };
                setTimeout(function(){ map.invalidateSize(); }, 200);
              })();
            </script>

          <?php elseif ($f['type'] === 'photos'): ?>
            <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
              <?php foreach ($f['kinds'] as $kind => $p): $pid = $photoKinds[$kind] ?? null; ?>
                <div style="display:flex;flex-direction:column;gap:7px">
                  <?php if ($pid): ?>
                    <div style="position:relative;height:132px;border-radius:12px;overflow:hidden;border:1px solid var(--border)">
                      <img src="index.php?r=photo&pid=<?= (int)$pid ?>" alt="<?= e($p['label']) ?>" style="width:100%;height:100%;object-fit:cover;display:block">
                      <span class="pill ok" style="position:absolute;top:6px;right:6px">✓ แนบแล้ว</span>
                    </div>
                  <?php else: ?>
                    <div style="display:grid;place-items:center;gap:6px;height:132px;border-radius:12px;border:1.5px dashed var(--border);background:var(--surface2);text-align:center;padding:10px">
                      <span style="font-size:22px"><?= $p['icon'] ?></span>
                      <span style="font-size:12px;font-weight:600;line-height:1.35"><?= e($p['label']) ?></span>
                      <span style="font-size:10.5px;color:var(--faint)"><?= e($p['hint']) ?></span>
                    </div>
                  <?php endif; ?>
                  <label class="btn <?= $pid ? 'sec' : '' ?> sm rvc-filebtn" style="cursor:pointer;text-align:center;display:block;position:relative;overflow:hidden">
                    <span class="rvc-filebtn-text"><?= $pid ? '🔁 เปลี่ยนภาพ' : '📷 แนบภาพ' ?></span>
                    <input type="file" name="photo[<?= e($kind) ?>]" accept="image/*" style="position:absolute;width:1px;height:1px;opacity:0">
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
            <span class="muted" style="font-size:11.5px">เลือกไฟล์ภาพแล้วกด "บันทึกฉบับร่าง" หรือปุ่มถัดไปเพื่ออัปโหลด · รองรับ JPG/PNG/WEBP/GIF สูงสุด 5 MB ต่อภาพ</span>

          <?php elseif ($f['type'] === 'files'): ?>
            <?php if ($docs): ?>
              <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:4px">
                <?php foreach ($docs as $d): ?>
                  <a href="index.php?r=photo&pid=<?= (int)$d['id'] ?>" target="_blank" rel="noopener"
                     style="display:flex;align-items:center;gap:8px;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface2);font-size:12.5px;color:var(--text)">
                    📎 <?= e($d['label'] ?: 'เอกสารแนบ') ?>
                    <span class="muted" style="margin-left:auto;font-size:11px"><?= e($d['uploaded_at']) ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <label class="btn sec sm rvc-filebtn" style="cursor:pointer;display:inline-block;position:relative;overflow:hidden">
              <span class="rvc-filebtn-text">📎 แนบไฟล์เพิ่มเติม</span>
              <input type="file" name="docs[]" accept=".pdf,image/*" multiple style="position:absolute;width:1px;height:1px;opacity:0">
            </label>
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

// ซ่อน/แสดงฟิลด์ที่มีเงื่อนไข (เช่น "ระบุผู้ให้ข้อมูล" เมื่อเลือก "อื่นๆ") ตามค่าฟิลด์ควบคุมแบบสด
document.addEventListener('change', function(e){
  var t = e.target;
  var m = t.name && t.name.match(/^f\[([^\]]+)\]$/);
  if (!m) return;
  var id = m[1];
  document.querySelectorAll('[data-show-if="' + id + '"]').forEach(function(el){
    el.hidden = (t.value !== el.getAttribute('data-show-val'));
  });
});

// แสดงชื่อไฟล์ที่เลือกไว้บนปุ่มแนบไฟล์ (input จริงถูกซ่อนไว้ด้วย CSS) ให้ผู้ใช้เห็นว่ากดแล้วมีผลจริง
document.addEventListener('change', function(e){
  var input = e.target;
  if (input.type !== 'file') return;
  var textEl = input.closest('.rvc-filebtn').querySelector('.rvc-filebtn-text');
  if (!textEl) return;
  if (!input.files || !input.files.length) return;
  textEl.textContent = input.files.length > 1
    ? '✓ เลือกแล้ว ' + input.files.length + ' ไฟล์'
    : '✓ ' + input.files[0].name;
});
</script>
