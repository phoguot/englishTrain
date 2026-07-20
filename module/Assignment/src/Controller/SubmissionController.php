<?php

declare(strict_types=1);

namespace Assignment\Controller;

use Application\Controller\BaseController;
use Application\Exception\ValidationException;
use Assignment\Service\SubmissionService;
use Laminas\View\Model\ViewModel;

/**
 * Chấm điểm bài nộp. Chỉ teacher — Service kiểm sở hữu lớp chứa bài tập của bài nộp.
 * Controller không validate: đưa POST thô cho Service, Service chạy GradeFilter.
 */
class SubmissionController extends BaseController
{
    protected const ALLOWED_ROLES = ['teacher'];

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
                'role' => 'teacher',
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
}
