<?php

declare(strict_types=1);

namespace User\Service;

use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Laminas\Db\Adapter\Adapter;
use Throwable;
use User\Filter\UserIdentity\UnlinkFilter;
use User\Model\User\UserMapper;
use User\Model\User\UserModel;
use User\Model\UserIdentity\UserIdentityMapper;
use User\Model\UserIdentity\UserIdentityModel;
use User\Model\UserLinkToken\UserLinkTokenMapper;
use User\OAuth\OAuthIdentity;

class UserIdentityService
{
    public function __construct(
        private readonly Adapter $adapter,
        private readonly UserIdentityMapper $identityMapper,
        private readonly UserLinkTokenMapper $tokenMapper,
        private readonly UserMapper $userMapper,
    ) {
    }

    /** @return UserIdentityModel[] */
    public function listForUser(int $userId): array
    {
        if ($this->userMapper->getUser($userId) === null) {
            throw new NotFoundException('Tài khoản không tồn tại.');
        }

        return $this->identityMapper->searchByUser($userId);
    }

    public function userForLogin(OAuthIdentity $external): ?UserModel
    {
        $identity = $this->identityMapper->getByProviderSubject(
            $external->provider,
            $external->providerUserId,
        );
        if ($identity === null) {
            return null;
        }
        $user = $this->userMapper->getUser((int) $identity->getUserId());
        if ($user === null || !$user->isActive()) {
            return null;
        }
        return $user;
    }

    public function linkWithToken(string $tokenHash, OAuthIdentity $external): UserModel
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();
        try {
            $token = $this->tokenMapper->getByHash($tokenHash);
            if ($token === null || !$token->isUsable()) {
                throw $this->invalidLinkCode();
            }
            $user = $this->userMapper->getUser((int) $token->getUserId());
            if ($user === null || !$user->isActive()) {
                throw $this->invalidLinkCode();
            }
            $this->assertIdentityAvailable((int) $user->getId(), $external);
            if (!$this->tokenMapper->consumeAtomically((int) $token->getId())) {
                throw $this->invalidLinkCode();
            }
            $this->identityMapper->saveUserIdentity($this->newIdentity((int) $user->getId(), $external));
            $connection->commit();

            return $user;
        } catch (Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }

    public function linkCurrent(int $userId, OAuthIdentity $external): void
    {
        $user = $this->userMapper->getUser($userId);
        if ($user === null || !$user->isActive()) {
            throw new ValidationException(['provider' => 'Phiên đăng nhập không còn hợp lệ.']);
        }
        $this->assertIdentityAvailable($userId, $external);
        $this->identityMapper->saveUserIdentity($this->newIdentity($userId, $external));
    }

    /** @param array<string,mixed> $data */
    public function unlink(int $userId, array $data): void
    {
        $filter = new UnlinkFilter();
        $filter->setData($data);
        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages($filter->getMessages());
        }
        if (!$this->identityMapper->deleteByUserProvider($userId, (string) $filter->getValue('provider'))) {
            throw new NotFoundException('Liên kết đăng nhập không tồn tại.');
        }
    }

    public function deleteAllForUser(int $userId): void
    {
        $this->identityMapper->deleteByUser($userId);
        $this->tokenMapper->deleteByUser($userId);
        $this->tokenMapper->deleteByCreator($userId);
    }

    public function recordLogin(OAuthIdentity $external): void
    {
        $identity = $this->identityMapper->getByProviderSubject(
            $external->provider,
            $external->providerUserId,
        );
        if ($identity !== null) {
            $this->identityMapper->touchLastLogin((int) $identity->getId());
        }
    }

    private function assertIdentityAvailable(int $userId, OAuthIdentity $external): void
    {
        if ($this->identityMapper->getByUserProvider($userId, $external->provider) !== null) {
            throw new ValidationException(['provider' => 'Tài khoản đã liên kết nhà cung cấp này.']);
        }
        if ($this->identityMapper->getByProviderSubject($external->provider, $external->providerUserId) !== null) {
            throw new ValidationException(['provider' => 'Danh tính này đã thuộc tài khoản khác. Vui lòng liên hệ quản trị viên.']);
        }
    }

    private function newIdentity(int $userId, OAuthIdentity $external): UserIdentityModel
    {
        $identity = new UserIdentityModel();
        $identity->setUserId($userId);
        $identity->setProvider($external->provider);
        $identity->setProviderUserId($external->providerUserId);
        $identity->setProviderEmail($external->email);

        return $identity;
    }

    private function invalidLinkCode(): ValidationException
    {
        return new ValidationException(['link_code' => 'Mã liên kết không hợp lệ hoặc đã hết hạn.']);
    }
}
