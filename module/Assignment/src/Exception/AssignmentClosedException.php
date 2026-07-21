<?php

declare(strict_types=1);

namespace Assignment\Exception;

use RuntimeException;

/**
 * Ném khi bài tập không còn nhận bài (đã đóng hoặc quá hạn) tại thời điểm xin/ xác nhận
 * upload video. Riêng cho luồng JSON upload-url/upload-done — map sang HTTP 409, khác với
 * AccessDeniedException (403) dùng cho các luồng HTML thông thường. Xem module/Assignment/CLAUDE.md.
 */
class AssignmentClosedException extends RuntimeException
{
}
