<?php

declare(strict_types=1);

namespace User\Model\UserRememberToken;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

class UserRememberTokenMapper
{
    public const TABLE_NAME = 'user_remember_token';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function getBySelector(string $selector): ?UserRememberTokenModel
    {
        $sql = $this->sql();
        $select = $sql->select(UserRememberTokenMapper::TABLE_NAME);
        $select->where(['selector = ?' => $selector]);
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row === false ? null : (new UserRememberTokenModel())->exchangeArray((array) $row);
    }

    public function saveUserRememberToken(UserRememberTokenModel $token): UserRememberTokenModel
    {
        $sql = $this->sql();
        $insert = $sql->insert(UserRememberTokenMapper::TABLE_NAME);
        $insert->values([
            'user_id' => $token->getUserId(),
            'selector' => $token->getSelector(),
            'token_hash' => $token->getTokenHash(),
            'expires_at' => $token->getExpiresAt(),
        ]);
        $result = $sql->prepareStatementForSqlObject($insert)->execute();
        $token->setId((int) $result->getGeneratedValue());

        return $token;
    }

    /** Rotate validator sau mỗi lần dùng. Atomic: chỉ update khi token còn hợp lệ tại thời điểm ghi. */
    public function rotateValidator(int $id, string $tokenHash, string $expiresAt): bool
    {
        $sql = $this->sql();
        $update = $sql->update(UserRememberTokenMapper::TABLE_NAME);
        $update->set([
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'last_used_at' => new Expression('NOW()'),
        ]);
        $update->where([
            'id = ?' => $id,
            'revoked_at IS NULL',
            'expires_at > NOW()',
        ]);

        return $sql->prepareStatementForSqlObject($update)->execute()->getAffectedRows() === 1;
    }

    public function revokeBySelector(string $selector): void
    {
        $sql = $this->sql();
        $update = $sql->update(UserRememberTokenMapper::TABLE_NAME);
        $update->set(['revoked_at' => new Expression('NOW()')]);
        $update->where(['selector = ?' => $selector, 'revoked_at IS NULL']);
        $sql->prepareStatementForSqlObject($update)->execute();
    }

    public function revokeAllByUser(int $userId): void
    {
        $sql = $this->sql();
        $update = $sql->update(UserRememberTokenMapper::TABLE_NAME);
        $update->set(['revoked_at' => new Expression('NOW()')]);
        $update->where(['user_id = ?' => $userId, 'revoked_at IS NULL']);
        $sql->prepareStatementForSqlObject($update)->execute();
    }

    public function deleteByUser(int $userId): void
    {
        $sql = $this->sql();
        $delete = $sql->delete(UserRememberTokenMapper::TABLE_NAME);
        $delete->where(['user_id = ?' => $userId]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }
}
