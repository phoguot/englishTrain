<?php

declare(strict_types=1);

namespace Assignment\Service;

use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Assignment\Filter\Assignment\AssignmentSaveFilter;
use Assignment\Model\Assignment\AssignmentMapper;
use Assignment\Model\Assignment\AssignmentModel;
use Assignment\Model\Submission\SubmissionMapper;
use Classroom\Service\ClassroomService;

/**
 * Nghiệp vụ bài tập.
 * - Kiểm GIÁ TRỊ đầu vào: chạy AssignmentSaveFilter + QuizJsonBuilder tại đây (ném ValidationException).
 * - Kiểm QUYỀN sở hữu: isTeacherOf/isStudentIn (ném AccessDeniedException).
 * Controller không làm cả hai việc trên. Xem module/Assignment/CLAUDE.md.
 */
class AssignmentService
{
    public function __construct(
        private readonly AssignmentMapper $assignmentMapper,
        private readonly SubmissionMapper $submissionMapper,
        private readonly ClassroomService $classroomService,
        private readonly QuizJsonBuilder $quizJsonBuilder,
    ) {
    }

    // ── Hợp đồng dashboard cho module Application ──────────────────────────

    /**
     * Lớp này đã có bài tập nào chưa — Application hỏi trước khi cho xóa lớp,
     * để không xóa mất bài tập và bài nộp của học sinh (docs/04-contracts.md).
     */
    public function countByClassroom(int $classroomId): int
    {
        return $this->assignmentMapper->countAssignmentByClassroom($classroomId);
    }

