<?php

declare(strict_types=1);

namespace Classroom\Model\ClassroomStudent;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Sql;

/**
 * Toàn bộ SQL bảng `classroom_student` (n-n lớp ↔ học sinh).
 * Module khác KHÔNG đụng bảng này — chỉ gọi ClassroomService::studentIds().
 */
class ClassroomStudentMapper
{
    public const TABLE_NAME = 'classroom_student';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    /** @return int[] id học sinh trong 1 lớp */
    public function getStudentIds(int $classroomId): array
    {
        $sql    = $this->sql();
        $select = $sql->select(ClassroomStudentMapper::TABLE_NAME);
        $select->columns(['student_id']);
        $select->where(['classroom_id = ?' => $classroomId]);

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $ids = [];
        foreach ($result as $row) {
            $ids[] = (int) $row['student_id'];
        }

        return $ids;
    }

    /** @return int[] id các lớp mà 1 học sinh đang thuộc về */
    public function getClassroomIdsForStudent(int $studentId): array
    {
        $sql    = $this->sql();
        $select = $sql->select(ClassroomStudentMapper::TABLE_NAME);
        $select->columns(['classroom_id']);
        $select->where(['student_id = ?' => $studentId]);

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $ids = [];
        foreach ($result as $row) {
            $ids[] = (int) $row['classroom_id'];
        }

        return $ids;
    }

    public function isStudentIn(int $studentId, int $classroomId): bool
    {
        $sql    = $this->sql();
        $select = $sql->select(ClassroomStudentMapper::TABLE_NAME);
        $select->columns(['student_id']);
        $select->where([
            'classroom_id = ?' => $classroomId,
            'student_id = ?'   => $studentId,
        ]);
        $select->limit(1);

        // current() trả false (không phải null) khi rỗng → dùng truthiness, không so !== null.
        return (bool) $sql->prepareStatementForSqlObject($select)->execute()->current();
    }

    /**
     * Đếm số học sinh cho NHIỀU lớp một lần (1 query) — tránh N+1 ở trang danh sách lớp.
     *
     * @param int[] $classroomIds
     * @return array<int,int> map classroom_id => số học sinh
     */
    public function countByClassroomIds(array $classroomIds): array
    {
        $classroomIds = array_values(array_filter(array_map('intval', $classroomIds), static fn (int $i): bool => $i > 0));
        if ($classroomIds === []) {
            return [];
        }

        $sql    = $this->sql();
        $select = $sql->select(ClassroomStudentMapper::TABLE_NAME);
        $select->columns([
            'classroom_id',
            'cnt' => new \Laminas\Db\Sql\Expression('COUNT(*)'),
        ]);
        $select->where->in('classroom_id', $classroomIds);
        $select->group('classroom_id');

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[(int) $row['classroom_id']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * Đồng bộ danh sách học sinh của 1 lớp về đúng $studentIds, trong 1 transaction.
     * Chỉ xóa/thêm phần chênh lệch — học sinh giữ nguyên KHÔNG bị reset joined_at.
     * Gỡ học sinh = xóa row ở đây; KHÔNG đụng submission/attendance/report của em đó.
     *
     * @param int[] $studentIds
     */
    public function syncStudents(int $classroomId, array $studentIds): void
    {
        $target  = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn (int $i): bool => $i > 0)));
        $current = $this->getStudentIds($classroomId);

        $toAdd    = array_values(array_diff($target, $current));
        $toRemove = array_values(array_diff($current, $target));

        if ($toAdd === [] && $toRemove === []) {
            return;
        }

        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();
        try {
            if ($toRemove !== []) {
                $sql    = $this->sql();
                $delete = $sql->delete(ClassroomStudentMapper::TABLE_NAME);
                $delete->where(['classroom_id = ?' => $classroomId]);
                $delete->where->in('student_id', $toRemove);
                $sql->prepareStatementForSqlObject($delete)->execute();
            }

            if ($toAdd !== []) {
                $today = date('Y-m-d');
                foreach ($toAdd as $studentId) {
                    $sql    = $this->sql();
                    $insert = $sql->insert(ClassroomStudentMapper::TABLE_NAME);
                    $insert->values([
                        'classroom_id' => $classroomId,
                        'student_id'   => $studentId,
                        'joined_at'    => $today,
                    ]);
                    $sql->prepareStatementForSqlObject($insert)->execute();
                }
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }
}
