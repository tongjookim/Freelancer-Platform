<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

admin_guard();

$role = isset($_GET['role']) && $_GET['role'] !== '' ? (string)$_GET['role'] : null;

$sql = 'SELECT id, email, name, phone, role, status, last_login_at, created_at FROM users';
$params = [];
if ($role !== null) {
    $sql .= ' WHERE role = ?';
    $params[] = $role;
}
$sql .= ' ORDER BY id DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$roleOptions = ['' => '전체', 'client' => '의뢰인', 'freelancer' => '프리랜서', 'admin' => '관리자'];
$roleLabel = ['client' => '의뢰인', 'freelancer' => '프리랜서', 'admin' => '관리자'];

admin_header('회원 관리', 'users');
?>

<div class="mb-4 flex flex-wrap gap-2">
  <?php foreach ($roleOptions as $value => $label): ?>
    <a href="?role=<?= h($value) ?>"
       class="btn <?= (string)$role === (string)$value ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 border border-slate-200' ?> no-underline">
      <?= h($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b text-left text-slate-500">
          <th class="py-2">ID</th><th class="py-2">이름</th><th class="py-2">이메일</th>
          <th class="py-2">연락처</th><th class="py-2">역할</th><th class="py-2">상태</th>
          <th class="py-2">최근 로그인</th><th class="py-2">가입일</th><th class="py-2"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr class="border-b last:border-0">
            <td class="py-2">#<?= (int)$u['id'] ?></td>
            <td class="py-2 font-medium"><a href="/admin/user.php?id=<?= (int)$u['id'] ?>" class="text-brand-600"><?= h($u['name']) ?></a></td>
            <td class="py-2 text-slate-600"><?= h($u['email']) ?></td>
            <td class="py-2 text-slate-500"><?= h($u['phone'] ?? '-') ?></td>
            <td class="py-2"><span class="badge bg-brand-50 text-brand-700"><?= h($roleLabel[$u['role']] ?? $u['role']) ?></span></td>
            <td class="py-2">
              <span class="badge <?= $u['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600' ?>">
                <?= h($u['status']) ?>
              </span>
            </td>
            <td class="py-2 text-xs text-slate-400"><?= h($u['last_login_at'] ?? '-') ?></td>
            <td class="py-2 text-xs text-slate-400"><?= h($u['created_at']) ?></td>
            <td class="py-2">
              <div class="flex justify-end gap-1" data-user-id="<?= (int)$u['id'] ?>" data-name="<?= h($u['name']) ?>">
                <?php if ($u['role'] !== 'admin'): ?>
                  <button class="btn-muted status-btn" data-status="<?= $u['status'] === 'active' ? 'suspended' : 'active' ?>">
                    <?= $u['status'] === 'active' ? '정지' : '해제' ?>
                  </button>
                <?php endif; ?>
                <button class="btn-muted reset-btn">비번 재설정</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
          <tr><td colspan="9" class="py-4 text-center text-slate-400">회원이 없습니다.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  document.querySelectorAll('.status-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const wrap = btn.closest('[data-user-id]');
      const id = wrap.dataset.userId;
      const status = btn.dataset.status;
      const label = status === 'suspended' ? '정지' : '활성화';
      if (!confirm(wrap.dataset.name + ' 회원을 ' + label + '할까요?')) return;
      btn.disabled = true;
      try {
        await adminApi('PATCH', '/admin/users/' + id + '/status', { status });
        adminFlash('회원 상태가 변경되었습니다.', true);
        setTimeout(() => location.reload(), 700);
      } catch (err) { adminFlash(err.message, false); btn.disabled = false; }
    });
  });

  document.querySelectorAll('.reset-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const wrap = btn.closest('[data-user-id]');
      const id = wrap.dataset.userId;
      if (!confirm(wrap.dataset.name + ' 회원의 비밀번호를 임시 비밀번호로 재설정할까요?')) return;
      btn.disabled = true;
      try {
        const data = await adminApi('POST', '/admin/users/' + id + '/reset-password');
        alert('임시 비밀번호: ' + data.temp_password + '\n\n사용자에게 안전하게 전달하고 즉시 변경하도록 안내하세요.');
        adminFlash('임시 비밀번호를 발급했습니다.', true);
      } catch (err) { adminFlash(err.message, false); }
      btn.disabled = false;
    });
  });
</script>

<?php admin_footer(); ?>
