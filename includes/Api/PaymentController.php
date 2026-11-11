<?php
declare(strict_types=1);

/**
 * PG 웹훅 수신 컨트롤러
 * - Stripe: payment_intent.succeeded 수신 시 주문을 에스크로 예치(deposit) 처리
 * - 서명 검증 + payment_webhooks 로 멱등 보장
 */
final class PaymentController
{
    /** GET /payments/methods — 사용 가능한 결제수단/공개키 */
    public static function methods(): void
    {
        $stripe = new StripeGateway();
        $toss = new TossPaymentsGateway();
        json_response([
            'stripe' => ['enabled' => $stripe->enabled(), 'publishable_key' => $stripe->publishableKey()],
            'toss'   => ['enabled' => $toss->enabled(), 'client_key' => $toss->clientKey()],
        ]);
    }

    /** POST /webhooks/toss  (CSRF 제외) */
    public static function toss(): void
    {
        $payload = file_get_contents('php://input') ?: '';
        $gateway = new TossPaymentsGateway();

        if (!$gateway->verifyWebhook($payload, $gateway->webhookSignature())) {
            json_error('토스 웹훅 서명 검증에 실패했습니다.', 400);
        }
        $event = json_decode($payload, true);
        if (!is_array($event)) {
            json_error('잘못된 토스 웹훅 페이로드입니다.', 400);
        }

        $eventType = (string)($event['eventType'] ?? '');
        $data      = (array)($event['data'] ?? []);
        $paymentKey = (string)($data['paymentKey'] ?? '');
        $eventId   = $paymentKey !== '' ? $paymentKey : ('toss_' . sha1($payload));

        // 멱등 기록
        try {
            db()->prepare('INSERT INTO payment_webhooks (provider, event_id, event_type, payload) VALUES (?, ?, ?, ?)')
                ->execute(['toss', $eventId, $eventType, $payload]);
            $webhookId = (int)db()->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_response(['duplicate' => true]);
            }
            throw $e;
        }

        $result = self::handleToss($eventType, $data);
        db()->prepare('UPDATE payment_webhooks SET status = ?, order_id = ?, message = ? WHERE id = ?')
            ->execute([$result['status'], $result['order_id'], $result['message'], $webhookId]);

