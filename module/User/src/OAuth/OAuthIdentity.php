<?php

declare(strict_types=1);

namespace User\OAuth;

final readonly class OAuthIdentity
{
    public string $provider;
    public string $providerUserId;
    public ?string $email;

    public function __construct(
        string $provider,
        string $providerUserId,
        ?string $email,
    ) {
        if ($providerUserId === '' || strlen($providerUserId) > 255) {
            throw new \InvalidArgumentException('Provider trả về ID danh tính không hợp lệ.');
        }
        $this->provider = $provider;
        $this->providerUserId = $providerUserId;
        $this->email = $email !== null
            && strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                ? $email
                : null;
    }
}
