<?php

declare(strict_types=1);

namespace User\Model\UserLoginToken;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

class UserLoginTokenMapper
{
    public const TABLE_NAME = 'user_login_token';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function getByHash(string $tokenHash): ?UserLoginTokenModel
    {
        $sql = $this->sql();
        $select = $sql->select(UserLoginTokenMapper::TABLE_NAME);
        $select->where(['token_hash = ?' => $tokenHash]);
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row === false ? null : (new UserLoginTokenModel())->exchangeArray((array) $row);
    }

    public function saveUserLoginToken(UserLoginTokenModel $token): UserLoginTokenModel
    {
        $sql = $this->sql();
        $insert = $sql->insert(UserLoginTokenMapper::TABLE_NAME);
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

    /** Tạo link mới cho user thì mã cũ chưa dùng của user đó coi như bị thu hồi. */
    public function revokeUnusedByUser(int $userId): void
    {
        $sql = $this->sql();
        $update = $sql->update(UserLoginTokenMapper::TABLE_NAME);
        $update->set(['used_at' => new Expression('NOW()')]);
        $update->where(['user_id = ?' => $userId, 'used_at IS NULL']);
        $sql->prepareStatementForSqlObject($update)->execute();
    }

    /** Atomic: chỉ đánh dấu used khi còn hợp lệ tại đúng thời điểm ghi — chống race. */
    public function consumeAtomically(int $id): bool
    {
        $sql = $this->sql();
        $update = $sql->update(UserLoginTokenMapper::TABLE_NAME);
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
        $delete = $sql->delete(UserLoginTokenMapper::TABLE_NAME);
        $delete->where(['user_id = ?' => $userId]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    public function deleteByCreator(int $creatorId): void
    {
        $sql = $this->sql();
        $delete = $sql->delete(UserLoginTokenMapper::TABLE_NAME);
        $delete->where(['created_by = ?' => $creatorId]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }
}
