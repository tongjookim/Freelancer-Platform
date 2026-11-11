<?php
declare(strict_types=1);

/**
 * =============================================================================
 * NotificationService — 인앱 알림 발송
 * =============================================================================
 * - 알림 실패가 본 트랜잭션/흐름을 깨지 않도록 예외를 삼킨다(로그만).
 * - 실제 발송(이메일/푸시)은 여기서 확장한다(현재는 DB 적재 = 인앱 알림).
 */
final class NotificationService
{
    /** 단일 사용자에게 알림 */
    public static function notify(
        int $userId,
        string $type,
        string $title,
        string $body = '',
        ?string $link = null,
        ?string $refType = null,
        ?int $refId = null
    ): void {
        try {
            $stmt = db()->prepare(
                'INSERT INTO notifications (user_id, type, title, body, link, ref_type, ref_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $type, $title, $body ?: null, $link, $refType, $refId]);

            self::maybeEnqueueEmail($userId, $title, $body, $link);
        } catch (Throwable $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('[notify] ' . $e->getMessage());
            }
        }
    }

    /** 이메일 알림이 켜져 있으면 아웃박스에 적재 (실제 발송은 크론) */
    private static function maybeEnqueueEmail(int $userId, string $title, string $body, ?string $link): void
    {
        try {
            if (get_setting('email_notify_enabled', '0') !== '1') {
                return;
            }
            $stmt = db()->prepare('SELECT email FROM users WHERE id = ? AND status = "active" LIMIT 1');
            $stmt->execute([$userId]);
            $email = $stmt->fetchColumn();
            if (!$email) {
                return;
            }
            $full = $body . ($link ? "\n\n" . 'https://work.swnest.net' . $link : '');
            db()->prepare('INSERT INTO email_outbox (user_id, to_email, subject, body) VALUES (?, ?, ?, ?)')
                ->execute([$userId, (string)$email, '[프리랜서 마켓] ' . $title, $full]);
        } catch (Throwable $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('[notify.email] ' . $e->getMessage());
            }
        }
    }

    /** 모든 관리자에게 알림 */
    public static function notifyAdmins(
        string $type,
        string $title,
        string $body = '',
        ?string $link = null,
        ?string $refType = null,
        ?int $refId = null
    ): void {
        try {
            $admins = db()->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminId) {
                self::notify((int)$adminId, $type, $title, $body, $link, $refType, $refId);
            }
        } catch (Throwable $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                error_log('[notifyAdmins] ' . $e->getMessage());
            }
        }
    }
}
