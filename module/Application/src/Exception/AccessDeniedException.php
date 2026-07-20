<?php

declare(strict_types=1);

namespace Application\Exception;

use RuntimeException;

/**
 * Ném khi user đăng nhập đúng role nhưng KHÔNG sở hữu dữ liệu đang thao tác
 * (teacher đụng lớp người khác, student xem bài người khác...).
 * Service ném — controller bắt và đổi thành 403. Xem .claude/rules/01-bao-mat.md.
 */
class AccessDeniedException extends RuntimeException
{
}
