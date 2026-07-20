<?php

declare(strict_types=1);

namespace Application\Exception;

use RuntimeException;

/**
 * Ném khi thao tác trên dữ liệu không tồn tại (id sai, đã bị xóa).
 * Service ném — BaseController bắt và đổi thành 404.
 */
class NotFoundException extends RuntimeException
{
}
