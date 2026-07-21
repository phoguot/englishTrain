<?php

declare(strict_types=1);

namespace Assignment\Service;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

/**
 * Bọc AWS SDK S3Client trỏ vào Cloudflare R2 (S3-compatible). File KHÔNG đi qua PHP —
 * chỉ ký URL, browser PUT/GET thẳng R2. Xem .claude/rules/01-bao-mat.md và module/Assignment/CLAUDE.md.
 */
class R2StorageService
{
    private const UPLOAD_TTL = '+15 minutes';
    private const VIEW_TTL   = '+1 hour';

    /** @var array<string,string> mime => phần mở rộng file lưu trên R2 */
    public const ALLOWED_MIME_EXTENSIONS = [
        'video/mp4'        => 'mp4',
        'video/webm'       => 'webm',
        'video/quicktime'  => 'mov',
        'video/x-matroska' => 'mkv',
    ];

    private readonly S3Client $client;
    private readonly string $bucket;
    private readonly int $maxUploadBytes;

    /** @param array<string,mixed> $config khối "r2" trong config/autoload/local.php */
    public function __construct(array $config)
    {
        $this->bucket         = (string) ($config['bucket'] ?? '');
        $this->maxUploadBytes = (int) ($config['max_upload_mb'] ?? 200) * 1024 * 1024;

        $this->client = new S3Client([
            'version'                 => 'latest',
            'region'                  => 'auto',
            'endpoint'                => sprintf('https://%s.r2.cloudflarestorage.com', (string) ($config['account_id'] ?? '')),
            'use_path_style_endpoint' => true,
            'credentials'             => [
                'key'    => (string) ($config['access_key'] ?? ''),
                'secret' => (string) ($config['secret_key'] ?? ''),
            ],
        ]);
    }

    public function maxUploadBytes(): int
    {
        return $this->maxUploadBytes;
    }

    /** @return string[] */
    public function allowedMimeTypes(): array
    {
        return array_keys(self::ALLOWED_MIME_EXTENSIONS);
    }

    /**
     * Sinh object key nhóm theo assignment/student — dùng lại ở completeVideoUpload để kiểm key
     * trả về đúng thuộc bài nộp này (không tin key client gửi khống).
     *
     * Tên file lấy theo "{username}_{ngày nộp ddmmyyyy}.{ext}" (vd "khanhvd_16072022.mov") để giáo
     * viên tải về nhận ra ngay của học sinh nào, thay vì chuỗi ngẫu nhiên vô nghĩa.
     */
    public function buildObjectKey(int $assignmentId, int $studentId, string $mime, string $studentUsername): string
    {
        $ext  = self::ALLOWED_MIME_EXTENSIONS[$mime] ?? 'bin';
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $studentUsername));
        if ($slug === '') {
            $slug = 'student';
        }

        return sprintf('submissions/%d/%d/%s_%s.%s', $assignmentId, $studentId, $slug, date('dmY'), $ext);
    }

    /**
     * Presigned PUT — hạn ≤ 15 phút. ContentType + ContentLength nằm trong chữ ký nên browser
     * PHẢI gửi đúng 2 header đó, không thì R2 từ chối (ràng buộc theo .claude/rules/01-bao-mat.md).
     */
    public function presignedUploadUrl(string $key, string $mime, int $size): string
    {
        $command = $this->client->getCommand('PutObject', [
            'Bucket'        => $this->bucket,
            'Key'           => $key,
            'ContentType'   => $mime,
            'ContentLength' => $size,
        ]);

        return (string) $this->client->createPresignedRequest($command, self::UPLOAD_TTL)->getUri();
    }

    /** Presigned GET — hạn 1h. CHỈ gọi sau khi Service đã kiểm quyền xem submission này. */
    public function presignedViewUrl(string $key): string
    {
        $command = $this->client->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]);

        return (string) $this->client->createPresignedRequest($command, self::VIEW_TTL)->getUri();
    }

    /** Xóa hẳn file trên R2 — dùng khi admin xóa video khỏi màn quản trị. Idempotent: object không
     * tồn tại vẫn coi là thành công (S3 DeleteObject không báo lỗi trong trường hợp đó). */
    public function deleteObject(string $key): void
    {
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }

    /**
     * Kích thước thật của object trên R2, null nếu không tồn tại — dùng để phát hiện
     * client gọi upload-done khống (mất mạng giữa chừng, hoặc không upload thật).
     */
    public function actualObjectSize(string $key): ?int
    {
        try {
            $result = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
        } catch (S3Exception) {
            return null;
        }

        return isset($result['ContentLength']) ? (int) $result['ContentLength'] : null;
    }
}
