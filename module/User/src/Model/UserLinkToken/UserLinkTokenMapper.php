<?php

declare(strict_types=1);

namespace User\Model\UserLinkToken;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

class UserLinkTokenMapper
{
    public const TABLE_NAME = 'user_link_token';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function getByHash(string $tokenHash): ?UserLinkTokenModel
    {
        $sql = $this->sql();
        $select = $sql->select(UserLinkTokenMapper::TABLE_NAME);
        $select->where(['token_hash = ?' => $tokenHash]);
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row === false ? null : (new UserLinkTokenModel())->exchangeArray((array) $row);
    }

    public function saveUserLinkToken(UserLinkTokenModel $token): UserLinkTokenModel
    {
        $sql = $this->sql();
        $insert = $sql->insert(UserLinkTokenMapper::TABLE_NAME);
        $insert->values([
            'user_id' => $token->getUserId(),
            'token_hash' => $token->getTokenHash(),
            'expires_at' => $token->getExpiresAt(),
            'created_by' => $token->getCreatedBy(),
        ]);
        $result = $sql->prepareStatementForSqlObject($insert)->execute();
        $token->setId((int) $result->getGeneratedValue());

        return $token;
    }

    public function revokeUnusedByUser(int $userId): void
    {
        $sql = $this->sql();
        $update = $sql->update(UserLinkTokenMapper::TABLE_NAME);
        $update->set(['used_at' => new Expression('NOW()')]);
        $update->where(['user_id = ?' => $userId, 'used_at IS NULL']);
        $sql->prepareStatementForSqlObject($update)->execute();
    }

    public function consumeAtomically(int $id): bool
    {
        $sql = $this->sql();
        $update = $sql->update(UserLinkTokenMapper::TABLE_NAME);
        $update->set(['used_at' => new Expression('NOW()')]);
        $update->where([
            'id = ?' => $id,
            'used_at IS NULL',
            'expires_at > NOW()',
        ]);

        return $sql->prepareStatementForSqlObject($update)->execute()->getAffectedRows() === 1;
    }

    public function deleteByUser(int $userId): void
    {
        $sql = $this->sql();
        $delete = $sql->delete(UserLinkTokenMapper::TABLE_NAME);
        $delete->where(['user_id = ?' => $userId]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    public function deleteByCreator(int $creatorId): void
    {
        $sql = $this->sql();
        $delete = $sql->delete(UserLinkTokenMapper::TABLE_NAME);
        $delete->where(['created_by = ?' => $creatorId]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }
}
