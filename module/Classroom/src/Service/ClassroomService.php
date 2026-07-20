<?php

declare(strict_types=1);

namespace Classroom\Service;

use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Classroom\Filter\Classroom\ClassroomStudentsFilter;
use Classroom\Filter\Classroom\ClassroomSaveFilter;
use Classroom\Model\Classroom\ClassroomMapper;
use Classroom\Model\Classroom\ClassroomModel;
use Classroom\Model\Classroom\ClassroomDto;
use Classroom\Model\ClassroomStudent\ClassroomStudentMapper;
use User\Service\UserService;

/**
 * Nghiệp vụ lớp học + gán học sinh. Các method trước vạch "Dùng nội bộ" là
 * HỢP ĐỒNG (docs/04-contracts.md) — đổi chữ ký là breaking change.
 * Kiểm quyền sở hữu ở đây, không ở view.
 * Xem module/Classroom/CLAUDE.md.
 */
class ClassroomService
{
    private const ROLE_ADMIN = 'admin';

    public function __construct(
        private readonly ClassroomMapper $classroomMapper,
        private readonly ClassroomStudentMapper $studentMapper,
        private readonly UserService $userService,
    ) {
    }

    // ── Hợp đồng liên module ────────────────────────────────────────────────

    public function find(int $id): ?ClassroomDto
    {
        return $this->classroomMapper->getClassroom($id)?->toDto();
    }

    /** @param int[] $ids @return array<int, ClassroomDto> map id => ClassroomDto */
    public function findMany(array $ids): array
    {
        $out = [];
        foreach ($this->classroomMapper->getClassroomsByIds($ids) as $classroom) {
            $out[(int) $classroom->getId()] = $classroom->toDto();
        }
        return $out;
    }

    /** @return int[] */
    public function studentIds(int $classroomId): array
    {
        return $this->studentMapper->getStudentIds($classroomId);
    }

    public function isTeacherOf(int $teacherId, int $classroomId): bool
    {
        $classroom = $this->classroomMapper->getClassroom($classroomId);

        return $classroom !== null && $classroom->getTeacherId() === $teacherId;
    }

    public function isStudentIn(int $studentId, int $classroomId): bool
    {
        return $this->studentMapper->isStudentIn($studentId, $classroomId);
    }

    /** Application dùng để bảo vệ integrity trước khi hard-delete tài khoản. */
    public function hasCurrentUserReference(int $userId): bool
    {
        return $this->classroomMapper->searchClassroom($userId) !== []
            || $this->studentMapper->getClassroomIdsForStudent($userId) !== [];
    }

    /**
     * Danh sách lớp theo actor cho trang /classrooms và dashboard Application.
     * admin thấy tất cả, teacher chỉ lớp mình (lọc trong SQL).
     * Gom tên giáo viên + sĩ số bằng query gộp, tránh N+1.
     *
     * @return array<int, array{id:int,name:string,teacherName:string,studentCount:int,status:string}>
     */
    public function listRowsForActor(int $userId, string $role): array
    {
        $classrooms = $role === self::ROLE_ADMIN
            ? $this->classroomMapper->searchClassroom(null)
            : $this->classroomMapper->searchClassroom($userId);

        if ($classrooms === []) {
            return [];
        }

        $classroomIds = array_map(static fn (ClassroomModel $c): int => (int) $c->getId(), $classrooms);
        $teacherIds   = array_map(static fn (ClassroomModel $c): int => (int) $c->getTeacherId(), $classrooms);

        $counts   = $this->studentMapper->countByClassroomIds($classroomIds);
        $teachers = $this->userService->findMany($teacherIds);

        $rows = [];
        foreach ($classrooms as $c) {
            $id  = (int) $c->getId();
            $tid = (int) $c->getTeacherId();
            $rows[] = [
                'id'           => $id,
                'name'         => (string) $c->getName(),
                'teacherName'  => $teachers[$tid]->fullName ?? '(không rõ)',
                'studentCount' => $counts[$id] ?? 0,
                'status'       => $c->getStatus(),
            ];
        }

        return $rows;
    }

    /**
     * Lớp mà 1 học sinh đang theo học — để student có lối vào danh sách bài tập.
     * Chỉ trả lớp đang hoạt động (lớp lưu trữ coi như đã kết thúc).
     *
     * @return array<int, array{id:int,name:string,teacherName:string}>
     */
    public function listForStudent(int $studentId): array
    {
        $classroomIds = $this->studentMapper->getClassroomIdsForStudent($studentId);
        $classrooms   = $this->classroomMapper->getClassroomsByIds($classroomIds);
        if ($classrooms === []) {
            return [];
        }

        $teachers = $this->userService->findMany(
            array_map(static fn (ClassroomModel $c): int => (int) $c->getTeacherId(), $classrooms),
        );

        $rows = [];
        foreach ($classrooms as $c) {
            if ($c->isArchived()) {
                continue;
            }
            $rows[] = [
                'id'          => (int) $c->getId(),
                'name'        => (string) $c->getName(),
                'teacherName' => $teachers[(int) $c->getTeacherId()]->fullName ?? '(không rõ)',
            ];
        }

        return $rows;
    }

