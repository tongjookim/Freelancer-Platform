<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

admin_guard();

// ---- 요약 지표 -------------------------------------------------------------
$escrowHeld = (float)db()->query(
    "SELECT COALESCE(SUM(amount),0) FROM orders WHERE escrow_status = 'held'"
)->fetchColumn();

$feeRevenue = (float)db()->query(
    "SELECT COALESCE(SUM(fee_amount),0) FROM orders WHERE escrow_status = 'released'"
)->fetchColumn();

$payoutPending = (float)db()->query(
    "SELECT COALESCE(SUM(net_amount),0) FROM settlements WHERE status IN ('pending','processing')"
)->fetchColumn();

$paidOut = (float)db()->query(
    "SELECT COALESCE(SUM(net_amount),0) FROM settlements WHERE status = 'paid'"
)->fetchColumn();

$statusRows = db()->query(
    "SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$recentOrders = db()->query(
    "SELECT o.order_no, o.title, o.amount, o.status, o.escrow_status, o.created_at,
            c.name AS client_name, f.name AS freelancer_name
     FROM orders o
     JOIN users c ON c.id = o.client_id
     JOIN users f ON f.id = o.freelancer_id
     ORDER BY o.id DESC LIMIT 10"
)->fetchAll();

admin_header('대시보드', 'dashboard');
?>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <div class="card">
    <p class="text-sm text-slate-500">에스크로 보관 총액</p>
    <p class="mt-1 text-2xl font-bold text-brand-700"><?= won($escrowHeld) ?></p>
    <p class="mt-1 text-xs text-slate-400">예치 중(held) 주문 합계</p>
  </div>
  <div class="card">
    <p class="text-sm text-slate-500">누적 수수료 수익</p>
    <p class="mt-1 text-2xl font-bold text-emerald-600"><?= won($feeRevenue) ?></p>
    <p class="mt-1 text-xs text-slate-400">정산 완료(released) 기준</p>
  </div>
  <div class="card">
    <p class="text-sm text-slate-500">지급 대기 정산</p>
    <p class="mt-1 text-2xl font-bold text-amber-600"><?= won($payoutPending) ?></p>
    <p class="mt-1 text-xs text-slate-400">프리랜서 지급 예정액</p>
  </div>
  <div class="card">
    <p class="text-sm text-slate-500">누적 지급 완료</p>
    <p class="mt-1 text-2xl font-bold text-slate-700"><?= won($paidOut) ?></p>
    <p class="mt-1 text-xs text-slate-400">정산 지급 완료액</p>
  </div>
</div>

<!-- 주문 상태 분포 -->
<div class="card mt-6">
  <h2 class="mb-3 font-semibold">주문 상태 분포</h2>
  <div class="flex flex-wrap gap-3">
    <?php foreach ($statusRows as $status => $cnt): ?>
      <div class="rounded-lg bg-slate-50 px-4 py-2">
        <span class="text-sm text-slate-500"><?= h(order_status_label($status)) ?></span>
        <span class="ml-2 font-bold"><?= (int)$cnt ?></span>
      </div>
    <?php endforeach; ?>
    <?php if (!$statusRows): ?><p class="text-sm text-slate-400">주문이 없습니다.</p><?php endif; ?>
  </div>
</div>

<!-- 최근 주문 -->
<div class="card mt-6">
  <h2 class="mb-3 font-semibold">최근 주문</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b text-left text-slate-500">
          <th class="py-2">주문번호</th><th class="py-2">제목</th>
          <th class="py-2">의뢰인</th><th class="py-2">프리랜서</th>
          <th class="py-2 text-right">금액</th><th class="py-2">상태</th>
          <th class="py-2">에스크로</th><th class="py-2">생성일</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentOrders as $o): ?>
          <tr class="border-b last:border-0">
            <td class="py-2 font-mono text-xs"><?= h($o['order_no']) ?></td>
            <td class="py-2"><?= h($o['title']) ?></td>
            <td class="py-2 text-slate-600"><?= h($o['client_name']) ?></td>
            <td class="py-2 text-slate-600"><?= h($o['freelancer_name']) ?></td>
            <td class="py-2 text-right"><?= won($o['amount']) ?></td>
            <td class="py-2"><span class="badge bg-slate-100 text-slate-600"><?= h(order_status_label($o['status'])) ?></span></td>
            <td class="py-2"><span class="badge bg-brand-50 text-brand-700"><?= h($o['escrow_status']) ?></span></td>
            <td class="py-2 text-xs text-slate-400"><?= h($o['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recentOrders): ?>
          <tr><td colspan="8" class="py-4 text-center text-slate-400">주문이 없습니다.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php admin_footer(); ?>
