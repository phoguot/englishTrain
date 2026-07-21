<?php

declare(strict_types=1);

namespace User\Model\UserLinkToken;

class UserLinkTokenModel
{
    private ?int $id = null;
    private ?int $userId = null;
    private ?string $tokenHash = null;
    private ?string $expiresAt = null;
    private ?string $usedAt = null;
    private ?int $createdBy = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(?string $tokenHash): void
    {
        $this->tokenHash = $tokenHash;
    }

    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?string $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function getUsedAt(): ?string
    {
        return $this->usedAt;
    }

    public function setUsedAt(?string $usedAt): void
    {
        $this->usedAt = $usedAt;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?int $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function isUsable(): bool
    {
        return $this->usedAt === null
            && $this->expiresAt !== null
            && strtotime($this->expiresAt) > time();
    }

    /** @param array<string,mixed> $row */
    public function exchangeArray(array $row): self
    {
        $this->id = isset($row['id']) ? (int) $row['id'] : null;
        $this->userId = isset($row['user_id']) ? (int) $row['user_id'] : null;
        $this->tokenHash = isset($row['token_hash']) ? (string) $row['token_hash'] : null;
        $this->expiresAt = isset($row['expires_at']) ? (string) $row['expires_at'] : null;
        $this->usedAt = isset($row['used_at']) ? (string) $row['used_at'] : null;
        $this->createdBy = isset($row['created_by']) ? (int) $row['created_by'] : null;

        return $this;
    }
}
