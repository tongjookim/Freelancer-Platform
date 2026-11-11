<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

admin_guard();

$monthly = db()->query(
    "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS orders,
            COALESCE(SUM(amount),0) AS gmv, COALESCE(SUM(fee_amount),0) AS fee,
            COALESCE(SUM(CASE WHEN escrow_status IN ('released','partially_refunded') THEN net_amount END),0) AS paid
       FROM orders GROUP BY ym ORDER BY ym DESC LIMIT 12"
)->fetchAll();

$top = db()->query(
    "SELECT f.id, f.name, COUNT(*) AS orders, COALESCE(SUM(o.net_amount),0) AS paid
       FROM orders o JOIN users f ON f.id = o.freelancer_id
      WHERE o.escrow_status IN ('released','partially_refunded')
      GROUP BY f.id, f.name ORDER BY paid DESC LIMIT 10"
)->fetchAll();

$disputes = db()->query("SELECT status, COUNT(*) AS cnt FROM disputes GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

$totals = db()->query(
    "SELECT COUNT(*) AS total_orders, COALESCE(SUM(amount),0) AS gmv, COALESCE(SUM(fee_amount),0) AS fee FROM orders"
)->fetch();

$maxGmv = 0.0;
foreach ($monthly as $m) { $maxGmv = max($maxGmv, (float)$m['gmv']); }

admin_header('통계', 'stats');
?>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
  <div class="card"><p class="text-sm text-slate-500">누적 주문</p><p class="mt-1 text-2xl font-bold"><?= number_format((int)$totals['total_orders']) ?>건</p></div>
  <div class="card"><p class="text-sm text-slate-500">누적 거래액(GMV)</p><p class="mt-1 text-2xl font-bold text-brand-700"><?= won($totals['gmv']) ?></p></div>
  <div class="card"><p class="text-sm text-slate-500">누적 수수료 수익</p><p class="mt-1 text-2xl font-bold text-emerald-600"><?= won($totals['fee']) ?></p></div>
</div>

<!-- 월별 -->
<div class="card mt-6">
  <h2 class="mb-3 font-semibold">월별 거래 (최근 12개월)</h2>
  <?php if (!$monthly): ?><p class="text-slate-400">데이터가 없습니다.</p><?php else: ?>
  <div class="space-y-2">
    <?php foreach ($monthly as $m): ?>
      <div class="flex items-center gap-3">
        <span class="w-20 text-sm text-slate-500"><?= h($m['ym']) ?></span>
        <div class="h-4 flex-1 rounded bg-slate-100">
          <div class="h-4 rounded bg-brand-500" style="width: <?= $maxGmv > 0 ? round((float)$m['gmv'] / $maxGmv * 100) : 0 ?>%"></div>
        </div>
        <span class="w-28 text-right text-sm"><?= won($m['gmv']) ?></span>
        <span class="w-16 text-right text-xs text-slate-400"><?= (int)$m['orders'] ?>건</span>
        <span class="w-28 text-right text-xs text-emerald-600">수수료 <?= won($m['fee']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
  <!-- 상위 프리랜서 -->
  <div class="card">
    <h2 class="mb-3 font-semibold">상위 프리랜서 (지급액 기준)</h2>
    <table class="w-full text-sm">
      <thead><tr class="border-b text-left text-slate-500"><th class="py-2">#</th><th class="py-2">프리랜서</th><th class="py-2 text-right">완료 건</th><th class="py-2 text-right">누적 지급</th></tr></thead>
      <tbody>
        <?php foreach ($top as $i => $t): ?>
          <tr class="border-b last:border-0">
            <td class="py-2"><?= $i + 1 ?></td>
            <td class="py-2"><?= h($t['name']) ?></td>
            <td class="py-2 text-right"><?= (int)$t['orders'] ?></td>
            <td class="py-2 text-right font-semibold"><?= won($t['paid']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$top): ?><tr><td colspan="4" class="py-3 text-center text-slate-400">데이터 없음</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- 분쟁 현황 -->
  <div class="card">
    <h2 class="mb-3 font-semibold">분쟁 현황</h2>
    <div class="flex flex-wrap gap-3">
      <?php
        $dl = ['open' => '처리 대기', 'resolved' => '처리 완료', 'cancelled' => '취소'];
        foreach ($dl as $k => $label): ?>
        <div class="rounded-lg bg-slate-50 px-4 py-2">
          <span class="text-sm text-slate-500"><?= h($label) ?></span>
          <span class="ml-2 font-bold"><?= (int)($disputes[$k] ?? 0) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php admin_footer(); ?>
