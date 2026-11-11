<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

admin_guard();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$user = $stmt->fetch();

admin_header('회원 상세', 'users');

if (!$user) {
    echo '<div class="card text-center text-slate-400">회원을 찾을 수 없습니다. <a href="/admin/users.php">목록으로</a></div>';
    admin_footer();
    exit;
}

$roleLabel = ['client' => '의뢰인', 'freelancer' => '프리랜서', 'admin' => '관리자'];
$statusClass = $user['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600';

// 프리랜서 프로필
$profile = null;
if ($user['role'] === 'freelancer') {
    $p = db()->prepare('SELECT * FROM freelancer_profiles WHERE user_id = ? LIMIT 1');
    $p->execute([$id]);
    $profile = $p->fetch() ?: null;
}

// 의뢰인으로서의 주문
$asClient = db()->prepare(
    "SELECT o.id, o.order_no, o.title, o.amount, o.status, o.escrow_status, o.created_at, f.name AS counterpart
       FROM orders o JOIN users f ON f.id = o.freelancer_id
      WHERE o.client_id = ? ORDER BY o.id DESC LIMIT 20"
);
$asClient->execute([$id]);

// 프리랜서로서의 주문
$asFree = db()->prepare(
    "SELECT o.id, o.order_no, o.title, o.amount, o.status, o.fee_amount, o.net_amount, f.name AS counterpart
       FROM orders o JOIN users f ON f.id = o.client_id
      WHERE o.freelancer_id = ? ORDER BY o.id DESC LIMIT 20"
);
$asFree->execute([$id]);

// 정산
$settlements = db()->prepare(
    "SELECT s.*, o.order_no FROM settlements s JOIN orders o ON o.id = s.order_id
      WHERE s.freelancer_id = ? ORDER BY s.id DESC LIMIT 20"
);
$settlements->execute([$id]);

// 받은 리뷰
$reviews = db()->prepare(
    "SELECT r.*, u.name AS reviewer_name FROM reviews r JOIN users u ON u.id = r.reviewer_id
      WHERE r.reviewee_id = ? ORDER BY r.id DESC LIMIT 20"
);
$reviews->execute([$id]);
$reviewRows = $reviews->fetchAll();
?>

<p class="mb-4 text-sm"><a href="/admin/users.php" class="text-brand-600">← 회원 목록</a></p>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
  <div class="card space-y-2">
    <h2 class="font-semibold">기본 정보</h2>
    <p class="text-sm text-slate-600">이름: <span class="font-medium"><?= h($user['name']) ?></span></p>
    <p class="text-sm text-slate-600">이메일: <?= h($user['email']) ?></p>
    <p class="text-sm text-slate-600">연락처: <?= h($user['phone'] ?? '-') ?></p>
    <p class="text-sm text-slate-600">역할: <span class="badge bg-brand-50 text-brand-700"><?= h($roleLabel[$user['role']] ?? $user['role']) ?></span></p>
    <p class="text-sm text-slate-600">상태: <span class="badge <?= $statusClass ?>"><?= h($user['status']) ?></span></p>
    <p class="text-xs text-slate-400">가입 <?= h($user['created_at']) ?> · 최근 로그인 <?= h($user['last_login_at'] ?? '-') ?></p>

    <?php if ($user['role'] !== 'admin'): ?>
      <div class="flex flex-wrap gap-2 pt-2" data-user-id="<?= (int)$user['id'] ?>" data-name="<?= h($user['name']) ?>">
        <button class="btn-muted status-btn" data-status="<?= $user['status'] === 'active' ? 'suspended' : 'active' ?>">
          <?= $user['status'] === 'active' ? '정지' : '활성화' ?>
        </button>
        <button class="btn-muted reset-btn">임시 비밀번호 발급</button>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($profile): ?>
  <div class="card space-y-1 lg:col-span-2">
    <h2 class="font-semibold">프리랜서 프로필</h2>
    <p class="text-sm text-slate-600">평점: ⭐ <?= h(number_format((float)$profile['rating_avg'], 2)) ?> (<?= (int)$profile['rating_count'] ?>건)
      · 완료 <?= (int)$profile['completed_orders'] ?>건</p>
    <p class="text-sm text-slate-600">소개: <?= h($profile['headline'] ?? '-') ?></p>
    <p class="text-sm text-slate-600">정산 계좌: <?= h($user['bank_name'] ?? '-') ?> <?= h($user['bank_account'] ?? '') ?> <?= h($user['bank_holder'] ?? '') ?></p>
  </div>
  <?php else: ?>
  <div class="card lg:col-span-2 text-slate-400">프로필 정보가 없습니다.</div>
  <?php endif; ?>
</div>

<!-- 의뢰인 주문 -->
<div class="card mt-6">
  <h2 class="mb-3 font-semibold">의뢰한 주문 (최근 20)</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="border-b text-left text-slate-500"><th class="py-2">주문번호</th><th class="py-2">제목</th><th class="py-2">프리랜서</th><th class="py-2 text-right">금액</th><th class="py-2">상태</th></tr></thead>
      <tbody>
        <?php foreach ($asClient->fetchAll() as $o): ?>
          <tr class="border-b last:border-0">
            <td class="py-2 font-mono text-xs"><?= h($o['order_no']) ?></td>
            <td class="py-2"><?= h($o['title']) ?></td>
            <td class="py-2 text-slate-600"><?= h($o['counterpart']) ?></td>
            <td class="py-2 text-right"><?= won($o['amount']) ?></td>
            <td class="py-2"><span class="badge bg-slate-100 text-slate-600"><?= h(order_status_label($o['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- 프리랜서 주문 -->
<?php if ($user['role'] === 'freelancer'): ?>
<div class="card mt-6">
  <h2 class="mb-3 font-semibold">수행한 주문 (최근 20)</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="border-b text-left text-slate-500"><th class="py-2">주문번호</th><th class="py-2">제목</th><th class="py-2">의뢰인</th><th class="py-2 text-right">금액</th><th class="py-2 text-right">지급액</th><th class="py-2">상태</th></tr></thead>
      <tbody>
        <?php foreach ($asFree->fetchAll() as $o): ?>
          <tr class="border-b last:border-0">
            <td class="py-2 font-mono text-xs"><?= h($o['order_no']) ?></td>
            <td class="py-2"><?= h($o['title']) ?></td>
            <td class="py-2 text-slate-600"><?= h($o['counterpart']) ?></td>
            <td class="py-2 text-right"><?= won($o['amount']) ?></td>
            <td class="py-2 text-right text-emerald-600"><?= $o['net_amount'] !== null ? won($o['net_amount']) : '-' ?></td>
            <td class="py-2"><span class="badge bg-slate-100 text-slate-600"><?= h(order_status_label($o['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2 class="mb-3 mt-6 font-semibold">정산 내역</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="border-b text-left text-slate-500"><th class="py-2">주문번호</th><th class="py-2 text-right">수수료</th><th class="py-2 text-right">지급액</th><th class="py-2">상태</th><th class="py-2">지급일</th></tr></thead>
      <tbody>
        <?php foreach ($settlements->fetchAll() as $s): ?>
          <tr class="border-b last:border-0">
            <td class="py-2 font-mono text-xs"><?= h($s['order_no']) ?></td>
            <td class="py-2 text-right"><?= won($s['fee_amount']) ?></td>
            <td class="py-2 text-right font-semibold"><?= won($s['net_amount']) ?></td>
            <td class="py-2"><span class="badge bg-slate-100 text-slate-600"><?= h(settlement_status_label($s['status'])) ?></span></td>
            <td class="py-2 text-xs text-slate-400"><?= h($s['paid_at'] ?? '-') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- 받은 리뷰 -->
<div class="card mt-6">
  <h2 class="mb-3 font-semibold">받은 리뷰 (<?= count($reviewRows) ?>)</h2>
  <?php if (!$reviewRows): ?><p class="text-slate-400">리뷰가 없습니다.</p><?php endif; ?>
  <?php foreach ($reviewRows as $r): ?>
    <div class="border-b py-2 text-sm last:border-0">
      <p class="font-medium"><?= h($r['reviewer_name']) ?> · <?= str_repeat('★', (int)$r['rating']) ?><?= str_repeat('☆', 5 - (int)$r['rating']) ?></p>
      <p class="text-slate-600"><?= h($r['comment'] ?? '') ?></p>
      <p class="text-xs text-slate-400"><?= h($r['created_at']) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<script>
  document.querySelectorAll('.status-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const wrap = btn.closest('[data-user-id]');
      const status = btn.dataset.status;
      if (!confirm(wrap.dataset.name + ' 회원을 ' + (status === 'suspended' ? '정지' : '활성화') + '할까요?')) return;
      btn.disabled = true;
      try { await adminApi('PATCH', '/admin/users/' + wrap.dataset.userId + '/status', { status }); adminFlash('변경되었습니다.', true); setTimeout(() => location.reload(), 700); }
      catch (err) { adminFlash(err.message, false); btn.disabled = false; }
    });
  });
  document.querySelectorAll('.reset-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const wrap = btn.closest('[data-user-id]');
      if (!confirm(wrap.dataset.name + ' 회원의 임시 비밀번호를 발급할까요?')) return;
      btn.disabled = true;
      try { const d = await adminApi('POST', '/admin/users/' + wrap.dataset.userId + '/reset-password'); alert('임시 비밀번호: ' + d.temp_password); }
      catch (err) { adminFlash(err.message, false); }
      btn.disabled = false;
    });
  });
</script>

<?php admin_footer(); ?>
