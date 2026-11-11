<?php
declare(strict_types=1);

/**
 * REST API 프론트 컨트롤러
 * - 모든 /api/* 요청은 .htaccess rewrite 를 통해 이 파일로 유입된다.
 * - 라우트 정의만 담당하고, 실제 처리는 includes/Api/* 컨트롤러가 맡는다.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/api.php';

// 컨트롤러 및 도메인 서비스 로드
require_once __DIR__ . '/../includes/Escrow/EscrowService.php';
require_once __DIR__ . '/../includes/Notify/NotificationService.php';
require_once __DIR__ . '/../includes/Payments/StripeGateway.php';
require_once __DIR__ . '/../includes/Payments/TossPaymentsGateway.php';
require_once __DIR__ . '/../includes/Upload/Uploader.php';
require_once __DIR__ . '/../includes/Api/AuthController.php';
require_once __DIR__ . '/../includes/Api/MarketController.php';
require_once __DIR__ . '/../includes/Api/OrderController.php';
require_once __DIR__ . '/../includes/Api/AdminController.php';
require_once __DIR__ . '/../includes/Api/PaymentController.php';
require_once __DIR__ . '/../includes/Api/MediaController.php';
require_once __DIR__ . '/../includes/Api/ReviewController.php';
require_once __DIR__ . '/../includes/Api/NotificationController.php';

// ---- CORS (프론트를 별도 도메인/포트에서 구동하는 경우) --------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = []; // 예: ['http://localhost:5173', 'https://app.example.com']
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router(API_BASE);

// ---- 인증 ------------------------------------------------------------------
$router->post('/auth/register', [AuthController::class, 'register']);
$router->post('/auth/login',    [AuthController::class, 'login']);
$router->post('/auth/logout',   [AuthController::class, 'logout']);
$router->get('/auth/me',        [AuthController::class, 'me']);
$router->get('/auth/csrf',      [AuthController::class, 'csrf']);
$router->post('/auth/password', [AuthController::class, 'changePassword']);   // 본인 비밀번호 변경

// ---- 인앱 알림 -------------------------------------------------------------
$router->get('/notifications',                 [NotificationController::class, 'index']);
$router->post('/notifications/read-all',       [NotificationController::class, 'markAllRead']);
$router->post('/notifications/{id}/read',      [NotificationController::class, 'markRead']);

// ---- 카탈로그/마켓 ---------------------------------------------------------
$router->get('/categories',           [MarketController::class, 'categories']);
$router->get('/services',             [MarketController::class, 'listServices']);
$router->get('/services/{id}',        [MarketController::class, 'showService']);
$router->post('/services',            [MarketController::class, 'createService']);       // freelancer
$router->get('/projects',             [MarketController::class, 'listProjects']);
$router->post('/projects',            [MarketController::class, 'createProject']);        // client
$router->get('/projects/{id}',        [MarketController::class, 'showProject']);
$router->post('/projects/{id}/proposals', [MarketController::class, 'submitProposal']);   // freelancer
$router->post('/proposals/{id}/accept',   [MarketController::class, 'acceptProposal']);   // client → 주문 생성

// ---- 파일 업로드 / 포트폴리오 ---------------------------------------------
$router->post('/uploads',            [MediaController::class, 'upload']);               // multipart
$router->get('/portfolios',          [MediaController::class, 'listPortfolios']);
$router->post('/portfolios',         [MediaController::class, 'createPortfolio']);      // freelancer
$router->delete('/portfolios/{id}',  [MediaController::class, 'deletePortfolio']);

// ---- 주문/에스크로 ---------------------------------------------------------
$router->get('/orders',                    [OrderController::class, 'index']);
$router->get('/orders/{id}',               [OrderController::class, 'show']);
$router->post('/orders',                   [OrderController::class, 'store']);       // 직접 주문 생성
$router->post('/orders/{id}/pay',          [OrderController::class, 'pay']);         // 에스크로 예치(수동/모의)
$router->post('/orders/{id}/intent',       [OrderController::class, 'intent']);      // Stripe PaymentIntent 생성
$router->post('/orders/{id}/toss/confirm', [OrderController::class, 'tossConfirm']); // 토스 결제 승인
$router->post('/orders/{id}/deliver',      [OrderController::class, 'deliver']);     // 작업물 발송(freelancer)
$router->post('/orders/{id}/approve',      [OrderController::class, 'approve']);     // 최종 승인 → 정산(client)
$router->post('/orders/{id}/refund',       [OrderController::class, 'refund']);      // 환불(정책 적용)
$router->post('/orders/{id}/dispute',      [OrderController::class, 'dispute']);     // 분쟁 제기
$router->get('/orders/{id}/messages',      [OrderController::class, 'messages']);
$router->post('/orders/{id}/messages',     [OrderController::class, 'sendMessage']);
$router->get('/orders/{id}/reviews',       [ReviewController::class, 'forOrder']);   // 주문 리뷰 목록
$router->post('/orders/{id}/reviews',      [ReviewController::class, 'store']);      // 리뷰 작성
$router->get('/reviews',                   [ReviewController::class, 'listByUser']);  // 사용자 리뷰
$router->post('/reviews/{id}/report',      [ReviewController::class, 'report']);     // 리뷰 신고

// ---- 결제수단 / PG 웹훅 (CSRF 제외) ---------------------------------------
$router->get('/payments/methods', [PaymentController::class, 'methods']);
$router->post('/webhooks/stripe', [PaymentController::class, 'stripe'], csrf: false);
$router->post('/webhooks/toss',   [PaymentController::class, 'toss'],   csrf: false);

// ---- 관리자 ----------------------------------------------------------------
$router->get('/admin/dashboard',   [AdminController::class, 'dashboard']);
$router->get('/admin/stats',       [AdminController::class, 'stats']);
$router->get('/admin/settings',    [AdminController::class, 'getSettings']);
$router->put('/admin/settings',    [AdminController::class, 'updateSettings']);
$router->get('/admin/orders',      [AdminController::class, 'orders']);
$router->get('/admin/settlements', [AdminController::class, 'settlements']);
$router->post('/admin/settlements/{id}/payout', [AdminController::class, 'markPaid']);
$router->get('/admin/disputes',    [AdminController::class, 'disputes']);
$router->get('/admin/review-reports',        [AdminController::class, 'reviewReports']);
$router->post('/admin/reviews/{id}/hide',    [AdminController::class, 'hideReview']);
$router->post('/admin/reviews/{id}/restore', [AdminController::class, 'restoreReview']);
$router->post('/admin/orders/{id}/resolve-dispute', [AdminController::class, 'resolveDispute']);
$router->get('/admin/users',       [AdminController::class, 'users']);
$router->patch('/admin/users/{id}/status',         [AdminController::class, 'updateUserStatus']);
$router->post('/admin/users/{id}/reset-password',  [AdminController::class, 'resetPassword']);

$router->dispatch();
