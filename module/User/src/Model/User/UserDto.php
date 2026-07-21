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
        public string $role,
        public string $fullName,
        public string $username,
        public int $status,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 1;
    }
}
