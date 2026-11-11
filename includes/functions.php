<?php
declare(strict_types=1);

/**
 * 공통 유틸리티: JSON 응답, 입력 파싱, 설정 조회, 감사 로그 등
 */

// ---------------------------------------------------------------------------
// API 응답
// ---------------------------------------------------------------------------

/** JSON 성공 응답 후 종료. */
function json_response($data = null, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['success' => true, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/** JSON 에러 응답 후 종료. $extra 로 검증 상세 등을 실어 보낼 수 있다. */
function json_error(string $message, int $status = 400, array $extra = []): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(['success' => false, 'error' => ['message' => $message]], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// ---------------------------------------------------------------------------
// 입력
// ---------------------------------------------------------------------------

/** 요청 본문(JSON)을 배열로 파싱. Content-Type 이 JSON 이 아니면 빈 배열. */
function json_input(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    $cached = is_array($decoded) ? $decoded : [];
    return $cached;
}

/** POST/GET/PUT 통합 입력값 조회 */
function input(string $key, $default = null)
{
    $json = json_input();
    if (array_key_exists($key, $json)) {
        return $json[$key];
    }
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

/** 필수 입력값 검증 */
function require_fields(array $data, array $fields): void
{
    $missing = [];
    foreach ($fields as $f) {
        if (!isset($data[$f]) || (is_string($data[$f]) && trim($data[$f]) === '')) {
            $missing[] = $f;
        }
    }
    if ($missing) {
        json_error('필수 항목이 누락되었습니다: ' . implode(', ', $missing), 422);
    }
}

// ---------------------------------------------------------------------------
// 설정 / 금액 / 번호
// ---------------------------------------------------------------------------

/** platform_settings 단일 값 조회 (요청당 캐시) */
function get_setting(string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = db()->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $cache[$key] = ($val === false ? $default : (string)$val);
}

/** 수수료율(%) 조회 */
function default_fee_rate(): float
{
    return (float)get_setting('default_fee_rate', '10.00');
}

/** 금액을 소수 2자리로 정규화 (부동소수점 오차 방지) */
function money(float $amount): float
{
    return round($amount, 2);
}

/** 원화 표시용 포맷 */
function format_krw(float $amount): string
{
    return number_format($amount) . '원';
}

/** 외부 노출용 주문번호 생성: ORD + YYYYMMDD + 6자리 난수 */
function generate_order_no(): string
{
    return 'ORD' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/** 클라이언트 IP */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ---------------------------------------------------------------------------
// 감사 로그
// ---------------------------------------------------------------------------

function audit_log(string $action, ?string $targetType = null, ?int $targetId = null, array $meta = []): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_logs (actor_id, action, target_type, target_id, meta, ip)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            current_user_id(),
            $action,
            $targetType,
            $targetId,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            client_ip(),
        ]);
    } catch (Throwable $e) {
        // 감사 로그 실패가 본 트랜잭션을 깨지 않도록 삼킨다.
        if (APP_DEBUG) {
            error_log('[audit_log] ' . $e->getMessage());
        }
    }
}
