<?php
declare(strict_types=1);

/**
 * =============================================================================
 * TossPaymentsGateway — 토스페이먼츠 결제 게이트웨이
 * =============================================================================
 * - 결제 승인(confirm): 클라이언트가 받은 paymentKey 로 서버에서 최종 승인
 *   POST https://api.tosspayments.com/v1/payments/confirm  (Basic auth)
 * - 웹훅 서명 검증: base64(HMAC-SHA256(rawBody, TOSS_WEBHOOK_SECRET))
 *   헤더: Tosspayments-Webhook-Signature
 *
 * 환경변수:
 *   TOSS_SECRET_KEY       : test_sk_... / live_sk_...
 *   TOSS_WEBHOOK_SECRET   : 웹훅 시크릿
 *   TOSS_CLIENT_KEY       : test_ck_... / live_ck_... (프론트에 노출)
 *
 * 미설정 시 enabled() === false 이며 수동/Stripe 결제로 폴백한다.
 */
final class TossPaymentsGateway
{
    private const API_BASE = 'https://api.tosspayments.com/v1';
    private const WEBHOOK_HEADER = 'HTTP_TOSSPAYMENTS_WEBHOOK_SIGNATURE';

    private string $secretKey;
    private string $webhookSecret;
    private string $clientKey;

    public function __construct(?string $secretKey = null, ?string $webhookSecret = null, ?string $clientKey = null)
    {
        $this->secretKey     = $secretKey     ?? (string)(env('TOSS_SECRET_KEY', '') ?? '');
        $this->webhookSecret = $webhookSecret ?? (string)(env('TOSS_WEBHOOK_SECRET', '') ?? '');
        $this->clientKey     = $clientKey     ?? (string)(env('TOSS_CLIENT_KEY', '') ?? '');
    }

    public function enabled(): bool
    {
        return $this->secretKey !== '' && (str_starts_with($this->secretKey, 'test_sk_') || str_starts_with($this->secretKey, 'live_sk_') || str_starts_with($this->secretKey, 'sk_'));
    }

    public function clientKey(): string
    {
        return $this->clientKey;
    }

    /**
     * 결제 승인.
     * @param string $paymentKey 클라이언트가 전달한 결제 키
     * @param string $orderId    우리 주문번호(order_no)와 동일해야 함
     * @param int    $amount     승인 금액(원)
     * @throws RuntimeException 승인 실패
     */
    public function confirm(string $paymentKey, string $orderId, int $amount): array
    {
        if (!$this->enabled()) {
            throw new RuntimeException('토스페이먼츠 키가 설정되지 않았습니다.');
        }
        return $this->request('POST', '/payments/confirm', [
            'paymentKey' => $paymentKey,
            'orderId'    => $orderId,
            'amount'     => $amount,
        ]);
    }

    public function retrieve(string $paymentKey): array
    {
        return $this->request('GET', '/payments/' . rawurlencode($paymentKey));
    }

    // -------------------------------------------------------------------------
    // 웹훅
    // -------------------------------------------------------------------------
    /** 서명이 설정되어 있으면 검증, 없으면 통과(개발 편의). */
    public function verifyWebhook(string $payload, ?string $signature): bool
    {
        if ($this->webhookSecret === '') {
            return true; // 시크릿 미설정 시 검증 생략(권장하지 않음)
        }
        if (!$signature) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $payload, $this->webhookSecret, true));
        return hash_equals($expected, $signature);
    }

    public function webhookSignature(): ?string
    {
        return $_SERVER[self::WEBHOOK_HEADER] ?? null;
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $ch = curl_init(self::API_BASE . $path);
        $headers = [
            'Authorization: Basic ' . base64_encode($this->secretKey . ':'),
            'Content-Type: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
        ];
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $resp = curl_exec($ch);
        $errNo = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($errNo !== 0 || $resp === false) {
            throw new RuntimeException('토스 통신 오류: ' . $err);
        }
        $decoded = json_decode((string)$resp, true);
        if ($status >= 400) {
            $msg = $decoded['message'] ?? ('HTTP ' . $status);
            throw new RuntimeException('토스 API 오류: ' . $msg);
        }
        return is_array($decoded) ? $decoded : [];
    }
}
