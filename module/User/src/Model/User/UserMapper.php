<?php

declare(strict_types=1);

namespace User\Model\User;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Predicate\Like;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

/**
 * Toàn bộ SQL của bảng `user` nằm ở đây. Service gọi Mapper, không tự viết SQL.
 * Nhận/trả UserModel, không trả mảng thô. Xem .claude/rules/02-database.md.
 */
class UserMapper
{
    public const TABLE_NAME = 'user';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function getUser(int $id): ?UserModel
    {
        if ($id <= 0) {
            return null;
        }

        $sql    = $this->sql();
        $select = $sql->select(UserMapper::TABLE_NAME);
        $select->where(['id = ?' => $id]);
        $select->limit(1);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row ? (new UserModel())->exchangeArray((array) $row) : null;
    }

    public function getUserByUsername(string $username): ?UserModel
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        $sql    = $this->sql();
        $select = $sql->select(UserMapper::TABLE_NAME);
        $select->where(['username = ?' => $username]);
        $select->limit(1);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row ? (new UserModel())->exchangeArray((array) $row) : null;
    }

    /** @return UserModel[] */
    public function searchUser(?string $keyword = null): array
    {
        $sql = $this->sql();
        $select = $sql->select(UserMapper::TABLE_NAME);
        if ($keyword !== null && $keyword !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
            $select->where->nest()
                ->addPredicate(new Like('full_name', '%' . $escaped . '%'))
                ->or
                ->addPredicate(new Like('username', '%' . $escaped . '%'))
                ->or
                ->addPredicate(new Like('email', '%' . $escaped . '%'))
                ->unnest();
        }
        $select->order(['status DESC', 'full_name ASC']);

        $out = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            $out[] = (new UserModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    public function usernameExists(string $username, ?int $exceptId = null): bool
    {
        return $this->fieldExists('username', $username, $exceptId);
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        return $this->fieldExists('email', $email, $exceptId);
    }

    public function saveUser(UserModel $item): UserModel
    {
        $data = [
            'role' => $item->getRole(),
            'full_name' => $item->getFullName(),
            'email' => $item->getEmail(),
            'phone' => $item->getPhone(),
            'username' => $item->getUsername(),
            'status' => $item->getStatus(),
        ];
        if ($item->getPasswordHash() !== null && $item->getPasswordHash() !== '') {
            $data['password_hash'] = $item->getPasswordHash();
        }

        $sql = $this->sql();
        if ($item->getId() === null) {
            $insert = $sql->insert(UserMapper::TABLE_NAME);
            $insert->values($data);
            $result = $sql->prepareStatementForSqlObject($insert)->execute();
            $item->setId((int) $result->getGeneratedValue());

            return $item;
        }

        $update = $sql->update(UserMapper::TABLE_NAME);
        $update->set($data);
        $update->where(['id = ?' => (int) $item->getId()]);
        $sql->prepareStatementForSqlObject($update)->execute();

        return $item;
    }

    public function deleteUser(int $id): bool
    {
        $sql = $this->sql();
        $delete = $sql->delete(UserMapper::TABLE_NAME);
        $delete->where(['id = ?' => $id]);

        return $sql->prepareStatementForSqlObject($delete)->execute()->getAffectedRows() > 0;
    }

    /** Khóa row user trong transaction hiện tại để serialize thao tác liên quan. */
    public function lockUser(int $id): void
    {
        $sql = $this->sql();
        $update = $sql->update(UserMapper::TABLE_NAME);
        $update->set(['id' => new Expression('id')]);
        $update->where(['id = ?' => $id]);
        $sql->prepareStatementForSqlObject($update)->execute();
    }

    private function fieldExists(string $field, string $value, ?int $exceptId): bool
    {
        $sql = $this->sql();
        $select = $sql->select(UserMapper::TABLE_NAME);
        $select->columns(['id']);
        $select->where([$field . ' = ?' => $value]);
        if ($exceptId !== null) {
            $select->where->notEqualTo('id', $exceptId);
        }
        $select->limit(1);

        return $sql->prepareStatementForSqlObject($select)->execute()->current() !== false;
    }

    /**
     * Lấy nhiều user một lần bằng WHERE id IN (...) — tránh N+1 ở trang danh sách.
     *
     * @param int[] $ids
     * @return UserModel[] map id => UserModel
     */
    public function getUsersByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }

        $sql    = $this->sql();
        $select = $sql->select(UserMapper::TABLE_NAME);
        $select->where->in('id', $ids);

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $model            = (new UserModel())->exchangeArray((array) $row);
            $out[$model->getId()] = $model;
        }

        return $out;
    }

    /**
     * Lấy user đang hoạt động (status=1) theo role, sắp theo tên — dùng cho dropdown/checkbox
     * (chọn giáo viên phụ trách, liệt kê học sinh để gán vào lớp).
     *
     * @return UserModel[]
     */
    public function getUsersByRole(string $role): array
    {
        $sql    = $this->sql();
        $select = $sql->select(UserMapper::TABLE_NAME);
        $select->where([
            'role = ?'   => $role,
            'status = ?' => 1,
        ]);
        $select->order('full_name ASC');

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[] = (new UserModel())->exchangeArray((array) $row);
        }

        return $out;
    }
}
