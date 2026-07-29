<?php

declare(strict_types=1);

namespace Assignment\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Assignment\Exception\AssignmentClosedException;
use Assignment\Model\Assignment\AssignmentModel;
use Assignment\Service\AssignmentService;
use Assignment\Service\QuizImportService;
use Assignment\Service\SubmissionService;
use Classroom\Service\ClassroomService;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;

/**
 * CRUD bài tập + xem chi tiết + nộp bài (essay/quiz) + xin/ xác nhận upload video R2.
 * - index, view: teacher + student (Service lọc theo quyền sở hữu).
 * - create, edit, delete: chỉ teacher (assertTeacher trong action).
 * - submit: chỉ student.
 * - uploadUrl, uploadDone: chỉ student — luồng JSON riêng cho browser PUT thẳng R2.
 * - quizImport: chỉ teacher — đọc file Excel/Word thành danh sách câu hỏi, trả JSON, không lưu DB.
 *
 * Controller KHÔNG validate và KHÔNG kiểm value — đưa POST thô cho Service, Service chạy
 * Filter class rồi ném ValidationException; ở đây chỉ lo render lại form / flash lỗi.
 *
 * uploadUrlAction/uploadDoneAction là API JSON (không phải form HTML) nên tự bắt exception
 * ngay tại action thay vì để onDispatch() render trang lỗi HTML — hợp đồng JSON luôn trả
 * {error: string} kèm mã HTTP đúng nghĩa, xem docs/04-contracts.md.
 */
class AssignmentController extends BaseController
{
    protected const ALLOWED_ROLES = ['teacher', 'student'];

    public function __construct(
        private readonly AssignmentService $assignmentService,
        private readonly SubmissionService $submissionService,
        private readonly ClassroomService $classroomService,
        private readonly QuizImportService $quizImportService,
    ) {
    }

    public function indexAction(): ViewModel
    {
        $classroomId = (int) $this->params()->fromQuery('classroom', 0);

        // listRowsForActor tự kiểm sở hữu (ném NotFound/AccessDenied) — gọi trước để không
        // lộ tên lớp khi không có quyền.
        $assignments = $this->assignmentService->listRowsForActor(
            $classroomId,
            (int) $this->currentUserId(),
            (string) $this->currentRole(),
        );
        $classroom = $this->classroomService->find($classroomId);

        $model = $this->getViewModel();
        $model->setVariables([
            'role'          => $this->currentRole(),
            'classroomId'   => $classroomId,
            'classroomName' => $classroom?->name ?? '',
            'canEdit'       => $this->currentRole() === 'teacher' && $classroom !== null && !$classroom->isArchived(),
            'assignments'   => $assignments,
        ]);
        $model->setTemplate('assignment/assignment/index');

        return $model;
    }

    public function createAction(): mixed
    {
        $this->assertTeacher();

        if ($this->getRequest()->isPost()) {
            return $this->handleSave(null);
        }

        $classroomId = (int) $this->params()->fromQuery('classroom', 0);

        return $this->formView(null, ['classroom_id' => $classroomId], [], []);
    }

    public function editAction(): mixed
    {
        $this->assertTeacher();

        $id         = (int) $this->params()->fromRoute('id', 0);
        $assignment = $this->assignmentService->getEditable($id, (int) $this->currentUserId());

        if ($this->getRequest()->isPost()) {
            return $this->handleSave($assignment);
        }

        return $this->formView($assignment, [], [], $assignment->getQuizJson() ?? []);
    }

    public function deleteAction(): mixed
    {
        $this->assertTeacher();

        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận xóa bài tập từ biểu mẫu xác nhận.');
        }

        $id = (int) $this->params()->fromRoute('id', 0);
        $assignment = $this->assignmentService->delete($id, (int) $this->currentUserId());
        $this->flashMessenger()->addSuccessMessage('Đã xóa bài tập "' . $assignment->getTitle() . '".');

