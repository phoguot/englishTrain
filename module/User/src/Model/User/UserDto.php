<?php

declare(strict_types=1);

namespace User\Model\User;

/**
 * Bản chiếu danh tính người dùng dùng CHUNG cho mọi module (hợp đồng: docs/04-contracts.md).
 * Cố ý KHÔNG chứa password_hash, email, phone — chỉ đủ để hiển thị và phân quyền.
 */
final readonly class UserDto
{
    public function __construct(
        public int $id,
        /** Xem UserModel::ROLE_* — TINYINT, không phải chuỗi. */
        public int $role,
        public string $fullName,
        public string $username,
        public int $status,
        /** Chỉ có giá trị khi role='teacher'. Ý nghĩa từng số: UserModel::TEACHER_TYPE_*. */
        public ?int $teacherType = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 1;
    }

    /**
     * Được giao bài tập dạng video (upload R2) hay không — chỉ giáo viên tiếng Anh.
     * Module Assignment hỏi qua đây, không tự đọc cột `teacher_type` (docs/04-contracts.md).
     */
    public function canAssignVideo(): bool
    {
        return $this->role === UserModel::ROLE_TEACHER
            && $this->teacherType === UserModel::TEACHER_TYPE_ENGLISH;
    }
}
