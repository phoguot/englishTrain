<?php

declare(strict_types=1);

namespace Application\Service;

use Application\Model\SystemLog\SystemLogMapper;
use Application\Model\SystemLog\SystemLogModel;
use Laminas\Http\Request as HttpRequest;
use Laminas\Stdlib\RequestInterface;
use Throwable;
use User\Service\AuthService;

/**
 * Ghi lỗi hệ thống vào bảng `system_log`.
 *
 * Được gọi từ listener dispatch.error / render.error trong Application\Module —
 * mọi exception không được xử lý (lỗi DB, lỗi lập trình, ValidationException lọt lưới)
 * đều đi qua đây trước khi user thấy trang lỗi chung.
 *
 * LUẬT QUAN TRỌNG: ghi log KHÔNG BAO GIỜ được ném lỗi tiếp — nếu chính việc ghi log hỏng
 * (ví dụ DB sập là nguyên nhân lỗi gốc) thì nuốt lặng lẽ, fallback error_log() của PHP.
 */
class SystemLogService
{
    private const MAX_MESSAGE_LENGTH = 2000;
    private const MAX_TRACE_LENGTH   = 60000;

    public function __construct(
        private readonly SystemLogMapper $systemLogMapper,
        private readonly AuthService $authService,
    ) {
    }

    /** Ghi một exception chưa được xử lý. Không ném lỗi trong mọi trường hợp. */
    public function logThrowable(Throwable $e, ?RequestInterface $request = null): void
    {
        try {
            $log = new SystemLogModel();
            $log->setLevel(SystemLogModel::LEVEL_ERROR);
            $log->setMessage(mb_substr($e->getMessage(), 0, self::MAX_MESSAGE_LENGTH));
            $log->setExceptionClass($e::class);
            $log->setFile(mb_substr($e->getFile(), 0, 500));
            $log->setLine($e->getLine());
            $log->setStackTrace(mb_substr($e->getTraceAsString(), 0, self::MAX_TRACE_LENGTH));

            if ($request instanceof HttpRequest) {
                $log->setRequestMethod(mb_substr($request->getMethod(), 0, 10));
                $log->setRequestUri(mb_substr($this->sanitizeRequestUri($request->getUriString()), 0, 500));
            }

            $log->setUserId($this->authService->currentUserId());

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $log->setIp(is_string($ip) ? mb_substr($ip, 0, 45) : null);

            $this->systemLogMapper->saveSystemLog($log);
        } catch (Throwable $loggingFailure) {
            // Ghi vào DB thất bại (thường vì DB chính là thứ đang hỏng) → fallback log file PHP.
            error_log(sprintf(
                'system_log ghi that bai (%s). Loi goc: %s: %s tai %s:%d',
                $loggingFailure->getMessage(),
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
        }
    }

    private function sanitizeRequestUri(string $uri): string
    {
        return (string) preg_replace(
            '/([?&](?:code|state|token|access_token|refresh_token|id_token|client_secret|error_description)=)[^&#]*/i',
            '$1[redacted]',
            $uri,
        );
    }

    /**
     * Log mới nhất trước — cho trang /system-logs của admin.
     * $level lạ (không thuộc tập hợp lệ) coi như null = xem tất cả; đây là giá trị LỌC
     * hiển thị, không phải dữ liệu ghi nên không cần ném ValidationException.
     *
     * @return SystemLogModel[]
     */
    public function latest(?string $level = null, int $limit = 100): array
    {
        $validLevels = [
            SystemLogModel::LEVEL_ERROR,
            SystemLogModel::LEVEL_WARNING,
            SystemLogModel::LEVEL_INFO,
        ];
        if ($level !== null && !in_array($level, $validLevels, true)) {
            $level = null;
        }

        return $this->systemLogMapper->searchSystemLog($level, $limit);
    }
}
