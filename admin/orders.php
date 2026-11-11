<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

admin_guard();

$status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : null;

$sql = 'SELECT o.*, c.name AS client_name, f.name AS freelancer_name
        FROM orders o
        JOIN users c ON c.id = o.client_id
        JOIN users f ON f.id = o.freelancer_id';
$params = [];
if ($status !== null) {
    $sql .= ' WHERE o.status = ?';
    $params[] = $status;
}
$sql .= ' ORDER BY o.id DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$statusOptions = [
    ''                   => '전체',
    'pending_payment'    => '결제 대기',
    'in_progress'        => '작업 진행',
    'delivered'          => '검수 대기',
    'completed'          => '완료',
    'disputed'           => '분쟁',
    'refunded'           => '환불',
    'cancelled'          => '취소',
];

admin_header('주문·결제 내역', 'orders');
?>

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
          <th class="py-2">주문번호</th><th class="py-2">제목</th>
          <th class="py-2">의뢰인</th><th class="py-2">프리랜서</th>
          <th class="py-2 text-right">금액</th><th class="py-2 text-right">수수료</th>
          <th class="py-2 text-right">지급액</th><th class="py-2">상태</th>
          <th class="py-2">에스크로</th><th class="py-2"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
          <tr class="border-b last:border-0">
            <td class="py-2 font-mono text-xs"><a href="/admin/order.php?id=<?= (int)$o['id'] ?>" class="text-brand-600"><?= h($o['order_no']) ?></a></td>
            <td class="py-2"><?= h($o['title']) ?></td>
            <td class="py-2 text-slate-600"><?= h($o['client_name']) ?></td>
            <td class="py-2 text-slate-600"><?= h($o['freelancer_name']) ?></td>
            <td class="py-2 text-right"><?= won($o['amount']) ?></td>
            <td class="py-2 text-right text-slate-500"><?= $o['fee_amount'] !== null ? won($o['fee_amount']) : '-' ?></td>
            <td class="py-2 text-right text-emerald-600"><?= $o['net_amount'] !== null ? won($o['net_amount']) : '-' ?></td>
            <td class="py-2"><span class="badge bg-slate-100 text-slate-600"><?= h(order_status_label($o['status'])) ?></span></td>
            <td class="py-2"><span class="badge bg-brand-50 text-brand-700"><?= h($o['escrow_status']) ?></span></td>
            <td class="py-2 text-right">
              <?php if ($o['escrow_status'] === 'held'): ?>
                <button class="btn-muted refund-btn" data-id="<?= (int)$o['id'] ?>">환불</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$orders): ?>
          <tr><td colspan="10" class="py-4 text-center text-slate-400">해당 주문이 없습니다.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  document.querySelectorAll('.refund-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.id;
      const reason = prompt('환불 사유를 입력하세요 (관리자 강제 환불)');
      if (reason === null) return;
      if (!confirm('이 주문의 예치 대금을 의뢰인에게 환불 처리합니다. 진행할까요?')) return;
      btn.disabled = true;
      try {
        await adminApi('POST', '/orders/' + id + '/refund', { reason });
        adminFlash('환불 처리되었습니다. 새로고침합니다…', true);
        setTimeout(() => location.reload(), 800);
      } catch (err) {
        adminFlash(err.message, false);
        btn.disabled = false;
      }
    });
  });
</script>

<?php admin_footer(); ?>
