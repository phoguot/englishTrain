<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Service\SystemLogService;
use Laminas\View\Model\ViewModel;

/**
 * Trang tra cứu lỗi hệ thống (bảng `system_log`) — chỉ admin.
 * Chỉ đọc: log được ghi tự động từ listener dispatch.error, không có thao tác ghi ở đây.
 */
class SystemLogController extends BaseController
{
    protected const ALLOWED_ROLES = ['admin'];

    private const LIMIT = 100;

    public function __construct(private readonly SystemLogService $systemLogService)
    {
    }

    public function indexAction(): ViewModel
    {
        $levelRaw = $this->params()->fromQuery('level');
        $level    = is_string($levelRaw) && $levelRaw !== '' ? $levelRaw : null;

        // Service tự chuẩn hóa level lạ về null (= tất cả) — giá trị lọc, không phải dữ liệu ghi.
        $logs = $this->systemLogService->latest($level, self::LIMIT);

        $model = $this->getViewModel();
        $model->setVariables([
            'logs'  => $logs,
            'level' => $level,
            'limit' => self::LIMIT,
        ]);
        $model->setTemplate('application/system-log/index');

        return $model;
    }
}
