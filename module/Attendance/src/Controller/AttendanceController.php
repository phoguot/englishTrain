<?php

declare(strict_types=1);

namespace Attendance\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\ValidationException;
use Attendance\Model\AttendanceRecord\AttendanceRecordModel;
use Attendance\Service\AttendanceService;
use Classroom\Service\ClassroomService;
use Laminas\View\Model\ViewModel;
use User\Model\User\UserModel;

/**
 * Buổi học + điểm danh + lịch sử chuyên cần.
 *
 * Tách rõ ĐỌC và GHI (khác các module khác — đọc kỹ):
 * - Đọc (index, mark dạng GET, student): admin xem **mọi lớp**, teacher chỉ lớp mình phụ trách.
 * - Ghi (create, mark dạng POST): **chỉ teacher** phụ trách lớp. Admin xem được nhưng không
 *   điểm danh hộ — Service chặn bằng assertCanEditClassroom(), view chỉ ẩn nút cho đỡ rối.
 * - student: chỉ xem chuyên cần của chính mình.
 *
 * Controller KHÔNG validate và KHÔNG kiểm value — đưa POST thô cho Service, Service chạy
 * Filter/SheetBuilder rồi ném ValidationException; ở đây chỉ lo render lại form / flash lỗi.
 */
class AttendanceController extends BaseController
{
    protected const ALLOWED_ROLES = [UserModel::ROLE_ADMIN, UserModel::ROLE_TEACHER, UserModel::ROLE_STUDENT];

    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly ClassroomService $classroomService,
    ) {
    }

    public function indexAction(): ViewModel
    {
        $this->assertNotStudent();

        $classroomId = (int) $this->params()->fromQuery('classroom', 0);

        // listSessionRows tự kiểm sở hữu (ném NotFound/AccessDenied) — gọi trước để
        // không lộ tên lớp khi không có quyền.
        $data = $this->attendanceService->listSessionRows(
            $classroomId,
            (int) $this->currentUserId(),
            (int) $this->currentRole(),
        );

        $model = $this->getViewModel();
        $model->setVariables([
            'classroomId'   => $classroomId,
            'classroomName' => $data['classroom']->name,
            'sessions'      => $data['rows'],
            // Lớp lưu trữ là chỉ đọc với cả giáo viên. Chặn thật nằm ở Service.
            'canEdit'       => $this->currentRole() === UserModel::ROLE_TEACHER && !$data['classroom']->isArchived(),
            // Trang học phí chỉ dành cho giáo viên — admin xem điểm danh nhưng không xem tiền.
            'canViewTuition' => $this->currentRole() === UserModel::ROLE_TEACHER,
        ]);

        return $model;
    }

    public function createAction(): mixed
    {
        $this->assertTeacher();

        if ($this->getRequest()->isPost()) {
            return $this->handleCreate();
        }

        $classroomId = (int) $this->params()->fromQuery('classroom', 0);

        return $this->formView(
            [
                'classroom_id'    => $classroomId,
                'session_date'    => date('Y-m-d'),
                'shift_label'     => '',
                'fee_per_session' => '',
                'note'            => '',
            ],
            [],
        );
    }

    /** Sửa buổi học: ngày, ca học, đơn giá, ghi chú. Chỉ giáo viên phụ trách lớp. */
    public function editAction(): mixed
    {
        $this->assertTeacher();

        $sessionId = (int) $this->params()->fromRoute('sessionId', 0);
        $userId    = (int) $this->currentUserId();
        $role      = (int) $this->currentRole();

        // Lấy buổi trước cả khi POST: ô chọn lớp bị disable nên POST không gửi classroom_id,
        // mà form lỗi vẫn cần biết lớp để dựng link "Quay lại".
        $session = $this->attendanceService->getSessionForEdit($sessionId, $userId, $role)['session'];

        if ($this->getRequest()->isPost()) {
            $post                 = $this->getAllPostParams();
            $post['classroom_id'] = (int) $session->getClassroomId();

            try {
                $saved = $this->attendanceService->updateSession($sessionId, $post, $userId, $role);
            } catch (ValidationException $e) {
                return $this->formView($post, $e->getErrors(), $sessionId);
            }

            $this->flashMessenger()->addSuccessMessage(
                'Đã cập nhật buổi học ngày ' . $saved->getSessionDateForHuman() . '.',
            );

            return $this->redirect()->toRoute(
                'attendance',
                [],
                ['query' => ['classroom' => (int) $saved->getClassroomId()]],
            );
        }

        return $this->formView(
            [
                'classroom_id'    => (int) $session->getClassroomId(),
                'session_date'    => (string) $session->getSessionDate(),
                'shift_label'     => $session->getShiftLabel() ?? '',
                'fee_per_session' => (string) (int) $session->getFeePerSession(),
                'note'            => $session->getNote() ?? '',
            ],
            [],
            $sessionId,
        );
    }

    /** Bảng điểm danh của 1 buổi: GET hiện bảng, POST lưu cả bảng một lần. */
    public function markAction(): mixed
    {
        $this->assertNotStudent();

        $sessionId = (int) $this->params()->fromRoute('sessionId', 0);
        $userId    = (int) $this->currentUserId();
        $role      = (int) $this->currentRole();

        if ($this->getRequest()->isPost()) {
            try {
                $session = $this->attendanceService->saveSheet($sessionId, $this->getAllPostParams(), $userId, $role);
                $this->flashMessenger()->addSuccessMessage(
                    'Đã lưu điểm danh buổi ' . $session->getSessionDateForHuman() . '.',
                );

                return $this->redirect()->toRoute(
                    'attendance',
                    [],
                    ['query' => ['classroom' => (int) $session->getClassroomId()]],
                );
            } catch (ValidationException $e) {
                return $this->markView(
                    $sessionId,
                    $userId,
                    $role,
                    $this->getAllPostParams(),
                    $e->getErrors(),
                );
            }
        }

        return $this->markView($sessionId, $userId, $role);
    }

    /** @param array<string,mixed> $values @param array<string,string> $errors */
    private function markView(int $sessionId, int $userId, int $role, array $values = [], array $errors = []): ViewModel
    {
        $sheet = $this->attendanceService->getSheet($sessionId, $userId, $role);

        $model = $this->getViewModel();
        $model->setVariables([
            'session'       => $sheet['session'],
            'classroomId'   => $sheet['classroom']->id,
            'classroomName' => $sheet['classroom']->name,
            'rows'          => $sheet['rows'],
            'statuses'      => AttendanceRecordModel::STATUSES,
            'labels'        => AttendanceRecordModel::LABELS,
            // admin chỉ xem, lớp lưu trữ chỉ đọc: ẩn nút Lưu cho đỡ rối. Chặn thật nằm ở Service.
            'canEdit'       => $role === UserModel::ROLE_TEACHER && !$sheet['classroom']->isArchived(),
            'paidCount'     => $sheet['paidCount'],
            'totalAmount'   => $sheet['totalAmount'],
            'sheetValues' => $values,
            'errors' => $errors,
        ]);

        return $model;
    }

    /**
     * Xóa buổi học (POST). Chỉ giáo viên phụ trách; Service chặn buổi đã điểm danh.
     * Không có trang xác nhận riêng — nút trong danh sách đã hỏi lại bằng `confirm()`.
     */
    public function deleteAction(): mixed
    {
        $this->assertTeacher();

        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('attendance');
        }

        $sessionId = (int) $this->params()->fromRoute('sessionId', 0);
        $userId    = (int) $this->currentUserId();
        $role      = (int) $this->currentRole();

        // Lấy trước để còn biết quay về lớp nào khi Service từ chối xóa.
        $session = $this->attendanceService->getSessionForEdit($sessionId, $userId, $role)['session'];

        try {
            $this->attendanceService->deleteSession($sessionId, $userId, $role);
            $this->flashMessenger()->addSuccessMessage(
                'Đã xóa buổi học ngày ' . $session->getSessionDateForHuman() . '.',
            );
        } catch (ValidationException $e) {
            $this->flashMessenger()->addErrorMessage($e->getErrors()['delete'] ?? 'Không xóa được buổi học.');
        }

        return $this->redirect()->toRoute(
            'attendance',
            [],
            ['query' => ['classroom' => (int) $session->getClassroomId()]],
        );
    }

    /** Lịch sử chuyên cần 1 học sinh. Service quyết định ai xem được của ai. */
    public function studentAction(): ViewModel
    {
        $studentId   = (int) $this->params()->fromRoute('id', 0);
        $classroomId = (int) $this->params()->fromQuery('classroom', 0);

        $history = $this->attendanceService->getStudentHistory(
            $studentId,
            $classroomId > 0 ? $classroomId : null,
            (int) $this->currentUserId(),
            (int) $this->currentRole(),
        );

        $model = $this->getViewModel();
        $model->setVariables([
            'studentName' => $history['studentName'],
            'summary'     => $history['summary'],
            'rows'        => $history['rows'],
            'classroomId' => $classroomId,
            'isOwnPage'   => $this->currentRole() === UserModel::ROLE_STUDENT,
        ]);

        return $model;
    }

    // ── Nội bộ ──────────────────────────────────────────────────────────────

    /** Màn hình quản lý điểm danh: admin + teacher xem được, student thì không. */
    private function assertNotStudent(): void
    {
        if ($this->currentRole() === UserModel::ROLE_STUDENT) {
            throw new AccessDeniedException('Bạn không có quyền xem trang này.');
        }
    }

    /** Đường GHI: chỉ teacher. Service kiểm lại kèm quyền sở hữu lớp. */
    private function assertTeacher(): void
    {
        if ($this->currentRole() !== UserModel::ROLE_TEACHER) {
            throw new AccessDeniedException('Chỉ giáo viên được điểm danh.');
        }
    }

    private function handleCreate(): mixed
    {
        $post = $this->getAllPostParams();

        try {
            $session = $this->attendanceService->createSession(
                $post,
                (int) $this->currentUserId(),
                (int) $this->currentRole(),
            );
        } catch (ValidationException $e) {
            return $this->formView($post, $e->getErrors());
        }

        $this->flashMessenger()->addSuccessMessage(
            'Đã tạo buổi học ngày ' . $session->getSessionDateForHuman() . '. Mời điểm danh.',
        );

        // Tạo xong đi thẳng vào bảng điểm danh — đó là việc tiếp theo giáo viên muốn làm.
        return $this->redirect()->toRoute('attendance_mark', ['sessionId' => (int) $session->getId()]);
    }

    /**
     * @param array<string,mixed>  $postValues giá trị điền lại vào form
     * @param array<string,string> $errors
     * @param int|null             $sessionId  khác null = đang SỬA buổi này, null = tạo mới
     */
    private function formView(array $postValues, array $errors, ?int $sessionId = null): ViewModel
    {
        $stringValue = static fn (string $key, string $default = ''): string
            => is_scalar($postValues[$key] ?? null) ? (string) $postValues[$key] : $default;
        $model = $this->getViewModel();
        $model->setVariables([
            'editId' => $sessionId,
            'values' => [
                'classroom_id' => is_scalar($postValues['classroom_id'] ?? null) ? (int) $postValues['classroom_id'] : 0,
                'session_date' => $stringValue('session_date', date('Y-m-d')),
                'shift_label'  => $stringValue('shift_label'),
                'fee_per_session' => $stringValue('fee_per_session'),
                'note'         => $stringValue('note'),
            ],
            'errors'     => $errors,
            // Chỉ lớp giáo viên phụ trách — sở hữu vẫn được Service kiểm lại khi lưu.
            'classrooms' => $this->classroomService->listRowsForActor(
                (int) $this->currentUserId(),
                (int) $this->currentRole(),
            ),
        ]);
        $model->setTemplate('attendance/attendance/form');

        return $model;
    }
}
