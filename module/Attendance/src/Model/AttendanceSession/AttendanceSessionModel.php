<?php

declare(strict_types=1);

namespace Attendance\Model\AttendanceSession;

/**
 * POPO nội bộ module Attendance cho bảng `attendance_session` (một buổi học của một lớp).
 * UNIQUE (classroom_id, session_date) → một lớp một ngày một buổi.
 * Xem module/Attendance/CLAUDE.md.
 */
class AttendanceSessionModel
{
    private ?int $id = null;
    private ?int $classroomId = null;
    private ?string $sessionDate = null;
    private ?string $note = null;
    private ?int $createdBy = null;

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

    /** @return ?string định dạng Y-m-d */
    public function getSessionDate(): ?string
    {
        return $this->sessionDate;
    }

    public function setSessionDate(?string $sessionDate): void
    {
        $this->sessionDate = $sessionDate;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?int $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    /** Ngày hiển thị cho người Việt: 17/07/2026. */
    public function getSessionDateForHuman(): string
    {
        $date = $this->sessionDate;
        if ($date === null || $date === '') {
            return '';
        }

        $parts = explode('-', substr($date, 0, 10));

        return count($parts) === 3 ? $parts[2] . '/' . $parts[1] . '/' . $parts[0] : $date;
    }

    /** @param array<string,mixed> $row */
    public function exchangeArray(array $row): self
    {
        $this->id          = isset($row['id']) ? (int) $row['id'] : null;
        $this->classroomId = isset($row['classroom_id']) ? (int) $row['classroom_id'] : null;
        // MySQL trả DATE dạng 'Y-m-d' — cắt phòng khi driver trả kèm giờ.
        $this->sessionDate = isset($row['session_date']) ? substr((string) $row['session_date'], 0, 10) : null;
        $this->note        = $row['note'] ?? null;
        $this->createdBy   = isset($row['created_by']) ? (int) $row['created_by'] : null;

        return $this;
    }
}
