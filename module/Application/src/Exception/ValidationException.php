<?php

declare(strict_types=1);

namespace Application\Exception;

use RuntimeException;

/**
 * Ném khi dữ liệu vào không hợp lệ. Service chạy Filter class rồi ném cái này —
 * controller bắt, render LẠI form kèm lỗi + dữ liệu người dùng vừa gõ.
 *
 * KHÁC AccessDeniedException/NotFoundException: BaseController **không** tự bắt cái này,
 * vì lỗi validate phải quay về đúng form chứ không phải trang lỗi chung.
 * Controller nào gọi Service có validate thì phải try/catch.
 *
 * $context mang dữ liệu phụ Service đã dựng dở để controller hiển thị lại
 * (ví dụ quizJson đã parse, assignmentId để redirect đúng chỗ).
 */
class ValidationException extends RuntimeException
{
    /**
     * @param array<string,string> $errors  map field => câu lỗi tiếng Việt
     * @param array<string,mixed>  $context dữ liệu phụ cho việc render lại
     */
    public function __construct(
        private readonly array $errors,
        private readonly array $context = [],
        string $message = 'Dữ liệu không hợp lệ.',
    ) {
        parent::__construct($message);
    }

    /** @return array<string,string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return array<string,mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Gộp getMessages() của InputFilter (field => [key => msg]) thành field => "msg1 msg2".
     *
     * @param array<string, array<string,string>|string> $filterMessages
     * @param array<string,mixed> $context
     */
    public static function fromFilterMessages(array $filterMessages, array $context = []): self
    {
        $errors = [];
        foreach ($filterMessages as $field => $messages) {
            $errors[(string) $field] = implode(' ', array_map('strval', (array) $messages));
        }

        return new self($errors, $context);
    }
}
