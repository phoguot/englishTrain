<?php

declare(strict_types=1);

namespace User\Filter\UserIdentity;

use Laminas\Filter\StringToLower;
use Laminas\Filter\StringTrim;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\InArray;
use User\OAuth\OAuthProviderType;

class UnlinkFilter extends InputFilter
{
    public function __construct()
    {
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
