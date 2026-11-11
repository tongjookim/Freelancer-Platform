<?php
declare(strict_types=1);

/** 회원가입 / 로그인 / 로그아웃 / 세션 조회 */
final class AuthController
{
    public static function register(): void
    {
        $data = json_input();
        require_fields($data, ['email', 'password', 'name', 'role']);

        $email = trim((string)$data['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_error('이메일 형식이 올바르지 않습니다.', 422);
        }
        if (strlen((string)$data['password']) < 8) {
            json_error('비밀번호는 8자 이상이어야 합니다.', 422);
        }
        $role = (string)$data['role'];
        if (!in_array($role, ['client', 'freelancer'], true)) {
            json_error('가입 역할은 client 또는 freelancer 만 가능합니다.', 422);
        }

        $exists = db()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetchColumn()) {
            json_error('이미 사용 중인 이메일입니다.', 409);
        }

        $userId = db_transaction(function (PDO $pdo) use ($email, $data, $role): int {
            $stmt = $pdo->prepare(
                'INSERT INTO users (email, password_hash, name, phone, role)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $email,
                password_hash((string)$data['password'], PASSWORD_DEFAULT),
                trim((string)$data['name']),
                $data['phone'] ?? null,
                $role,
            ]);
            $id = (int)$pdo->lastInsertId();

            if ($role === 'freelancer') {
                $pdo->prepare('INSERT INTO freelancer_profiles (user_id) VALUES (?)')->execute([$id]);
            }
            return $id;
        });

        login_user($userId);
        json_response(['user' => current_user(), 'csrf_token' => csrf_token()], 201);
    }

    public static function login(): void
    {
        $data = json_input();
        require_fields($data, ['email', 'password']);

        $stmt = db()->prepare('SELECT id, password_hash, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([trim((string)$data['email'])]);
        $row = $stmt->fetch();

        if (!$row || !password_verify((string)$data['password'], $row['password_hash'])) {
            json_error('이메일 또는 비밀번호가 올바르지 않습니다.', 401);
        }
        if ($row['status'] !== 'active') {
            json_error('정지되었거나 탈퇴한 계정입니다.', 403);
        }

        login_user((int)$row['id']);
        json_response(['user' => current_user(), 'csrf_token' => csrf_token()]);
    }

    public static function logout(): void
    {
        logout_user();
        json_response(['message' => '로그아웃되었습니다.']);
    }

    public static function me(): void
    {
        json_response([
            'user'       => current_user(),
            'csrf_token' => csrf_token(),
        ]);
    }

    /** CSRF 토큰 발급(프론트 초기화용) */
    public static function csrf(): void
    {
        json_response(['csrf_token' => csrf_token()]);
    }

    /** 비밀번호 변경 (로그인한 본인) */
    public static function changePassword(): void
    {
        $user = require_login();
        $data = json_input();
        require_fields($data, ['current_password', 'new_password']);

        $new = (string)$data['new_password'];
        if (strlen($new) < 8) {
            json_error('새 비밀번호는 8자 이상이어야 합니다.', 422);
        }
        if ($new === (string)$data['current_password']) {
            json_error('현재 비밀번호와 다른 값을 입력해 주세요.', 422);
        }

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        $hash = (string)$stmt->fetchColumn();
        if (!password_verify((string)$data['current_password'], $hash)) {
            json_error('현재 비밀번호가 올바르지 않습니다.', 422);
        }

        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);

        audit_log('user.password_change', 'user', (int)$user['id']);
        json_response(['message' => '비밀번호가 변경되었습니다.']);
    }
}
