<?php
declare(strict_types=1);

/**
 * 세션 기반 인증/인가 헬퍼
 * - 프론트(React)는 fetch 시 credentials:'include' 로 세션 쿠키를 함께 전송한다.
 */

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/** 현재 로그인 사용자(캐시) */
function current_user(): ?array
{
    static $user = null;
    $uid = current_user_id();
    if ($uid === null) {
        return null;
    }
    if ($user !== null && (int)$user['id'] === $uid) {
        return $user;
    }
    $stmt = db()->prepare(
        'SELECT id, email, name, phone, role, status, created_at FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $row = $stmt->fetch();
    return $user = ($row ?: null);
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/** 로그인 필수 */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        json_error('로그인이 필요합니다.', 401);
    }
    if ($user['status'] !== 'active') {
        json_error('정지되었거나 탈퇴한 계정입니다.', 403);
    }
    return $user;
}

/** 특정 역할 이상 필수 (문자열 또는 배열) */
function require_role(string|array $roles): array
{
    $user = require_login();
    $roles = (array)$roles;
    if (!in_array($user['role'], $roles, true)) {
        json_error('접근 권한이 없습니다.', 403);
    }
    return $user;
}

/** 로그인 처리 */
function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------------
// CSRF (상태 변경 요청 보호)
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** POST/PUT/PATCH/DELETE 요청에 대해 X-CSRF-Token 헤더 검증 */
function verify_csrf(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        json_error('CSRF 토큰이 유효하지 않습니다.', 419);
    }
}
