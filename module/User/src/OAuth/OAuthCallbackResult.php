<?php

declare(strict_types=1);

namespace User\OAuth;

final readonly class OAuthCallbackResult
{
    public function __construct(
        public string $mode,
        public OAuthIdentity $identity,
        public ?int $userId = null,
        public ?string $tokenHash = null,
    ) {
    }
}
