<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Exception\AccessDeniedException;
use Application\Service\SystemLogService;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;

/**
 * Trang tra cứu lỗi hệ thống (bảng `system_log`) — chỉ admin.
 * Đọc: log được ghi tự động từ listener dispatch.error. Ghi duy nhất ở đây là xóa dọn dẹp
 * (xóa 1 dòng hoặc xóa nhiều dòng đã chọn), luôn qua POST + CSRF.
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

    /** Xóa một dòng log. Chỉ nhận POST từ biểu mẫu có CSRF. */
    public function deleteAction(): Response
    {
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận xóa log từ biểu mẫu xác nhận.');
        }

        $id = (int) $this->params()->fromRoute('id', 0);
        $this->systemLogService->delete($id);
        $this->flashMessenger()->addSuccessMessage('Đã xóa 1 dòng log.');

        return $this->redirectToList();
    }

    /** Xóa nhiều dòng log đã chọn. Chỉ nhận POST từ biểu mẫu có CSRF. */
    public function deleteManyAction(): Response
    {
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận xóa log từ biểu mẫu xác nhận.');
        }

        $ids = $this->getRequest()->getPost('ids', []);
        $ids = is_array($ids) ? $ids : [];

        $count = $this->systemLogService->deleteMany($ids);
        $this->flashMessenger()->addSuccessMessage(
            $count > 0 ? sprintf('Đã xóa %d dòng log.', $count) : 'Chưa chọn dòng log nào để xóa.',
        );

        return $this->redirectToList();
    }

    /** Quay về danh sách, giữ nguyên bộ lọc level (nếu có) người dùng đang xem. */
    private function redirectToList(): Response
    {
        $levelRaw = $this->params()->fromQuery('level');
        $query    = is_string($levelRaw) && $levelRaw !== '' ? ['level' => $levelRaw] : [];

        return $this->redirect()->toRoute('system_logs', [], ['query' => $query]);
    }
}
