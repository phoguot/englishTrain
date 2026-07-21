<?php

declare(strict_types=1);

namespace User\Service;

use RuntimeException;
use Throwable;
use User\OAuth\OAuthCompletion;

class OAuthLoginService
{
    public function __construct(
        private readonly OAuthService $oauthService,
        private readonly UserIdentityService $identityService,
        private readonly AuthService $authService,
    ) {
    }

    public function complete(string $provider, ?int $currentUserId): OAuthCompletion
    {
        $result = $this->oauthService->finishCallback($provider);
        if ($result->mode === 'self_link') {
            if ($result->userId === null || $result->userId !== $currentUserId) {
                throw new RuntimeException('Phiên liên kết không còn hợp lệ.');
            }
            $this->identityService->linkCurrent($result->userId, $result->identity);

            return new OAuthCompletion('account_identities', 'Đã liên kết tài khoản đăng nhập.', true);
        }

        if ($currentUserId !== null) {
            throw new RuntimeException('Phiên đăng nhập ngoài không còn hợp lệ.');
        }
        if ($result->mode === 'link_code') {
            if ($result->tokenHash === null) {
                throw new RuntimeException('Phiên liên kết không còn hợp lệ.');
            }
            $user = $this->identityService->linkWithToken($result->tokenHash, $result->identity);
        } else {
            $user = $this->identityService->userForLogin($result->identity);
            if ($user === null) {
                return new OAuthCompletion(
                    'link_account',
                    'Tài khoản này chưa được liên kết với EnglishTrain.',
                    false,
                );
            }
        }

        if (!$this->authService->loginAsUser((int) $user->getId())) {
            throw new RuntimeException('Tài khoản không còn hoạt động.');
        }
        try {
            $this->identityService->recordLogin($result->identity);
        } catch (Throwable) {
            // last_login_at chỉ là metadata; không đảo ngược session đã xác thực thành công.
        }

        return new OAuthCompletion('dashboard', 'Bạn đã đăng nhập thành công.', true);
    }
}
