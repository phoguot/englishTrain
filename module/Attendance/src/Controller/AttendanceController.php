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
    protected const ALLOWED_ROLES = ['admin', 'teacher', 'student'];

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
            (string) $this->currentRole(),
        );

        $model = $this->getViewModel();
        $model->setVariables([
            'classroomId'   => $classroomId,
            'classroomName' => $data['classroom']->name,
            'sessions'      => $data['rows'],
            // Lớp lưu trữ là chỉ đọc với cả giáo viên. Chặn thật nằm ở Service.
            'canEdit'       => $this->currentRole() === 'teacher' && !$data['classroom']->isArchived(),
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
            ['classroom_id' => $classroomId, 'session_date' => date('Y-m-d'), 'note' => ''],
            [],
        );
    }

    /** Bảng điểm danh của 1 buổi: GET hiện bảng, POST lưu cả bảng một lần. */
    public function markAction(): mixed
    {
        $this->assertNotStudent();

        $sessionId = (int) $this->params()->fromRoute('sessionId', 0);
        $userId    = (int) $this->currentUserId();
        $role      = (string) $this->currentRole();

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
    private function markView(int $sessionId, int $userId, string $role, array $values = [], array $errors = []): ViewModel
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
            'canEdit'       => $role === 'teacher' && !$sheet['classroom']->isArchived(),
            'sheetValues' => $values,
            'errors' => $errors,
        ]);

        return $model;
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
            (string) $this->currentRole(),
        );

        $model = $this->getViewModel();
        $model->setVariables([
            'studentName' => $history['studentName'],
            'summary'     => $history['summary'],
            'rows'        => $history['rows'],
            'classroomId' => $classroomId,
            'isOwnPage'   => $this->currentRole() === 'student',
        ]);

        return $model;
    }

    // ── Nội bộ ──────────────────────────────────────────────────────────────

    /** Màn hình quản lý điểm danh: admin + teacher xem được, student thì không. */
    private function assertNotStudent(): void
    {
        if ($this->currentRole() === 'student') {
            throw new AccessDeniedException('Bạn không có quyền xem trang này.');
        }
    }

    /** Đường GHI: chỉ teacher. Service kiểm lại kèm quyền sở hữu lớp. */
    private function assertTeacher(): void
    {
        if ($this->currentRole() !== 'teacher') {
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
                (string) $this->currentRole(),
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
     */
    private function formView(array $postValues, array $errors): ViewModel
    {
        $stringValue = static fn (string $key, string $default = ''): string
            => is_scalar($postValues[$key] ?? null) ? (string) $postValues[$key] : $default;
        $model = $this->getViewModel();
        $model->setVariables([
            'values' => [
                'classroom_id' => is_scalar($postValues['classroom_id'] ?? null) ? (int) $postValues['classroom_id'] : 0,
                'session_date' => $stringValue('session_date', date('Y-m-d')),
                'note'         => $stringValue('note'),
            ],
            'errors'     => $errors,
            // Chỉ lớp giáo viên phụ trách — sở hữu vẫn được Service kiểm lại khi lưu.
            'classrooms' => $this->classroomService->listRowsForActor(
                (int) $this->currentUserId(),
                (string) $this->currentRole(),
            ),
        ]);
        $model->setTemplate('attendance/attendance/form');

        return $model;
    }
}
