<?php
declare(strict_types=1);

/** 인앱 알림 조회/읽음 처리 */
final class NotificationController
{
    /** GET /notifications?limit=30 */
    public static function index(): void
    {
        $user = require_login();
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));

        $stmt = db()->prepare(
            'SELECT id, type, title, body, link, ref_type, ref_id, is_read, created_at
               FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([(int)$user['id']]);
        $items = $stmt->fetchAll();

        $cnt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $cnt->execute([(int)$user['id']]);

        json_response(['notifications' => $items, 'unread' => (int)$cnt->fetchColumn()]);
    }

    /** POST /notifications/{id}/read */
    public static function markRead(string $id): void
    {
        $user = require_login();
        $stmt = db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ? AND is_read = 0');
        $stmt->execute([(int)$id, (int)$user['id']]);
        json_response(['message' => '읽음 처리되었습니다.']);
    }

    /** POST /notifications/read-all */
    public static function markAllRead(): void
    {
        $user = require_login();
        db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0')
            ->execute([(int)$user['id']]);
        json_response(['message' => '모두 읽음 처리되었습니다.']);
    }
}
