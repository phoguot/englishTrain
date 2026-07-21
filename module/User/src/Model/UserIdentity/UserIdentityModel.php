<?php

declare(strict_types=1);

namespace User\Model\UserIdentity;

class UserIdentityModel
{
    private ?int $id = null;
    private ?int $userId = null;
    private ?string $provider = null;
    private ?string $providerUserId = null;
    private ?string $providerEmail = null;
    private ?string $lastLoginAt = null;

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

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): void
    {
        $this->provider = $provider;
    }

    public function getProviderUserId(): ?string
    {
        return $this->providerUserId;
    }

    public function setProviderUserId(?string $providerUserId): void
    {
        $this->providerUserId = $providerUserId;
    }

    public function getProviderEmail(): ?string
    {
        return $this->providerEmail;
    }

    public function setProviderEmail(?string $providerEmail): void
    {
        $this->providerEmail = $providerEmail;
    }

    public function getLastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?string $lastLoginAt): void
    {
        $this->lastLoginAt = $lastLoginAt;
    }

    /** @param array<string,mixed> $row */
    public function exchangeArray(array $row): self
    {
        $this->id = isset($row['id']) ? (int) $row['id'] : null;
        $this->userId = isset($row['user_id']) ? (int) $row['user_id'] : null;
        $this->provider = isset($row['provider']) ? (string) $row['provider'] : null;
        $this->providerUserId = isset($row['provider_user_id']) ? (string) $row['provider_user_id'] : null;
        $this->providerEmail = isset($row['provider_email']) ? (string) $row['provider_email'] : null;
        $this->lastLoginAt = isset($row['last_login_at']) ? (string) $row['last_login_at'] : null;

        return $this;
    }
}
