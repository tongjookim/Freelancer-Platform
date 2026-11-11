<?php
declare(strict_types=1);

/**
 * 자동 승인(에스크로 자동 정산) 크론
 * -----------------------------------------------------------------------------
 * 검수 대기(delivered) 상태로 auto_release_days 일이 지난 주문을
 * 의뢰인 최종 승인 없이 자동으로 정산(release)한다.
 *
 * 실행: scripts/cron/run.sh auto_release.php
 *   crontab 예) 30분마다 root 권한으로 run.sh 실행 (설정: /etc/cron.d/web-project)
 */
require __DIR__ . '/../../includes/config.php';
require __DIR__ . '/../../includes/Escrow/EscrowService.php';

$days = (int)get_setting('auto_release_days', '7');
$stamp = date('Y-m-d H:i:s');

if ($days <= 0) {
    echo "[$stamp] auto_release: 비활성 (auto_release_days=0)\n";
    exit(0);
}

$stmt = db()->prepare(
    "SELECT id, client_id, order_no
       FROM orders
      WHERE status = 'delivered'
        AND escrow_status = 'held'
        AND delivered_at IS NOT NULL
        AND delivered_at <= (NOW() - INTERVAL ? DAY)
      ORDER BY id
      LIMIT 200"
);
$stmt->execute([$days]);
$orders = $stmt->fetchAll();

$svc = new EscrowService();
$ok = 0;
$fail = 0;

foreach ($orders as $o) {
    try {
        $svc->release((int)$o['id'], (int)$o['client_id']);
        $ok++;
        echo "[$stamp] auto-released order #{$o['id']} ({$o['order_no']})\n";
    } catch (Throwable $e) {
        $fail++;
        echo "[$stamp] FAILED order #{$o['id']} ({$o['order_no']}): {$e->getMessage()}\n";
    }
}

echo "[$stamp] auto_release done. days={$days}, released={$ok}, failed={$fail}, candidates=" . count($orders) . "\n";
