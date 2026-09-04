<?php /** @var array $counts @var string $rmsUrl @var ?string $lastSync */ ?>
<style>
  /* ---------- ภาพเคลื่อนไหวการโอนข้อมูล ---------- */
  .viz{display:flex;align-items:center;gap:14px;padding:18px;border:1px solid var(--border);border-radius:14px;
       background:linear-gradient(160deg,var(--primary-weak),var(--surface2))}
  .viz .node{display:flex;flex-direction:column;align-items:center;gap:4px;font-size:12px;font-weight:600;color:var(--text);flex:0 0 auto;position:relative}
  .viz .node .glyph{width:52px;height:52px;border-radius:14px;background:var(--surface);border:1px solid var(--border);
       display:grid;place-items:center;font-size:24px;box-shadow:var(--shadow)}
  .viz .wire{position:relative;flex:1;height:16px;min-width:80px;overflow:hidden}
  .viz .wire::before{content:"";position:absolute;top:50%;left:0;right:0;height:3px;transform:translateY(-50%);
       border-radius:3px;background:repeating-linear-gradient(90deg,var(--border) 0 8px,transparent 8px 16px)}
  .viz .pkt{position:absolute;top:50%;left:-8px;width:9px;height:9px;margin-top:-4.5px;border-radius:50%;
       background:var(--primary);box-shadow:0 0 8px var(--primary);opacity:0}
  .viz.active .wire::before{background:repeating-linear-gradient(90deg,var(--primary) 0 8px,transparent 8px 16px);opacity:.5}
  .viz.active .pkt{opacity:1;animation:pkt-flow 1.5s linear infinite}
  .viz.active .pkt:nth-child(2){animation-delay:.30s}
  .viz.active .pkt:nth-child(3){animation-delay:.60s}
  .viz.active .pkt:nth-child(4){animation-delay:.90s}
  .viz.active .pkt:nth-child(5){animation-delay:1.20s}
  @keyframes pkt-flow{from{left:-8px;transform:scale(.7)}20%{transform:scale(1)}to{left:calc(100% + 8px);transform:scale(.7)}}
  .viz .node.dst .glyph{transition:transform .25s,box-shadow .25s}
  .viz .node.dst.recv .glyph{transform:scale(1.12);box-shadow:0 0 0 6px var(--primary-weak),var(--shadow)}
  .viz .node.dst .ring{position:absolute;top:0;left:50%;width:52px;height:52px;margin-left:-26px;border-radius:14px;
       border:2px solid var(--primary);opacity:0}
  .viz .node.dst.recv .ring{animation:recv-ring .6s ease-out}
  @keyframes recv-ring{0%{opacity:.9;transform:scale(1)}100%{opacity:0;transform:scale(1.5)}}
  .viz-meta{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin-top:10px}
  .viz-meta #vizStat{font-size:14px}
  .viz-meta #vizCount{font-size:22px;font-weight:700;letter-spacing:-.02em;color:var(--primary-dark)}
  .viz-meta #vizCount small{font-size:12px;font-weight:500;color:var(--muted)}
  .progress.live i{background-image:linear-gradient(90deg,transparent,rgba(255,255,255,.5),transparent);
       background-size:40px 100%;background-repeat:repeat;animation:stripe 1s linear infinite}
  @keyframes stripe{from{background-position:0 0}to{background-position:40px 0}}
  @media (prefers-reduced-motion:reduce){
    .viz.active .pkt,.viz .node.dst.recv .ring,.progress.live i{animation:none}
    .viz.active .pkt{opacity:1;left:45%}
  }
</style>

