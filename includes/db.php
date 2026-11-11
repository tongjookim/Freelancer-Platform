<?php
declare(strict_types=1);

/**
 * PDO 커넥션 (지연 초기화 싱글턴)
 * - 예외 모드 + 에뮬레이션 비활성화(실제 prepared statement)로 SQL 인젝션 차단
 * - InnoDB 트랜잭션/행 잠금(SELECT ... FOR UPDATE) 사용을 전제로 한다.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    return $pdo;
}

/**
 * 트랜잭션 헬퍼: 콜백을 원자적으로 실행한다.
 * 예외 발생 시 롤백 후 재던짐.
 *
 * @template T
 * @param callable(PDO):T $callback
 * @return T
 */
function db_transaction(callable $callback)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
