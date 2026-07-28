<?php

declare(strict_types=1);

namespace Classroom\Model\Classroom;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Sql;

/**
 * Toàn bộ SQL bảng `classroom`. Xem .claude/rules/02-database.md.
 */
class ClassroomMapper
{
    public const TABLE_NAME = 'classroom';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function getClassroom(int $id): ?ClassroomModel
    {
        if ($id <= 0) {
            return null;
        }

        $sql    = $this->sql();
        $select = $sql->select(ClassroomMapper::TABLE_NAME);
        $select->where(['id = ?' => $id]);
        $select->limit(1);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row ? (new ClassroomModel())->exchangeArray((array) $row) : null;
    }

    /**
     * Danh sách lớp. $teacherId = null → tất cả (admin); có giá trị → chỉ lớp của giáo viên đó.
     * Lọc quyền NGAY TRONG SQL, không select hết rồi lọc bằng PHP (module/Classroom/CLAUDE.md).
     *
     * @return ClassroomModel[]
     */
    public function searchClassroom(?int $teacherId = null): array
    {
        $sql    = $this->sql();
        $select = $sql->select(ClassroomMapper::TABLE_NAME);
        if ($teacherId !== null) {
            $select->where(['teacher_id = ?' => $teacherId]);
        }
        $select->order('name ASC');

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[] = (new ClassroomModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    /**
     * Lấy nhiều lớp theo id trong 1 query — dùng cho danh sách lớp của học sinh (tránh N+1).
     *
     * @param int[] $ids
     * @return ClassroomModel[]
     */
    public function getClassroomsByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }

        $sql    = $this->sql();
        $select = $sql->select(ClassroomMapper::TABLE_NAME);
        $select->where->in('id', $ids);
        $select->order('name ASC');

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[] = (new ClassroomModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    /**
     * Xóa cứng lớp. **Chỉ gọi từ `ClassroomService::delete()`**, sau khi Application đã xác nhận
     * lớp không còn bài tập / buổi học / report (xem module/Classroom/CLAUDE.md).
     */
    public function deleteClassroom(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $sql    = $this->sql();
        $delete = $sql->delete(ClassroomMapper::TABLE_NAME);
        $delete->where(['id = ?' => $id]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    public function saveClassroom(ClassroomModel $item): ClassroomModel
    {
        $sql  = $this->sql();
        $data = [
            'name'          => $item->getName(),
            'teacher_id'    => $item->getTeacherId(),
            'schedule_note' => $item->getScheduleNote(),
            'fee_per_session' => $item->getFeePerSession(),
            'status'        => $item->getStatus(),
        ];

        if (!$item->getId()) {
            $insert = $sql->insert(ClassroomMapper::TABLE_NAME);
            $insert->values($data);
            $result = $sql->prepareStatementForSqlObject($insert)->execute();
            $item->setId((int) $result->getGeneratedValue());

            return $item;
        }

        $update = $sql->update(ClassroomMapper::TABLE_NAME);
        $update->set($data);
        $update->where(['id = ?' => (int) $item->getId()]);
        $sql->prepareStatementForSqlObject($update)->execute();

        return $item;
    }
}
