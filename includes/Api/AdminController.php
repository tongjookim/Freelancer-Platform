<?php
declare(strict_types=1);

/** 관리자: 대시보드 / 수수료율 설정 / 주문·정산·회원 관리 */
final class AdminController
{
    // ---- 대시보드 ---------------------------------------------------------
    /** 에스크로 보관 총액, 수수료 수익, 정산 현황 요약 */
    public static function dashboard(): void
    {
        require_role('admin');

        $escrowHeld = (float)db()->query(
            "SELECT COALESCE(SUM(amount),0) FROM orders WHERE escrow_status = 'held'"
        )->fetchColumn();

        $feeRevenue = (float)db()->query(
            "SELECT COALESCE(SUM(fee_amount),0) FROM orders WHERE escrow_status = 'released'"
        )->fetchColumn();

        $payoutPending = (float)db()->query(
            "SELECT COALESCE(SUM(net_amount),0) FROM settlements WHERE status IN ('pending','processing')"
        )->fetchColumn();

        $paidOut = (float)db()->query(
            "SELECT COALESCE(SUM(net_amount),0) FROM settlements WHERE status = 'paid'"
        )->fetchColumn();

        $counts = db()->query(
            "SELECT status, COUNT(*) AS cnt FROM orders GROUP BY status"
        )->fetchAll();

        json_response([
            'escrow_held'     => money($escrowHeld),
            'fee_revenue'     => money($feeRevenue),
            'payout_pending'  => money($payoutPending),
            'paid_out'        => money($paidOut),
            'order_status'    => $counts,
            'fee_rate'        => default_fee_rate(),
        ]);
    }

    // ---- 수수료율 / 설정 --------------------------------------------------
    public static function getSettings(): void
    {
        require_role('admin');
        $rows = db()->query('SELECT setting_key, setting_value, description, updated_at FROM platform_settings ORDER BY setting_key')->fetchAll();
        json_response(['settings' => $rows]);
    }

    public static function updateSettings(): void
    {
        require_role('admin');
        $data = json_input();
        require_fields($data, ['settings']);

        $settings = (array)$data['settings'];
        if (isset($settings['default_fee_rate'])) {
            $rate = (float)$settings['default_fee_rate'];
            if ($rate < 0 || $rate > 100) {
                json_error('수수료율은 0~100 사이여야 합니다.', 422);
            }
        }

        db_transaction(function (PDO $pdo) use ($settings): void {
            $stmt = $pdo->prepare(
                'INSERT INTO platform_settings (setting_key, setting_value)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ($settings as $k => $v) {
                $stmt->execute([$k, (string)$v]);
            }
        });

        audit_log('settings.update', 'platform_settings', null, $settings);
        json_response(['message' => '설정이 저장되었습니다.']);
    }

    // ---- 통계 -------------------------------------------------------------
    public static function stats(): void
    {
        require_role('admin');

        $monthly = db()->query(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym,
                    COUNT(*) AS orders,
                    COALESCE(SUM(amount),0) AS gmv,
                    COALESCE(SUM(fee_amount),0) AS fee
               FROM orders
              GROUP BY ym ORDER BY ym DESC LIMIT 12"
        )->fetchAll();

        $top = db()->query(
            "SELECT f.id, f.name, COUNT(*) AS orders, COALESCE(SUM(o.net_amount),0) AS paid
               FROM orders o JOIN users f ON f.id = o.freelancer_id
              WHERE o.escrow_status IN ('released','partially_refunded')
              GROUP BY f.id, f.name ORDER BY paid DESC LIMIT 10"
        )->fetchAll();

        $disputeCounts = db()->query(
            "SELECT status, COUNT(*) AS cnt FROM disputes GROUP BY status"
        )->fetchAll();

        $totals = db()->query(
            "SELECT COUNT(*) AS total_orders, COALESCE(SUM(amount),0) AS gmv,
                    COALESCE(SUM(fee_amount),0) AS fee FROM orders"
        )->fetch();

        json_response(compact('monthly', 'top', 'disputeCounts', 'totals'));
    }

