<?php /** @var array $users */ $me = Auth::user(); ?>
<section class="card">
  <div style="padding:16px 18px;border-bottom:1px solid var(--border)">
    <strong style="font-size:14.5px">ผู้ใช้ระบบทั้งหมด (<?= count($users) ?>)</strong><br>
    <span class="muted" style="font-size:11.5px">ผู้ดูแลสามารถ "สวมสิทธิ์" เพื่อดูระบบในมุมมองของผู้ใช้คนนั้น — ออกจากระบบเพื่อกลับสู่สิทธิ์ผู้ดูแลได้ทุกเมื่อ</span>
  </div>
  <div class="tablewrap">
    <table class="data" style="min-width:760px">
      <thead><tr>
        <th>ผู้ใช้</th><th>บทบาท</th><th>แผนก</th><th>สถานะ</th><th>เข้าสู่ระบบล่าสุด</th><th></th>
      </tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <span class="avatar" style="width:30px;height:30px;font-size:12px"><?= e(th_initial($u['full_name'])) ?></span>
                <div>
                  <strong style="font-size:13px"><?= e($u['full_name']) ?></strong><br>
                  <span class="muted" style="font-size:11px">@<?= e($u['username']) ?><?= $u['email'] ? ' · ' . e($u['email']) : '' ?></span>
                </div>
              </div>
            </td>
            <td><span class="pill primary"><?= e(Auth::ROLES[$u['role']] ?? $u['role']) ?></span></td>
            <td class="muted"><?= e($u['department'] ?: '-') ?></td>
            <td><span class="pill <?= $u['is_active'] ? 'ok' : 'muted' ?>"><?= $u['is_active'] ? 'ใช้งานอยู่' : 'ปิดใช้งาน' ?></span></td>
            <td class="muted"><?= e($u['last_login_at'] ?: 'ยังไม่เคยเข้าใช้') ?></td>
            <td style="text-align:right;white-space:nowrap">
              <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                <form method="post" action="index.php?r=users" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn sec sm" name="action" value="toggle_active"
                    onclick="return confirm('<?= $u['is_active'] ? 'ปิดใช้งานผู้ใช้นี้?' : 'เปิดใช้งานผู้ใช้นี้?' ?>')">
                    <?= $u['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>
                  </button>
                </form>
                <?php if ($u['is_active']): ?>
                  <form method="post" action="index.php?r=users" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="btn sm" name="action" value="impersonate"
                      onclick="return confirm('สวมสิทธิ์เป็น &quot;<?= e($u['full_name']) ?>&quot; ใช่หรือไม่?\nกด \'ออกจากระบบ\' เพื่อกลับมาเป็นผู้ดูแลภายหลัง')">
                      🎭 สวมสิทธิ์
                    </button>
                  </form>
                <?php endif; ?>
              <?php else: ?>
                <span class="muted" style="font-size:11.5px">คุณ</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
