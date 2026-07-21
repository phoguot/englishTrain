<?php

declare(strict_types=1);

namespace User\OAuth;

interface OAuthProviderInterface
{
    public function name(): string;

    public function authorizationUrl(
        string $redirectUri,
        string $state,
        ?string $codeChallenge,
    ): string;

    public function fetchIdentity(
        string $authorizationCode,
        string $redirectUri,
        ?string $codeVerifier,
    ): OAuthIdentity;
}
