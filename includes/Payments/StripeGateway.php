<?php
declare(strict_types=1);

/**
 * =============================================================================
 * StripeGateway — Stripe 결제 게이트웨이 (외부 라이브러리 의존 없음, cURL 사용)
 * =============================================================================
 * - PaymentIntent 생성(결제창용 client_secret 발급)
 * - 웹훅 서명 검증(HMAC-SHA256) 및 이벤트 파싱
 *
 * 환경변수 (nginx fastcgi_param 또는 getenv 로 주입):
 *   STRIPE_SECRET_KEY      : sk_live_... / sk_test_...
 *   STRIPE_WEBHOOK_SECRET  : whsec_...
 *   STRIPE_PUBLISHABLE_KEY : pk_live_... / pk_test_... (프론트에 노출)
 *
 * 미설정 시 enabled() === false 이며, 수동(manual) 결제로 폴백한다.
 */
final class StripeGateway
{
    private const API_BASE = 'https://api.stripe.com/v1';

    private string $secretKey;
    private string $webhookSecret;
    private string $publishableKey;

    public function __construct(
        ?string $secretKey = null,
        ?string $webhookSecret = null,
        ?string $publishableKey = null
    ) {
        $this->secretKey      = $secretKey      ?? (string)(env('STRIPE_SECRET_KEY', '') ?? '');
        $this->webhookSecret  = $webhookSecret  ?? (string)(env('STRIPE_WEBHOOK_SECRET', '') ?? '');
        $this->publishableKey = $publishableKey ?? (string)(env('STRIPE_PUBLISHABLE_KEY', '') ?? '');
    }

    public function enabled(): bool
    {
        return $this->secretKey !== '' && str_starts_with($this->secretKey, 'sk_');
    }

    public function publishableKey(): string
    {
        return $this->publishableKey;
    }

    // -------------------------------------------------------------------------
    // 결제 생성
    // -------------------------------------------------------------------------
    /**
     * PaymentIntent 생성
     * @param int    $amount   최소 통화단위 정수 (KRW 는 원 단위 그대로)
     * @param array  $metadata 주문 식별용 메타데이터 (order_id, order_no)
     * @return array  Stripe 응답 (id, client_secret, status ...)
     * @throws RuntimeException API 오류 시
     */
    public function createPaymentIntent(int $amount, string $currency = 'krw', array $metadata = [], ?string $idempotencyKey = null): array
    {
        if (!$this->enabled()) {
            throw new RuntimeException('Stripe 키가 설정되지 않았습니다.');
        }
        $params = [
            'amount'   => $amount,
            'currency' => strtolower($currency),
            'automatic_payment_methods' => ['enabled' => 'true'],
        ];
        foreach ($metadata as $k => $v) {
            $params["metadata[$k]"] = (string)$v;
        }

        $headers = [];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        return $this->request('POST', '/payment_intents', $params, $headers);
    }

    public function retrievePaymentIntent(string $id): array
    {
        return $this->request('GET', '/payment_intents/' . rawurlencode($id));
    }

    // -------------------------------------------------------------------------
    // 웹훅
    // -------------------------------------------------------------------------
    /**
     * Stripe-Signature 헤더 검증.
     * 헤더 형식: `t=timestamp,v1=signature[,v1=...]`
     * 서명 대상: "{t}.{payload}"  →  HMAC-SHA256(webhookSecret)
     */
    public function verifyWebhook(string $payload, string $sigHeader, int $tolerance = 300): bool
    {
        if ($this->webhookSecret === '' || $sigHeader === '') {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $sigHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$k, $v] = $pair;
            if ($k === 't') {
                $timestamp = (int)$v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }
        if ($timestamp === null || !$signatures) {
            return false;
        }
        // 리플레이 방지: 타임스탬프 허용 범위
        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 웹훅 페이로드 파싱(검증 포함).
     * @throws RuntimeException 서명 불일치 / JSON 오류
     */
    public function constructEvent(string $payload, string $sigHeader): array
    {
        if (!$this->verifyWebhook($payload, $sigHeader)) {
            throw new RuntimeException('Stripe 웹훅 서명 검증에 실패했습니다.');
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
            throw new RuntimeException('Stripe 웹훅 페이로드 형식이 올바르지 않습니다.');
        }
        return $event;
    }

    // -------------------------------------------------------------------------
    // 내부: Stripe REST 호출
    // -------------------------------------------------------------------------
    private function request(string $method, string $path, array $params = [], array $extraHeaders = []): array
    {
        $ch = curl_init(self::API_BASE . $path);
        $headers = array_merge([
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/x-www-form-urlencoded',
        ], $extraHeaders);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ];
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params, '', '&');
        }
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0 || $body === false) {
            throw new RuntimeException('Stripe 통신 오류: ' . curl_error($ch));
        }
        $decoded = json_decode((string)$body, true);
        if ($status >= 400) {
            $msg = $decoded['error']['message'] ?? ('HTTP ' . $status);
            throw new RuntimeException('Stripe API 오류: ' . $msg);
        }
        return is_array($decoded) ? $decoded : [];
    }
}
