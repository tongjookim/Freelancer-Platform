<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

admin_guard();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare(
    'SELECT o.*, c.name AS client_name, c.email AS client_email, c.id AS client_id,
            f.name AS freelancer_name, f.email AS freelancer_email, f.id AS freelancer_id
       FROM orders o
       JOIN users c ON c.id = o.client_id
       JOIN users f ON f.id = o.freelancer_id
      WHERE o.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$order = $stmt->fetch();

admin_header('주문 상세', 'orders');

if (!$order) {
    echo '<div class="card text-center text-slate-400">주문을 찾을 수 없습니다. <a href="/admin/orders.php">목록으로</a></div>';
    admin_footer();
    exit;
}

$od = db()->prepare("SELECT COUNT(*) FROM disputes WHERE order_id = ? AND status = 'open'");
$od->execute([$id]);
$openDisputeCount = (int)$od->fetchColumn();

$ledger = db()->prepare('SELECT * FROM escrow_transactions WHERE order_id = ? ORDER BY id');
$ledger->execute([$id]);

$payments = db()->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY id');
$payments->execute([$id]);

$deliverables = db()->prepare('SELECT * FROM deliverables WHERE order_id = ? ORDER BY id DESC');
$deliverables->execute([$id]);

$disputes = db()->prepare('SELECT * FROM disputes WHERE order_id = ? ORDER BY id DESC');
$disputes->execute([$id]);

$settlement = db()->prepare('SELECT * FROM settlements WHERE order_id = ? LIMIT 1');
$settlement->execute([$id]);

$messages = db()->prepare('SELECT m.*, u.name AS sender_name FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.order_id = ? ORDER BY m.id');
$messages->execute([$id]);

$resolutionLabel = ['refund_full' => '전액 환불', 'refund_partial' => '부분 환불', 'release_to_freelancer' => '프리랜서 지급', 'none' => '-'];
?>

<p class="mb-4 text-sm"><a href="/admin/orders.php" class="text-brand-600">← 주문 목록</a></p>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
  <div class="card space-y-1 lg:col-span-2">
    <h2 class="font-semibold"><?= h($order['title']) ?></h2>
    <p class="font-mono text-xs text-slate-500"><?= h($order['order_no']) ?></p>
    <p class="text-sm text-slate-600">의뢰인:
      <a href="/admin/user.php?id=<?= (int)$order['client_id'] ?>" class="text-brand-600"><?= h($order['client_name']) ?></a>
      <span class="text-slate-400">(<?= h($order['client_email']) ?>)</span>
    </p>
    <p class="text-sm text-slate-600">프리랜서:
      <a href="/admin/user.php?id=<?= (int)$order['freelancer_id'] ?>" class="text-brand-600"><?= h($order['freelancer_name']) ?></a>
      <span class="text-slate-400">(<?= h($order['freelancer_email']) ?>)</span>
    </p>
    <p class="text-xs text-slate-400">생성 <?= h($order['created_at']) ?> · 결제 <?= h($order['paid_at'] ?? '-') ?> · 발송 <?= h($order['delivered_at'] ?? '-') ?> · 승인 <?= h($order['approved_at'] ?? '-') ?></p>
  </div>

  <div class="card space-y-2">
    <p class="text-sm text-slate-500">상태</p>
    <p><span class="badge bg-slate-100 text-slate-600"><?= h(order_status_label($order['status'])) ?></span>
       <span class="badge bg-brand-50 text-brand-700">escrow: <?= h($order['escrow_status']) ?></span></p>
    <p class="text-lg font-bold"><?= won($order['amount']) ?></p>
    <?php if ($order['fee_amount'] !== null): ?>
      <p class="text-sm text-slate-600">수수료 <?= h($order['fee_rate']) ?>% · <?= won($order['fee_amount']) ?></p>
      <p class="text-sm font-semibold text-emerald-600">지급액 <?= won($order['net_amount']) ?></p>
    <?php endif; ?>
  </div>
</div>

<?php if ($order['escrow_status'] === 'held'): ?>
<div class="card mt-4">
  <h2 class="mb-2 font-semibold">관리자 작업</h2>
  <div class="flex flex-wrap gap-2" data-order-id="<?= (int)$order['id'] ?>" data-amount="<?= h($order['amount']) ?>">
    <?php if ($openDisputeCount > 0): ?>
      <button class="btn-muted act" data-act="refund_full">분쟁 처리: 전액 환불</button>
      <button class="btn-primary act" data-act="refund_partial">분쟁 처리: 부분 환불</button>
      <button class="btn-success act" data-act="release_to_freelancer">분쟁 처리: 프리랜서 지급</button>
    <?php else: ?>
      <button class="btn-muted act" data-act="admin_refund">강제 환불 (의뢰인)</button>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
  <!-- 에스크로 원장 -->
  <div class="card">
    <h2 class="mb-2 font-semibold">에스크로 원장</h2>
    <table class="w-full text-sm">
      <thead><tr class="border-b text-left text-slate-500"><th class="py-2">유형</th><th class="py-2">방향</th><th class="py-2 text-right">금액</th><th class="py-2">시각</th></tr></thead>
      <tbody>
        <?php foreach ($ledger->fetchAll() as $t): ?>
          <tr class="border-b last:border-0">
            <td class="py-2"><?= h($t['type']) ?></td>
            <td class="py-2 text-slate-500"><?= $t['direction'] === 'in' ? '유입' : '유출' ?></td>
            <td class="py-2 text-right"><?= won($t['amount']) ?></td>
            <td class="py-2 text-xs text-slate-400"><?= h($t['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($ledger->rowCount() === 0): ?><tr><td colspan="4" class="py-3 text-center text-slate-400">내역 없음</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- 결제 / 정산 -->
  <div class="card">
    <h2 class="mb-2 font-semibold">결제 / 정산</h2>
    <table class="w-full text-sm">
      <thead><tr class="border-b text-left text-slate-500"><th class="py-2">PG</th><th class="py-2">거래번호</th><th class="py-2 text-right">금액</th><th class="py-2">상태</th></tr></thead>
      <tbody>
        <?php foreach ($payments->fetchAll() as $p): ?>
          <tr class="border-b last:border-0">
            <td class="py-2"><?= h($p['pg_provider']) ?></td>
            <td class="py-2 font-mono text-xs"><?= h($p['pg_tx_id'] ?? '-') ?></td>
            <td class="py-2 text-right"><?= won($p['amount']) ?></td>
            <td class="py-2"><span class="badge bg-slate-100 text-slate-600"><?= h($p['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($payments->rowCount() === 0): ?><tr><td colspan="4" class="py-3 text-center text-slate-400">결제 없음</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php if ($st = $settlement->fetch()): ?>
      <p class="mt-3 border-t pt-2 text-sm">
        정산 #<?= (int)$st['id'] ?> · 기준액 <?= won($st['gross_amount']) ?> · 지급액 <span class="font-semibold"><?= won($st['net_amount']) ?></span>
        · <span class="badge bg-slate-100 text-slate-600"><?= h(settlement_status_label($st['status'])) ?></span>
      </p>
    <?php endif; ?>
  </div>
</div>

<!-- 작업물 -->
<div class="card mt-6">
  <h2 class="mb-2 font-semibold">작업물 (<?= $deliverables->rowCount() ?>)</h2>
  <?php foreach ($deliverables->fetchAll() as $d): ?>
    <div class="border-b py-2 text-sm last:border-0">
      <p class="font-medium">v<?= (int)$d['version'] ?> · <?= h($d['created_at']) ?></p>
      <p class="text-slate-600"><?= h($d['message'] ?? '') ?></p>
      <?php if ($d['file_path']): ?><a href="/<?= h($d['file_path']) ?>" target="_blank" rel="noreferrer">첨부 다운로드</a><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($deliverables->rowCount() === 0): ?><p class="text-slate-400">제출된 작업물이 없습니다.</p><?php endif; ?>
</div>

<!-- 분쟁 -->
<div class="card mt-6">
  <h2 class="mb-2 font-semibold">분쟁 (<?= $disputes->rowCount() ?>)</h2>
  <?php foreach ($disputes->fetchAll() as $d): ?>
    <div class="border-b py-2 text-sm last:border-0">
      <p><span class="badge <?= $d['status'] === 'open' ? 'bg-rose-50 text-rose-600' : 'bg-slate-100 text-slate-600' ?>"><?= h($d['status']) ?></span>
         <?= h($resolutionLabel[$d['resolution']] ?? $d['resolution']) ?>
         <?= $d['refund_amount'] !== null ? '· 환불 ' . won($d['refund_amount']) : '' ?></p>
      <p class="text-slate-600"><?= h($d['reason']) ?></p>
      <p class="text-xs text-slate-400"><?= h($d['created_at']) ?></p>
    </div>
  <?php endforeach; ?>
  <?php if ($disputes->rowCount() === 0): ?><p class="text-slate-400">분쟁 이력이 없습니다.</p><?php endif; ?>
</div>

<!-- 메시지 -->
<div class="card mt-6">
  <h2 class="mb-2 font-semibold">메시지 (<?= $messages->rowCount() ?>)</h2>
  <div class="max-h-80 space-y-1 overflow-y-auto">
    <?php foreach ($messages->fetchAll() as $m): ?>
      <div class="rounded bg-slate-50 p-2 text-sm">
        <span class="font-medium"><?= h($m['sender_name']) ?></span>
        <span class="ml-2 text-xs text-slate-400"><?= h($m['created_at']) ?></span>
        <p><?= h($m['content']) ?></p>
      </div>
    <?php endforeach; ?>
    <?php if ($messages->rowCount() === 0): ?><p class="text-slate-400">메시지가 없습니다.</p><?php endif; ?>
  </div>
</div>

<script>
  document.querySelectorAll('.act').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const wrap = btn.closest('[data-order-id]');
      const orderId = wrap.dataset.orderId;
      const total = parseFloat(wrap.dataset.amount);
      const act = btn.dataset.act;

      if (act === 'admin_refund') {
        const reason = prompt('강제 환불 사유'); if (reason === null) return;
        if (!confirm('예치 대금을 의뢰인에게 환불 처리할까요?')) return;
        try { await adminApi('POST', '/orders/' + orderId + '/refund', { reason }); adminFlash('환불 처리되었습니다.', true); setTimeout(() => location.reload(), 700); }
        catch (e) { adminFlash(e.message, false); }
        return;
      }

      // 분쟁 처리
      let refundAmount = 0;
      if (act === 'refund_partial') {
        const input = prompt('의뢰인에게 환불할 금액(원) (총 ' + total.toLocaleString() + '원)', Math.round(total / 2));
        if (input === null) return;
        refundAmount = parseFloat(input);
        if (!(refundAmount > 0 && refundAmount < total)) { adminFlash('환불 금액이 올바르지 않습니다.', false); return; }
      }
      const label = { refund_full: '전액 환불', refund_partial: '부분 환불', release_to_freelancer: '프리랜서 지급' }[act];
      const note = prompt(label + ' 처리 메모(선택)') ?? '';
      if (!confirm('[' + label + '] 로 분쟁을 처리할까요?')) return;
      try { await adminApi('POST', '/admin/orders/' + orderId + '/resolve-dispute', { decision: act, refund_amount: refundAmount, note }); adminFlash('분쟁 처리가 완료되었습니다.', true); setTimeout(() => location.reload(), 700); }
      catch (e) { adminFlash(e.message, false); }
    });
  });
</script>

<?php admin_footer(); ?>
