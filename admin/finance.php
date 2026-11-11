<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

admin_guard();

$status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : null;

$sql = 'SELECT s.*, u.name AS freelancer_name, o.order_no
        FROM settlements s
        JOIN users u ON u.id = s.freelancer_id
        JOIN orders o ON o.id = s.order_id';
$params = [];
if ($status !== null) {
    $sql .= ' WHERE s.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY s.id DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$settlements = $stmt->fetchAll();

// 요약
$summary = db()->query(
    "SELECT
        COALESCE(SUM(CASE WHEN status IN ('pending','processing') THEN net_amount END),0) AS pending,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount END),0) AS paid,
        COALESCE(SUM(fee_amount),0) AS fee
     FROM settlements WHERE status <> 'cancelled'"
)->fetch();

$statusOptions = [
    ''           => '전체',
    'pending'    => '지급 대기',
    'processing' => '지급 처리중',
    'paid'       => '지급 완료',
    'cancelled'  => '취소',
];

admin_header('정산 관리', 'finance');
?>

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
  <div class="card">
    <p class="text-sm text-slate-500">지급 대기 합계</p>
    <p class="mt-1 text-2xl font-bold text-amber-600"><?= won($summary['pending']) ?></p>
  </div>
  <div class="card">
    <p class="text-sm text-slate-500">지급 완료 합계</p>
    <p class="mt-1 text-2xl font-bold text-emerald-600"><?= won($summary['paid']) ?></p>
  </div>
  <div class="card">
    <p class="text-sm text-slate-500">플랫폼 수수료 합계</p>
    <p class="mt-1 text-2xl font-bold text-brand-700"><?= won($summary['fee']) ?></p>
  </div>
</div>

<div class="mb-4 flex flex-wrap gap-2">
  <?php foreach ($statusOptions as $value => $label): ?>
    <a href="?status=<?= h($value) ?>"
       class="btn <?= (string)$status === (string)$value ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 border border-slate-200' ?> no-underline">
      <?= h($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b text-left text-slate-500">
          <th class="py-2">정산ID</th><th class="py-2">주문번호</th>
          <th class="py-2">프리랜서</th><th class="py-2 text-right">예치총액</th>
          <th class="py-2 text-right">수수료율</th><th class="py-2 text-right">수수료</th>
          <th class="py-2 text-right">지급액</th><th class="py-2">상태</th>
          <th class="py-2">지급일</th><th class="py-2"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($settlements as $s): ?>
          <tr class="border-b last:border-0">
            <td class="py-2">#<?= (int)$s['id'] ?></td>
            <td class="py-2 font-mono text-xs"><?= h($s['order_no']) ?></td>
            <td class="py-2 text-slate-600"><?= h($s['freelancer_name']) ?></td>
            <td class="py-2 text-right"><?= won($s['gross_amount']) ?></td>
            <td class="py-2 text-right text-slate-500"><?= h($s['fee_rate']) ?>%</td>
            <td class="py-2 text-right text-brand-700"><?= won($s['fee_amount']) ?></td>
            <td class="py-2 text-right font-semibold"><?= won($s['net_amount']) ?></td>
            <td class="py-2"><span class="badge bg-slate-100 text-slate-600"><?= h(settlement_status_label($s['status'])) ?></span></td>
            <td class="py-2 text-xs text-slate-400"><?= h($s['paid_at'] ?? '-') ?></td>
            <td class="py-2 text-right">
              <?php if (in_array($s['status'], ['pending', 'processing'], true)): ?>
                <button class="btn-success payout-btn" data-id="<?= (int)$s['id'] ?>">지급 완료</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$settlements): ?>
          <tr><td colspan="10" class="py-4 text-center text-slate-400">해당 정산 내역이 없습니다.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  document.querySelectorAll('.payout-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.id;
      if (!confirm('해당 프리랜서에게 지급 완료로 처리할까요?')) return;
      btn.disabled = true;
      try {
        await adminApi('POST', '/admin/settlements/' + id + '/payout');
        adminFlash('지급 완료로 처리했습니다.', true);
        setTimeout(() => location.reload(), 800);
      } catch (err) {
        adminFlash(err.message, false);
        btn.disabled = false;
      }
    });
  });
</script>

<?php admin_footer(); ?>
