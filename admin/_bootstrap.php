<?php
declare(strict_types=1);

/**
 * 관리자 페이지 공통 부트스트랩
 * - 세션/DB/공통함수 로드
 * - 관리자 인증 가드(HTML 페이지용: 실패 시 로그인 페이지로 리다이렉트)
 * - 뷰 헬퍼(h, won)
 */
require_once dirname(__DIR__) . '/includes/config.php';

/** 관리자만 통과. 아니면 로그인 페이지로 이동. */
function admin_guard(): array
{
    $user = current_user();
    if ($user === null || $user['role'] !== 'admin') {
        header('Location: /admin/login.php');
        exit;
    }
    if ($user['status'] !== 'active') {
        logout_user();
        header('Location: /admin/login.php?err=inactive');
        exit;
    }
    return $user;
}

/** HTML 이스케이프 */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** 숫자 포맷 금액 + '원' */
function won($amount): string
{
    return number_format((float)$amount) . '원';
}

/** 주문 상태 한글 라벨 */
function order_status_label(string $status): string
{
    return [
        'pending_payment'    => '결제 대기',
        'in_progress'        => '작업 진행',
        'delivered'          => '검수 대기',
        'revision_requested' => '수정 요청',
        'completed'          => '완료',
        'cancelled'          => '취소',
        'disputed'           => '분쟁',
        'refunded'           => '환불',
    ][$status] ?? $status;
}

/** 정산 상태 한글 라벨 */
function settlement_status_label(string $status): string
{
    return [
        'pending'    => '지급 대기',
        'processing' => '지급 처리중',
        'paid'       => '지급 완료',
        'failed'     => '지급 실패',
        'cancelled'  => '취소',
    ][$status] ?? $status;
}
