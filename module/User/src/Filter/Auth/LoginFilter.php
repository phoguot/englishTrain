<?php

declare(strict_types=1);

namespace User\Filter\Auth;

use Laminas\Filter\Boolean;
use Laminas\Filter\StringTrim;
use Laminas\Filter\StripTags;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * Validate dữ liệu form đăng nhập. Server luôn kiểm lại — HTML required chỉ là gợi ý.
 * Xem .claude/rules/01-bao-mat.md.
 */
class LoginFilter extends InputFilter
{
    public function __construct()
    {
        $this->add([
            'name'       => 'username',
            'required'   => true,
            'filters'    => [
                ['name' => StringTrim::class],
                ['name' => StripTags::class],
            ],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng nhập tên đăng nhập.']]],
                ['name' => StringLength::class, 'options' => ['max' => 50, 'messages' => [StringLength::TOO_LONG => 'Tên đăng nhập quá dài.']]],
            ],
        ]);

        $this->add([
            'name'       => 'password',
            'required'   => true,
            'filters'    => [],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng nhập mật khẩu.']]],
            ],
        ]);

        // Checkbox "ghi nhớ đăng nhập" — không bắt buộc, không có giá trị sai, chỉ chuẩn hóa
        // input HTML checkbox (có mặt = tick, vắng mặt = không tick) thành true/false thật sự.
        $this->add([
            'name'       => 'remember_me',
            'required'   => false,
            'filters'    => [['name' => Boolean::class, 'options' => ['type' => Boolean::TYPE_ALL]]],
            'validators' => [],
        ]);
    }
}
