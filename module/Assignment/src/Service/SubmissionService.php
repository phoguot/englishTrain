<?php

declare(strict_types=1);

namespace Assignment\Service;

use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Assignment\Exception\AssignmentClosedException;
use Assignment\Filter\Submission\EssaySubmitFilter;
use Assignment\Filter\Submission\GradeFilter;
use Assignment\Filter\Submission\VideoUploadUrlFilter;
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
        private readonly R2StorageService $r2Storage,
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

    /** R2 đã đủ credential để nộp video chưa. False = chưa cấu hình production. */
    public function isVideoUploadConfigured(): bool
    {
        return $this->r2Storage->isConfigured();
    }

    /** Hạn dung lượng video (MB) đọc từ config r2.max_upload_mb — cho view hiển thị, JS kiểm trước khi upload. */
    public function maxUploadMb(): int
    {
        return (int) round($this->r2Storage->maxUploadBytes() / 1024 / 1024);
    }

    /** @return string[] mime video được chấp nhận — cho input[accept] ở view. */
    public function allowedVideoMimeTypes(): array
    {
        return $this->r2Storage->allowedMimeTypes();
    }

    /**
     * Bước 1/3 nộp video: xin presigned PUT URL. Kiểm quyền + bài còn nhận bài TRƯỚC khi ký URL —
     * ký xong mới kiểm là đã lộ quyền ghi. Nhận POST THÔ — validate bằng VideoUploadUrlFilter tại đây.
     *
     * @param array<string,mixed> $data dữ liệu POST thô: filename, size, mime
     * @return array{url:string,key:string,expires_in:int}
     * @throws ValidationException|AssignmentClosedException
     */
    public function requestVideoUploadUrl(int $assignmentId, int $studentId, array $data): array
    {
        $assignment = $this->getAssignmentForVideoSubmission($assignmentId, $studentId);
        if (!$assignment->acceptsSubmission()) {
            throw new AssignmentClosedException('Bài tập đã đóng.');
        }

        $filter = new VideoUploadUrlFilter($this->r2Storage);
        $filter->setData($data);
        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages($filter->getMessages());
        }
        $values = $filter->getValues();
        $mime   = (string) $values['mime'];

        $student = $this->userService->find($studentId);
        $key     = $this->r2Storage->buildObjectKey($assignmentId, $studentId, $mime, $student?->username ?? '');
        $url     = $this->r2Storage->presignedUploadUrl($key, $mime, (int) $values['size']);

        return ['url' => $url, 'key' => $key, 'expires_in' => 900];
    }

    /**
     * Bước 3/3 nộp video: xác nhận đã upload xong → tạo/ghi đè submission. Không tin `key`/`size`
     * client gửi mù quáng: kiểm key đúng thuộc assignment/student này (tiền tố do buildObjectKey
     * sinh ra) rồi gọi headObject thật trên R2 lấy size xác thực — client gọi khống (chưa upload
     * thật) sẽ bị từ chối ở đây thay vì tạo submission rỗng.
     *
     * @param array<string,mixed> $data dữ liệu POST thô: key, size
     * @throws ValidationException|AssignmentClosedException
     */
    public function completeVideoUpload(int $assignmentId, int $studentId, array $data): SubmissionModel
    {
        $assignment = $this->getAssignmentForVideoSubmission($assignmentId, $studentId);
        if (!$assignment->acceptsSubmission()) {
            throw new AssignmentClosedException('Bài tập đã đóng.');
        }

        $key = is_string($data['key'] ?? null) ? trim($data['key']) : '';
        $expectedPrefix = sprintf('submissions/%d/%d/', $assignmentId, $studentId);
        if ($key === '' || !str_starts_with($key, $expectedPrefix)) {
            throw new ValidationException(['key' => 'Không xác định được file đã tải lên.']);
        }

        $actualSize = $this->r2Storage->actualObjectSize($key);
        if ($actualSize === null) {
            throw new ValidationException(['key' => 'Chưa tìm thấy file trên hệ thống lưu trữ. Vui lòng thử tải lên lại.']);
        }

        $model = new SubmissionModel();
        $model->setAssignmentId((int) $assignment->getId());
        $model->setStudentId($studentId);
        $model->setStatus(SubmissionModel::STATUS_SUBMITTED);
        $model->setVideoKey($key);
        $model->setVideoSize($actualSize);
        $model->setSubmittedAt(date('Y-m-d H:i:s'));

        return $this->submissionMapper->upsertSubmission($model);
    }

    /**
     * URL xem video (presigned GET, hạn 1h) cho giáo viên chấm bài — chỉ giáo viên phụ trách
     * lớp chứa assignment của submission này. Ký MỚI mỗi lần gọi, không cache URL cũ.
     *
     * @throws NotFoundException|AccessDeniedException
     */
    public function getVideoViewUrlForTeacher(int $submissionId, int $teacherId): string
    {
        $submission = $this->submissionMapper->getSubmission($submissionId);
        if ($submission === null || $submission->getVideoKey() === null) {
            throw new NotFoundException('Bài nộp không tồn tại.');
        }

        $assignment = $this->assignmentMapper->getAssignment((int) $submission->getAssignmentId());
        if ($assignment === null || !$this->classroomService->isTeacherOf($teacherId, (int) $assignment->getClassroomId())) {
            throw new AccessDeniedException('Bạn không phụ trách lớp này.');
        }

        return $this->r2Storage->presignedViewUrl((string) $submission->getVideoKey());
    }

    /** URL xem video (presigned GET, hạn 1h) cho admin — không kiểm phụ trách lớp, admin xem toàn hệ thống. */
    public function getVideoViewUrlForAdmin(int $submissionId): string
    {
        $submission = $this->submissionMapper->getSubmission($submissionId);
        if ($submission === null || $submission->getVideoKey() === null) {
            throw new NotFoundException('Bài nộp không tồn tại.');
        }

        return $this->r2Storage->presignedViewUrl((string) $submission->getVideoKey());
    }

    /**
     * Danh sách toàn bộ bài nộp video trong hệ thống cho màn quản trị, kèm tên học sinh/lớp/bài
     * tập để hiển thị — tránh N+1 bằng findMany thay vì loop find(). $studentId lọc 1 học sinh nếu > 0.
     *
     * @return array<int, array{
     *   submissionId:int, studentId:int, studentName:string,
     *   classroomId:int, classroomName:string,
     *   assignmentId:int, assignmentTitle:string,
     *   videoKey:string, videoSizeBytes:int, submittedAt:?string
     * }>
     */
    public function listVideoSubmissionsForAdmin(int $studentId = 0): array
    {
        $submissions = $this->submissionMapper->searchVideoSubmissions($studentId);
        if ($submissions === []) {
            return [];
        }

        $assignmentIds = array_values(array_unique(array_map(
            static fn (SubmissionModel $s): int => (int) $s->getAssignmentId(),
            $submissions,
        )));
        $assignments = [];
        foreach ($assignmentIds as $assignmentId) {
            $assignment = $this->assignmentMapper->getAssignment($assignmentId);
            if ($assignment !== null) {
                $assignments[$assignmentId] = $assignment;
            }
        }

        $studentIds = array_values(array_unique(array_map(
            static fn (SubmissionModel $s): int => (int) $s->getStudentId(),
            $submissions,
        )));
        $students = $this->userService->findMany($studentIds);

        $classroomIds = array_values(array_unique(array_map(
            static fn (AssignmentModel $a): int => (int) $a->getClassroomId(),
            $assignments,
        )));
        $classrooms = $this->classroomService->findMany($classroomIds);

        $rows = [];
        foreach ($submissions as $s) {
            $assignmentId = (int) $s->getAssignmentId();
            $assignment   = $assignments[$assignmentId] ?? null;
            $classroomId  = $assignment !== null ? (int) $assignment->getClassroomId() : 0;
            $studentId2   = (int) $s->getStudentId();

            $rows[] = [
                'submissionId'    => (int) $s->getId(),
                'studentId'       => $studentId2,
                'studentName'     => $students[$studentId2]->fullName ?? '(không rõ)',
                'classroomId'     => $classroomId,
                'classroomName'   => $classrooms[$classroomId]->name ?? '(không rõ)',
                'assignmentId'    => $assignmentId,
                'assignmentTitle' => $assignment !== null ? (string) $assignment->getTitle() : '(không rõ)',
                'videoKey'        => (string) $s->getVideoKey(),
                'videoSizeBytes'  => (int) $s->getVideoSize(),
                'submittedAt'     => $s->getSubmittedAt(),
            ];
        }

        return $rows;
    }

    /**
     * Xóa 1 video khỏi hệ thống: xóa file thật trên R2 TRƯỚC, xóa row submission SAU — nếu xóa
     * row trước mà xóa R2 lỗi giữa chừng thì mất dấu vết để dọn lại file rác. Không có row =
     * "chưa nộp" nên học sinh nộp lại video mới được ngay sau khi admin xóa.
     *
     * @throws NotFoundException
     */
    public function deleteVideoSubmissionForAdmin(int $submissionId): void
    {
        $submission = $this->submissionMapper->getSubmission($submissionId);
        if ($submission === null || $submission->getVideoKey() === null) {
            throw new NotFoundException('Không tìm thấy video.');
        }

        $this->r2Storage->deleteObject((string) $submission->getVideoKey());
        $this->submissionMapper->deleteSubmission($submissionId);
    }

    /** Chỉ dùng cho luồng video (upload-url/upload-done) — khác getAssignmentForSubmission ở chỗ
     * không kiểm loại essay/quiz mà bắt buộc đúng loại video. */
    private function getAssignmentForVideoSubmission(int $assignmentId, int $studentId): AssignmentModel
    {
        $assignment = $this->assignmentMapper->getAssignment($assignmentId);

        if ($assignment === null
            || !$this->classroomService->isStudentIn($studentId, (int) $assignment->getClassroomId())
            || !$assignment->isPublished()
            || $assignment->getType() !== AssignmentModel::TYPE_VIDEO
        ) {
            throw new NotFoundException('Bạn không thuộc lớp này.');
        }

        return $assignment;
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
