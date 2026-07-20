<?php

declare(strict_types=1);

namespace Attendance\Model\AttendanceRecord;

/**
 * POPO nội bộ module Attendance cho bảng `attendance_record`
 * (trạng thái điểm danh của MỘT học sinh trong MỘT buổi).
 *
 * Không có record ≠ absent: học sinh vào lớp giữa kỳ thì các buổi trước khi vào
 * đơn giản là THIẾU record, và không được tính vào mẫu số của summary().
 * Xem module/Attendance/CLAUDE.md.
 */
class AttendanceRecordModel
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT  = 'absent';
    public const STATUS_LATE    = 'late';
    public const STATUS_EXCUSED = 'excused';

    /** @var string[] Thứ tự này cũng là thứ tự hiển thị radio trên bảng điểm danh. */
    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_LATE,
        self::STATUS_ABSENT,
        self::STATUS_EXCUSED,
    ];

    /** @var array<string,string> Nhãn tiếng Việt dùng chung mọi màn hình. */
    public const LABELS = [
        self::STATUS_PRESENT => 'Có mặt',
        self::STATUS_LATE    => 'Đi muộn',
        self::STATUS_ABSENT  => 'Vắng',
        self::STATUS_EXCUSED => 'Có phép',
    ];

    private ?int $id = null;
    private ?int $sessionId = null;
    private ?int $studentId = null;
    private ?string $status = null;
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getSessionId(): ?int
    {
        return $this->sessionId;
    }

    public function setSessionId(?int $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getStudentId(): ?int
    {
        return $this->studentId;
    }

    public function setStudentId(?int $studentId): void
    {
        $this->studentId = $studentId;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    /** Nhãn tiếng Việt của trạng thái hiện tại. */
    public function getStatusLabel(): string
    {
        return self::LABELS[$this->status] ?? '';
    }

    /** @param array<string,mixed> $row */
    public function exchangeArray(array $row): self
    {
        $this->id        = isset($row['id']) ? (int) $row['id'] : null;
        $this->sessionId = isset($row['session_id']) ? (int) $row['session_id'] : null;
        $this->studentId = isset($row['student_id']) ? (int) $row['student_id'] : null;
        $this->status    = $row['status'] ?? null;
        $this->note      = $row['note'] ?? null;

        return $this;
    }
}