    // ---- 주문 조회 --------------------------------------------------------
    public static function orders(): void
    {
        require_role('admin');
        $status = $_GET['status'] ?? null;

        $sql = 'SELECT o.*, c.name AS client_name, f.name AS freelancer_name
                FROM orders o
                JOIN users c ON c.id = o.client_id
                JOIN users f ON f.id = o.freelancer_id';
        $params = [];
        if ($status) {
            $sql .= ' WHERE o.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY o.id DESC LIMIT 200';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        json_response(['orders' => $stmt->fetchAll()]);
    }

    // ---- 정산 관리 --------------------------------------------------------
    public static function settlements(): void
    {
        require_role('admin');
        $status = $_GET['status'] ?? null;

        $sql = 'SELECT s.*, u.name AS freelancer_name, o.order_no
                FROM settlements s
                JOIN users u ON u.id = s.freelancer_id
                JOIN orders o ON o.id = s.order_id';
        $params = [];
        if ($status) {
            $sql .= ' WHERE s.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY s.id DESC LIMIT 200';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        json_response(['settlements' => $stmt->fetchAll()]);
    }

    /** 정산 지급 완료 처리 */
    public static function markPaid(string $id): void
    {
        require_role('admin');
        $settlementId = (int)$id;

        db_transaction(function (PDO $pdo) use ($settlementId): void {
            $stmt = $pdo->prepare('SELECT * FROM settlements WHERE id = ? FOR UPDATE');
            $stmt->execute([$settlementId]);
            $s = $stmt->fetch();
            if (!$s) {
                throw new DomainException('정산 내역을 찾을 수 없습니다.');
            }
            if ($s['status'] === 'paid') {
                return; // 멱등
            }
            if ($s['status'] === 'cancelled') {
                throw new DomainException('취소된 정산은 지급할 수 없습니다.');
            }
            $pdo->prepare("UPDATE settlements SET status = 'paid', paid_at = NOW() WHERE id = ?")
                ->execute([$settlementId]);
        });

        audit_log('settlement.payout', 'settlement', $settlementId, []);
        $row = db()->prepare('SELECT freelancer_id, net_amount, order_id FROM settlements WHERE id = ? LIMIT 1');
        $row->execute([$settlementId]);
        if ($s = $row->fetch()) {
            NotificationService::notify((int)$s['freelancer_id'], 'settlement.paid', '정산금이 지급되었습니다',
                number_format((float)$s['net_amount']) . '원이 지급 완료 처리되었습니다.',
                '/orders/' . (int)$s['order_id'], 'settlement', $settlementId);
        }
        json_response(['message' => '정산 지급 완료로 처리했습니다.']);
    }

    // ---- 회원 관리 --------------------------------------------------------
    public static function users(): void
    {
        require_role('admin');
        $role = $_GET['role'] ?? null;

        $sql = 'SELECT id, email, name, role, status, created_at FROM users';
        $params = [];
        if ($role) {
            $sql .= ' WHERE role = ?';
            $params[] = $role;
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        json_response(['users' => $stmt->fetchAll()]);
    }

    /** 회원 상태 변경: active | suspended | withdrawn */
    public static function updateUserStatus(string $id): void
    {
        $admin = require_role('admin');
        $userId = (int)$id;
        $status = (string)(json_input()['status'] ?? '');

        if (!in_array($status, ['active', 'suspended', 'withdrawn'], true)) {
            json_error('유효하지 않은 상태값입니다.', 422);
        }
        if ($userId === (int)$admin['id']) {
            json_error('본인 계정의 상태는 변경할 수 없습니다.', 422);
        }

        $stmt = db()->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) {
            json_error('회원을 찾을 수 없습니다.', 404);
        }

        db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $userId]);
        audit_log('user.status_change', 'user', $userId, ['status' => $status]);
        json_response(['message' => '회원 상태가 변경되었습니다.', 'status' => $status]);
    }

    /** 관리자에 의한 임시 비밀번호 재설정 — 생성된 임시 비밀번호를 1회 반환 */
    public static function resetPassword(string $id): void
    {
        require_role('admin');
        $userId = (int)$id;

        $stmt = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) {
            json_error('회원을 찾을 수 없습니다.', 404);
        }

