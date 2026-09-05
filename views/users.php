<?php
/** @var array $users @var string $q @var string $roleF @var string $status @var int $totalUsers */
$me = Auth::user();
$hasFilter = $q !== '' || $roleF !== '' || $status !== '';
$hiddenFilters = fn() =>
    '<input type="hidden" name="q" value="' . e($q) . '">'
  . '<input type="hidden" name="role" value="' . e($roleF) . '">'
  . '<input type="hidden" name="status" value="' . e($status) . '">';
?>
<section class="card pad" style="display:flex;flex-direction:column;gap:10px">
  <form method="get" action="index.php" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="r" value="users">
    <label class="field" style="flex:1;min-width:200px">
      <span class="lbl">ค้นหา</span>
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="ชื่อ, ชื่อผู้ใช้, อีเมล หรือแผนก">
    </label>
    <label class="field" style="min-width:170px">
      <span class="lbl">บทบาท</span>
      <select name="role">
        <option value="">ทุกบทบาท</option>
        <?php foreach (Auth::ROLES as $rk => $rl): ?>
          <option value="<?= e($rk) ?>" <?= $roleF === $rk ? 'selected' : '' ?>><?= e($rl) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field" style="min-width:150px">
      <span class="lbl">สถานะ</span>
      <select name="status">
        <option value="">ทุกสถานะ</option>
        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>ใช้งานอยู่</option>
        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>ปิดใช้งาน</option>
      </select>
    </label>
    <button class="btn" type="submit">ค้นหา</button>
    <?php if ($hasFilter): ?>
      <a class="btn sec" href="index.php?r=users">ล้างตัวกรอง</a>
    <?php endif; ?>
  </form>
</section>

<section class="card">
  <div style="padding:16px 18px;border-bottom:1px solid var(--border)">
    <strong style="font-size:14.5px">
      <?= $hasFilter ? 'พบ ' . count($users) . ' จากทั้งหมด ' . $totalUsers . ' คน' : 'ผู้ใช้ระบบทั้งหมด (' . $totalUsers . ')' ?>
    </strong><br>
    <span class="muted" style="font-size:11.5px">
      แก้ไขบทบาทและเลขบัตรประชาชนได้โดยตรงในตาราง (เลขบัตรใช้เชื่อมโยงกับกลุ่มที่เป็นครูที่ปรึกษาจาก RMS)
      ผู้ดูแลสามารถ "สวมสิทธิ์" เพื่อดูระบบในมุมมองของผู้ใช้คนนั้น — ออกจากระบบเพื่อกลับสู่สิทธิ์ผู้ดูแลได้ทุกเมื่อ
    </span>
  </div>
  <div class="tablewrap">
    <table class="data" style="min-width:1000px">
      <thead><tr>
        <th>ผู้ใช้</th><th>บทบาท</th><th>เลขบัตรประชาชน (เชื่อมกลุ่มที่ปรึกษา)</th><th>แผนก</th><th>สถานะ</th><th>เข้าสู่ระบบล่าสุด</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($users as $u): $isSelf = (int)$u['id'] === (int)$me['id']; ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <span class="avatar" style="width:30px;height:30px;font-size:12px"><?= avatar_html($u) ?></span>
                <div>
                  <strong style="font-size:13px"><?= e($u['full_name']) ?></strong><br>
                  <span class="muted" style="font-size:11px">@<?= e($u['username']) ?><?= $u['email'] ? ' · ' . e($u['email']) : '' ?></span>
                </div>
              </div>
            </td>
            <td>
              <?php if ($isSelf): ?>
                <span class="pill primary"><?= e(Auth::ROLES[$u['role']] ?? $u['role']) ?></span>
              <?php else: ?>
                <form method="post" action="index.php?r=users" style="display:flex;gap:6px;align-items:center">
                  <?= csrf_field() ?><?= $hiddenFilters() ?>
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <select name="new_role" style="padding:6px 8px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);font-size:12px">
                    <?php foreach (Auth::ROLES as $rk => $rl): ?>
                      <option value="<?= e($rk) ?>" <?= $u['role'] === $rk ? 'selected' : '' ?>><?= e($rl) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn sec sm" name="action" value="set_role">บันทึก</button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isSelf): ?>
                <span class="muted"><?= e($u['people_id'] ?: '-') ?></span>
              <?php else: ?>
                <form method="post" action="index.php?r=users" style="display:flex;gap:6px;align-items:center">
                  <?= csrf_field() ?><?= $hiddenFilters() ?>
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <input type="text" name="people_id" value="<?= e($u['people_id'] ?? '') ?>" maxlength="13" pattern="\d{13}"
                         placeholder="13 หลัก" style="width:120px;padding:6px 8px;border-radius:8px;border:1px solid var(--border);background:var(--surface2);font-size:12px">
                  <button class="btn sec sm" name="action" value="set_idcard">บันทึก</button>
                </form>
              <?php endif; ?>
            </td>
            <td class="muted"><?= e($u['department'] ?: '-') ?></td>
            <td><span class="pill <?= $u['is_active'] ? 'ok' : 'muted' ?>"><?= $u['is_active'] ? 'ใช้งานอยู่' : 'ปิดใช้งาน' ?></span></td>
            <td class="muted"><?= e($u['last_login_at'] ?: 'ยังไม่เคยเข้าใช้') ?></td>
            <td style="text-align:right;white-space:nowrap">
              <?php if (!$isSelf): ?>
                <form method="post" action="index.php?r=users" style="display:inline">
                  <?= csrf_field() ?><?= $hiddenFilters() ?>
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn sec sm" name="action" value="toggle_active"
                    onclick="return confirm('<?= $u['is_active'] ? 'ปิดใช้งานผู้ใช้นี้?' : 'เปิดใช้งานผู้ใช้นี้?' ?>')">
                    <?= $u['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>
                  </button>
                </form>
                <?php if ($u['is_active']): ?>
                  <form method="post" action="index.php?r=users" style="display:inline">
                    <?= csrf_field() ?><?= $hiddenFilters() ?>
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="btn sm" name="action" value="impersonate"
                      onclick="return confirm('สวมสิทธิ์เป็น &quot;<?= e($u['full_name']) ?>&quot; ใช่หรือไม่?\nกด \'ออกจากระบบ\' เพื่อกลับมาเป็นผู้ดูแลภายหลัง')">
                      🎭 สวมสิทธิ์
                    </button>
                  </form>
                <?php endif; ?>
                <form method="post" action="index.php?r=users" style="display:inline">
                  <?= csrf_field() ?><?= $hiddenFilters() ?>
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn danger sm" name="action" value="delete"
                    onclick="return confirm('ลบผู้ใช้ &quot;<?= e($u['full_name']) ?>&quot; ถาวรใช่หรือไม่?\nข้อมูลจะหายไปเลย ไม่สามารถกู้คืนได้ (ถ้าแค่ต้องการห้ามเข้าระบบชั่วคราว ให้ใช้ \'ปิดใช้งาน\' แทน)')">
                    🗑️ ลบ
                  </button>
                </form>
              <?php else: ?>
                <span class="muted" style="font-size:11.5px">คุณ</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
          <tr><td colspan="7" class="muted" style="text-align:center;padding:24px">ไม่พบผู้ใช้ที่ตรงกับตัวกรอง</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
