<?php
declare(strict_types=1);

require_once __DIR__ . '/../Notify/NotificationService.php';

/**
 * =============================================================================
 * EscrowService — 에스크로 예치 / 정산 / 환불 도메인 로직
 * =============================================================================
 * 설계 원칙
 *  1) 모든 자금 이동은 단일 DB 트랜잭션 안에서 원자적으로 처리한다.
 *  2) 기준 주문 행은 항상 `SELECT ... FOR UPDATE` 로 잠가 동시성(중복 정산)을 차단한다.
 *  3) 멱등성: escrow_transactions.idempotency_key / payments.idempotency_key 의
 *     UNIQUE 제약으로 중복 처리를 DB 레벨에서 거부한다.
 *  4) 금액은 소수 2자리로 정규화(money())하여 부동소수점 오차를 제거한다.
 *  5) 상태 전이는 화이트리스트로 검증한다.
 *
 * 주문 상태(status) 전이도
 *   pending_payment --pay--> in_progress --deliver--> delivered
 *        |                        |                       |
 *        |cancel                  |dispute               |approve(최종 승인)
 *        v                        v                       v
 *    cancelled / refunded     disputed --refund--> refunded · completed
 *
 * 에스크로 상태(escrow_status)
 *   none --deposit--> held --release--> released
 *        \-------------refund----------> refunded
 * =============================================================================
 */
