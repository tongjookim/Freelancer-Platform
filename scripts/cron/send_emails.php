<?php
declare(strict_types=1);

/**
 * 이메일 아웃박스 발송 크론
 * -----------------------------------------------------------------------------
 * email_outbox 의 pending 건을 순차 발송(PHP mail / sendmail).
 * 설정 email_notify_enabled 가 1일 때만 알림이 적재되므로, 이 크론은 항상 돌려도 무해하다.
 *
 * 실행: scripts/cron/run.sh send_emails.php
 */
require __DIR__ . '/../../includes/config.php';

$stamp = date('Y-m-d H:i:s');

$stmt = db()->query("SELECT id, to_email, subject, body, attempts FROM email_outbox WHERE status = 'pending' ORDER BY id LIMIT 50");
$rows = $stmt->fetchAll();

$sent = 0;
$failed = 0;

foreach ($rows as $row) {
    $headers = implode("\r\n", [
        'From: 프리랜서 마켓 <no-reply@work.swnest.net>',
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
    ]);
    $ok = @mail((string)$row['to_email'], (string)$row['subject'], (string)$row['body'], $headers);

    if ($ok) {
        db()->prepare("UPDATE email_outbox SET status = 'sent', sent_at = NOW(), attempts = attempts + 1 WHERE id = ?")
            ->execute([(int)$row['id']]);
        $sent++;
    } else {
        $attempts = (int)$row['attempts'] + 1;
        $status = $attempts >= 3 ? 'failed' : 'pending';
        db()->prepare('UPDATE email_outbox SET attempts = ?, status = ?, error = ? WHERE id = ?')
            ->execute([$attempts, $status, 'mail() failed', (int)$row['id']]);
        $failed++;
    }
}

echo "[$stamp] send_emails done. sent={$sent}, failed={$failed}, candidates=" . count($rows) . "\n";
