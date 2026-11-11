<?php
declare(strict_types=1);

/** 파일 업로드 / 프리랜서 포트폴리오 */
final class MediaController
{
    /** POST /uploads  (multipart/form-data: file, purpose) */
    public static function upload(): void
    {
        require_login();

        if (empty($_FILES['file'])) {
            json_error('업로드할 파일(file)이 없습니다.', 422);
        }
        $purpose = (string)($_POST['purpose'] ?? 'message');

        try {
            $result = Uploader::handle($_FILES['file'], $purpose);
        } catch (DomainException $e) {
            json_error($e->getMessage(), 422);
        }

        audit_log('upload.create', 'file', null, ['purpose' => $purpose, 'path' => $result['path']]);
        json_response($result, 201);
    }

    // ---- 포트폴리오 -------------------------------------------------------

    /** GET /portfolios?freelancer_id= */
    public static function listPortfolios(): void
    {
        $freelancerId = isset($_GET['freelancer_id']) ? (int)$_GET['freelancer_id'] : current_user_id();
        if (!$freelancerId) {
            json_error('freelancer_id 가 필요합니다.', 422);
        }
        $stmt = db()->prepare('SELECT * FROM portfolios WHERE freelancer_id = ? ORDER BY id DESC');
        $stmt->execute([$freelancerId]);
        json_response(['portfolios' => $stmt->fetchAll()]);
    }

    /** POST /portfolios  (freelancer) */
    public static function createPortfolio(): void
    {
        $user = require_role('freelancer');
        $data = json_input();
        require_fields($data, ['title']);

        $stmt = db()->prepare(
            'INSERT INTO portfolios (freelancer_id, title, description, thumbnail, link_url)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['id'],
            trim((string)$data['title']),
            $data['description'] ?? null,
            $data['thumbnail'] ?? null,
            $data['link_url'] ?? null,
        ]);
        json_response(['portfolio_id' => (int)db()->lastInsertId()], 201);
    }

    /** DELETE /portfolios/{id} */
    public static function deletePortfolio(string $id): void
    {
        $user = require_role('freelancer');
        $pid = (int)$id;

        $stmt = db()->prepare('SELECT freelancer_id, thumbnail FROM portfolios WHERE id = ? LIMIT 1');
        $stmt->execute([$pid]);
        $row = $stmt->fetch();
        if (!$row) {
            json_error('포트폴리오를 찾을 수 없습니다.', 404);
        }
        if ((int)$row['freelancer_id'] !== (int)$user['id']) {
            json_error('본인의 포트폴리오만 삭제할 수 있습니다.', 403);
        }

        db()->prepare('DELETE FROM portfolios WHERE id = ?')->execute([$pid]);
        self::removeStoredFile($row['thumbnail']);
        json_response(['message' => '삭제되었습니다.']);
    }

    /** uploads/ 하위 파일 안전 삭제 */
    public static function removeStoredFile(?string $relPath): void
    {
        if (!$relPath || !str_starts_with($relPath, 'uploads/')) {
            return;
        }
        $full = realpath(BASE_PATH . '/' . $relPath);
        $base = realpath(UPLOAD_DIR);
        if ($full && $base && str_starts_with($full, $base) && is_file($full)) {
            @unlink($full);
        }
    }
}
