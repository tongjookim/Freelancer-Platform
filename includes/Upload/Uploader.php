<?php
declare(strict_types=1);

/**
 * =============================================================================
 * Uploader — 파일 업로드 처리
 * =============================================================================
 * - 용도(purpose)별 허용 MIME/확장자 화이트리스트
 * - 확장자 위조 방지: finfo 로 실제 MIME 확인 + 확장자 매칭
 * - 저장 파일명은 랜덤(원본명 노출/충돌 방지), uploads/YYYY/MM/ 하위에 저장
 * - 반환: ['path' => 'uploads/2026/11/xxxx.pdf', 'url' => '/uploads/...', 'name' => 원본명, ...]
 */
final class Uploader
{
    private const PURPOSES = [
        'deliverable' => [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
            'app'   => ['pdf', 'zip', 'txt', 'md', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'psd', 'ai', 'fig', 'sketch'],
        ],
        'portfolio' => [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        ],
        'project' => [
            'app' => ['pdf', 'zip', 'txt', 'md', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg'],
        ],
        'message' => [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'app'   => ['pdf', 'zip', 'txt', 'doc', 'docx', 'xls', 'xlsx'],
        ],
    ];

    private const MIME_MAP = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf', 'zip' => 'application/zip',
        'txt' => 'text/plain', 'md' => 'text/markdown',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'psd' => 'image/vnd.adobe.photoshop', 'ai' => 'application/postscript',
        'fig' => 'application/octet-stream', 'sketch' => 'application/octet-stream',
    ];

    /** 허용 확장자 목록 반환 */
    public static function allowedExtensions(string $purpose): array
    {
        $p = self::PURPOSES[$purpose] ?? null;
        if ($p === null) {
            throw new DomainException('지원하지 않는 업로드 용도입니다: ' . $purpose);
        }
        return array_merge(...array_values($p));
    }

    /**
     * 업로드 처리.
     * @param array  $file    $_FILES['file'] 형태 배열
     * @param string $purpose deliverable | portfolio | project | message
     * @return array{path:string,url:string,name:string,size:int,mime:string}
     * @throws DomainException 검증 실패
     */
    public static function handle(array $file, string $purpose): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new DomainException('잘못된 업로드 요청입니다.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new DomainException('업로드 실패 (오류코드 ' . $file['error'] . ')');
        }
        if ($file['size'] > UPLOAD_MAX_BYTES) {
            throw new DomainException('파일이 너무 큽니다. 최대 ' . round(UPLOAD_MAX_BYTES / 1048576) . 'MB');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new DomainException('유효하지 않은 업로드 파일입니다.');
        }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $allowed = self::allowedExtensions($purpose);
        if (!in_array($ext, $allowed, true)) {
            throw new DomainException('허용되지 않는 파일 형식입니다: .' . $ext);
        }

        // 실제 MIME 검사 (확장자 위조 방지)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        $expected = self::MIME_MAP[$ext] ?? null;
        if ($expected !== null && $mime !== $expected && !self::mimeCompatible($ext, $mime)) {
            throw new DomainException('파일 내용과 확장자가 일치하지 않습니다. (' . $mime . ')');
        }

        $dir = sprintf('%s/%s/%s', UPLOAD_DIR, date('Y'), date('m'));
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new DomainException('업로드 디렉터리를 생성할 수 없습니다.');
        }

        $stored = date('Ymd_') . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = $dir . '/' . $stored;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new DomainException('파일 저장에 실패했습니다.');
        }
        @chmod($target, 0644);

        $rel = sprintf('uploads/%s/%s/%s', date('Y'), date('m'), $stored);
        return [
            'path' => $rel,
            'url'  => '/' . $rel,
            'name' => (string)$file['name'],
            'size' => (int)$file['size'],
            'mime' => $mime,
        ];
    }

    /** 일부 브라우저/OS 가 다르게 보고하는 MIME 보정 */
    private static function mimeCompatible(string $ext, string $mime): bool
    {
        $map = [
            'zip'   => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
            'docx'  => ['application/zip', 'application/octet-stream'],
            'xlsx'  => ['application/zip', 'application/octet-stream'],
            'pptx'  => ['application/zip', 'application/octet-stream'],
            'svg'   => ['image/svg+xml', 'text/plain', 'text/xml', 'application/xml'],
            'fig'   => ['application/octet-stream'],
            'sketch'=> ['application/zip', 'application/octet-stream'],
        ];
        return in_array($mime, $map[$ext] ?? [], true);
    }
}