final class EscrowService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? db();
    }

    // -------------------------------------------------------------------------
    // 1. 예치 (의뢰인 결제 → 플랫폼이 대금 보관)
    // -------------------------------------------------------------------------
    /**
     * @param int    $orderId
     * @param int    $clientId    결제자(의뢰인) — 소유권 검증
     * @param array  $payment     ['pg_provider','pg_tx_id','method','idempotency_key','amount']
     * @return array  갱신된 주문
     * @throws DomainException 상태 위반 / 금액 불일치
     */
    public function deposit(int $orderId, int $clientId, array $payment): array
    {
        return db_transaction(function (PDO $pdo) use ($orderId, $clientId, $payment): array {
            $order = $this->lockOrder($pdo, $orderId);

            if ((int)$order['client_id'] !== $clientId) {
                throw new DomainException('본인의 주문만 결제할 수 있습니다.');
            }
            if ($order['status'] !== 'pending_payment') {
                throw new DomainException('결제 대기 상태의 주문만 예치할 수 있습니다. (현재: ' . $order['status'] . ')');
            }

            $payAmount = money((float)($payment['amount'] ?? $order['amount']));
            if (abs($payAmount - (float)$order['amount']) > 0.001) {
                throw new DomainException('결제 금액이 주문 금액과 일치하지 않습니다.');
            }

            // --- PG 결제 원장 기록 (멱등 키 UNIQUE 로 중복 방지) -------------
            $pgProvider = (string)($payment['pg_provider'] ?? 'manual');
            $idemKey = (string)($payment['idempotency_key'] ?? ($order['order_no'] . ':payment'));
            $stmt = $pdo->prepare(
                'INSERT INTO payments
                   (order_id, client_id, pg_provider, pg_tx_id, amount, method, status, idempotency_key, paid_at)
                 VALUES (?, ?, ?, ?, ?, ?, "paid", ?, NOW())'
            );
            $stmt->execute([
                $orderId,
                $clientId,
                $pgProvider,
                $payment['pg_tx_id'] ?? null,
                $payAmount,
                $payment['method'] ?? null,
                $idemKey,
            ]);

            // --- 에스크로 자금 유입 -----------------------------------------
            $this->recordEscrow(
                $pdo,
                $orderId,
                $clientId,
                'deposit',
                'in',
                $payAmount,
                $payAmount,               // 예치 후 잔액 = 총액
                $order['order_no'] . ':deposit',
                '에스크로 예치'
            );

            // --- 주문 상태 전이: pending_payment → in_progress ---------------
            $pdo->prepare(
                "UPDATE orders
                    SET status = 'in_progress', escrow_status = 'held', paid_at = NOW()
                  WHERE id = ?"
            )->execute([$orderId]);

            $this->audit($pdo, 'escrow.deposit', $orderId, ['amount' => $payAmount]);

            NotificationService::notify(
                (int)$order['freelancer_id'],
                'order.paid',
                '결제 완료 · 작업을 시작하세요',
                "주문 {$order['order_no']}의 대금 " . number_format($payAmount) . '원이 에스크로에 예치되었습니다.',
                '/orders/' . $orderId,
                'order',
                $orderId
            );

            return $this->lockOrder($pdo, $orderId);
        });
    }

    // -------------------------------------------------------------------------
    // 2. 최종 승인 → 수수료 공제 후 프리랜서 정산 (에스크로 해제)
    // -------------------------------------------------------------------------
    /**
     * 의뢰인이 '최종 승인'을 하면:
     *   fee = amount * fee_rate/100
     *   net = amount - fee
     * 를 확정하고 settlements(정산 대기) + orders(completed/released) 를 기록한다.
     *
     * @param int      $orderId
     * @param int      $clientId  승인자(의뢰인)
     * @param float|null $feeRate 지정 시 해당 요율, 미지정 시 현재 기본 수수료율
     * @throws DomainException
     */
    public function release(int $orderId, int $clientId, ?float $feeRate = null): array
    {
        return db_transaction(function (PDO $pdo) use ($orderId, $clientId, $feeRate): array {
            $order = $this->lockOrder($pdo, $orderId);

            if ((int)$order['client_id'] !== $clientId) {
                throw new DomainException('본인의 주문만 승인할 수 있습니다.');
            }
            // 멱등: 이미 정산 완료된 주문은 재처리하지 않고 현재 상태 반환
            if ($order['escrow_status'] === 'released' && $order['status'] === 'completed') {
                return $order;
            }
            if ($order['status'] !== 'delivered') {
                throw new DomainException('작업물이 발송(delivered)된 주문만 최종 승인할 수 있습니다. (현재: ' . $order['status'] . ')');
            }
            if ($order['escrow_status'] !== 'held') {
                throw new DomainException('에스크로에 보관 중인 대금이 없습니다.');
            }

            // --- 정산 금액 확정 --------------------------------------------
            $gross   = money((float)$order['amount']);
            $rate    = money($feeRate ?? default_fee_rate());
            if ($rate < 0 || $rate > 100) {
                throw new DomainException('수수료율은 0~100 사이여야 합니다.');
            }
            $fee = money($gross * $rate / 100);
            $net = money($gross - $fee);
            if ($net < 0) {
                throw new DomainException('정산 금액이 음수입니다.');
            }

            // --- 정산 레코드 (주문당 1건, UNIQUE(order_id)) ------------------
            $bank = $pdo->prepare(
                'SELECT bank_name, bank_account, bank_holder FROM users WHERE id = ?'
            );
            $bank->execute([(int)$order['freelancer_id']]);
            $bankSnapshot = $bank->fetch() ?: null;

            $pdo->prepare(
                'INSERT INTO settlements
                   (order_id, freelancer_id, gross_amount, fee_rate, fee_amount, net_amount, status, bank_snapshot)
                 VALUES (?, ?, ?, ?, ?, ?, "pending", ?)'
            )->execute([
                $orderId,
                (int)$order['freelancer_id'],
                $gross,
                $rate,
                $fee,
                $net,
                $bankSnapshot ? json_encode($bankSnapshot, JSON_UNESCAPED_UNICODE) : null,
            ]);

            // --- 에스크로 자금 유출 내역 기록 -------------------------------
            $this->recordEscrow($pdo, $orderId, (int)$order['freelancer_id'], 'release', 'out', $net, 0.00,
                $order['order_no'] . ':release', '프리랜서 지급액');
            $this->recordEscrow($pdo, $orderId, null, 'fee', 'out', $fee, 0.00,
                $order['order_no'] . ':fee', '플랫폼 수수료');

            // --- 주문 확정 --------------------------------------------------
            $pdo->prepare(
                "UPDATE orders
                    SET status = 'completed', escrow_status = 'released',
                        fee_rate = ?, fee_amount = ?, net_amount = ?,
                        approved_at = NOW(), settled_at = NOW()
                  WHERE id = ?"
            )->execute([$rate, $fee, $net, $orderId]);

            // --- 프리랜서 누적 실적 + 프로젝트 종료 --------------------------
            $pdo->prepare(
                'UPDATE freelancer_profiles SET completed_orders = completed_orders + 1 WHERE user_id = ?'
            )->execute([(int)$order['freelancer_id']]);
            if ($order['project_id']) {
                $pdo->prepare("UPDATE projects SET status = 'closed' WHERE id = ?")->execute([(int)$order['project_id']]);
            }

            $this->audit($pdo, 'escrow.release', $orderId, [
                'gross' => $gross, 'fee_rate' => $rate, 'fee' => $fee, 'net' => $net,
            ]);

            NotificationService::notify(
                (int)$order['freelancer_id'],
                'order.settled',
                '정산이 완료되었습니다',
                '주문 ' . $order['order_no'] . ' 지급액 ' . number_format($net) . '원 (수수료 ' . number_format($fee) . '원)',
                '/orders/' . $orderId,
                'order',
                $orderId
            );

            return $this->lockOrder($pdo, $orderId);
        });
    }

    // -------------------------------------------------------------------------
    // 3. 환불 (예치금 반환)
    // -------------------------------------------------------------------------
    public function refund(int $orderId, int $actorId, bool $isAdmin = false, string $reason = ''): array
    {
        return db_transaction(function (PDO $pdo) use ($orderId, $actorId, $isAdmin, $reason): array {
            $order = $this->lockOrder($pdo, $orderId);

            if (!$isAdmin && (int)$order['client_id'] !== $actorId) {
                throw new DomainException('본인의 주문만 환불 요청할 수 있습니다.');
            }
            if ($order['escrow_status'] !== 'held') {
                throw new DomainException('보관 중인 대금이 없어 환불할 수 없습니다.');
            }
            if (!in_array($order['status'], ['pending_payment', 'in_progress', 'delivered', 'disputed'], true)) {
                throw new DomainException('환불할 수 없는 상태입니다. (현재: ' . $order['status'] . ')');
            }

            // --- 환불 정책 -------------------------------------------------
            // 의뢰인 자체 환불은 "작업물 발송 전"에만 허용한다.
            // 이미 작업물이 제출된 뒤에는 분쟁(dispute) → 관리자 판단으로만 환불 가능.
            if (!$isAdmin) {
                $deliverableCount = $pdo->prepare('SELECT COUNT(*) FROM deliverables WHERE order_id = ?');
                $deliverableCount->execute([$orderId]);
                $hasDeliverable = (int)$deliverableCount->fetchColumn() > 0;
                $selfAllowed = get_setting('client_self_refund_allowed', '1') === '1';
                if ($hasDeliverable || !$selfAllowed) {
                    throw new DomainException('작업물이 제출된 이후에는 직접 환불할 수 없습니다. 분쟁(dispute)을 제기해 주세요.');
                }
            }

            $amount = money((float)$order['amount']);

            $this->recordEscrow($pdo, $orderId, (int)$order['client_id'], 'refund', 'out', $amount, 0.00,
                $order['order_no'] . ':refund', '의뢰인 환불' . ($reason !== '' ? " - $reason" : ''));

            $pdo->prepare(
                "UPDATE orders SET status = 'refunded', escrow_status = 'refunded', cancelled_at = NOW() WHERE id = ?"
            )->execute([$orderId]);

            // 결제 원장도 환불 상태로 갱신
            $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE order_id = ? AND status = 'paid'")
                ->execute([$orderId]);
            // 기존 정산은 취소
            $pdo->prepare("UPDATE settlements SET status = 'cancelled' WHERE order_id = ?")
                ->execute([$orderId]);

            $this->audit($pdo, 'escrow.refund', $orderId, ['amount' => $amount, 'reason' => $reason, 'is_admin' => $isAdmin]);

            return $this->lockOrder($pdo, $orderId);
        });
    }

    // -------------------------------------------------------------------------
    // 4. 분쟁 제기 (의뢰인 또는 프리랜서)
    // -------------------------------------------------------------------------
    public function openDispute(int $orderId, int $userId, string $reason): array
    {
        if (trim($reason) === '') {
            throw new DomainException('분쟁 사유를 입력해 주세요.');
        }
        return db_transaction(function (PDO $pdo) use ($orderId, $userId, $reason): array {
            $order = $this->lockOrder($pdo, $orderId);

            if ((int)$order['client_id'] !== $userId && (int)$order['freelancer_id'] !== $userId) {
                throw new DomainException('해당 주문의 당사자만 분쟁을 제기할 수 있습니다.');
            }
            if ($order['escrow_status'] !== 'held') {
                throw new DomainException('에스크로에 보관 중인 대금이 없어 분쟁을 제기할 수 없습니다.');
            }
            if (!in_array($order['status'], ['in_progress', 'delivered', 'revision_requested'], true)) {
                throw new DomainException('현재 상태에서는 분쟁을 제기할 수 없습니다. (현재: ' . $order['status'] . ')');
            }
            $chk = $pdo->prepare("SELECT COUNT(*) FROM disputes WHERE order_id = ? AND status = 'open'");
            $chk->execute([$orderId]);
            if ((int)$chk->fetchColumn() > 0) {
                throw new DomainException('이미 처리 중인 분쟁이 있습니다.');
            }

            $pdo->prepare('INSERT INTO disputes (order_id, opened_by, reason) VALUES (?, ?, ?)')
                ->execute([$orderId, $userId, trim($reason)]);
            $pdo->prepare("UPDATE orders SET status = 'disputed', disputed_at = NOW() WHERE id = ?")
                ->execute([$orderId]);

            $this->audit($pdo, 'dispute.open', $orderId, ['by' => $userId]);

            $other = ((int)$order['client_id'] === $userId) ? (int)$order['freelancer_id'] : (int)$order['client_id'];
            NotificationService::notify(
                $other, 'dispute.opened', '분쟁이 제기되었습니다',
                "주문 {$order['order_no']}에 대한 분쟁이 접수되었습니다.",
                '/orders/' . $orderId, 'order', $orderId
            );
            NotificationService::notifyAdmins(
                'dispute.opened', '새 분쟁 접수',
                "주문 {$order['order_no']} (" . number_format((float)$order['amount']) . '원)',
                '/admin/disputes.php', 'order', $orderId
            );

            return $this->lockOrder($pdo, $orderId);
        });
    }

    // -------------------------------------------------------------------------
    // 5. 분쟁 처리 (관리자) — 전액환불 / 부분환불 / 프리랜서 지급
    // -------------------------------------------------------------------------
    public function resolveDispute(int $orderId, int $adminId, string $decision, float $refundAmount = 0.0, string $note = ''): array
    {
        return db_transaction(function (PDO $pdo) use ($orderId, $adminId, $decision, $refundAmount, $note): array {
            $order = $this->lockOrder($pdo, $orderId);

            if ($order['escrow_status'] !== 'held') {
                throw new DomainException('보관 중인 대금이 없어 분쟁을 처리할 수 없습니다.');
            }
            $ds = $pdo->prepare("SELECT * FROM disputes WHERE order_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $ds->execute([$orderId]);
            $dispute = $ds->fetch();
            if (!$dispute) {
                throw new DomainException('처리할 분쟁이 없습니다.');
            }
            if (!in_array($decision, ['refund_full', 'refund_partial', 'release_to_freelancer'], true)) {
                throw new DomainException('알 수 없는 처리 방식입니다.');
            }

            $gross = money((float)$order['amount']);
            $rate  = money(default_fee_rate());
            $refund = 0.0;

            if ($decision === 'refund_full') {
                // (a) 전액 환불 — 프리랜서 지급 없음
                $refund = $gross;
                $this->recordEscrow($pdo, $orderId, (int)$order['client_id'], 'refund', 'out', $refund, 0.00,
                    $order['order_no'] . ':dispute:refund', '분쟁 전액 환불');
                $pdo->prepare("UPDATE orders SET status = 'refunded', escrow_status = 'refunded' WHERE id = ?")
                    ->execute([$orderId]);
                $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE order_id = ? AND status = 'paid'")->execute([$orderId]);
                $pdo->prepare("UPDATE settlements SET status = 'cancelled' WHERE order_id = ?")->execute([$orderId]);

            } elseif ($decision === 'release_to_freelancer') {
                // (b) 프리랜서 지급 (전액 정산)
                $fee = money($gross * $rate / 100);
                $net = money($gross - $fee);
                $this->createSettlement($pdo, $order, $gross, $rate, $fee, $net);
                $this->recordEscrow($pdo, $orderId, (int)$order['freelancer_id'], 'release', 'out', $net, 0.00,
                    $order['order_no'] . ':dispute:release', '분쟁 처리 프리랜서 지급');
                $this->recordEscrow($pdo, $orderId, null, 'fee', 'out', $fee, 0.00,
                    $order['order_no'] . ':dispute:fee', '플랫폼 수수료');
                $pdo->prepare(
                    "UPDATE orders SET status = 'completed', escrow_status = 'released',
                        fee_rate = ?, fee_amount = ?, net_amount = ?, approved_at = NOW(), settled_at = NOW() WHERE id = ?"
                )->execute([$rate, $fee, $net, $orderId]);
                $pdo->prepare('UPDATE freelancer_profiles SET completed_orders = completed_orders + 1 WHERE user_id = ?')
                    ->execute([(int)$order['freelancer_id']]);

            } else {
                // (c) 부분 환불 — 환불액은 의뢰인에게, 잔여금은 수수료 공제 후 프리랜서에게
                $refund = money($refundAmount);
                if ($refund <= 0 || $refund >= $gross) {
                    throw new DomainException('부분 환불 금액은 0보다 크고 예치 총액보다 작아야 합니다.');
                }
                $remainder = money($gross - $refund);
                $fee = money($remainder * $rate / 100);
                $net = money($remainder - $fee);

                $this->recordEscrow($pdo, $orderId, (int)$order['client_id'], 'refund', 'out', $refund, 0.00,
                    $order['order_no'] . ':dispute:refund', '분쟁 부분 환불');
                $this->createSettlement($pdo, $order, $remainder, $rate, $fee, $net);
                $this->recordEscrow($pdo, $orderId, (int)$order['freelancer_id'], 'release', 'out', $net, 0.00,
                    $order['order_no'] . ':dispute:release', '분쟁 잔여금 프리랜서 지급');
                $this->recordEscrow($pdo, $orderId, null, 'fee', 'out', $fee, 0.00,
                    $order['order_no'] . ':dispute:fee', '플랫폼 수수료(잔여금)');
                $pdo->prepare(
                    "UPDATE orders SET status = 'partially_refunded', escrow_status = 'partially_refunded',
                        fee_rate = ?, fee_amount = ?, net_amount = ?, settled_at = NOW() WHERE id = ?"
                )->execute([$rate, $fee, $net, $orderId]);
                $pdo->prepare('UPDATE freelancer_profiles SET completed_orders = completed_orders + 1 WHERE user_id = ?')
                    ->execute([(int)$order['freelancer_id']]);
            }

            // 분쟁 기록 마감
            $pdo->prepare(
                "UPDATE disputes SET status = 'resolved', resolution = ?, refund_amount = ?, note = ?,
                    resolved_by = ?, resolved_at = NOW() WHERE id = ?"
            )->execute([$decision, $refund > 0 ? $refund : null, $note !== '' ? $note : null, $adminId, (int)$dispute['id']]);

            if ($order['project_id']) {
                $pdo->prepare("UPDATE projects SET status = 'closed' WHERE id = ?")->execute([(int)$order['project_id']]);
            }

            $this->audit($pdo, 'dispute.resolve', $orderId, ['decision' => $decision, 'refund' => $refund, 'note' => $note]);

            $label = [
                'refund_full'           => '전액 환불',
                'refund_partial'        => '부분 환불',
                'release_to_freelancer' => '프리랜서 지급',
            ][$decision] ?? $decision;
            $msg = "주문 {$order['order_no']} 분쟁 처리 결과: {$label}" . ($refund > 0 ? ' (환불 ' . number_format($refund) . '원)' : '');
            NotificationService::notify((int)$order['client_id'], 'dispute.resolved', '분쟁이 처리되었습니다', $msg, '/orders/' . $orderId, 'order', $orderId);
            NotificationService::notify((int)$order['freelancer_id'], 'dispute.resolved', '분쟁이 처리되었습니다', $msg, '/orders/' . $orderId, 'order', $orderId);

            return $this->lockOrder($pdo, $orderId);
        });
    }

    // -------------------------------------------------------------------------
    // 내부 헬퍼
    // -------------------------------------------------------------------------

    /** 정산 레코드 생성 (정산 대상 금액 기준) */
    private function createSettlement(PDO $pdo, array $order, float $gross, float $rate, float $fee, float $net): void
    {
        $bank = $pdo->prepare('SELECT bank_name, bank_account, bank_holder FROM users WHERE id = ?');
        $bank->execute([(int)$order['freelancer_id']]);
        $bankSnapshot = $bank->fetch() ?: null;

        $pdo->prepare(
            'INSERT INTO settlements
               (order_id, freelancer_id, gross_amount, fee_rate, fee_amount, net_amount, status, bank_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, "pending", ?)'
        )->execute([
            (int)$order['id'],
            (int)$order['freelancer_id'],
            $gross,
            $rate,
            $fee,
            $net,
            $bankSnapshot ? json_encode($bankSnapshot, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }


    /** 주문 행을 배타적 잠금으로 조회 */
    private function lockOrder(PDO $pdo, int $orderId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new DomainException('주문을 찾을 수 없습니다.');
        }
        return $order;
    }

    /** 에스크로 원장 기록 (멱등 키 UNIQUE 로 중복 차단) */
    private function recordEscrow(
        PDO $pdo,
        int $orderId,
        ?int $userId,
        string $type,
        string $direction,
        float $amount,
        float $balanceAfter,
        string $idemKey,
        string $memo
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO escrow_transactions
               (order_id, user_id, type, direction, amount, balance_after, idempotency_key, memo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$orderId, $userId, $type, $direction, money($amount), money($balanceAfter), $idemKey, $memo]);
    }

    private function audit(PDO $pdo, string $action, int $orderId, array $meta): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (actor_id, action, target_type, target_id, meta, ip)
             VALUES (?, ?, "order", ?, ?, ?)'
        );
        $stmt->execute([
            current_user_id(),
            $action,
            $orderId,
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            client_ip(),
        ]);
    }
}