<section class="card pad" style="display:flex;flex-direction:column;gap:12px">
  <strong style="font-size:15px">การเชื่อมต่อระบบ RMS</strong>
  <p class="muted" style="font-size:12.5px;margin:0">
    ระบบดึงข้อมูลผ่าน <code>{URL}/api_connection.php?app_name=nutty&amp;data=...</code>
    โดยเก็บเฉพาะ <strong>URL</strong> ไว้ในฐานข้อมูล (แก้ได้ที่นี่) ส่วน path และ app_name กำหนดตายตัวในโค้ดเพื่อความปลอดภัย
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="text" id="rmsUrl" value="<?= e($rmsUrl) ?>" placeholder="http://rms.rvc.ac.th"
           style="flex:1;min-width:240px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--surface2)">
    <button class="btn sec" onclick="saveUrl()">บันทึก URL</button>
  </div>
  <span class="muted" style="font-size:11.5px">โอนข้อมูลนักเรียนล่าสุด: <?= e($lastSync ?: 'ยังไม่เคยโอน') ?></span>
</section>

<section class="card pad" style="display:flex;flex-direction:column;gap:12px">
  <div class="viz" id="viz">
    <div class="node src"><span class="glyph">🖥️</span>RMS</div>
    <div class="wire"><span class="pkt"></span><span class="pkt"></span><span class="pkt"></span><span class="pkt"></span><span class="pkt"></span></div>
    <div class="node dst" id="vizDst"><span class="glyph">🗄️</span>ระบบเยี่ยมบ้าน<i class="ring"></i></div>
  </div>
  <div class="viz-meta">
    <strong id="vizStat">พร้อมโอนข้อมูล</strong>
    <span id="vizCount"></span>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-top:4px">
    <strong style="font-size:14px">โอนข้อมูล (เฉพาะส่วนที่ใช้กับงานเยี่ยมบ้านโดยครูที่ปรึกษา)</strong>
    <button class="btn" onclick="runAll()" id="btnAll">โหลดทั้งหมดตามลำดับ</button>
  </div>
  <p class="muted" style="font-size:12px;margin:0">
    ลำดับมี dependency: <strong>บุคลากร → ภาคเรียน → กลุ่มเรียน → นักเรียน</strong>
    (ครูที่ปรึกษาของนักเรียนดึงจากกลุ่มเรียน) · ข้อมูลนักเรียนมีจำนวนมากและโหลดแบบแบ่งท่อน
    <strong>อย่าปิดหน้าจอ</strong>ระหว่างโอน
  </p>

  <div id="rows" style="display:flex;flex-direction:column;gap:10px;margin-top:2px"></div>
</section>

<script>
window.RVC_CSRF = '<?= e(csrf_token()) ?>';
const API = 'index.php?r=rms';
const COUNTS = <?= json_encode($counts) ?>;

const TRANSFERS = [
  { key:'people',    icon:'👥', title:'บุคลากร (ครูที่ปรึกษา / ผู้ใช้ระบบ)', action:'sync_people',
    note:'เพิ่มผู้ใช้ใหม่เป็นบทบาท "ครูที่ปรึกษา" · ไม่ทับ role/รหัสผ่านที่แก้เอง · ผู้ที่ออกแล้วถูกปิดใช้งาน (ไม่ลบ)',
    badges:d => B('เพิ่ม',d.created)+B('อัปเดต',d.updated)+B('ปิดใช้งาน',d.deactivated) },
  { key:'semesters', icon:'📅', title:'ภาคเรียน', action:'sync_semesters',
    note:'ใช้อ้างอิงภาคเรียนของการเยี่ยม · ไม่แตะค่าภาคเรียนปัจจุบันที่ผู้ดูแลตั้งเอง',
    badges:d => B('เพิ่ม',d.added)+B('อัปเดต',d.updated)+B('ข้าม',d.skipped) },
  { key:'groups',    icon:'🏫', title:'กลุ่มเรียน (+ ครูที่ปรึกษาประจำกลุ่ม)', action:'sync_groups',
    note:'ใช้ map นักเรียน → ครูที่ปรึกษา ต้องโอนก่อนข้อมูลนักเรียน',
    badges:d => B('เพิ่ม',d.added)+B('อัปเดต',d.updated)+B('ข้าม',d.skipped) },
  { key:'students',  icon:'🧑‍🎓', title:'นักเรียน/นักศึกษา', chunked:true, row:100,
    note:'โหลดแบบแบ่งท่อน (นับจำนวนก่อน) · ไม่ทับที่อยู่ พิกัด และกลุ่มคัดกรองที่กรอกระหว่างเยี่ยมบ้าน',
    badges:d => B('เพิ่ม',d.added)+B('อัปเดต',d.updated)+B('ข้าม',d.skipped) },
];

