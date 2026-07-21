<?php

declare(strict_types=1);

namespace Application\Model\SystemLog;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Sql;

/**
 * Toàn bộ SQL bảng `system_log`. Chỉ SystemLogService gọi — module nghiệp vụ không đụng bảng này.
 * Xem .claude/rules/02-database.md.
 */
class SystemLogMapper
{
    public const TABLE_NAME = 'system_log';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function saveSystemLog(SystemLogModel $item): SystemLogModel
    {
        $sql    = $this->sql();
        $insert = $sql->insert(SystemLogMapper::TABLE_NAME);
        $insert->values([
            'level'           => $item->getLevel(),
            'message'         => $item->getMessage(),
            'exception_class' => $item->getExceptionClass(),
            'file'            => $item->getFile(),
            'line'            => $item->getLine(),
            'stack_trace'     => $item->getStackTrace(),
            'request_method'  => $item->getRequestMethod(),
            'request_uri'     => $item->getRequestUri(),
            'user_id'         => $item->getUserId(),
            'ip'              => $item->getIp(),
        ]);

        $result = $sql->prepareStatementForSqlObject($insert)->execute();
        $item->setId((int) $result->getGeneratedValue());

        return $item;
    }

    /**
     * Danh sách log mới nhất trước — cho nhu cầu tra cứu khi chẩn đoán sự cố.
     *
     * @return SystemLogModel[]
     */
    public function searchSystemLog(?string $level = null, int $limit = 100): array
    {
        $sql    = $this->sql();
        $select = $sql->select(SystemLogMapper::TABLE_NAME);
        if ($level !== null) {
            $select->where(['level = ?' => $level]);
        }
        $select->order('created_at DESC');
        $select->limit(max(1, $limit));

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[] = (new SystemLogModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    /** Xóa một dòng log theo id. */
    public function deleteSystemLog(int $id): void
    {
        $sql    = $this->sql();
        $delete = $sql->delete(SystemLogMapper::TABLE_NAME);
        $delete->where(['id = ?' => $id]);

        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    /**
     * Xóa nhiều dòng log cùng lúc. Trả số dòng thực xóa.
     *
     * @param int[] $ids
     */
    public function deleteManySystemLog(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $sql    = $this->sql();
        $delete = $sql->delete(SystemLogMapper::TABLE_NAME);
        $delete->where->in('id', $ids);

        $result = $sql->prepareStatementForSqlObject($delete)->execute();

        return $result->getAffectedRows();
    }
}
