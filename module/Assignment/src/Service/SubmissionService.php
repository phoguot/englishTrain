<?php

declare(strict_types=1);

namespace Assignment\Service;

use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Assignment\Filter\Submission\EssaySubmitFilter;
use Assignment\Filter\Submission\GradeFilter;
use Assignment\Model\Assignment\AssignmentMapper;
use Assignment\Model\Assignment\AssignmentModel;
use Assignment\Model\Submission\SubmissionMapper;
use Assignment\Model\Submission\SubmissionModel;
use Classroom\Service\ClassroomService;
use User\Service\UserService;

/**
 * Nghiệp vụ nộp bài + chấm điểm. Kiểm quyền sở hữu ở đây. Xem module/Assignment/CLAUDE.md.
 * avgScore()/countByStatus() là hợp đồng đọc cho module Report.
 */
class SubmissionService
{
    public function __construct(
        private readonly AssignmentMapper $assignmentMapper,
        private readonly SubmissionMapper $submissionMapper,
        private readonly ClassroomService $classroomService,
        private readonly UserService $userService,
        private readonly QuizGrader $quizGrader,
    ) {
    }

    // ── Hợp đồng liên module ────────────────────────────────────────────────

    public function avgScore(int $studentId, int $classroomId, string $periodLabel): ?float
    {
        [$from, $to] = $this->periodRange($periodLabel);
        $scores = $this->submissionMapper->getGradedScores($studentId, $classroomId, $from, $to);
        if ($scores === []) {
            return null;
        }
        return round(array_sum($scores) / count($scores), 2);
    }

    /** @return array{submitted:int,graded:int,missed:int} */
    public function countByStatus(int $studentId, int $classroomId, string $periodLabel): array
    {
        [$from, $to] = $this->periodRange($periodLabel);
        return $this->submissionMapper->countStudentStatuses($studentId, $classroomId, $from, $to);
    }

    /**
     * Nộp bài tự luận. Nhận POST THÔ — validate bằng EssaySubmitFilter tại đây.
     *
     * @param array<string,mixed> $data dữ liệu POST thô
     * @throws ValidationException
     */
    public function submitEssay(int $assignmentId, int $studentId, array $data): void
    {
        $assignment = $this->getAssignmentForSubmission($assignmentId, $studentId, AssignmentModel::TYPE_ESSAY);

        $filter = new EssaySubmitFilter();
        $filter->setData($data);
        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages($filter->getMessages());
        }

        $model = new SubmissionModel();
        $model->setAssignmentId((int) $assignment->getId());
        $model->setStudentId($studentId);
        $model->setStatus(SubmissionModel::STATUS_SUBMITTED);
        $model->setEssayText((string) $filter->getValues()['essay_text']);
        $model->setSubmittedAt(date('Y-m-d H:i:s'));

