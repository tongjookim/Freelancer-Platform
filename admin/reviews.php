<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

admin_guard();

$status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : 'open';

// 신고 목록
$stmt = db()->prepare(
    "SELECT rp.*, r.rating, r.comment, r.status AS review_status, r.order_id,
            ru.name AS reporter_name, eu.name AS reviewee_name, eu.id AS reviewee_id
       FROM review_reports rp
       JOIN reviews r ON r.id = rp.review_id
       JOIN users ru ON ru.id = rp.reporter_id
       JOIN users eu ON eu.id = r.reviewee_id
      WHERE rp.status = ? ORDER BY rp.id DESC LIMIT 200"
);
$stmt->execute([$status]);
$reports = $stmt->fetchAll();

// 블라인드된 리뷰
$hidden = db()->query(
    "SELECT r.*, u.name AS reviewee_name, o.order_no
       FROM reviews r JOIN users u ON u.id = r.reviewee_id JOIN orders o ON o.id = r.order_id
      WHERE r.status = 'hidden' ORDER BY r.id DESC LIMIT 100"
)->fetchAll();

$statusOptions = ['' => '전체', 'open' => '처리 대기', 'resolved' => '처리 완료', 'rejected' => '기각'];

admin_header('리뷰 관리', 'reviews');
?>

<div class="mb-4 flex flex-wrap gap-2">
  <?php foreach ($statusOptions as $value => $label): ?>
    <a href="?status=<?= h($value) ?>"
       class="btn <?= (string)$status === (string)$value ? 'bg-brand-600 text-white' : 'bg-white text-slate-600 border border-slate-200' ?> no-underline">
      <?= h($label) ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="space-y-3">
  <?php foreach ($reports as $r): ?>
    <div class="card">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="text-sm">
            <span class="badge bg-rose-50 text-rose-600">신고</span>
            <span class="ml-2 text-slate-500">신고자 <?= h($r['reporter_name']) ?></span>
            <span class="ml-2 text-slate-400">· <?= h($r['created_at']) ?></span>
          </p>
          <p class="mt-2 rounded bg-slate-50 p-3 text-sm"><?= nl2br(h($r['reason'])) ?></p>
          <div class="mt-2 rounded border border-slate-200 p-3 text-sm">
            <p class="font-medium"><?= h($r['reviewee_name']) ?> 님이 받은 리뷰 · <?= str_repeat('★', (int)$r['rating']) ?><?= str_repeat('☆', 5 - (int)$r['rating']) ?>
              <span class="badge ml-2 <?= $r['review_status'] === 'hidden' ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-700' ?>"><?= h($r['review_status']) ?></span>
            </p>
            <p class="text-slate-600"><?= h($r['comment'] ?? '') ?></p>
          </div>
        </div>
        <div class="flex flex-col gap-2" data-review-id="<?= (int)$r['review_id'] ?>">
          <?php if ($r['review_status'] === 'visible'): ?>
            <button class="btn-muted hide-btn">블라인드 처리</button>
          <?php else: ?>
            <button class="btn-muted restore-btn">블라인드 해제</button>
          <?php endif; ?>
          <?php if ($r['status'] === 'open'): ?>
            <button class="btn-muted reject-btn">신고 기각</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$reports): ?>
    <div class="card text-center text-slate-400">해당 신고가 없습니다.</div>
  <?php endif; ?>
</div>

<?php if ($hidden): ?>
<div class="card mt-6">
  <h2 class="mb-3 font-semibold">블라인드된 리뷰</h2>
  <table class="w-full text-sm">
    <thead><tr class="border-b text-left text-slate-500"><th class="py-2">주문</th><th class="py-2">대상</th><th class="py-2">평점</th><th class="py-2">사유</th><th class="py-2"></th></tr></thead>
    <tbody>
      <?php foreach ($hidden as $h): ?>
        <tr class="border-b last:border-0">
          <td class="py-2 font-mono text-xs"><?= h($h['order_no']) ?></td>
          <td class="py-2"><?= h($h['reviewee_name']) ?></td>
          <td class="py-2"><?= str_repeat('★', (int)$h['rating']) ?></td>
          <td class="py-2 text-slate-500"><?= h($h['hidden_reason'] ?? '-') ?></td>
          <td class="py-2 text-right" data-review-id="<?= (int)$h['id'] ?>"><button class="btn-muted restore-btn">해제</button></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
  async function act(btn, path, body, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    const wrap = btn.closest('[data-review-id]');
    btn.disabled = true;
    try {
      await adminApi('POST', path.replace('{id}', wrap.dataset.reviewId), body || {});
      adminFlash('처리되었습니다.', true);
      setTimeout(() => location.reload(), 700);
    } catch (err) { adminFlash(err.message, false); btn.disabled = false; }
  }
  document.querySelectorAll('.hide-btn').forEach((b) => b.addEventListener('click', () => {
    const reason = prompt('블라인드 사유를 입력하세요');
    if (reason === null) return;
    act(b, '/admin/reviews/{id}/hide', { reason }, '이 리뷰를 블라인드 처리할까요?');
  }));
  document.querySelectorAll('.restore-btn').forEach((b) => b.addEventListener('click', () => {
    act(b, '/admin/reviews/{id}/restore', {}, '블라인드를 해제할까요?');
  }));
  document.querySelectorAll('.reject-btn').forEach((b) => b.addEventListener('click', () => {
    act(b, '/admin/reviews/{id}/restore', {}, '신고를 기각하고 리뷰를 유지할까요?');
  }));
</script>

<?php admin_footer(); ?>
