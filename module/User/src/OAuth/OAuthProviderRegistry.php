<?php

declare(strict_types=1);

namespace User\OAuth;

use RuntimeException;

class OAuthProviderRegistry
{
    /** @var array<string,OAuthProviderInterface> */
    private array $providers = [];

    /** @param OAuthProviderInterface[] $providers */
    public function __construct(array $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->name()] = $provider;
        }
    }

    public function get(string $name): OAuthProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new RuntimeException('Nhà cung cấp đăng nhập chưa được cấu hình.');
        }

        return $this->providers[$name];
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
