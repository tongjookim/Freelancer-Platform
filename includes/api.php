<?php
declare(strict_types=1);

/**
 * 경량 REST 라우터
 * - /api/* 요청을 메서드 + 경로 패턴으로 매칭해 컨트롤러로 위임한다.
 * - 경로 파라미터는 {id} 형태로 정의하며 콜백에 순서대로 전달된다.
 *
 * 사용 예:
 *   $router->get('/orders/{id}', [OrderController::class, 'show']);
 *   → OrderController::show(12)
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, keys:string[], handler:callable}> */
    private array $routes = [];
    private string $prefix;

    public function __construct(string $prefix = '')
    {
        $this->prefix = rtrim($prefix, '/');
    }

    public function get(string $path, callable $handler, bool $csrf = true): void    { $this->add('GET', $path, $handler, $csrf); }
    public function post(string $path, callable $handler, bool $csrf = true): void   { $this->add('POST', $path, $handler, $csrf); }
    public function put(string $path, callable $handler, bool $csrf = true): void    { $this->add('PUT', $path, $handler, $csrf); }
    public function patch(string $path, callable $handler, bool $csrf = true): void  { $this->add('PATCH', $path, $handler, $csrf); }
    public function delete(string $path, callable $handler, bool $csrf = true): void { $this->add('DELETE', $path, $handler, $csrf); }

    private function add(string $method, string $path, callable $handler, bool $csrf = true): void
    {
        $path = '/' . trim($path, '/');
        $keys = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$keys) {
            $keys[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'keys'    => $keys,
            'handler' => $handler,
            'csrf'    => $csrf,
        ];
    }

    /** 현재 요청 URI 에서 라우터 prefix 를 제거한 경로를 계산 */
    private function currentPath(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($this->prefix !== '' && str_starts_with($uri, $this->prefix)) {
            $uri = substr($uri, strlen($this->prefix));
        }
        $uri = '/' . trim($uri, '/');
        return $uri === '//' ? '/' : $uri;
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path   = $this->currentPath();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[$route['method']] = true;
                continue;
            }

            // CSRF 는 상태 변경 메서드에 자동 적용 (웹훅 등 예외는 라우트 등록 시 csrf:false)
            if ($route['csrf'] !== false) {
                verify_csrf();
            }

            array_shift($matches); // 전체 매칭 제거
            $params = array_map('urldecode', $matches);

            try {
                $result = call_user_func_array($route['handler'], $params);
                // 컨트롤러가 json_response 로 종료하지 않은 경우 결과를 표준 응답으로 감싼다.
                if ($result !== null) {
                    json_response($result);
                }
            } catch (PDOException $e) {
                json_error(APP_DEBUG ? 'DB 오류: ' . $e->getMessage() : '데이터베이스 처리 중 오류가 발생했습니다.', 500);
            } catch (DomainException $e) {
                json_error($e->getMessage(), 409);
            } catch (Throwable $e) {
                json_error(APP_DEBUG ? $e->getMessage() : '서버 오류가 발생했습니다.', 500);
            }
            return;
        }

        if ($allowed) {
            header('Allow: ' . implode(', ', array_keys($allowed)));
            json_error('허용되지 않은 메서드입니다.', 405);
        }
        json_error('존재하지 않는 API 경로입니다: ' . $path, 404);
    }
}
