<?php

declare(strict_types=1);

namespace User\Model\User;

/**
 * POPO nội bộ module User. CHỨA password_hash → tuyệt đối không trả thẳng ra ngoài module.
 * Ra khỏi module chỉ đi qua UserDto (không có password_hash). Xem module/User/CLAUDE.md.
 */
class UserModel
{
    private ?int $id = null;
    private ?string $role = null;
    private ?string $fullName = null;
    private ?string $email = null;
    private ?string $phone = null;
    private ?string $username = null;
    private ?string $passwordHash = null;
    private int $status = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): void
    {
        $this->role = $role;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): void
    {
        $this->fullName = $fullName;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function isActive(): bool
    {
        return $this->status === 1;
    }

    /** Hydrate từ 1 row DB. Chỉ Mapper gọi. */
    public function exchangeArray(array $row): self
    {
        $this->id           = isset($row['id']) ? (int) $row['id'] : null;
        $this->role         = $row['role'] ?? null;
        $this->fullName     = $row['full_name'] ?? null;
        $this->email        = $row['email'] ?? null;
        $this->phone        = $row['phone'] ?? null;
        $this->username     = $row['username'] ?? null;
        $this->passwordHash = $row['password_hash'] ?? null;
        $this->status       = isset($row['status']) ? (int) $row['status'] : 1;

        return $this;
    }

    /** Bản chiếu an toàn để truyền ra ngoài module — KHÔNG có password_hash. */
    public function toDto(): UserDto
    {
        return new UserDto(
            (int) $this->id,
            (string) $this->role,
            (string) $this->fullName,
            (string) $this->username,
            $this->status,
        );
    }
}
