<?php

declare(strict_types=1);

namespace Application\Model\SystemLog;

/**
 * POPO cho bảng `system_log` — lỗi hệ thống ghi tự động từ listener dispatch.error.
 * Nội bộ module Application, không export cho module khác.
 */
class SystemLogModel
{
    public const LEVEL_ERROR   = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_INFO    = 'info';

    private ?int $id = null;
    private string $level = self::LEVEL_ERROR;
    private string $message = '';
    private ?string $exceptionClass = null;
    private ?string $file = null;
    private ?int $line = null;
    private ?string $stackTrace = null;
    private ?string $requestMethod = null;
    private ?string $requestUri = null;
    private ?int $userId = null;
    private ?string $ip = null;
    private ?string $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): void
    {
        $this->level = $level;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function getExceptionClass(): ?string
    {
        return $this->exceptionClass;
    }

    public function setExceptionClass(?string $exceptionClass): void
    {
        $this->exceptionClass = $exceptionClass;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function setFile(?string $file): void
    {
        $this->file = $file;
    }

    public function getLine(): ?int
    {
        return $this->line;
    }

    public function setLine(?int $line): void
    {
        $this->line = $line;
    }

    public function getStackTrace(): ?string
    {
        return $this->stackTrace;
    }

    public function setStackTrace(?string $stackTrace): void
    {
        $this->stackTrace = $stackTrace;
    }

    public function getRequestMethod(): ?string
    {
        return $this->requestMethod;
    }

    public function setRequestMethod(?string $requestMethod): void
    {
        $this->requestMethod = $requestMethod;
    }

    public function getRequestUri(): ?string
    {
        return $this->requestUri;
    }

    public function setRequestUri(?string $requestUri): void
    {
        $this->requestUri = $requestUri;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): void
    {
        $this->userId = $userId;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): void
    {
        $this->ip = $ip;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /** @param array<string,mixed> $row */
    public function exchangeArray(array $row): self
    {
        $this->id             = isset($row['id']) ? (int) $row['id'] : null;
        $this->level          = (string) ($row['level'] ?? self::LEVEL_ERROR);
        $this->message        = (string) ($row['message'] ?? '');
        $this->exceptionClass = $row['exception_class'] ?? null;
        $this->file           = $row['file'] ?? null;
        $this->line           = isset($row['line']) ? (int) $row['line'] : null;
        $this->stackTrace     = $row['stack_trace'] ?? null;
        $this->requestMethod  = $row['request_method'] ?? null;
        $this->requestUri     = $row['request_uri'] ?? null;
        $this->userId         = isset($row['user_id']) ? (int) $row['user_id'] : null;
        $this->ip             = $row['ip'] ?? null;
        $this->createdAt      = $row['created_at'] ?? null;

        return $this;
    }
}
