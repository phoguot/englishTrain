<?php

declare(strict_types=1);

namespace Assignment\Model\Submission;

/**
 * POPO nội bộ module Assignment cho bảng `submission`.
 * KHÔNG có row nghĩa là "chưa nộp" — đây không phải giá trị enum. Không tự ý tạo row rỗng.
 * Xem module/Assignment/CLAUDE.md.
 */
class SubmissionModel
{
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED    = 'graded';

    private ?int $id = null;
    private ?int $assignmentId = null;
    private ?int $studentId = null;
    private string $status = self::STATUS_SUBMITTED;
    private ?string $videoKey = null;
    private ?int $videoSize = null;
    private ?string $essayText = null;
    /** @var array<int, int|null>|null chỉ số đáp án theo từng câu; null = câu bỏ trống */
    private ?array $quizAnswers = null;
    private ?float $autoScore = null;
    private ?float $score = null;
    private ?string $feedback = null;
    private ?string $submittedAt = null;
    private ?string $gradedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getAssignmentId(): ?int
    {
        return $this->assignmentId;
    }

    public function setAssignmentId(?int $assignmentId): void
    {
        $this->assignmentId = $assignmentId;
    }

    public function getStudentId(): ?int
    {
        return $this->studentId;
    }

    public function setStudentId(?int $studentId): void
    {
        $this->studentId = $studentId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isGraded(): bool
    {
        return $this->status === self::STATUS_GRADED;
    }

    public function getVideoKey(): ?string
    {
        return $this->videoKey;
    }

    public function setVideoKey(?string $videoKey): void
    {
        $this->videoKey = $videoKey;
    }

    public function getVideoSize(): ?int
    {
        return $this->videoSize;
    }

    public function setVideoSize(?int $videoSize): void
    {
        $this->videoSize = $videoSize;
    }

    public function getEssayText(): ?string
    {
        return $this->essayText;
    }

    public function setEssayText(?string $essayText): void
    {
        $this->essayText = $essayText;
    }

    /** @return array<int, int|null>|null */
    public function getQuizAnswers(): ?array
    {
        return $this->quizAnswers;
    }

    /** @param array<int, int|null>|null $quizAnswers */
    public function setQuizAnswers(?array $quizAnswers): void
    {
        $this->quizAnswers = $quizAnswers;
    }

    public function getAutoScore(): ?float
    {
        return $this->autoScore;
    }

    public function setAutoScore(?float $autoScore): void
    {
        $this->autoScore = $autoScore;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): void
    {
        $this->score = $score;
    }

    public function getFeedback(): ?string
    {
        return $this->feedback;
    }

    public function setFeedback(?string $feedback): void
    {
        $this->feedback = $feedback;
    }

    public function getSubmittedAt(): ?string
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?string $submittedAt): void
    {
        $this->submittedAt = $submittedAt;
    }

    public function getGradedAt(): ?string
    {
        return $this->gradedAt;
    }

    public function setGradedAt(?string $gradedAt): void
    {
        $this->gradedAt = $gradedAt;
    }

    public function exchangeArray(array $row): self
    {
        $this->id           = isset($row['id']) ? (int) $row['id'] : null;
        $this->assignmentId = isset($row['assignment_id']) ? (int) $row['assignment_id'] : null;
        $this->studentId    = isset($row['student_id']) ? (int) $row['student_id'] : null;
        $this->status       = $row['status'] ?? self::STATUS_SUBMITTED;
        $this->videoKey     = $row['video_key'] ?? null;
        $this->videoSize    = isset($row['video_size']) ? (int) $row['video_size'] : null;
        $this->essayText    = $row['essay_text'] ?? null;
        $this->autoScore    = isset($row['auto_score']) ? (float) $row['auto_score'] : null;
        $this->score        = isset($row['score']) ? (float) $row['score'] : null;
        $this->feedback     = $row['feedback'] ?? null;
        $this->submittedAt  = $row['submitted_at'] ?? null;
        $this->gradedAt     = $row['graded_at'] ?? null;

        $rawAnswers = $row['quiz_answers'] ?? null;
        if (is_string($rawAnswers) && $rawAnswers !== '') {
            $decoded           = json_decode($rawAnswers, true);
            $this->quizAnswers = is_array($decoded) ? $decoded : null;
        } elseif (is_array($rawAnswers)) {
            $this->quizAnswers = $rawAnswers;
        } else {
            $this->quizAnswers = null;
        }

        return $this;
    }
}
