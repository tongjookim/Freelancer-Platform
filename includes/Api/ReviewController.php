<?php
declare(strict_types=1);

/** 완료 주문에 대한 상호 리뷰/평점 */
final class ReviewController
{
    /** POST /orders/{id}/reviews  { rating, comment } */
    public static function store(string $orderId): void
    {
        $user = require_login();
        $oid = (int)$orderId;
        $data = json_input();
        require_fields($data, ['rating']);

        $rating = (int)$data['rating'];
        if ($rating < 1 || $rating > 5) {
            json_error('평점은 1~5 사이여야 합니다.', 422);
        }

        $stmt = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$oid]);
        $order = $stmt->fetch();
        if (!$order) {
            json_error('주문을 찾을 수 없습니다.', 404);
        }
        if ((int)$order['client_id'] !== (int)$user['id'] && (int)$order['freelancer_id'] !== (int)$user['id']) {
            json_error('해당 주문의 당사자만 리뷰를 작성할 수 있습니다.', 403);
        }
        if ($order['status'] !== 'completed') {
            json_error('완료된 주문에만 리뷰를 남길 수 있습니다.', 409);
        }

        $revieweeId = (int)$order['client_id'] === (int)$user['id']
            ? (int)$order['freelancer_id']
            : (int)$order['client_id'];

        try {
            db_transaction(function (PDO $pdo) use ($oid, $user, $revieweeId, $rating, $data) {
                $ins = $pdo->prepare(
                    'INSERT INTO reviews (order_id, reviewer_id, reviewee_id, rating, comment)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $ins->execute([$oid, (int)$user['id'], $revieweeId, $rating, $data['comment'] ?? null]);

                // 프리랜서 평점 집계 갱신
                self::recomputeRating($pdo, $revieweeId);
            });
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_error('이미 리뷰를 작성했습니다.', 409);
            }
            throw $e;
        }

        audit_log('review.create', 'order', $oid, ['rating' => $rating, 'reviewee' => $revieweeId]);
        NotificationService::notify($revieweeId, 'review.created', '새 리뷰가 등록되었습니다',
            '평점 ' . $rating . '점 · ' . (string)($data['comment'] ?? ''), null, 'order', $oid);
        json_response(['message' => '리뷰가 등록되었습니다.'], 201);
    }

    /** GET /orders/{id}/reviews — 해당 주문의 리뷰 목록 (블라인드 제외) */
    public static function forOrder(string $orderId): void
    {
        require_login();
        $stmt = db()->prepare(
            "SELECT r.*, u.name AS reviewer_name FROM reviews r
             JOIN users u ON u.id = r.reviewer_id
             WHERE r.order_id = ? AND r.status = 'visible' ORDER BY r.id"
        );
        $stmt->execute([(int)$orderId]);
        json_response(['reviews' => $stmt->fetchAll()]);
    }

    /** GET /reviews?user_id= — 사용자가 받은 리뷰 + 요약 (블라인드 제외) */
    public static function listByUser(): void
    {
        $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : current_user_id();
        if (!$userId) {
            json_error('user_id 가 필요합니다.', 422);
        }
        $stmt = db()->prepare(
            "SELECT r.*, u.name AS reviewer_name, o.title AS order_title
             FROM reviews r
             JOIN users u ON u.id = r.reviewer_id
             JOIN orders o ON o.id = r.order_id
             WHERE r.reviewee_id = ? AND r.status = 'visible' ORDER BY r.id DESC LIMIT 100"
        );
        $stmt->execute([$userId]);
        $reviews = $stmt->fetchAll();

        $avg = null;
        if ($reviews) {
            $avg = round(array_sum(array_map(fn($r) => (int)$r['rating'], $reviews)) / count($reviews), 2);
        }
        json_response(['reviews' => $reviews, 'count' => count($reviews), 'average' => $avg]);
    }

    /** POST /reviews/{id}/report  { reason } */
    public static function report(string $id): void
    {
        $user = require_login();
        $rid = (int)$id;
        $reason = trim((string)(json_input()['reason'] ?? ''));
        if ($reason === '') {
            json_error('신고 사유를 입력해 주세요.', 422);
        }

        $stmt = db()->prepare('SELECT reviewer_id, reviewee_id FROM reviews WHERE id = ? LIMIT 1');
        $stmt->execute([$rid]);
        $review = $stmt->fetch();
        if (!$review) {
            json_error('리뷰를 찾을 수 없습니다.', 404);
        }
        if ((int)$review['reviewer_id'] === (int)$user['id']) {
            json_error('본인이 작성한 리뷰는 신고할 수 없습니다.', 422);
        }

        try {
            db()->prepare('INSERT INTO review_reports (review_id, reporter_id, reason) VALUES (?, ?, ?)')
                ->execute([$rid, (int)$user['id'], $reason]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_error('이미 신고한 리뷰입니다.', 409);
            }
            throw $e;
        }

        NotificationService::notifyAdmins('review.reported', '리뷰 신고 접수', $reason, '/admin/reviews.php', 'review', $rid);
        audit_log('review.report', 'review', $rid, ['reason' => $reason]);

        $autoHidden = self::autoHideIfThresholdReached($rid);

        json_response([
            'message' => $autoHidden
                ? '신고가 누적되어 리뷰가 자동 블라인드 처리되었습니다.'
                : '신고가 접수되었습니다. 관리자 검토 후 처리됩니다.',
        ], 201);
    }

    /** 신고 임계치 도달 시 자동 블라인드 (설정 review_auto_hide_reports > 0) */
    private static function autoHideIfThresholdReached(int $reviewId): bool
    {
        $threshold = (int)get_setting('review_auto_hide_reports', '0');
        if ($threshold <= 0) {
            return false;
        }
        $open = db()->prepare("SELECT COUNT(*) FROM review_reports WHERE review_id = ? AND status = 'open'");
        $open->execute([$reviewId]);
        if ((int)$open->fetchColumn() < $threshold) {
            return false;
        }

        db_transaction(function (PDO $pdo) use ($reviewId) {
            $s = $pdo->prepare('SELECT reviewee_id, status FROM reviews WHERE id = ? FOR UPDATE');
            $s->execute([$reviewId]);
            $r = $s->fetch();
            if (!$r || $r['status'] === 'hidden') {
                return;
            }
            $pdo->prepare("UPDATE reviews SET status = 'hidden', hidden_reason = '신고 누적 자동 블라인드', hidden_at = NOW() WHERE id = ?")
                ->execute([$reviewId]);
            $pdo->prepare("UPDATE review_reports SET status = 'resolved', handled_at = NOW(), note = '자동 블라인드' WHERE review_id = ? AND status = 'open'")
                ->execute([$reviewId]);
            self::recomputeRating($pdo, (int)$r['reviewee_id']);
        });

        audit_log('review.auto_hide', 'review', $reviewId, ['threshold' => $threshold]);
        NotificationService::notifyAdmins('review.auto_hidden', '리뷰 자동 블라인드', "신고 누적으로 리뷰 #{$reviewId}가 자동 블라인드되었습니다.", '/admin/reviews.php', 'review', $reviewId);
        return true;
    }

    /** 프리랜서 평점 재집계 (visible 만) */
    public static function recomputeRating(PDO $pdo, int $revieweeId): void
    {
        $agg = $pdo->prepare("SELECT AVG(rating) AS avg_r, COUNT(*) AS cnt FROM reviews WHERE reviewee_id = ? AND status = 'visible'");
        $agg->execute([$revieweeId]);
        $a = $agg->fetch();
        $pdo->prepare('UPDATE freelancer_profiles SET rating_avg = ?, rating_count = ? WHERE user_id = ?')
            ->execute([round((float)($a['avg_r'] ?? 0), 2), (int)$a['cnt'], $revieweeId]);
    }
}
