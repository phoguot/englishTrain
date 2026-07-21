<?php

declare(strict_types=1);

namespace User\Model\UserIdentity;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

class UserIdentityMapper
{
    public const TABLE_NAME = 'user_identity';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function getByProviderSubject(string $provider, string $providerUserId): ?UserIdentityModel
    {
        $sql = $this->sql();
        $select = $sql->select(UserIdentityMapper::TABLE_NAME);
        $select->where([
            'provider = ?' => $provider,
            'provider_user_id = ?' => $providerUserId,
        ]);
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row === false ? null : (new UserIdentityModel())->exchangeArray((array) $row);
    }

    public function getByUserProvider(int $userId, string $provider): ?UserIdentityModel
    {
        $sql = $this->sql();
        $select = $sql->select(UserIdentityMapper::TABLE_NAME);
        $select->where(['user_id = ?' => $userId, 'provider = ?' => $provider]);
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row === false ? null : (new UserIdentityModel())->exchangeArray((array) $row);
    }

    /** @return UserIdentityModel[] */
    public function searchByUser(int $userId): array
    {
        $sql = $this->sql();
        $select = $sql->select(UserIdentityMapper::TABLE_NAME);
        $select->where(['user_id = ?' => $userId]);
        $select->order('provider ASC');

        $out = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            $out[] = (new UserIdentityModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    public function saveUserIdentity(UserIdentityModel $identity): UserIdentityModel
    {
        $sql = $this->sql();
        $insert = $sql->insert(UserIdentityMapper::TABLE_NAME);
        $insert->values([
            'user_id' => $identity->getUserId(),
            'provider' => $identity->getProvider(),
            'provider_user_id' => $identity->getProviderUserId(),
            'provider_email' => $identity->getProviderEmail(),
        ]);
        $result = $sql->prepareStatementForSqlObject($insert)->execute();
        $identity->setId((int) $result->getGeneratedValue());

        return $identity;
    }

    public function touchLastLogin(int $id): void
    {
        $sql = $this->sql();
        $update = $sql->update(UserIdentityMapper::TABLE_NAME);
        $update->set(['last_login_at' => new Expression('NOW()')]);
        $update->where(['id = ?' => $id]);
        $sql->prepareStatementForSqlObject($update)->execute();
    }

    public function deleteByUserProvider(int $userId, string $provider): bool
    {
        $sql = $this->sql();
        $delete = $sql->delete(UserIdentityMapper::TABLE_NAME);
        $delete->where(['user_id = ?' => $userId, 'provider = ?' => $provider]);

        return $sql->prepareStatementForSqlObject($delete)->execute()->getAffectedRows() > 0;
    }

    public function deleteByUser(int $userId): void
    {
        $sql = $this->sql();
        $delete = $sql->delete(UserIdentityMapper::TABLE_NAME);
        $delete->where(['user_id = ?' => $userId]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }
}
