<?php

declare(strict_types=1);

namespace User\Filter\UserIdentity;

use Laminas\Filter\StringToLower;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\InArray;
use Laminas\Validator\Regex;
use User\OAuth\OAuthProviderType;

class LinkCodeFilter extends InputFilter
{
    public function __construct()
    {
        $this->add([
            'name' => 'link_code',
            'required' => true,
            'filters' => [['name' => StringTrim::class], ['name' => StringToLower::class]],
            'validators' => [
                [
                    'name' => Regex::class,
                    'options' => [
                        'pattern' => '/\A[a-f0-9]{32}\z/',
                        'messages' => [Regex::NOT_MATCH => 'Mã liên kết không hợp lệ hoặc đã hết hạn.'],
                    ],
                ],
            ],
        ]);
        $this->add([
            'name' => 'provider',
            'required' => true,
            'filters' => [['name' => StringTrim::class], ['name' => StringToLower::class]],
            'validators' => [[
                'name' => InArray::class,
                'options' => [
                    'haystack' => OAuthProviderType::ALL,
                    'strict' => InArray::COMPARE_STRICT,
                    'messages' => [InArray::NOT_IN_ARRAY => 'Nhà cung cấp đăng nhập không hợp lệ.'],
                ],
            ]],
        ]);
    }
}
