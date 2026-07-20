<?php

declare(strict_types=1);

namespace Classroom\Model\Classroom;

/**
 * POPO nội bộ module Classroom cho bảng `classroom`.
 * Ra ngoài module chỉ đi qua ClassroomDto. Xem module/Classroom/CLAUDE.md.
 */
class ClassroomModel
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_ARCHIVED = 'archived';

    private ?int $id = null;
    private ?string $name = null;
    private ?int $teacherId = null;
    private ?string $scheduleNote = null;
    private string $status = self::STATUS_ACTIVE;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getTeacherId(): ?int
    {
        return $this->teacherId;
    }

    public function setTeacherId(?int $teacherId): void
    {
        $this->teacherId = $teacherId;
    }

    public function getScheduleNote(): ?string
    {
        return $this->scheduleNote;
    }

    public function setScheduleNote(?string $scheduleNote): void
    {
        $this->scheduleNote = $scheduleNote;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    public function exchangeArray(array $row): self
    {
        $this->id           = isset($row['id']) ? (int) $row['id'] : null;
        $this->name         = $row['name'] ?? null;
        $this->teacherId    = isset($row['teacher_id']) ? (int) $row['teacher_id'] : null;
        $this->scheduleNote = $row['schedule_note'] ?? null;
        $this->status       = $row['status'] ?? self::STATUS_ACTIVE;

        return $this;
    }

    public function toDto(): ClassroomDto
    {
        return new ClassroomDto(
            (int) $this->id,
            (string) $this->name,
            (int) $this->teacherId,
            $this->status,
        );
    }
}
