<?php

declare(strict_types=1);

namespace Assignment\Model\Assignment;

/**
 * POPO nội bộ module Assignment cho bảng `assignment`.
 * Không export ra module khác (chưa có nhu cầu) — xem module/Assignment/CLAUDE.md.
 */
class AssignmentModel
{
    public const TYPE_VIDEO = 'video';
    public const TYPE_QUIZ  = 'quiz';
    public const TYPE_ESSAY = 'essay';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CLOSED    = 'closed';

    private ?int $id = null;
    private ?int $classroomId = null;
    private ?int $teacherId = null;
    private ?string $title = null;
    private ?string $description = null;
    private ?string $type = null;
    /** @var array<int, array{question:string,options:string[],correct_index:int}>|null */
    private ?array $quizJson = null;
    private ?string $deadlineAt = null;
    private string $status = self::STATUS_DRAFT;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getClassroomId(): ?int
    {
        return $this->classroomId;
    }

    public function setClassroomId(?int $classroomId): void
    {
        $this->classroomId = $classroomId;
    }

    public function getTeacherId(): ?int
    {
        return $this->teacherId;
    }

    public function setTeacherId(?int $teacherId): void
    {
        $this->teacherId = $teacherId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    /** @return array<int, array{question:string,options:string[],correct_index:int}>|null */
    public function getQuizJson(): ?array
    {
        return $this->quizJson;
    }

    /** @param array<int, array{question:string,options:string[],correct_index:int}>|null $quizJson */
    public function setQuizJson(?array $quizJson): void
    {
        $this->quizJson = $quizJson;
    }

    public function getDeadlineAt(): ?string
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(?string $deadlineAt): void
    {
        $this->deadlineAt = $deadlineAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** deadline_at = NULL nghĩa là không hạn. */
    public function isPastDeadline(): bool
    {
        if ($this->deadlineAt === null) {
            return false;
        }

        return strtotime($this->deadlineAt) < time();
    }

    /** Còn nhận bài: đã published, chưa closed, chưa quá hạn. */
    public function acceptsSubmission(): bool
    {
        return $this->isPublished() && !$this->isPastDeadline();
    }

    public function exchangeArray(array $row): self
    {
        $this->id           = isset($row['id']) ? (int) $row['id'] : null;
        $this->classroomId  = isset($row['classroom_id']) ? (int) $row['classroom_id'] : null;
        $this->teacherId    = isset($row['teacher_id']) ? (int) $row['teacher_id'] : null;
        $this->title        = $row['title'] ?? null;
        $this->description  = $row['description'] ?? null;
        $this->type         = $row['type'] ?? null;
        $this->deadlineAt   = $row['deadline_at'] ?? null;
        $this->status       = $row['status'] ?? self::STATUS_DRAFT;

        $rawQuiz = $row['quiz_json'] ?? null;
        if (is_string($rawQuiz) && $rawQuiz !== '') {
            $decoded        = json_decode($rawQuiz, true);
            $this->quizJson = is_array($decoded) ? $decoded : null;
        } elseif (is_array($rawQuiz)) {
            $this->quizJson = $rawQuiz;
        } else {
            $this->quizJson = null;
        }

        return $this;
    }
}
