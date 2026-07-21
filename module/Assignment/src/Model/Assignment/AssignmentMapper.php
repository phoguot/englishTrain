<?php

declare(strict_types=1);

namespace Assignment\Model\Assignment;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Sql;

/**
 * Toàn bộ SQL bảng `assignment`. Xem .claude/rules/02-database.md.
 */
class AssignmentMapper
{
    public const TABLE_NAME = 'assignment';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function getAssignment(int $id): ?AssignmentModel
    {
        if ($id <= 0) {
            return null;
        }

        $sql    = $this->sql();
        $select = $sql->select(AssignmentMapper::TABLE_NAME);
        $select->where(['id = ?' => $id]);
        $select->limit(1);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row ? (new AssignmentModel())->exchangeArray((array) $row) : null;
    }

    /**
     * Danh sách bài tập của 1 lớp. $onlyPublished=true dùng cho student
     * (draft KHÔNG được lộ ra, kể cả gõ thẳng URL — lọc ngay trong SQL).
     *
     * @return AssignmentModel[]
     */
    public function searchClassroomAssignment(int $classroomId, bool $onlyPublished): array
    {
        $sql    = $this->sql();
        $select = $sql->select(AssignmentMapper::TABLE_NAME);
        $select->where(['classroom_id = ?' => $classroomId]);
        if ($onlyPublished) {
            $select->where(['status = ?' => AssignmentModel::STATUS_PUBLISHED]);
        }
        $select->order('created_at DESC');

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[] = (new AssignmentModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    /**
     * Lấy bài tập của nhiều lớp trong một query cho dashboard, tránh Application loop từng lớp.
     *
     * @param int[] $classroomIds
     * @return AssignmentModel[]
     */
    public function searchAssignmentsByClassroomIds(array $classroomIds, bool $onlyPublished): array
    {
        $classroomIds = array_values(array_unique(array_filter(
            array_map('intval', $classroomIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($classroomIds === []) {
            return [];
        }

        $sql = $this->sql();
        $select = $sql->select(AssignmentMapper::TABLE_NAME);
        $select->where->in('classroom_id', $classroomIds);
        if ($onlyPublished) {
            $select->where(['status = ?' => AssignmentModel::STATUS_PUBLISHED]);
        }
        $select->order(['deadline_at ASC', 'created_at DESC']);

        $out = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            $out[] = (new AssignmentModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    public function saveAssignment(AssignmentModel $item): AssignmentModel
    {
        $sql  = $this->sql();
        $data = [
            'classroom_id' => $item->getClassroomId(),
            'teacher_id'   => $item->getTeacherId(),
            'title'        => $item->getTitle(),
            'description'  => $item->getDescription(),
            'type'         => $item->getType(),
            'quiz_json'    => $item->getQuizJson() !== null ? json_encode($item->getQuizJson(), JSON_UNESCAPED_UNICODE) : null,
            'deadline_at'  => $item->getDeadlineAt(),
            'status'       => $item->getStatus(),
        ];

        if (!$item->getId()) {
            $insert = $sql->insert(AssignmentMapper::TABLE_NAME);
            $insert->values($data);
            $result = $sql->prepareStatementForSqlObject($insert)->execute();
            $item->setId((int) $result->getGeneratedValue());

            return $item;
        }

        $update = $sql->update(AssignmentMapper::TABLE_NAME);
        $update->set($data);
        $update->where(['id = ?' => (int) $item->getId()]);
        $sql->prepareStatementForSqlObject($update)->execute();

        return $item;
    }

    public function deleteAssignment(int $id): void
    {
        $sql    = $this->sql();
        $delete = $sql->delete(AssignmentMapper::TABLE_NAME);
        $delete->where(['id = ?' => $id]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }
}