function B(label, n){ if(n===undefined||n===null) return ''; return `<span class="pill muted" style="margin-right:6px">${label} ${Number(n).toLocaleString()}</span>`; }

async function post(action, extra){
  const body = new URLSearchParams({ action, _csrf: window.RVC_CSRF, ...(extra||{}) });
  const res = await fetch(API, { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
  const txt = await res.text();
  try { return JSON.parse(txt); }
  catch(e){ return { success:false, message:'การตอบกลับไม่ถูกต้อง ('+res.status+')' }; }
}

/* ---------- ตัวควบคุมภาพเคลื่อนไหว ---------- */
const viz = document.getElementById('viz');
const vizDst = document.getElementById('vizDst');
const vizStat = document.getElementById('vizStat');
const vizCount = document.getElementById('vizCount');
let vizShown = 0, vizTarget = 0, vizRAF = null;

function vizStart(label){ viz.classList.add('active'); vizStat.textContent = label; }
function vizStop(label){ viz.classList.remove('active'); vizStat.textContent = label || 'เสร็จสิ้น'; }
function vizPulse(){ vizDst.classList.remove('recv'); void vizDst.offsetWidth; vizDst.classList.add('recv'); }
function vizSetCount(n, totalTxt){
  vizTarget = n;
  vizCount.innerHTML = '';
  const tick = () => {
    const diff = vizTarget - vizShown;
    if (Math.abs(diff) < 1){ vizShown = vizTarget; }
    else { vizShown += diff * 0.25; }
    vizCount.innerHTML = Math.round(vizShown).toLocaleString() +
      (totalTxt ? ' <small>/ '+totalTxt+' รายการ</small>' : ' <small>รายการ</small>');
    if (vizShown !== vizTarget){ vizRAF = requestAnimationFrame(tick); }
  };
  cancelAnimationFrame(vizRAF); tick();
}
function vizReset(){ vizShown = 0; vizTarget = 0; vizCount.innerHTML = ''; }

function render(){
  document.getElementById('rows').innerHTML = TRANSFERS.map(t => `
    <div class="card" style="padding:14px;display:flex;flex-direction:column;gap:8px" data-k="${t.key}">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <span style="font-size:20px">${t.icon}</span>
        <div style="flex:1;min-width:160px">
          <strong style="font-size:13.5px">${t.title}</strong><br>
          <span class="muted rms-have" style="font-size:11px">มีในระบบแล้ว ${Number(COUNTS[t.key]||0).toLocaleString()} รายการ</span>
        </div>
        <button class="btn sm" onclick="runOne('${t.key}')">โอนข้อมูล</button>
      </div>
      <span class="muted" style="font-size:11.5px">${t.note}</span>
      <div class="progress" style="display:none"><i style="width:0%"></i></div>
      <div class="rms-status muted" style="font-size:12px"></div>
      <div class="rms-badges" style="margin-top:2px"></div>
    </div>`).join('');
}
render();

function el(key){ return document.querySelector(`[data-k="${key}"]`); }
function setStatus(key, msg, cls){ const s = el(key).querySelector('.rms-status'); s.textContent = msg; s.style.color = cls==='err'?'var(--danger)':cls==='ok'?'var(--ok)':'var(--muted)'; }
function setProgress(key, pct, show, live){ const p = el(key).querySelector('.progress'); p.style.display = show?'block':'none'; p.classList.toggle('live', !!live); p.querySelector('i').style.width = Math.max(0,Math.min(100,pct))+'%'; }
function setBadges(key, html){ el(key).querySelector('.rms-badges').innerHTML = html; }
function bumpHave(key, n){ el(key).querySelector('.rms-have').textContent = 'มีในระบบแล้ว ' + Number(n).toLocaleString() + ' รายการ'; }

async function runOne(key){
  const t = TRANSFERS.find(x => x.key===key);
  document.querySelectorAll('button').forEach(b => b.disabled = true);
  vizReset();
  try {
    if (!t.chunked){
      setStatus(key, 'กำลังโอน…');
      setProgress(key, 100, true, true);
      vizStart('กำลังโอน' + t.title.replace(/\s*\(.*/,'') + '…');
      const r = await post(t.action);
      setProgress(key, 100, false);
      if (!r.success){ setStatus(key, r.message, 'err'); vizStop('เกิดข้อผิดพลาด'); return false; }
      const moved = (r.data.created||0) + (r.data.added||0) + (r.data.updated||0);
      vizPulse(); vizSetCount(moved);
      vizStop('โอน' + t.title.replace(/\s*\(.*/,'') + 'เสร็จสิ้น');
      setStatus(key, r.message || 'เสร็จสิ้น', 'ok');
      setBadges(key, t.badges(r.data) + B('รับมา', r.data.fetched));
      bumpHave(key, (COUNTS[key]||0) + (r.data.created||0) + (r.data.added||0));
      return true;
    }
    // chunked (students) — โอนทีละท่อน แสดงภาพเคลื่อนไหวไหลเข้าระบบ
    setStatus(key, 'กำลังนับจำนวน…'); setProgress(key, 0, true, true);
    vizStart('กำลังนับจำนวนนักเรียน…');
    const c = await post('count_students');
    if (!c.success){ setStatus(key, c.message, 'err'); vizStop('เกิดข้อผิดพลาด'); return false; }
    const total = c.data.total || 0;
    vizStart('กำลังนำเข้าข้อมูลนักเรียน…'); vizSetCount(0, total.toLocaleString());
    let offset = 0, added = 0, updated = 0, skipped = 0, done = 0;
    const row = t.row || 100;
    while (true){
      const r = await post('sync_students', { offset, row });
      if (!r.success){ setStatus(key, `หยุดที่ ${done.toLocaleString()} รายการ: ${r.message}`, 'err'); vizStop('หยุดกลางคัน'); return false; }
      added += r.data.added||0; updated += r.data.updated||0; skipped += r.data.skipped||0;
      done += r.data.fetched||0; offset += row;
      setProgress(key, total ? done/total*100 : 0, true, true);
      setStatus(key, `นำเข้าแล้ว ${done.toLocaleString()}${total?' / '+total.toLocaleString():''} รายการ`);
      setBadges(key, B('เพิ่ม',added)+B('อัปเดต',updated)+B('ข้าม',skipped));
      vizPulse(); vizSetCount(done, total ? total.toLocaleString() : '');
      if ((r.data.fetched||0) < row) break;
      if (total && done >= total) break;
    }
    setProgress(key, 100, false);
    vizStop(`นำเข้าข้อมูลนักเรียนเสร็จสิ้น — รวม ${done.toLocaleString()} รายการ`);
    setStatus(key, `เสร็จสิ้น — รวม ${done.toLocaleString()} รายการ`, 'ok');
    bumpHave(key, (COUNTS[key]||0) + added);
    return true;
  } finally {
    document.querySelectorAll('button').forEach(b => b.disabled = false);
  }
}

async function runAll(){
  for (const t of TRANSFERS){
    const ok = await runOne(t.key);
    if (!ok){ alert('หยุดที่ขั้นตอน "'+t.title+'" — แก้ไขแล้วลองใหม่'); break; }
    await new Promise(r => setTimeout(r, 400));
  }
  vizStop('โอนข้อมูลทั้งหมดเสร็จสิ้น');
}
</script>
