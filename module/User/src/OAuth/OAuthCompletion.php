<?php

declare(strict_types=1);

namespace User\OAuth;

final readonly class OAuthCompletion
{
    public function __construct(
        public string $route,
        public string $message,
        public bool $success,
    ) {
    }
}