        return $this->redirect()->toRoute(
            'assignments',
            [],
            ['query' => ['classroom' => (int) $assignment->getClassroomId()]],
        );
    }

    /**
     * Đọc file Excel/Word của giáo viên thành danh sách câu hỏi trắc nghiệm, trả JSON cho form
     * tự điền. KHÔNG lưu gì — giáo viên xem lại, sửa rồi mới bấm lưu bài tập như bình thường.
     * Cùng quy ước JSON với luồng upload video: tự bắt exception tại action, lỗi là {error: string}.
     */
    public function quizImportAction(): JsonModel
    {
        if ($this->currentRole() !== 'teacher') {
            return $this->jsonError(403, 'Chỉ giáo viên được nhập câu hỏi từ file.');
        }
        if (!$this->getRequest()->isPost()) {
            return $this->jsonError(405, 'Phương thức không hợp lệ.');
        }

        try {
            $result = $this->quizImportService->import($this->getAllPostParams());
        } catch (ValidationException $e) {
            return $this->jsonError(422, $this->firstErrorMessage($e));
        }

        return new JsonModel($result);
    }

    public function viewAction(): mixed
    {
        $id     = (int) $this->params()->fromRoute('id', 0);
        $userId = (int) $this->currentUserId();
        $role   = (string) $this->currentRole();

        $assignment = $this->assignmentService->getForView($id, $userId, $role);

        $model = $this->getViewModel();
        $model->setVariables(['assignment' => $assignment, 'role' => $role]);

        if ($role === 'teacher') {
            $grading = $this->submissionService->getForGrading($id, $userId);
            $classroom = $this->classroomService->find((int) $assignment->getClassroomId());
            $model->setVariable('rows', $grading['rows']);
            $model->setVariable('canEdit', $classroom !== null && !$classroom->isArchived());
            $model->setTemplate('assignment/assignment/view-teacher');

            return $model;
        }

        $model->setVariable('mySubmission', $this->submissionService->getMySubmission($id, $userId));
        $model->setVariable('submitValues', []);
        $model->setVariable('submitErrors', []);
        $model->setVariable('maxUploadMb', $this->submissionService->maxUploadMb());
        $model->setVariable('allowedVideoMimeTypes', $this->submissionService->allowedVideoMimeTypes());
        $model->setTemplate('assignment/assignment/view-student');

        return $model;
    }

    public function submitAction(): mixed
    {
        if ($this->currentRole() !== 'student') {
            throw new AccessDeniedException('Chỉ học sinh được nộp bài.');
        }
        if (!$this->getRequest()->isPost()) {
            $response = $this->getResponse();
            $response->setStatusCode(405);
            $response->getHeaders()->addHeaderLine('Allow', 'POST');

            return $response;
        }

        $id        = (int) $this->params()->fromRoute('id', 0);
        $studentId = (int) $this->currentUserId();

        // getForView xác nhận: đúng học sinh của lớp, bài đã published (student không thấy draft).
        $assignment = $this->assignmentService->getForView($id, $studentId, 'student');
        $post       = $this->getAllPostParams();

        try {
            if ($assignment->getType() === AssignmentModel::TYPE_ESSAY) {
                $this->submissionService->submitEssay($id, $studentId, $post);
                $this->flashMessenger()->addSuccessMessage('Đã nộp bài.');
            } elseif ($assignment->getType() === AssignmentModel::TYPE_QUIZ) {
                $this->submissionService->submitQuiz($id, $studentId, $post);
                $this->flashMessenger()->addSuccessMessage('Đã nộp bài. Điểm trắc nghiệm được chấm tự động.');
            } else {
                // Video nộp qua luồng JSON riêng (uploadUrlAction/uploadDoneAction), không qua form này.
                $this->flashMessenger()->addErrorMessage('Bài tập dạng video: dùng nút "Nộp video" ở trang chi tiết bài tập.');
            }
        } catch (ValidationException $e) {
            $model = $this->getViewModel();
            $model->setVariables([
                'assignment' => $assignment,
                'role' => 'student',
                'mySubmission' => $this->submissionService->getMySubmission($id, $studentId),
                'submitValues' => $post,
                'submitErrors' => $e->getErrors(),
            ]);
            $model->setTemplate('assignment/assignment/view-student');

            return $model;
        }

        return $this->redirect()->toRoute('assignment_view', ['id' => $id]);
    }

    /** Bước 1/3 nộp video: xin presigned PUT URL. Chỉ student. JSON — xem docs/04-contracts.md. */
    public function uploadUrlAction(): JsonModel
    {
        if ($this->currentRole() !== 'student') {
            return $this->jsonError(403, 'Chỉ học sinh được nộp bài.');
        }
        if (!$this->getRequest()->isPost()) {
            return $this->jsonError(405, 'Phương thức không hợp lệ.');
        }
        if (!$this->submissionService->isVideoUploadConfigured()) {
            return $this->jsonError(503, 'Chức năng nộp video chưa được cấu hình. Vui lòng liên hệ quản trị viên.');
        }

        $id = (int) $this->params()->fromRoute('id', 0);

        try {
            $result = $this->submissionService->requestVideoUploadUrl(
                $id,
                (int) $this->currentUserId(),
                $this->getPostParamsApi(),
            );
        } catch (NotFoundException|AccessDeniedException $e) {
            return $this->jsonError(403, $e->getMessage());
        } catch (AssignmentClosedException $e) {
            return $this->jsonError(409, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->jsonError(422, $this->firstErrorMessage($e));
        }

        return new JsonModel($result);
    }

    /** Bước 3/3 nộp video: xác nhận đã upload xong → tạo submission. Chỉ student. */
    public function uploadDoneAction(): JsonModel
    {
        if ($this->currentRole() !== 'student') {
            return $this->jsonError(403, 'Chỉ học sinh được nộp bài.');
        }
        if (!$this->getRequest()->isPost()) {
            return $this->jsonError(405, 'Phương thức không hợp lệ.');
        }

        $id = (int) $this->params()->fromRoute('id', 0);

        try {
            $submission = $this->submissionService->completeVideoUpload(
                $id,
                (int) $this->currentUserId(),
                $this->getPostParamsApi(),
            );
        } catch (NotFoundException|AccessDeniedException $e) {
            return $this->jsonError(403, $e->getMessage());
        } catch (AssignmentClosedException $e) {
            return $this->jsonError(409, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->jsonError(422, $this->firstErrorMessage($e));
        }

        return new JsonModel(['submission_id' => $submission->getId(), 'status' => $submission->getStatus()]);
    }

    // ── Nội bộ ──────────────────────────────────────────────────────────────

    private function assertTeacher(): void
    {
        if ($this->currentRole() !== 'teacher') {
            throw new AccessDeniedException('Chỉ giáo viên được tạo/sửa bài tập.');
        }
    }

    private function jsonError(int $status, string $message): JsonModel
    {
        $this->getResponse()->setStatusCode($status);

        return new JsonModel(['error' => $message]);
    }

    /** Gộp lỗi ValidationException thành 1 câu — hợp đồng JSON chỉ có 1 {error: string}. */
    private function firstErrorMessage(ValidationException $e): string
    {
        $errors = $e->getErrors();

        return $errors === [] ? $e->getMessage() : (string) reset($errors);
    }

    private function handleSave(?AssignmentModel $existing): mixed
    {
        $post   = $this->getAllPostParams();
        $userId = (int) $this->currentUserId();

        try {
            if ($existing === null) {
                $saved = $this->assignmentService->create($post, $userId);
                $this->flashMessenger()->addSuccessMessage('Đã tạo bài tập "' . $saved->getTitle() . '".');
            } else {
                $saved = $this->assignmentService->update((int) $existing->getId(), $post, $userId);
                $this->flashMessenger()->addSuccessMessage('Đã cập nhật bài tập "' . $saved->getTitle() . '".');
            }
        } catch (ValidationException $e) {
            // quizJson đã parse nằm trong context — dùng để vẽ lại đúng các câu hỏi vừa gõ.
            return $this->formView($existing, $post, $e->getErrors(), $e->getContext()['quizJson'] ?? []);
        }

        return $this->redirect()->toRoute(
            'assignments',
            [],
            ['query' => ['classroom' => (int) $saved->getClassroomId()]],
        );
    }

    /**
     * @param array<string,mixed> $postValues
     * @param array<string,string> $errors
     * @param array<int, array{question:string,options:string[],correct_index:int}> $quizJson
     */
    private function formView(?AssignmentModel $existing, array $postValues, array $errors, array $quizJson): ViewModel
    {
        if ($postValues !== [] && isset($postValues['title'])) {
            $stringValue = static fn (string $key, string $default = ''): string
                => is_scalar($postValues[$key] ?? null) ? (string) $postValues[$key] : $default;
            $values = [
                'classroom_id' => is_scalar($postValues['classroom_id'] ?? null) ? (int) $postValues['classroom_id'] : 0,
                'title'        => $stringValue('title'),
                'description'  => $stringValue('description'),
                'type'         => $stringValue('type', AssignmentModel::TYPE_ESSAY),
                'deadline_at'  => $stringValue('deadline_at'),
                'status'       => $stringValue('status', AssignmentModel::STATUS_DRAFT),
            ];
        } elseif ($existing !== null) {
            $values = [
                'classroom_id' => (int) $existing->getClassroomId(),
                'title'        => (string) $existing->getTitle(),
                'description'  => $existing->getDescription() ?? '',
                'type'         => (string) $existing->getType(),
                'deadline_at'  => $existing->getDeadlineAt() ?? '',
                'status'       => $existing->getStatus(),
            ];
        } else {
            $values = [
                'classroom_id' => is_scalar($postValues['classroom_id'] ?? null) ? (int) $postValues['classroom_id'] : 0,
                'title'        => '',
                'description'  => '',
                'type'         => AssignmentModel::TYPE_ESSAY,
                'deadline_at'  => '',
                'status'       => AssignmentModel::STATUS_DRAFT,
            ];
        }

        $classrooms = array_values(array_filter(
            $this->classroomService->listRowsForActor(
                (int) $this->currentUserId(),
                (string) $this->currentRole(),
            ),
            static fn (array $classroom): bool => $classroom['status'] === 'active',
        ));

        $model = $this->getViewModel();
        $model->setVariables([
            'isEdit'     => $existing !== null,
            'editId'     => $existing?->getId(),
            'values'     => $values,
            'errors'     => $errors,
            'quizJson'   => $quizJson,
            // Chỉ lớp đang hoạt động do giáo viên phụ trách; Service vẫn kiểm lại khi lưu.
            'classrooms' => $classrooms,
        ]);
        $model->setTemplate('assignment/assignment/form');

        return $model;
    }
}
