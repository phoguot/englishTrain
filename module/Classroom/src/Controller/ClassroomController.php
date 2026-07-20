<?php

declare(strict_types=1);

namespace Classroom\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\ValidationException;
use Classroom\Model\Classroom\ClassroomModel;
use Classroom\Service\ClassroomService;
use Laminas\View\Model\ViewModel;
use User\Service\UserService;

/**
 * CRUD lớp + gán học sinh.
 * - index, students: admin + teacher (teacher chỉ lớp mình — Service lọc/kiểm).
 * - create, edit: chỉ admin (assertAdmin trong action).
 * Controller mỏng: Service ném AccessDenied/NotFound, BaseController đổi thành 403/404.
 */
class ClassroomController extends BaseController
{
    protected const ALLOWED_ROLES = ['admin', 'teacher'];

    public function __construct(
        private readonly ClassroomService $classroomService,
        private readonly UserService $userService,
    ) {
    }

    public function indexAction(): ViewModel
    {
        $model = $this->getViewModel();
        $model->setVariables([
            'role'       => $this->currentRole(),
            'classrooms' => $this->classroomService->listRowsForActor(
                (int) $this->currentUserId(),
                (string) $this->currentRole(),
            ),
        ]);

        return $model;
    }

    public function createAction(): mixed
    {
        $this->assertAdmin();

        if ($this->getRequest()->isPost()) {
            return $this->handleSave(null);
        }

        return $this->formView(null, [], []);
    }

    public function editAction(): mixed
    {
        $this->assertAdmin();

        $id        = (int) $this->params()->fromRoute('id', 0);
        $classroom = $this->classroomService->getEditable($id, (int) $this->currentUserId(), (string) $this->currentRole());

        if ($this->getRequest()->isPost()) {
            return $this->handleSave($classroom);
        }

        return $this->formView($classroom, [], []);
    }

    public function studentsAction(): mixed
    {
        $id     = (int) $this->params()->fromRoute('id', 0);
        $userId = (int) $this->currentUserId();
        $role   = (string) $this->currentRole();

        if ($this->getRequest()->isPost()) {
            $studentIds = $this->params()->fromPost('student_ids', []);
            try {
                $this->classroomService->saveStudents(
                    $id,
                    $studentIds,
                    $userId,
                    $role,
                );
            } catch (ValidationException $e) {
                return $this->studentsView(
                    $id,
                    $userId,
                    $role,
                    $e->getContext()['selectedIds'] ?? [],
                    $e->getErrors(),
                );
            }
            $this->flashMessenger()->addSuccessMessage('Đã lưu danh sách học sinh của lớp.');

            return $this->redirect()->toRoute('classroom_students', ['id' => $id]);
        }

        return $this->studentsView($id, $userId, $role);
    }

    /** @param int[]|null $selectedIds @param array<string,string> $errors */
    private function studentsView(int $id, int $userId, string $role, ?array $selectedIds = null, array $errors = []): mixed
    {
        $data = $this->classroomService->getManageStudentsData($id, $userId, $role);

        if ($data['classroom']->isArchived()) {
            $this->flashMessenger()->addErrorMessage('Lớp đã lưu trữ, không thể thay đổi học sinh.');

            return $this->redirect()->toRoute('classrooms');
        }

        $model = $this->getViewModel();
        $model->setVariables([
            'classroom' => $data['classroom'],
            'students'  => $data['students'],
            'memberIds' => $selectedIds ?? $data['memberIds'],
            'errors' => $errors,
        ]);

        return $model;
    }

    // ── Nội bộ ──────────────────────────────────────────────────────────────

    private function assertAdmin(): void
    {
        if ($this->currentRole() !== 'admin') {
            throw new AccessDeniedException('Chỉ admin được tạo/sửa lớp.');
        }
    }

    /**
     * Lưu (dùng chung create & edit). Controller KHÔNG validate — đưa POST thô cho Service,
     * Service chạy Filter và ném ValidationException; ở đây chỉ lo render lại form kèm lỗi.
     */
    private function handleSave(?ClassroomModel $existing): mixed
    {
        $post = $this->getAllPostParams();

        try {
            if ($existing === null) {
                $saved = $this->classroomService->create($post);
                $this->flashMessenger()->addSuccessMessage('Đã tạo lớp "' . $saved->getName() . '".');
            } else {
                $saved = $this->classroomService->update(
                    (int) $existing->getId(),
                    $post,
                    (int) $this->currentUserId(),
                    (string) $this->currentRole(),
                );
                $this->flashMessenger()->addSuccessMessage('Đã cập nhật lớp "' . $saved->getName() . '".');
            }
        } catch (ValidationException $e) {
            return $this->formView($existing, $post, $e->getErrors());
        }

        return $this->redirect()->toRoute('classrooms');
    }

    /**
     * Dựng ViewModel cho form. $old = giá trị điền lại (từ model khi GET edit, hoặc từ POST khi lỗi).
     *
     * @param array<string,string> $errors
     */
    private function formView(?ClassroomModel $existing, array $postValues, array $errors): ViewModel
    {
        if ($postValues !== []) {
            $stringValue = static fn (string $key, string $default = ''): string
                => is_scalar($postValues[$key] ?? null) ? (string) $postValues[$key] : $default;
            $values = [
                'name'          => $stringValue('name'),
                'teacher_id'    => is_scalar($postValues['teacher_id'] ?? null) ? (int) $postValues['teacher_id'] : 0,
                'schedule_note' => $stringValue('schedule_note'),
                'status'        => $stringValue('status', ClassroomModel::STATUS_ACTIVE),
            ];
        } elseif ($existing !== null) {
            $values = [
                'name'          => $existing->getName(),
                'teacher_id'    => (int) $existing->getTeacherId(),
                'schedule_note' => $existing->getScheduleNote() ?? '',
                'status'        => $existing->getStatus(),
            ];
        } else {
            $values = ['name' => '', 'teacher_id' => 0, 'schedule_note' => '', 'status' => ClassroomModel::STATUS_ACTIVE];
        }

        $model = $this->getViewModel();
        $model->setVariables([
            'isEdit'   => $existing !== null,
            'editId'   => $existing?->getId(),
            'values'   => $values,
            'errors'   => $errors,
            'teachers' => $this->userService->findByRole('teacher'),
        ]);
        $model->setTemplate('classroom/classroom/form');

        return $model;
    }
}
