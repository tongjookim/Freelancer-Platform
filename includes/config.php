<?php
declare(strict_types=1);

/**
 * 전역 설정 및 부트스트랩
 * - DB 접속 정보, 타임존, 세션, 에러 표기 정책을 한 곳에서 관리한다.
 */

/**
 * 환경변수 조회 헬퍼
 * - CLI(getenv)와 PHP-FPM(fastcgi_param → $_SERVER) 양쪽에서 동작하도록 순회 조회한다.
 */
function env(string $key, ?string $default = null): ?string
{
    $v = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return (string)$v;
}

// ---- 환경 구분 -------------------------------------------------------------
define('APP_ENV', env('APP_ENV', 'production'));          // local | production
define('APP_DEBUG', env('APP_DEBUG', APP_ENV === 'local' ? '1' : '0') === '1');

// ---- DB 접속 ---------------------------------------------------------------
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'freelance_market'));
define('DB_USER', env('DB_USER', 'freelance'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// ---- 앱 경로 ---------------------------------------------------------------
define('BASE_PATH', dirname(__DIR__));                     // 프로젝트 루트
define('API_BASE', '/api');                                // REST API 기본 prefix

// ---- 업로드 ----------------------------------------------------------------
define('UPLOAD_DIR', BASE_PATH . '/uploads');              // 실제 저장 경로
define('UPLOAD_URL', '/uploads');                          // 웹 접근 경로
define('UPLOAD_MAX_BYTES', 10 * 1024 * 1024);              // 파일당 10MB

// ---- 에러 표기 -------------------------------------------------------------
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

// ---- 타임존 / 인코딩 -------------------------------------------------------
date_default_timezone_set('Asia/Seoul');
mb_internal_encoding('UTF-8');

// ---- 세션 (React fetch: credentials:'include' 와 함께 사용) ---------------
// CLI(크론 등)에서는 세션을 시작하지 않는다.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => APP_ENV === 'production',
    ]);
    session_name('FMSESSID');
    session_start();
}

// ---- 공통 로더 -------------------------------------------------------------
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
