<?php

declare(strict_types=1);

namespace User\Model\UserRememberToken;

class UserRememberTokenModel
{
    private ?int $id = null;
    private ?int $userId = null;
    private ?string $selector = null;
    private ?string $tokenHash = null;
    private ?string $expiresAt = null;
    private ?string $lastUsedAt = null;
    private ?string $revokedAt = null;

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

    public function getSelector(): ?string
    {
        return $this->selector;
    }

    public function setSelector(?string $selector): void
    {
        $this->selector = $selector;
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

    public function getLastUsedAt(): ?string
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?string $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
    }

    public function getRevokedAt(): ?string
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?string $revokedAt): void
    {
        $this->revokedAt = $revokedAt;
    }

    public function isUsable(): bool
    {
        return $this->revokedAt === null
            && $this->expiresAt !== null
            && strtotime($this->expiresAt) > time();
    }

    /** @param array<string,mixed> $row */
    public function exchangeArray(array $row): self
    {
        $this->id = isset($row['id']) ? (int) $row['id'] : null;
        $this->userId = isset($row['user_id']) ? (int) $row['user_id'] : null;
        $this->selector = isset($row['selector']) ? (string) $row['selector'] : null;
        $this->tokenHash = isset($row['token_hash']) ? (string) $row['token_hash'] : null;
        $this->expiresAt = isset($row['expires_at']) ? (string) $row['expires_at'] : null;
        $this->lastUsedAt = isset($row['last_used_at']) ? (string) $row['last_used_at'] : null;
        $this->revokedAt = isset($row['revoked_at']) ? (string) $row['revoked_at'] : null;

        return $this;
    }
}
