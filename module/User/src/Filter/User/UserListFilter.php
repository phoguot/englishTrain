<?php

declare(strict_types=1);

namespace User\Filter\User;

use Laminas\Filter\StringTrim;
use Laminas\Filter\StripTags;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\StringLength;

class UserListFilter extends InputFilter
{
    public function __construct()
    {
        $this->add([
            'name' => 'q',
            'required' => false,
            'filters' => [['name' => StringTrim::class], ['name' => StripTags::class]],
            'validators' => [[
                'name' => StringLength::class,
                'options' => ['max' => 100, 'messages' => [StringLength::TOO_LONG => 'Từ khóa tìm kiếm tối đa 100 ký tự.']],
            ]],
        ]);
    }
}
