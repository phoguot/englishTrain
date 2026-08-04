<?php

declare(strict_types=1);

namespace Assignment\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Assignment\Service\SubmissionService;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use User\Model\User\UserModel;
use User\Service\UserService;

/**
 * Màn quản trị video bài nộp (bảng `submission`, file thật trên R2) — chỉ admin.
 * Xem toàn hệ thống theo học sinh (lọc ?student=), xem video, xóa hẳn file trên R2 + row submission.
 */
class VideoAdminController extends BaseController
{
    protected const ALLOWED_ROLES = [UserModel::ROLE_ADMIN];

    public function __construct(
        private readonly SubmissionService $submissionService,
        private readonly UserService $userService,
    ) {
    }

    public function indexAction(): ViewModel
    {
        $studentId = (int) $this->params()->fromQuery('student', 0);

        $model = $this->getViewModel();
        $model->setVariables([
            'videos'    => $this->submissionService->listVideoSubmissionsForAdmin($studentId),
            'students'  => $this->userService->findByRole(UserModel::ROLE_STUDENT),
            'studentId' => $studentId,
        ]);
        $model->setTemplate('assignment/video-admin/index');

        return $model;
    }

    public function deleteAction(): mixed
    {
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận xóa video từ biểu mẫu xác nhận.');
        }

        $id        = (int) $this->params()->fromRoute('id', 0);
        $studentId = (int) $this->params()->fromQuery('student', 0);

        $this->submissionService->deleteVideoSubmissionForAdmin($id);
        $this->flashMessenger()->addSuccessMessage('Đã xóa video khỏi hệ thống lưu trữ.');

        $query = $studentId > 0 ? ['student' => $studentId] : [];

        return $this->redirect()->toRoute('admin_videos', [], ['query' => $query]);
    }

    /** Bước xin URL xem video — JSON, cùng luồng data-video-view với SubmissionController::videoUrlAction. */
    public function videoUrlAction(): JsonModel
    {
        $id = (int) $this->params()->fromRoute('id', 0);

        try {
            $url = $this->submissionService->getVideoViewUrlForAdmin($id);
        } catch (NotFoundException $e) {
            $this->getResponse()->setStatusCode(404);

            return new JsonModel(['error' => $e->getMessage()]);
        }

        return new JsonModel(['url' => $url]);
    }
}