        $this->submissionMapper->upsertSubmission($model);
    }

    /**
     * Nộp bài trắc nghiệm. Nhận POST THÔ; đáp án là mảng động (số câu thay đổi) nên không có
     * Filter class — QuizGrader tự coi mọi giá trị không phải chỉ số hợp lệ là SAI, không ném lỗi
     * (bỏ trống cũng là một cách nộp hợp lệ). Xem QuizGrader.
     *
     * @param array<string,mixed> $data dữ liệu POST thô
     */
    public function submitQuiz(int $assignmentId, int $studentId, array $data): void
    {
        $assignment = $this->getAssignmentForSubmission($assignmentId, $studentId, AssignmentModel::TYPE_QUIZ);

        $rawAnswers = $data['answers'] ?? [];
        $rawAnswers = is_array($rawAnswers) ? $rawAnswers : [];

        // Giữ NGUYÊN chỉ số câu hỏi: câu bỏ trống lưu null chứ không bị dồn chỉ số,
        // nếu không đáp án các câu sau sẽ lệch sang câu khác khi chấm/hiển thị lại.
        $answers   = [];
        $quizJson  = $assignment->getQuizJson() ?? [];
        foreach (array_keys($quizJson) as $index) {
            $value            = $rawAnswers[$index] ?? null;
            $answers[$index]  = is_numeric($value) ? (int) $value : null;
        }

        $autoScore = $this->quizGrader->grade($quizJson, $answers);

        $model = new SubmissionModel();
        $model->setAssignmentId((int) $assignment->getId());
        $model->setStudentId($studentId);
        $model->setStatus(SubmissionModel::STATUS_SUBMITTED);
        $model->setQuizAnswers($answers);
        $model->setAutoScore($autoScore);
        $model->setSubmittedAt(date('Y-m-d H:i:s'));

        $this->submissionMapper->upsertSubmission($model);
    }

    /** Bài nộp của chính học sinh cho 1 assignment — null nếu chưa nộp. */
    public function getMySubmission(int $assignmentId, int $studentId): ?SubmissionModel
    {
        return $this->submissionMapper->getByAssignmentAndStudent($assignmentId, $studentId);
    }

    /**
     * Dữ liệu màn hình chấm điểm: bài tập + từng học sinh trong lớp kèm bài nộp (null = chưa nộp).
     * Chỉ teacher sở hữu lớp.
     *
     * @return array{assignment: AssignmentModel, rows: array<int, array{studentId:int,studentName:string,submission:?SubmissionModel}>}
     */
    public function getForGrading(int $assignmentId, int $userId): array
    {
        $assignment = $this->assignmentMapper->getAssignment($assignmentId);
        if ($assignment === null) {
            throw new NotFoundException('Bài tập không tồn tại.');
        }
        if (!$this->classroomService->isTeacherOf($userId, (int) $assignment->getClassroomId())) {
            throw new AccessDeniedException('Bạn không phụ trách lớp này.');
        }

        $subsByStudent = [];
        foreach ($this->submissionMapper->searchByAssignment($assignmentId) as $s) {
            $subsByStudent[$s->getStudentId()] = $s;
        }

        $memberIds = $this->classroomService->studentIds((int) $assignment->getClassroomId());
        $memberSet = array_fill_keys($memberIds, true);
        $studentIds = array_values(array_unique(array_merge($memberIds, array_keys($subsByStudent))));
        $students = $this->userService->findMany($studentIds);

        $rows = [];
        foreach ($studentIds as $sid) {
            $rows[] = [
                'studentId'   => $sid,
                'studentName' => $students[$sid]->fullName ?? '(không rõ)',
                'submission'  => $subsByStudent[$sid] ?? null,
                'isMember' => isset($memberSet[$sid]),
            ];
        }

        return ['assignment' => $assignment, 'rows' => $rows];
    }

    /**
     * Chấm điểm. Chỉ teacher phụ trách lớp chứa bài tập của bài nộp này.
     * Nhận POST THÔ — validate bằng GradeFilter tại đây.
     *
     * Thứ tự cố ý: tìm bài nộp + kiểm quyền TRƯỚC, validate SAU — để khi dữ liệu sai còn biết
     * assignment_id mà đưa người dùng về đúng trang chấm (kèm trong $context của exception).
     *
     * @param array<string,mixed> $data dữ liệu POST thô
     * @return int assignment_id — để controller redirect về đúng trang chấm.
     * @throws ValidationException
     */
    public function grade(int $submissionId, array $data, int $teacherId): int
    {
        $submission = $this->submissionMapper->getSubmission($submissionId);
        if ($submission === null) {
            throw new NotFoundException('Bài nộp không tồn tại.');
        }

        $assignment = $this->assignmentMapper->getAssignment((int) $submission->getAssignmentId());
        if ($assignment === null || !$this->classroomService->isTeacherOf($teacherId, (int) $assignment->getClassroomId())) {
            throw new AccessDeniedException('Bạn không phụ trách lớp này.');
        }
        $classroom = $this->classroomService->find((int) $assignment->getClassroomId());
        if ($classroom === null || $classroom->isArchived()) {
            throw new AccessDeniedException('Lớp đã lưu trữ nên không thể chấm hoặc sửa điểm.');
        }

        $filter = new GradeFilter();
        $filter->setData($data);
        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages(
                $filter->getMessages(),
                ['assignmentId' => (int) $assignment->getId()],
            );
        }

        $values   = $filter->getValues();
        $feedback = ($values['feedback'] ?? '') !== '' ? (string) $values['feedback'] : null;

        $this->submissionMapper->updateAttrsGrade($submissionId, (float) $values['score'], $feedback);

        return (int) $assignment->getId();
    }

    private function getAssignmentForSubmission(int $assignmentId, int $studentId, string $expectedType): AssignmentModel
    {
        $assignment = $this->assignmentMapper->getAssignment($assignmentId);

        // Không thuộc lớp hoặc bài chưa published → coi như không tồn tại, không tiết lộ gì thêm.
        if ($assignment === null
            || !$this->classroomService->isStudentIn($studentId, (int) $assignment->getClassroomId())
            || !$assignment->isPublished()
        ) {
            throw new NotFoundException('Bài tập không tồn tại.');
        }

        if ($assignment->getType() !== $expectedType) {
            throw new AccessDeniedException('Loại bài tập không khớp.');
        }

        if (!$assignment->acceptsSubmission()) {
            throw new AccessDeniedException('Bài tập đã đóng hoặc quá hạn nộp.');
        }

        return $assignment;
    }

    /** @return array{0:?string,1:?string} */
    private function periodRange(string $periodLabel): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', trim($periodLabel), $matches) !== 1) {
            return [null, null];
        }
        $month = (int) $matches[2];
        if ($month < 1 || $month > 12) {
            return [null, null];
        }
        $from = sprintf('%04d-%02d-01', (int) $matches[1], $month);
        return [$from, date('Y-m-t', (int) strtotime($from))];
    }
}
