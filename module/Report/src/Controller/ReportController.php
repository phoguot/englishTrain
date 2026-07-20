<?php

declare(strict_types=1);

namespace Report\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\ValidationException;
use Laminas\View\Model\ViewModel;
use Report\Model\StudentReport\StudentReportModel;
use Report\Service\ReportService;

class ReportController extends BaseController
{
    protected const ALLOWED_ROLES = ['teacher', 'student'];

    public function __construct(private readonly ReportService $reportService)
    {
    }

    public function indexAction(): ViewModel
    {
        $this->assertTeacher();
        $classroomId = (int) $this->params()->fromQuery('classroom', 0);
        $data = $this->reportService->listForTeacher($classroomId, (int) $this->currentUserId());
        $model = $this->getViewModel();
        $model->setVariables($data + [
            'canEdit' => !$data['classroom']->isArchived(),
            'csrfToken' => $this->authService->csrfToken(),
        ]);
        return $model;
    }

    public function createAction(): mixed
    {
        $this->assertTeacher();
        $studentId = (int) $this->params()->fromQuery('student', 0);
        $classroomId = (int) $this->params()->fromQuery('classroom', 0);
        if ($this->getRequest()->isPost()) {
            return $this->handleSave(null, $studentId, $classroomId);
        }
        return $this->formView(null, [
            'student_id' => $studentId,
            'classroom_id' => $classroomId,
            'period_label' => date('Y-m'),
            'content' => '',
        ], []);
    }

    public function editAction(): mixed
    {
        $this->assertTeacher();
        $id = (int) $this->params()->fromRoute('id', 0);
        $report = $this->reportService->getEditable($id, (int) $this->currentUserId());
        if ($this->getRequest()->isPost()) {
            return $this->handleSave($report, (int) $report->getStudentId(), (int) $report->getClassroomId());
        }
        return $this->formView($report, [
            'student_id' => (int) $report->getStudentId(),
            'classroom_id' => (int) $report->getClassroomId(),
            'period_label' => (string) $report->getPeriodLabel(),
            'content' => (string) $report->getContent(),
        ], []);
    }

    public function publishAction(): mixed
    {
        $this->assertTeacher();
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận xuất bản từ biểu mẫu xác nhận.');
        }
        $classroomId = $this->reportService->publish(
            (int) $this->params()->fromRoute('id', 0),
            (int) $this->currentUserId(),
        );
        $this->flashMessenger()->addSuccessMessage('Đã xuất bản report cho học sinh.');
        return $this->redirect()->toRoute('reports', [], ['query' => ['classroom' => $classroomId]]);
    }

    public function myReportsAction(): ViewModel
    {
        if ($this->currentRole() !== 'student') {
            throw new AccessDeniedException('Chỉ học sinh được xem trang report cá nhân.');
        }
        $model = $this->getViewModel();
        $model->setVariables(['reports' => $this->reportService->listPublishedForStudent((int) $this->currentUserId())]);
        $model->setTemplate('report/report/my-reports');
        return $model;
    }

    private function assertTeacher(): void
    {
        if ($this->currentRole() !== 'teacher') {
            throw new AccessDeniedException('Chỉ giáo viên được quản lý report.');
        }
    }

    private function handleSave(?StudentReportModel $report, int $studentId, int $classroomId): mixed
    {
        $post = $this->getAllPostParams();
        $post['student_id'] = $studentId;
        $post['classroom_id'] = $classroomId;
        try {
            $saved = $report === null
                ? $this->reportService->create($post, (int) $this->currentUserId())
                : $this->reportService->update((int) $report->getId(), $post, (int) $this->currentUserId());
            $this->flashMessenger()->addSuccessMessage($report === null ? 'Đã lưu report nháp.' : 'Đã cập nhật report nháp.');
            return $this->redirect()->toRoute('reports', [], ['query' => ['classroom' => $saved->getClassroomId()]]);
        } catch (ValidationException $e) {
            return $this->formView($report, $post, $e->getErrors());
        }
    }

    /** @param array<string,mixed> $values @param array<string,string> $errors */
    private function formView(?StudentReportModel $report, array $values, array $errors): ViewModel
    {
        $studentId = is_numeric($values['student_id'] ?? null) ? (int) $values['student_id'] : 0;
        $classroomId = is_numeric($values['classroom_id'] ?? null) ? (int) $values['classroom_id'] : 0;
        $periodLabel = is_scalar($values['period_label'] ?? null) ? (string) $values['period_label'] : date('Y-m');
        $statsPeriod = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodLabel) === 1
            ? $periodLabel
            : date('Y-m');
        $data = $report === null
            ? $this->reportService->getComposerData($studentId, $classroomId, $statsPeriod, (int) $this->currentUserId())
            : $this->reportService->getEditComposerData((int) $report->getId(), $statsPeriod, (int) $this->currentUserId());
        $model = $this->getViewModel();
        $model->setVariables($data + [
            'isEdit' => $report !== null,
            'editId' => $report?->getId(),
            'values' => [
                'student_id' => $studentId,
                'classroom_id' => $classroomId,
                'period_label' => $periodLabel,
                'content' => is_scalar($values['content'] ?? null) ? (string) $values['content'] : '',
            ],
            'errors' => $errors,
            'csrfToken' => $this->authService->csrfToken(),
        ]);
        $model->setTemplate('report/report/form');
        return $model;
    }
}