    /**
     * @return array<int,array{id:int,title:string,classroomId:int,classroomName:string,submittedCount:int,deadlineAt:?string,lastSubmittedAt:?string}>
     */
    public function dashboardForTeacher(int $teacherId): array
    {
        $classrooms = array_values(array_filter(
            $this->classroomService->listRowsForActor($teacherId, 'teacher'),
            static fn (array $row): bool => ($row['status'] ?? null) === 'active',
        ));
        $classroomNames = array_column($classrooms, 'name', 'id');
        $assignments = $this->assignmentMapper->searchAssignmentsByClassroomIds(
            array_map(static fn (array $row): int => (int) $row['id'], $classrooms),
            false,
        );
        $counts = $this->submissionMapper->countByAssignmentIds(array_map(
            static fn (AssignmentModel $assignment): int => (int) $assignment->getId(),
            $assignments,
        ));

        $rows = [];
        foreach ($assignments as $assignment) {
            $id = (int) $assignment->getId();
            $pending = (int) ($counts[$id]['submitted'] ?? 0);
            if ($pending === 0) {
                continue;
            }
            $classroomId = (int) $assignment->getClassroomId();
            $rows[] = [
                'id' => $id,
                'title' => (string) $assignment->getTitle(),
                'classroomId' => $classroomId,
                'classroomName' => (string) ($classroomNames[$classroomId] ?? '(không rõ)'),
                'submittedCount' => $pending,
                'deadlineAt' => $assignment->getDeadlineAt(),
                'lastSubmittedAt' => $counts[$id]['lastSubmittedAt'] ?? null,
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int
                => strcmp($right['lastSubmittedAt'] ?? '', $left['lastSubmittedAt'] ?? ''),
        );

        return array_slice($rows, 0, 5);
    }

    /**
     * @return array{
     *   upcoming:array<int,array{id:int,title:string,classroomId:int,classroomName:string,deadlineAt:string}>,
     *   graded:array<int,array{id:int,title:string,classroomId:int,classroomName:string,score:float,gradedAt:?string}>
     * }
     */
    public function dashboardForStudent(int $studentId): array
    {
        $classrooms = $this->classroomService->listForStudent($studentId);
        $classroomNames = array_column($classrooms, 'name', 'id');
        $assignments = $this->assignmentMapper->searchAssignmentsByClassroomIds(
            array_map(static fn (array $row): int => (int) $row['id'], $classrooms),
            true,
        );
        $submissionByAssignment = $this->submissionMapper->getByAssignmentIdsForStudent(
            array_map(static fn (AssignmentModel $assignment): int => (int) $assignment->getId(), $assignments),
            $studentId,
        );

        $upcoming = [];
        $graded = [];
        $now = time();
        foreach ($assignments as $assignment) {
            $id = (int) $assignment->getId();
            $classroomId = (int) $assignment->getClassroomId();
            $deadline = $assignment->getDeadlineAt();
            if ($deadline !== null && strtotime($deadline) >= $now) {
                $upcoming[] = [
                    'id' => $id,
                    'title' => (string) $assignment->getTitle(),
                    'classroomId' => $classroomId,
                    'classroomName' => (string) ($classroomNames[$classroomId] ?? '(không rõ)'),
                    'deadlineAt' => $deadline,
                ];
            }

            $submission = $submissionByAssignment[$id] ?? null;
            if ($submission?->isGraded() && $submission->getScore() !== null) {
                $graded[] = [
                    'id' => $id,
                    'title' => (string) $assignment->getTitle(),
                    'classroomId' => $classroomId,
                    'classroomName' => (string) ($classroomNames[$classroomId] ?? '(không rõ)'),
                    'score' => (float) $submission->getScore(),
                    'gradedAt' => $submission->getGradedAt(),
                ];
            }
        }

        usort($upcoming, static fn (array $left, array $right): int => strcmp($left['deadlineAt'], $right['deadlineAt']));
        usort(
            $graded,
            static fn (array $left, array $right): int
                => strcmp($right['gradedAt'] ?? '', $left['gradedAt'] ?? ''),
        );

        return ['upcoming' => array_slice($upcoming, 0, 5), 'graded' => array_slice($graded, 0, 5)];
    }

    /**
     * Danh sách bài tập của 1 lớp. teacher thấy hết (kèm số liệu nộp/chấm),
     * student chỉ thấy published (kèm trạng thái bài nộp của chính mình).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRowsForActor(int $classroomId, int $userId, string $role): array
    {
        $classroom = $this->classroomService->find($classroomId);
        if ($classroom === null) {
            throw new NotFoundException('Lớp không tồn tại.');
        }
        if ($role === 'teacher' && $classroom->teacherId !== $userId) {
            throw new AccessDeniedException('Bạn không phụ trách lớp này.');
        }
        if ($role === 'student' && !$this->classroomService->isStudentIn($userId, $classroomId)) {
            throw new AccessDeniedException('Bạn không thuộc lớp này.');
        }

        $onlyPublished = $role === 'student';
        $assignments   = $this->assignmentMapper->searchClassroomAssignment($classroomId, $onlyPublished);
        if ($assignments === []) {
            return [];
        }

        $ids = array_map(static fn (AssignmentModel $a): int => (int) $a->getId(), $assignments);

        if ($role === 'teacher') {
            $counts        = $this->submissionMapper->countByAssignmentIds($ids);
            $totalStudents = count($this->classroomService->studentIds($classroomId));

            return array_map(static function (AssignmentModel $a) use ($counts, $totalStudents): array {
                $id = (int) $a->getId();

                return [
                    'id'             => $id,
                    'title'          => (string) $a->getTitle(),
                    'type'           => (string) $a->getType(),
                    'status'         => $a->getStatus(),
                    'deadlineAt'     => $a->getDeadlineAt(),
                    'submittedCount' => $counts[$id]['submitted'] ?? 0,
                    'gradedCount'    => $counts[$id]['graded'] ?? 0,
                    'totalStudents'  => $totalStudents,
                ];
            }, $assignments);
        }

        $mySubmissions = $this->submissionMapper->getByAssignmentIdsForStudent($ids, $userId);

        return array_map(static function (AssignmentModel $a) use ($mySubmissions): array {
            $id  = (int) $a->getId();
            $sub = $mySubmissions[$id] ?? null;

            return [
                'id'                 => $id,
                'title'              => (string) $a->getTitle(),
                'type'               => (string) $a->getType(),
                'status'             => $a->getStatus(),
                'deadlineAt'         => $a->getDeadlineAt(),
                'mySubmissionStatus' => $sub?->getStatus(), // null = chưa nộp
                'myScore'            => $sub?->isGraded() ? $sub->getScore() : null,
            ];
        }, $assignments);
    }

    /** Lấy bài tập để sửa. Chỉ teacher sở hữu lớp đang hoạt động chứa bài tập. */
    public function getEditable(int $id, int $userId): AssignmentModel
    {
        $assignment = $this->assignmentMapper->getAssignment($id);
        if ($assignment === null) {
            throw new NotFoundException('Bài tập không tồn tại.');
        }
        $this->assertClassroomWritable((int) $assignment->getClassroomId(), $userId);

        return $assignment;
    }

    /**
     * Lấy bài tập để xem chi tiết.
     * - teacher: sở hữu lớp mới xem được, kể cả draft.
     * - student: phải thuộc lớp VÀ bài đã published — sai một trong hai → NotFound (không tiết lộ
     *   bài draft tồn tại, đúng luật "student không thấy draft kể cả gõ thẳng URL").
     */
    public function getForView(int $id, int $userId, string $role): AssignmentModel
    {
        $assignment = $this->assignmentMapper->getAssignment($id);
        if ($assignment === null) {
            throw new NotFoundException('Bài tập không tồn tại.');
        }

        if ($role === 'teacher') {
            if (!$this->classroomService->isTeacherOf($userId, (int) $assignment->getClassroomId())) {
                throw new AccessDeniedException('Bạn không phụ trách lớp này.');
            }

            return $assignment;
        }

        $inClassroom = $this->classroomService->isStudentIn($userId, (int) $assignment->getClassroomId());
        if (!$inClassroom || !$assignment->isPublished()) {
            throw new NotFoundException('Bài tập không tồn tại.');
        }

        return $assignment;
    }

    /**
     * Tạo bài tập. Nhận POST THÔ — validate bằng Filter ngay tại đây.
     *
     * @param array<string,mixed> $data dữ liệu POST thô
     * @throws ValidationException giá trị không hợp lệ (controller render lại form)
     * @throws AccessDeniedException không phụ trách lớp
     */
    public function create(array $data, int $teacherId): AssignmentModel
    {
        ['values' => $values, 'quizJson' => $quizJson] = $this->validate($data);

        $this->assertClassroomWritable((int) $values['classroom_id'], $teacherId);

        $model = new AssignmentModel();
        $model->setTeacherId($teacherId);
        $this->fill($model, $values, $quizJson);

        return $this->assignmentMapper->saveAssignment($model);
    }

    /**
     * Sửa bài tập. Nhận POST THÔ.
     *
     * @param array<string,mixed> $data dữ liệu POST thô
     * @throws ValidationException
     * @throws AccessDeniedException
     */
    public function update(int $id, array $data, int $userId): AssignmentModel
    {
        $model = $this->getEditable($id, $userId);

        ['values' => $values, 'quizJson' => $quizJson] = $this->validate($data);

        // classroom_id có thể đổi khi sửa — kiểm lớp MỚI, không chỉ lớp cũ.
        $this->assertClassroomWritable((int) $values['classroom_id'], $userId);

        $this->fill($model, $values, $quizJson);

        return $this->assignmentMapper->saveAssignment($model);
    }

    /**
     * Xóa bài tập. Chỉ teacher sở hữu lớp đang hoạt động chứa bài tập (dùng lại quyền như sửa).
     * Xóa luôn bài nộp của học sinh cho bài tập này trước — tránh mồ côi dữ liệu trong bảng submission.
     *
     * @throws NotFoundException|AccessDeniedException
     */
    public function delete(int $id, int $userId): AssignmentModel
    {
        $assignment = $this->getEditable($id, $userId);

        $this->submissionMapper->deleteByAssignmentId($id);
        $this->assignmentMapper->deleteAssignment($id);

        return $assignment;
    }

    /**
     * Chạy Filter + dựng quiz_json. NƠI DUY NHẤT kiểm giá trị đầu vào của bài tập.
     * Gom lỗi của cả hai rồi ném MỘT lần để người dùng thấy hết vấn đề, không phải sửa từng vòng.
     * quizJson được trả kèm trong $context của exception để controller render lại câu hỏi đã gõ.
     *
     * @param array<string,mixed> $data
     * @return array{values: array<string,mixed>, quizJson: array<int, array{question:string,options:string[],correct_index:int}>|null}
     * @throws ValidationException
     */
    private function validate(array $data): array
    {
        $filter = new AssignmentSaveFilter();
        $filter->setData($data);

        $errors = [];
        if (!$filter->isValid()) {
            foreach ($filter->getMessages() as $field => $messages) {
                $errors[(string) $field] = implode(' ', array_map('strval', (array) $messages));
            }
        }

        $values   = $filter->getValues();
        $quizJson = null;

        if (($values['type'] ?? null) === AssignmentModel::TYPE_QUIZ) {
            $rawQuestions = $data['questions'] ?? [];
            $built        = $this->quizJsonBuilder->build(is_array($rawQuestions) ? $rawQuestions : []);
            $quizJson     = $built['quizJson'];

            if ($built['errors'] !== []) {
                $errors['quiz'] = implode(' ', $built['errors']);
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors, ['quizJson' => $quizJson ?? []]);
        }

        return ['values' => $values, 'quizJson' => $quizJson];
    }

    private function assertClassroomWritable(int $classroomId, int $teacherId): void
    {
        $classroom = $this->classroomService->find($classroomId);
        if ($classroom === null) {
            throw new NotFoundException('Lớp không tồn tại.');
        }
        if ($classroom->teacherId !== $teacherId) {
            throw new AccessDeniedException('Bạn không phụ trách lớp này.');
        }
        if ($classroom->isArchived()) {
            throw new AccessDeniedException('Lớp đã lưu trữ nên không thể tạo hoặc sửa bài tập.');
        }
    }

    /** @param array{classroom_id:int,title:string,description:?string,type:string,deadline_at:?string,status:string} $v */
    private function fill(AssignmentModel $model, array $v, ?array $quizJson): void
    {
        $model->setClassroomId((int) $v['classroom_id']);
        $model->setTitle($v['title']);
        $model->setDescription(($v['description'] ?? '') !== '' ? $v['description'] : null);
        $model->setType($v['type']);
        $model->setQuizJson($v['type'] === AssignmentModel::TYPE_QUIZ ? $quizJson : null);
        $model->setDeadlineAt($this->normalizeDatetime($v['deadline_at'] ?? null));
        $model->setStatus($v['status']);
    }

    /** Input HTML datetime-local "2026-07-20T18:00" → MySQL DATETIME "2026-07-20 18:00:00". */
    private function normalizeDatetime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
