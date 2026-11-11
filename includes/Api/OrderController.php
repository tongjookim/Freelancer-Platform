<?php
declare(strict_types=1);

/** 주문 조회 / 결제(예치) / 발송 / 승인(정산) / 환불 / 메시지 */
final class OrderController
{
    private static function escrow(): EscrowService
    {
        return new EscrowService();
    }

    /** 내 주문 목록 (역할별) */
    public static function index(): void
    {
        $user = require_login();
        $column = $user['role'] === 'freelancer' ? 'freelancer_id' : 'client_id';
        if ($user['role'] === 'admin') {
            $stmt = db()->query('SELECT * FROM orders ORDER BY id DESC LIMIT 100');
        } else {
            $stmt = db()->prepare("SELECT * FROM orders WHERE $column = ? ORDER BY id DESC LIMIT 100");
            $stmt->execute([$user['id']]);
        }
        json_response(['orders' => $stmt->fetchAll()]);
    }

    public static function show(string $id): void
    {
        $user = require_login();
        $order = self::fetchOrderOrFail((int)$id);

        if ($user['role'] !== 'admin'
            && (int)$order['client_id'] !== (int)$user['id']
            && (int)$order['freelancer_id'] !== (int)$user['id']) {
            json_error('이 주문에 접근할 권한이 없습니다.', 403);
        }

        $tx = db()->prepare('SELECT * FROM escrow_transactions WHERE order_id = ? ORDER BY id');
        $tx->execute([(int)$id]);

        $deliverables = db()->prepare('SELECT * FROM deliverables WHERE order_id = ? ORDER BY id DESC');
        $deliverables->execute([(int)$id]);

        json_response([
            'order'               => $order,
            'escrow_transactions' => $tx->fetchAll(),
            'deliverables'        => $deliverables->fetchAll(),
        ]);
    }

    /** 서비스를 직접 구매해 주문 생성 (에스크로 대기) */
    public static function store(): void
    {
        $user = require_role('client');
        $data = json_input();
        require_fields($data, ['service_id']);

        $orderNo = db_transaction(function (PDO $pdo) use ($user, $data): string {
            $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ? AND status = 'active' LIMIT 1");
            $stmt->execute([(int)$data['service_id']]);
            $service = $stmt->fetch();
            if (!$service) {
                throw new DomainException('판매 중인 서비스를 찾을 수 없습니다.');
            }
            if ((int)$service['freelancer_id'] === (int)$user['id']) {
                throw new DomainException('본인의 서비스는 구매할 수 없습니다.');
            }

            $amount = isset($data['amount']) ? money((float)$data['amount']) : money((float)$service['price']);
            $orderNo = generate_order_no();
            $pdo->prepare(
                'INSERT INTO orders
                   (order_no, service_id, client_id, freelancer_id, title, amount, delivery_days,
                    status, escrow_status, due_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "pending_payment", "none",
                         DATE_ADD(CURDATE(), INTERVAL ? DAY))'
            )->execute([
                $orderNo,
                (int)$service['id'],
                $user['id'],
                (int)$service['freelancer_id'],
                $service['title'],
                $amount,
                (int)$service['delivery_days'],
                (int)$service['delivery_days'],
            ]);
            return $orderNo;
        });

        audit_log('order.created', 'order', null, ['order_no' => $orderNo]);
        $row = db()->prepare('SELECT id, freelancer_id FROM orders WHERE order_no = ? LIMIT 1');
        $row->execute([$orderNo]);
        if ($o = $row->fetch()) {
            NotificationService::notify((int)$o['freelancer_id'], 'order.created', '새 주문이 도착했습니다',
                "주문 {$orderNo}의 결제를 기다리고 있습니다.", '/orders/' . $o['id'], 'order', (int)$o['id']);
        }
        json_response(['message' => '주문이 생성되었습니다. 결제를 진행해 주세요.', 'order_no' => $orderNo], 201);
    }

