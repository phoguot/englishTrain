<?php

declare(strict_types=1);

namespace Assignment\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Assignment\Service\SubmissionService;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use User\Model\User\UserModel;

/**
 * Chấm điểm bài nộp + xin URL xem video. Chỉ teacher — Service kiểm sở hữu lớp chứa bài tập
 * của bài nộp. Controller không validate: đưa POST thô cho Service, Service chạy GradeFilter.
 */
class SubmissionController extends BaseController
{
    protected const ALLOWED_ROLES = [UserModel::ROLE_TEACHER];

    public function __construct(private readonly SubmissionService $submissionService)
    {
    }

    public function gradeAction(): mixed
    {
        $submissionId = (int) $this->params()->fromRoute('id', 0);

        try {
            $assignmentId = $this->submissionService->grade(
                $submissionId,
                $this->getAllPostParams(),
                (int) $this->currentUserId(),
            );
            $this->flashMessenger()->addSuccessMessage('Đã lưu điểm.');
        } catch (ValidationException $e) {
            $assignmentId = (int) ($e->getContext()['assignmentId'] ?? 0);
            if ($assignmentId === 0) {
                return $this->redirect()->toRoute('dashboard');
            }
            $grading = $this->submissionService->getForGrading($assignmentId, (int) $this->currentUserId());
            $model = $this->getViewModel();
            $model->setVariables([
                'assignment' => $grading['assignment'],
                'role' => UserModel::ROLE_TEACHER,
                'rows' => $grading['rows'],
                'canEdit' => true,
                'gradeSubmissionId' => $submissionId,
                'gradeValues' => $this->getAllPostParams(),
                'gradeErrors' => $e->getErrors(),
            ]);
            $model->setTemplate('assignment/assignment/view-teacher');

            return $model;
        }

        return $this->redirect()->toRoute('assignment_view', ['id' => $assignmentId]);
    }

    /** Xin presigned GET URL (hạn 1h) để xem video một bài nộp — chỉ giáo viên phụ trách lớp. */
    public function videoUrlAction(): JsonModel
    {
        $submissionId = (int) $this->params()->fromRoute('id', 0);

        try {
            $url = $this->submissionService->getVideoViewUrlForTeacher($submissionId, (int) $this->currentUserId());
        } catch (NotFoundException|AccessDeniedException $e) {
            $this->getResponse()->setStatusCode($e instanceof NotFoundException ? 404 : 403);

            return new JsonModel(['error' => $e->getMessage()]);
        }

        return new JsonModel(['url' => $url]);
    }
}
