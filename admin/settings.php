<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

admin_guard();

$settings = db()->query(
    'SELECT setting_key, setting_value, description, updated_at FROM platform_settings ORDER BY setting_key'
)->fetchAll();

$current = [];
foreach ($settings as $row) {
    $current[$row['setting_key']] = $row['setting_value'];
}

admin_header('수수료 설정', 'settings');
?>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
  <!-- 설정 폼 -->
  <form id="settings-form" class="card space-y-4">
    <h2 class="font-semibold">플랫폼 설정</h2>

    <label class="block text-sm font-medium text-slate-600">
      기본 수수료율 (%)
      <input id="default_fee_rate" type="number" step="0.01" min="0" max="100"
             class="input mt-1" value="<?= h($current['default_fee_rate'] ?? '10.00') ?>">
      <span class="mt-1 block text-xs text-slate-400">최종 승인 시점에 이 요율이 적용되어 정산됩니다.</span>
    </label>

    <label class="block text-sm font-medium text-slate-600">
      최소 정산 지급 금액 (원)
      <input id="min_payout_amount" type="number" step="1" min="0"
             class="input mt-1" value="<?= h($current['min_payout_amount'] ?? '10000') ?>">
    </label>

    <label class="block text-sm font-medium text-slate-600">
      정산 주기
      <select id="settlement_cycle" class="input mt-1">
        <?php foreach (['daily' => '일별', 'weekly' => '주별', 'monthly' => '월별'] as $v => $label): ?>
          <option value="<?= h($v) ?>" <?= ($current['settlement_cycle'] ?? 'weekly') === $v ? 'selected' : '' ?>>
            <?= h($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <hr class="border-slate-200">
    <h3 class="font-semibold text-slate-700">환불/분쟁 정책</h3>

    <label class="block text-sm font-medium text-slate-600">
      작업물 발송 전 의뢰인 자체 환불
      <select id="client_self_refund_allowed" class="input mt-1">
        <option value="1" <?= ($current['client_self_refund_allowed'] ?? '1') === '1' ? 'selected' : '' ?>>허용</option>
        <option value="0" <?= ($current['client_self_refund_allowed'] ?? '1') === '0' ? 'selected' : '' ?>>관리자만 허용</option>
      </select>
      <span class="mt-1 block text-xs text-slate-400">작업물 제출 이후에는 항상 분쟁을 통해서만 환불됩니다.</span>
    </label>

    <label class="block text-sm font-medium text-slate-600">
      분쟁 처리 기한 (일)
      <input id="dispute_sla_days" type="number" step="1" min="0" class="input mt-1"
             value="<?= h($current['dispute_sla_days'] ?? '7') ?>">
    </label>

    <label class="block text-sm font-medium text-slate-600">
      자동 승인 (검수 대기 후 일수)
      <input id="auto_release_days" type="number" step="1" min="0" class="input mt-1"
             value="<?= h($current['auto_release_days'] ?? '7') ?>">
      <span class="mt-1 block text-xs text-slate-400">작업물 발송 후 이 일수가 지나면 자동 정산됩니다. 0이면 비활성(크론은 30분마다 실행).</span>
    </label>

    <label class="block text-sm font-medium text-slate-600">
      이메일 알림 발송
      <select id="email_notify_enabled" class="input mt-1">
        <option value="0" <?= ($current['email_notify_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>사용 안 함</option>
        <option value="1" <?= ($current['email_notify_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>발송</option>
      </select>
      <span class="mt-1 block text-xs text-slate-400">켜면 인앱 알림이 이메일 아웃박스에 적재되고, 크론(5분)이 발송합니다.</span>
    </label>

    <label class="block text-sm font-medium text-slate-600">
      리뷰 자동 블라인드 임계치 (신고 건수)
      <input id="review_auto_hide_reports" type="number" step="1" min="0" class="input mt-1"
             value="<?= h($current['review_auto_hide_reports'] ?? '0') ?>">
      <span class="mt-1 block text-xs text-slate-400">신고가 이 건수에 도달하면 리뷰가 자동 블라인드됩니다. 0이면 사용 안 함.</span>
    </label>

    <button type="submit" class="btn-primary">설정 저장</button>
  </form>

  <!-- 현재 값 -->
  <div class="card">
    <h2 class="mb-3 font-semibold">현재 설정값</h2>
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b text-left text-slate-500">
          <th class="py-2">키</th><th class="py-2">값</th><th class="py-2">설명</th><th class="py-2">갱신</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($settings as $row): ?>
          <tr class="border-b last:border-0">
            <td class="py-2 font-mono text-xs"><?= h($row['setting_key']) ?></td>
            <td class="py-2 font-semibold"><?= h($row['setting_value']) ?></td>
            <td class="py-2 text-slate-500"><?= h($row['description'] ?? '') ?></td>
            <td class="py-2 text-xs text-slate-400"><?= h($row['updated_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  document.getElementById('settings-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const settings = {
      default_fee_rate: document.getElementById('default_fee_rate').value,
      min_payout_amount: document.getElementById('min_payout_amount').value,
      settlement_cycle: document.getElementById('settlement_cycle').value,
      client_self_refund_allowed: document.getElementById('client_self_refund_allowed').value,
      dispute_sla_days: document.getElementById('dispute_sla_days').value,
      auto_release_days: document.getElementById('auto_release_days').value,
      email_notify_enabled: document.getElementById('email_notify_enabled').value,
      review_auto_hide_reports: document.getElementById('review_auto_hide_reports').value,
    };
    try {
      await adminApi('PUT', '/admin/settings', { settings });
      adminFlash('설정이 저장되었습니다.', true);
      setTimeout(() => location.reload(), 800);
    } catch (err) {
      adminFlash(err.message, false);
    }
  });
</script>

<?php admin_footer(); ?>