    /** 에스크로 예치(결제) */
    public static function pay(string $id): void
    {
        $user = require_role('client');
        $data = json_input();

        $order = self::escrow()->deposit((int)$id, (int)$user['id'], [
            'pg_provider'     => $data['pg_provider'] ?? 'manual',
            'pg_tx_id'        => $data['pg_tx_id'] ?? null,
            'method'          => $data['method'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'amount'          => $data['amount'] ?? null,
        ]);

        json_response(['message' => '결제가 완료되어 대금이 에스크로에 예치되었습니다.', 'order' => $order]);
    }

    /**
     * Stripe PaymentIntent 생성 → 프론트 결제창용 client_secret 반환
     * (결제 성공 시 Stripe 웹훅이 /webhooks/stripe 로 들어와 에스크로 예치를 완료한다)
     */
    public static function intent(string $id): void
    {
        $user = require_role('client');
        $order = self::fetchOrderOrFail((int)$id);

        if ((int)$order['client_id'] !== (int)$user['id']) {
            json_error('본인의 주문만 결제할 수 있습니다.', 403);
        }
        if ($order['status'] !== 'pending_payment') {
            json_error('결제 대기 상태의 주문만 결제할 수 있습니다.', 409);
        }

        $gateway = new StripeGateway();
        if (!$gateway->enabled()) {
            json_error('Stripe 결제가 설정되지 않았습니다.', 503);
        }

        // Stripe 금액은 최소 통화단위 정수 (KRW 는 원 단위 그대로)
        $amount = (int)round((float)$order['amount']);
        $pi = $gateway->createPaymentIntent($amount, strtolower($order['currency'] ?: 'krw'), [
            'order_id' => $order['id'],
            'order_no' => $order['order_no'],
        ], 'order-' . $order['order_no']);

        json_response([
            'payment_intent_id' => $pi['id'],
            'client_secret'     => $pi['client_secret'],
            'publishable_key'   => $gateway->publishableKey(),
            'amount'            => $amount,
            'currency'          => $order['currency'] ?: 'KRW',
        ]);
    }

    /**
     * 토스페이먼츠 결제 승인 → 에스크로 예치
     * POST /orders/{id}/toss/confirm  { payment_key, amount }
     */
    public static function tossConfirm(string $id): void
    {
        $user = require_role('client');
        $order = self::fetchOrderOrFail((int)$id);
        if ((int)$order['client_id'] !== (int)$user['id']) {
            json_error('본인의 주문만 결제할 수 있습니다.', 403);
        }
        $data = json_input();
        require_fields($data, ['payment_key', 'amount']);

        $oid = (int)$id;
        $amount = (int)round((float)$order['amount']);
        if ((int)$data['amount'] !== $amount) {
            json_error('결제 금액이 주문 금액과 일치하지 않습니다.', 422);
        }

        $gateway = new TossPaymentsGateway();
        if (!$gateway->enabled()) {
            json_error('토스페이먼츠 결제가 설정되지 않았습니다.', 503);
        }
        if ($order['status'] !== 'pending_payment') {
            // 이미 처리된 경우(웹훅 선도착) 성공으로 간주
            json_response(['message' => '이미 결제가 처리된 주문입니다.', 'order' => $order]);
        }

        try {
            $result = $gateway->confirm((string)$data['payment_key'], (string)$order['order_no'], $amount);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 402);
        }
        $paymentKey = (string)($result['paymentKey'] ?? $data['payment_key']);

        try {
            $updated = self::escrow()->deposit($oid, (int)$user['id'], [
                'pg_provider'     => 'toss',
                'pg_tx_id'        => $paymentKey,
                'method'          => 'card',
                'amount'          => $amount,
                'idempotency_key' => 'toss:' . $paymentKey,
            ]);
        } catch (DomainException $e) {
            $updated = self::fetchOrderOrFail($oid); // 웹훅이 먼저 처리한 경우
        }

        json_response(['message' => '토스 결제가 완료되어 대금이 에스크로에 예치되었습니다.', 'order' => $updated]);
    }

    /** 분쟁 제기 (의뢰인/프리랜서) */
    public static function dispute(string $id): void
    {
        $user = require_login();
        $reason = (string)(json_input()['reason'] ?? '');
        $order = self::escrow()->openDispute((int)$id, (int)$user['id'], $reason);
        json_response(['message' => '분쟁이 접수되었습니다. 관리자 검토 후 처리됩니다.', 'order' => $order]);
    }