        json_response(['handled' => $eventType, 'result' => $result]);
    }

    /** @return array{status:string,order_id:?int,message:string} */
    private static function handleToss(string $eventType, array $data): array
    {
        $status = (string)($data['status'] ?? '');
        if ($eventType !== 'PAYMENT_STATUS_CHANGED' || $status !== 'DONE') {
            return ['status' => 'ignored', 'order_id' => null, 'message' => "미처리 이벤트: {$eventType}/{$status}"];
        }

        $orderNo = (string)($data['orderId'] ?? '');
        $paymentKey = (string)($data['paymentKey'] ?? '');
        $amount = (float)($data['totalAmount'] ?? 0);

        $stmt = db()->prepare('SELECT * FROM orders WHERE order_no = ? LIMIT 1');
        $stmt->execute([$orderNo]);
        $order = $stmt->fetch();
        if (!$order) {
            return ['status' => 'failed', 'order_id' => null, 'message' => "주문 {$orderNo} 없음"];
        }
        if (abs($amount - (float)$order['amount']) > 0.001) {
            return ['status' => 'failed', 'order_id' => (int)$order['id'], 'message' => "금액 불일치: 결제 {$amount} / 주문 {$order['amount']}"];
        }

        try {
            (new EscrowService())->deposit((int)$order['id'], (int)$order['client_id'], [
                'pg_provider'     => 'toss',
                'pg_tx_id'        => $paymentKey,
                'method'          => 'card',
                'amount'          => $amount,
                'idempotency_key' => 'toss:' . $paymentKey,
            ]);
            return ['status' => 'processed', 'order_id' => (int)$order['id'], 'message' => '에스크로 예치 완료'];
        } catch (DomainException $e) {
            return ['status' => 'ignored', 'order_id' => (int)$order['id'], 'message' => $e->getMessage()];
        }
    }

    /** POST /webhooks/stripe  (CSRF 제외) */
    public static function stripe(): void
    {
        $payload = file_get_contents('php://input') ?: '';
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $gateway = new StripeGateway();
            if (!$gateway->enabled()) {
                // 키 미설정: 재시도를 막기 위해 200 으로 무시
                json_response(['ignored' => true, 'reason' => 'stripe disabled']);
            }
            $event = $gateway->constructEvent($payload, $sigHeader);
        } catch (Throwable $e) {
            // 잘못된 서명/형식 → 400 (Stripe 가 재시도하지 않음)
            json_error($e->getMessage(), 400);
        }

        $provider = 'stripe';
        $eventId   = (string)$event['id'];
        $eventType = (string)$event['type'];

        // 멱등: 동일 event_id 재수신 차단
        $ins = db()->prepare(
            'INSERT INTO payment_webhooks (provider, event_id, event_type, payload)
             VALUES (?, ?, ?, ?)'
        );
        try {
            $ins->execute([$provider, $eventId, $eventType, $payload]);
            $webhookId = (int)db()->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {            // UNIQUE 위반 = 중복 이벤트
                json_response(['duplicate' => true]);
            }
            throw $e;
        }

        try {
            $result = self::handleEvent($eventType, $event['data']['object'] ?? []);

            db()->prepare('UPDATE payment_webhooks SET status = ?, order_id = ?, message = ? WHERE id = ?')
                ->execute([$result['status'], $result['order_id'], $result['message'], $webhookId]);

            json_response(['handled' => $eventType, 'result' => $result]);
        } catch (Throwable $e) {
            db()->prepare("UPDATE payment_webhooks SET status = 'failed', message = ? WHERE id = ?")
                ->execute([substr($e->getMessage(), 0, 250), $webhookId]);
            // Stripe 재시도를 위해 500 반환
            json_error('웹훅 처리 실패: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 이벤트별 처리
     * @return array{status:string,order_id:?int,message:string}
     */
    private static function handleEvent(string $type, array $object): array
    {
        switch ($type) {
            case 'payment_intent.succeeded':
                return self::onPaymentSucceeded($object);

            case 'payment_intent.payment_failed':
                return ['status' => 'ignored', 'order_id' => null, 'message' => '결제 실패 이벤트 무시'];

            case 'charge.refunded':
                // 플랫폼에서 환불을 트리거하고 PG 에서도 환불 처리된 경우 (기록만)
                return ['status' => 'ignored', 'order_id' => null, 'message' => '환불 이벤트 기록'];

            default:
                return ['status' => 'ignored', 'order_id' => null, 'message' => '미처리 이벤트: ' . $type];
        }
    }

    private static function onPaymentSucceeded(array $pi): array
    {
        $piId = (string)($pi['id'] ?? '');
        $orderId = isset($pi['metadata']['order_id']) ? (int)$pi['metadata']['order_id'] : 0;
        $amountReceived = isset($pi['amount_received']) ? (float)$pi['amount_received'] : (float)($pi['amount'] ?? 0);

        if ($orderId <= 0 || $piId === '') {
            return ['status' => 'failed', 'order_id' => null, 'message' => 'metadata.order_id 또는 payment_intent id 누락'];
        }

        $stmt = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            return ['status' => 'failed', 'order_id' => null, 'message' => "주문 {$orderId} 없음"];
        }

        // 금액 검증 (Stripe 는 최소단위 정수, KRW 는 원 단위)
        if (abs($amountReceived - (float)$order['amount']) > 0.001) {
            return ['status' => 'failed', 'order_id' => $orderId, 'message' => "금액 불일치: 결제 {$amountReceived} / 주문 {$order['amount']}"];
        }

        try {
            $escrow = new EscrowService();
            $escrow->deposit($orderId, (int)$order['client_id'], [
                'pg_provider'     => 'stripe',
                'pg_tx_id'        => $piId,
                'method'          => 'card',
                'amount'          => $amountReceived,
                'idempotency_key' => 'stripe:' . $piId,
            ]);
            return ['status' => 'processed', 'order_id' => $orderId, 'message' => '에스크로 예치 완료'];
        } catch (DomainException $e) {
            // 이미 예치된 주문(중복 성공 이벤트) → 정상 무시
            return ['status' => 'ignored', 'order_id' => $orderId, 'message' => $e->getMessage()];
        }
    }
}
