<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

admin_guard();

$status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : 'open';

$stmt = db()->prepare(
    'SELECT d.*, o.order_no, o.amount, o.escrow_status, o.status AS order_status,
            c.name AS client_name, f.name AS freelancer_name
     FROM disputes d
     JOIN orders o ON o.id = d.order_id
     JOIN users c ON c.id = o.client_id
     JOIN users f ON f.id = o.freelancer_id
     WHERE d.status = ?
     ORDER BY d.id DESC LIMIT 200'
);
$stmt->execute([$status]);
$disputes = $stmt->fetchAll();

$statusOptions = ['' => '전체', 'open' => '처리 대기', 'resolved' => '처리 완료', 'cancelled' => '취소'];
$resolutionLabel = [
    'none'                 => '-',
    'refund_full'          => '전액 환불',
    'refund_partial'       => '부분 환불',
    'release_to_freelancer'=> '프리랜서 지급',
];

admin_header('분쟁 관리', 'disputes');
?>

<div class="mb-4 flex flex-wrap gap-2">
  <?php foreach ($statusOptions as $value => $label): ?>
    <a href="?status=<?= h($value) ?>"
       class="btn <?= (string)$status === (string)$value ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 border border-slate-200' ?> no-underline">
      <?= h($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="space-y-4">
  <?php foreach ($disputes as $d): ?>
    <div class="card">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="font-semibold">
            <span class="font-mono text-xs text-slate-500"><?= h($d['order_no']) ?></span>
            · <?= won($d['amount']) ?>
            <span class="badge ml-2 bg-slate-100 text-slate-600"><?= h(order_status_label($d['order_status'])) ?></span>
            <span class="badge bg-brand-50 text-brand-700">escrow: <?= h($d['escrow_status']) ?></span>
          </p>
          <p class="mt-1 text-sm text-slate-500">
            의뢰인 <?= h($d['client_name']) ?> / 프리랜서 <?= h($d['freelancer_name']) ?>
            · 제기일 <?= h($d['created_at']) ?>
          </p>
          <p class="mt-2 rounded bg-slate-50 p-3 text-sm"><?= nl2br(h($d['reason'])) ?></p>
          <?php if ($d['status'] === 'resolved'): ?>
            <p class="mt-2 text-sm text-emerald-700">
              처리결과: <?= h($resolutionLabel[$d['resolution']] ?? $d['resolution']) ?>
              <?= $d['refund_amount'] !== null ? '· 환불액 ' . won($d['refund_amount']) : '' ?>
              <?= $d['note'] ? '· ' . h($d['note']) : '' ?>
            </p>
          <?php endif; ?>
        </div>

        <?php if ($d['status'] === 'open' && $d['escrow_status'] === 'held'): ?>
          <div class="flex flex-col gap-2" data-dispute-id="<?= (int)$d['id'] ?>" data-order-id="<?= (int)$d['order_id'] ?>" data-amount="<?= h($d['amount']) ?>">
            <button class="btn-muted resolve-btn" data-decision="refund_full">전액 환불</button>
            <button class="btn-primary resolve-btn" data-decision="refund_partial">부분 환불</button>
            <button class="btn-success resolve-btn" data-decision="release_to_freelancer">프리랜서 지급</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$disputes): ?>
    <div class="card text-center text-slate-400">해당 분쟁이 없습니다.</div>
  <?php endif; ?>
</div>

<script>
  document.querySelectorAll('.resolve-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const wrap = btn.closest('[data-dispute-id]');
      const orderId = wrap.dataset.orderId;
      const total = parseFloat(wrap.dataset.amount);
      const decision = btn.dataset.decision;
      const label = { refund_full: '전액 환불', refund_partial: '부분 환불', release_to_freelancer: '프리랜서 지급' }[decision];

      let refundAmount = 0;
      if (decision === 'refund_partial') {
        const input = prompt('의뢰인에게 환불할 금액(원)을 입력하세요. (총 ' + total.toLocaleString() + '원, 잔여금은 수수료 공제 후 프리랜서에게 지급)', Math.round(total / 2));
        if (input === null) return;
        refundAmount = parseFloat(input);
        if (!(refundAmount > 0 && refundAmount < total)) { adminFlash('환불 금액이 올바르지 않습니다.', false); return; }
      }

      const note = prompt(label + ' 처리 메모(선택)') ?? '';
      if (!confirm('[' + label + '] 로 분쟁을 처리할까요?')) return;

      btn.disabled = true;
      try {
        await adminApi('POST', '/admin/orders/' + orderId + '/resolve-dispute',
          { decision, refund_amount: refundAmount, note });
        adminFlash('분쟁 처리가 완료되었습니다.', true);
        setTimeout(() => location.reload(), 800);
      } catch (err) {
        adminFlash(err.message, false);
        btn.disabled = false;
      }
    });
  });
</script>

<?php admin_footer(); ?>