    // ── Dùng nội bộ cho controller của module Classroom ─────────────────────

    /** Lấy lớp để sửa. NotFound nếu không có, AccessDenied nếu teacher không sở hữu. */
    public function getEditable(int $id, int $userId, string $role): ClassroomModel
    {
        $classroom = $this->classroomMapper->getClassroom($id);
        if ($classroom === null) {
            throw new NotFoundException('Lớp không tồn tại.');
        }
        $this->assertCanManage($classroom, $userId, $role);

        return $classroom;
    }

    /**
     * Tạo lớp mới. Chỉ admin (controller đã chặn role).
     * Nhận dữ liệu POST THÔ — validate bằng ClassroomSaveFilter ngay tại đây.
     *
     * @param array<string,mixed> $data dữ liệu POST thô
     * @throws ValidationException dữ liệu không hợp lệ (controller render lại form)
     */
    public function create(array $data): ClassroomModel
    {
        $values = $this->validate($data);

        $model = new ClassroomModel();
        $this->fill($model, $values);

        return $this->classroomMapper->saveClassroom($model);
    }

    /**
     * Sửa lớp. admin bất kỳ; teacher chỉ lớp mình.
     *
     * @param array<string,mixed> $data dữ liệu POST thô
     * @throws ValidationException
     */
    public function update(int $id, array $data, int $userId, string $role): ClassroomModel
    {
        $model  = $this->getEditable($id, $userId, $role);
        $values = $this->validate($data);

        $this->fill($model, $values);

        return $this->classroomMapper->saveClassroom($model);
    }

    /**
     * Chạy Filter class. Đây là NƠI DUY NHẤT kiểm giá trị đầu vào của lớp học —
     * không rải if/else kiểm value ở Service hay Controller.
     *
     * @param array<string,mixed> $data
     * @return array{name:string,teacher_id:int,schedule_note:?string,status:string}
     * @throws ValidationException
     */
    private function validate(array $data): array
    {
        $filter = new ClassroomSaveFilter($this->userService);
        $filter->setData($data);

        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages($filter->getMessages());
        }

        return $filter->getValues();
    }

    /**
     * Dữ liệu cho màn hình gán học sinh: lớp, toàn bộ học sinh đang hoạt động, id đang trong lớp.
     * NotFound / AccessDenied như getEditable.
     *
     * @return array{classroom:ClassroomModel, students:\User\Model\User\UserDto[], memberIds:int[]}
     */
    public function getManageStudentsData(int $id, int $userId, string $role): array
    {
        $classroom = $this->getEditable($id, $userId, $role);

        return [
            'classroom' => $classroom,
            'students'  => $this->userService->findByRole('student'),
            'memberIds' => $this->studentMapper->getStudentIds($id),
        ];
    }

    /**
     * Lưu danh sách học sinh của lớp. Chặn nếu lớp đã lưu trữ.
     * Chỉ nhận id thật sự là học sinh đang hoạt động — lọc bỏ mọi id lạ (chống tamper).
     *
     * @param mixed $studentIds
     */
    public function saveStudents(int $id, mixed $studentIds, int $userId, string $role): void
    {
        $classroom = $this->getEditable($id, $userId, $role);

        if ($classroom->isArchived()) {
            throw new AccessDeniedException('Lớp đã lưu trữ, không thể thay đổi học sinh.');
        }

        $validStudentIds = array_map(
            static fn ($dto): int => $dto->id,
            $this->userService->findByRole('student'),
        );
        $filter = new ClassroomStudentsFilter($validStudentIds);
        $filter->setData(['student_ids' => $studentIds]);
        if (!$filter->isValid()) {
            $rawIds = is_array($studentIds) ? $studentIds : [];
            $selectedIds = array_values(array_filter(
                array_map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, $rawIds),
                static fn (int $value): bool => $value > 0,
            ));
            throw ValidationException::fromFilterMessages(
                $filter->getMessages(),
                ['selectedIds' => $selectedIds],
            );
        }
        $values = $filter->getValues();
        $clean = array_values(array_unique(array_map('intval', $values['student_ids'] ?? [])));

        $this->studentMapper->syncStudents($id, $clean);
    }

    // ── Nội bộ ──────────────────────────────────────────────────────────────

    private function assertCanManage(ClassroomModel $classroom, int $userId, string $role): void
    {
        if ($role === self::ROLE_ADMIN) {
            return;
        }
        if ((int) $classroom->getTeacherId() !== $userId) {
            throw new AccessDeniedException('Bạn không phụ trách lớp này.');
        }
    }

    /** @param array{name:string,teacher_id:int,schedule_note:?string,status:string} $v */
    private function fill(ClassroomModel $model, array $v): void
    {
        $model->setName($v['name']);
        $model->setTeacherId((int) $v['teacher_id']);
        $model->setScheduleNote(($v['schedule_note'] ?? '') !== '' ? $v['schedule_note'] : null);
        $model->setStatus($v['status']);
    }
}
