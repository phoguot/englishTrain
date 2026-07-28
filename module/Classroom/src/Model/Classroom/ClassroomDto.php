<?php

declare(strict_types=1);

namespace Classroom\Model\Classroom;

/**
 * Bản chiếu lớp học dùng CHUNG cho module khác (hợp đồng: docs/04-contracts.md).
 * Assignment / Attendance / Report nhận cái này, không đụng bảng classroom.
 */
final readonly class ClassroomDto
{
    public function __construct(
        public int $id,
        public string $name,
        public int $teacherId,
        public string $status,
        /** Học phí mặc định 1 buổi/1 học sinh (VNĐ) — Attendance dùng làm giá gợi ý khi tạo buổi. */
        public float $feePerSession = 0.0,
    ) {
    }

    public function isArchived(): bool
    {
        return $this->status === ClassroomModel::STATUS_ARCHIVED;
    }
}
