<?php

declare(strict_types=1);

namespace Application\Controller;

/**
 * @deprecated Controller mới phải kế thừa BaseController trực tiếp.
 *
 * Alias tạm để code ngoài module (nếu còn) không gãy ngay. Toàn bộ helper request/container
 * hợp lệ đã được chuyển sang BaseController; các helper legacy phụ thuộc class không tồn tại
 * đã được loại bỏ thay vì để lại API gọi vào sẽ fatal.
 */
abstract class AppController extends BaseController
{
}
