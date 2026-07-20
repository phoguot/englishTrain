<?php

declare(strict_types=1);

namespace Attendance\Model\AttendanceSessionStudent;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Sql;

/** SQL cho roster bất biến được chụp tại lúc tạo buổi học. */
class AttendanceSessionStudentMapper
{
    public const TABLE_NAME = 'attendance_session_student';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    /** @param int[] $studentIds */
    public function saveRoster(int $sessionId, array $studentIds): void
    {
        foreach (array_values(array_unique(array_map('intval', $studentIds))) as $studentId) {
            if ($studentId <= 0) {
                continue;
            }
            $sql = new Sql($this->adapter);
            $insert = $sql->insert(AttendanceSessionStudentMapper::TABLE_NAME);
            $insert->values(['session_id' => $sessionId, 'student_id' => $studentId]);
            $sql->prepareStatementForSqlObject($insert)->execute();
        }
    }

    /** @return int[] */
    public function getStudentIds(int $sessionId): array
    {
        $sql = new Sql($this->adapter);
        $select = $sql->select(AttendanceSessionStudentMapper::TABLE_NAME);
        $select->columns(['student_id']);
        $select->where(['session_id = ?' => $sessionId]);

        $ids = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            $ids[] = (int) $row['student_id'];
        }

        return $ids;
    }

    /** @param int[] $sessionIds @return array<int,int> map session_id => sĩ số snapshot */
    public function countBySessionIds(array $sessionIds): array
    {
        $sessionIds = array_values(array_unique(array_filter(
            array_map('intval', $sessionIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($sessionIds === []) {
            return [];
        }

        $sql = new Sql($this->adapter);
        $select = $sql->select(AttendanceSessionStudentMapper::TABLE_NAME);
        $select->columns([
            'session_id',
            'cnt' => new \Laminas\Db\Sql\Expression('COUNT(*)'),
        ]);
        $select->where->in('session_id', $sessionIds);
        $select->group('session_id');

        $counts = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            $counts[(int) $row['session_id']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
