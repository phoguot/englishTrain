<?php

declare(strict_types=1);

namespace Application\View\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * In tiền VNĐ theo cách người Việt đọc: 150000 → "150.000 đ".
 *
 * Có helper riêng để mọi trang tiền (học phí buổi, tổng hợp tháng, đơn giá lớp) hiển thị
 * giống nhau — đừng rải number_format() trong từng view.
 * Không làm tròn hào: học phí trong dự án là số nguyên đồng.
 */
class MoneyVnd extends AbstractHelper
{
    /** @param string $zeroLabel chữ hiện khi số tiền bằng 0 (ví dụ "Chưa đặt") */
    public function __invoke(float $amount, string $zeroLabel = '0 đ'): string
    {
        if ($amount === 0.0) {
            return $zeroLabel;
        }

        return number_format($amount, 0, ',', '.') . ' đ';
    }
}
