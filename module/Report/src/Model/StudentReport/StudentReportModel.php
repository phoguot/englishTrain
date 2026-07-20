<?php

declare(strict_types=1);

namespace Report\Model\StudentReport;

class StudentReportModel
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    private ?int $id = null;
    private ?int $studentId = null;
    private ?int $classroomId = null;
    private ?int $teacherId = null;
    private ?string $periodLabel = null;
    private ?string $content = null;
    private string $status = self::STATUS_DRAFT;
    private ?string $publishedAt = null;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getStudentId(): ?int
    {
        return $this->studentId;
    }

    public function setStudentId(?int $studentId): void
    {
        $this->studentId = $studentId;
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

    public function getPeriodLabel(): ?string
    {
        return $this->periodLabel;
    }

    public function setPeriodLabel(?string $periodLabel): void
    {
        $this->periodLabel = $periodLabel;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): void
    {
        $this->content = $content;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getPublishedAt(): ?string
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?string $publishedAt): void
    {
        $this->publishedAt = $publishedAt;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function exchangeArray(array $row): self
    {
        $this->id = isset($row['id']) ? (int) $row['id'] : null;
        $this->studentId = isset($row['student_id']) ? (int) $row['student_id'] : null;
        $this->classroomId = isset($row['classroom_id']) ? (int) $row['classroom_id'] : null;
        $this->teacherId = isset($row['teacher_id']) ? (int) $row['teacher_id'] : null;
        $this->periodLabel = $row['period_label'] ?? null;
        $this->content = $row['content'] ?? null;
        $this->status = $row['status'] ?? self::STATUS_DRAFT;
        $this->publishedAt = $row['published_at'] ?? null;
        $this->createdAt = $row['created_at'] ?? null;
        $this->updatedAt = $row['updated_at'] ?? null;

        return $this;
    }
}