    /** 프리랜서 작업물 발송 */
    public static function deliver(string $id): void
    {
        $user = require_role('freelancer');
        $orderId = (int)$id;

        $order = db_transaction(function (PDO $pdo) use ($orderId, $user): array {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new DomainException('주문을 찾을 수 없습니다.');
            }
            if ((int)$order['freelancer_id'] !== (int)$user['id']) {
                throw new DomainException('본인에게 배정된 주문만 발송할 수 있습니다.');
            }
            if (!in_array($order['status'], ['in_progress', 'revision_requested'], true)) {
                throw new DomainException('작업 중인 주문만 발송할 수 있습니다. (현재: ' . $order['status'] . ')');
            }

            $data = json_input();
            $version = (int)$pdo->query("SELECT COALESCE(MAX(version),0)+1 FROM deliverables WHERE order_id = {$orderId}")->fetchColumn();

            $pdo->prepare(
                'INSERT INTO deliverables (order_id, freelancer_id, message, file_path, version)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$orderId, (int)$user['id'], $data['message'] ?? null, $data['file_path'] ?? null, $version]);

            $pdo->prepare("UPDATE orders SET status = 'delivered', delivered_at = NOW() WHERE id = ?")
                ->execute([$orderId]);

            return $order;
        });

        audit_log('order.delivered', 'order', $orderId, []);
        NotificationService::notify((int)$order['client_id'], 'order.delivered', '작업물이 도착했습니다',
            "주문 {$order['order_no']}를 검수하고 최종 승인해 주세요.", '/orders/' . $orderId, 'order', $orderId);
        json_response(['message' => '작업물을 발송했습니다. 의뢰인의 최종 승인을 기다립니다.']);
    }

    /** 의뢰인 최종 승인 → 에스크로 해제 및 정산 */
    public static function approve(string $id): void
    {
        $user = require_role('client');
        $order = self::escrow()->release((int)$id, (int)$user['id']);
        json_response([
            'message' => '최종 승인이 완료되어 프리랜서 정산이 진행됩니다.',
            'order'   => $order,
        ]);
    }

    /** 환불 (의뢰인 요청 또는 관리자 강제) */
    public static function refund(string $id): void
    {
        $user = require_login();
        $isAdmin = $user['role'] === 'admin';
        if (!$isAdmin) {
            $user = require_role('client');
        }
        $reason = (string)(json_input()['reason'] ?? '');
        $order = self::escrow()->refund((int)$id, (int)$user['id'], $isAdmin, $reason);
        json_response(['message' => '환불이 처리되었습니다.', 'order' => $order]);
    }

    // ---- 메시지 -----------------------------------------------------------

    public static function messages(string $id): void
    {
        $user = require_login();
        self::assertParticipant((int)$id, (int)$user['id'], $user['role']);

        $stmt = db()->prepare(
            'SELECT m.*, u.name AS sender_name FROM messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.order_id = ? ORDER BY m.id'
        );
        $stmt->execute([(int)$id]);
        json_response(['messages' => $stmt->fetchAll()]);
    }

    public static function sendMessage(string $id): void
    {
        $user = require_login();
        self::assertParticipant((int)$id, (int)$user['id'], $user['role']);

        $data = json_input();
        require_fields($data, ['content']);

        db()->prepare('INSERT INTO messages (order_id, sender_id, content, attachment) VALUES (?, ?, ?, ?)')
            ->execute([(int)$id, (int)$user['id'], (string)$data['content'], $data['attachment'] ?? null]);

        json_response(['message_id' => (int)db()->lastInsertId()], 201);
    }

    // ---- 헬퍼 -------------------------------------------------------------

    private static function fetchOrderOrFail(int $id): array
    {
        $stmt = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) {
            json_error('주문을 찾을 수 없습니다.', 404);
        }
        return $order;
    }

    private static function assertParticipant(int $orderId, int $userId, string $role): void
    {
        if ($role === 'admin') {
            return;
        }
        $order = self::fetchOrderOrFail($orderId);
        if ((int)$order['client_id'] !== $userId && (int)$order['freelancer_id'] !== $userId) {
            json_error('이 주문에 접근할 권한이 없습니다.', 403);
        }
    }
}