        $temp = bin2hex(random_bytes(4)) . rand(100, 999); // 11자리 임시 비밀번호
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($temp, PASSWORD_DEFAULT), $userId]);

        audit_log('user.password_reset', 'user', $userId);
        json_response(['message' => '임시 비밀번호를 발급했습니다. 사용자에게 안전하게 전달하세요.', 'temp_password' => $temp]);
    }

    // ---- 분쟁 처리 --------------------------------------------------------
    /** 관리자 분쟁 목록 */
    public static function disputes(): void
    {
        require_role('admin');
        $status = $_GET['status'] ?? 'open';

        $stmt = db()->prepare(
            'SELECT d.*, o.order_no, o.amount, o.status AS order_status,
                    c.name AS client_name, f.name AS freelancer_name
             FROM disputes d
             JOIN orders o ON o.id = d.order_id
             JOIN users c ON c.id = o.client_id
             JOIN users f ON f.id = o.freelancer_id
             WHERE d.status = ? ORDER BY d.id DESC LIMIT 200'
        );
        $stmt->execute([$status]);
        json_response(['disputes' => $stmt->fetchAll()]);
    }

    /**
     * 분쟁 처리: decision = refund_full | refund_partial | release_to_freelancer
     * POST /admin/orders/{id}/resolve-dispute
     */
    public static function resolveDispute(string $id): void
    {
        $admin = require_role('admin');
        $data = json_input();
        $decision = (string)($data['decision'] ?? '');
        $refundAmount = (float)($data['refund_amount'] ?? 0);
        $note = (string)($data['note'] ?? '');

        $order = new EscrowService();
        $updated = $order->resolveDispute((int)$id, (int)$admin['id'], $decision, $refundAmount, $note);
        json_response(['message' => '분쟁 처리가 완료되었습니다.', 'order' => $updated]);
    }

    // ---- 리뷰 관리 (신고/블라인드) ----------------------------------------
    /** GET /admin/review-reports?status=open */
    public static function reviewReports(): void
    {
        require_role('admin');
        $status = $_GET['status'] ?? 'open';

        $stmt = db()->prepare(
            "SELECT rp.*, r.rating, r.comment, r.status AS review_status, r.reviewee_id,
                    ru.name AS reporter_name, eu.name AS reviewee_name
               FROM review_reports rp
               JOIN reviews r ON r.id = rp.review_id
               JOIN users ru ON ru.id = rp.reporter_id
               JOIN users eu ON eu.id = r.reviewee_id
              WHERE rp.status = ? ORDER BY rp.id DESC LIMIT 200"
        );
        $stmt->execute([$status]);
        json_response(['reports' => $stmt->fetchAll()]);
    }

    /** POST /admin/reviews/{id}/hide  { reason } */
    public static function hideReview(string $id): void
    {
        $admin = require_role('admin');
        $rid = (int)$id;
        $reason = (string)(json_input()['reason'] ?? '');

        db_transaction(function (PDO $pdo) use ($rid, $reason, $admin) {
            $s = $pdo->prepare('SELECT reviewee_id FROM reviews WHERE id = ? FOR UPDATE');
            $s->execute([$rid]);
            $r = $s->fetch();
            if (!$r) {
                throw new DomainException('리뷰를 찾을 수 없습니다.');
            }
            $pdo->prepare("UPDATE reviews SET status = 'hidden', hidden_reason = ?, hidden_by = ?, hidden_at = NOW() WHERE id = ?")
                ->execute([$reason ?: null, (int)$admin['id'], $rid]);
            $pdo->prepare("UPDATE review_reports SET status = 'resolved', handled_by = ?, handled_at = NOW(), note = ? WHERE review_id = ? AND status = 'open'")
                ->execute([(int)$admin['id'], $reason ?: null, $rid]);
            ReviewController::recomputeRating($pdo, (int)$r['reviewee_id']);
        });

        audit_log('review.hide', 'review', $rid, ['reason' => $reason]);
        json_response(['message' => '리뷰를 블라인드 처리했습니다.']);
    }

    /** POST /admin/reviews/{id}/restore */
    public static function restoreReview(string $id): void
    {
        require_role('admin');
        $rid = (int)$id;

        db_transaction(function (PDO $pdo) use ($rid) {
            $s = $pdo->prepare('SELECT reviewee_id FROM reviews WHERE id = ? FOR UPDATE');
            $s->execute([$rid]);
            $r = $s->fetch();
            if (!$r) {
                throw new DomainException('리뷰를 찾을 수 없습니다.');
            }
            $pdo->prepare("UPDATE reviews SET status = 'visible', hidden_reason = NULL, hidden_by = NULL, hidden_at = NULL WHERE id = ?")
                ->execute([$rid]);
            // 블라인드 해제 = 신고를 최종 기각 처리 (open/resolved 모두)
            $pdo->prepare("UPDATE review_reports SET status = 'rejected', handled_at = NOW() WHERE review_id = ? AND status IN ('open','resolved')")
                ->execute([$rid]);
            ReviewController::recomputeRating($pdo, (int)$r['reviewee_id']);
        });

        audit_log('review.restore', 'review', $rid);
        json_response(['message' => '리뷰 블라인드를 해제했습니다.']);
    }
}
