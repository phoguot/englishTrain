<?php

declare(strict_types=1);

namespace User\Service;

use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Laminas\Db\Adapter\Adapter;
use Throwable;
use User\Filter\UserIdentity\LinkCodeFilter;
use User\Model\OAuthLinkThrottle\OAuthLinkThrottleMapper;
use User\Model\User\UserMapper;
use User\Model\UserLinkToken\UserLinkTokenMapper;
use User\Model\UserLinkToken\UserLinkTokenModel;

class UserLinkTokenService
{
    private const WINDOW_SECONDS = 900;
    private const MAX_ATTEMPTS = 10;
    private const TOKEN_LIFETIME_HOURS = 24;

    public function __construct(
        private readonly UserLinkTokenMapper $tokenMapper,
        private readonly OAuthLinkThrottleMapper $throttleMapper,
        private readonly UserMapper $userMapper,
        private readonly Adapter $adapter,
    ) {
    }

    public function create(int $userId, int $adminId): string
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();
        try {
            $this->userMapper->lockUser($userId);
            $user = $this->userMapper->getUser($userId);
            if ($user === null) {
                throw new NotFoundException('Tài khoản không tồn tại.');
            }
            $this->tokenMapper->revokeUnusedByUser($userId);
            $plain = bin2hex(random_bytes(16));
            $token = new UserLinkTokenModel();
            $token->setUserId($userId);
            $token->setTokenHash(hash('sha256', $plain));
            $token->setExpiresAt(date('Y-m-d H:i:s', time() + self::TOKEN_LIFETIME_HOURS * 3600));
            $token->setCreatedBy($adminId);
            $this->tokenMapper->saveUserLinkToken($token);
            $connection->commit();

            return $plain;
        } catch (Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }

    /** @param array<string,mixed> $data @return array{provider:string,token_hash:string} */
    public function prepareLink(array $data, string $ipAddress): array
    {
        $filter = new LinkCodeFilter();
        $filter->setData($data);
        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages($filter->getMessages());
        }
        if (!$this->throttleMapper->recordAttempt(
            hash('sha256', $ipAddress),
            self::WINDOW_SECONDS,
            self::MAX_ATTEMPTS,
        )) {
            throw new ValidationException(['link_code' => 'Bạn đã thử quá nhiều lần. Vui lòng thử lại sau.']);
        }

        $values = $filter->getValues();
        $tokenHash = hash('sha256', (string) $values['link_code']);
        $token = $this->tokenMapper->getByHash($tokenHash);
        if ($token === null || !$token->isUsable()) {
            throw new ValidationException(['link_code' => 'Mã liên kết không hợp lệ hoặc đã hết hạn.']);
        }
        $user = $this->userMapper->getUser((int) $token->getUserId());
        if ($user === null || !$user->isActive()) {
            throw new ValidationException(['link_code' => 'Mã liên kết không hợp lệ hoặc đã hết hạn.']);
        }

        return ['provider' => (string) $values['provider'], 'token_hash' => $tokenHash];
    }
}
