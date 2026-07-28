<?php

declare(strict_types=1);

namespace Attendance\Model\AttendanceRecord;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

/**
 * Toàn bộ SQL bảng `attendance_record`. Xem .claude/rules/02-database.md.
 * Module khác KHÔNG đụng bảng này — chỉ gọi AttendanceService.
 */
class AttendanceRecordMapper
{
    public const TABLE_NAME = 'attendance_record';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    /**
     * Record của 1 buổi, đánh khóa theo student_id để view tra thẳng.
     *
     * @return array<int, AttendanceRecordModel> map student_id => record
     */
    public function getRecordsBySession(int $sessionId): array
    {
        $sql    = $this->sql();
        $select = $sql->select(AttendanceRecordMapper::TABLE_NAME);
        $select->where(['session_id = ?' => $sessionId]);

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $model                            = (new AttendanceRecordModel())->exchangeArray((array) $row);
            $out[(int) $model->getStudentId()] = $model;
        }

        return $out;
    }

    /**
     * Record của NHIỀU buổi trong 1 query — dùng cho bảng học phí cả tháng, tránh N+1.
     *
     * @param int[] $sessionIds
     * @return array<int, array<int, AttendanceRecordModel>> map session_id => [student_id => record]
     */
    public function getRecordsBySessionIds(array $sessionIds): array
    {
        $sessionIds = array_values(array_filter(array_map('intval', $sessionIds), static fn (int $i): bool => $i > 0));
        if ($sessionIds === []) {
            return [];
        }

        $sql    = $this->sql();
        $select = $sql->select(AttendanceRecordMapper::TABLE_NAME);
        $select->where->in('session_id', $sessionIds);

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $model = (new AttendanceRecordModel())->exchangeArray((array) $row);
            $out[(int) $model->getSessionId()][(int) $model->getStudentId()] = $model;
        }

        return $out;
    }

    /**
     * Toàn bộ record của 1 học sinh (mọi lớp, mọi buổi). Service lọc theo lớp/kỳ bằng
     * danh sách session lấy kèm — 2 query, không N+1, và mỗi mapper vẫn chỉ đụng bảng của mình.
     *
     * @return AttendanceRecordModel[]
     */
    public function searchStudentRecord(int $studentId): array
    {
        $sql    = $this->sql();
        $select = $sql->select(AttendanceRecordMapper::TABLE_NAME);
        $select->where(['student_id = ?' => $studentId]);

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[] = (new AttendanceRecordModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    /**
     * Đếm record theo trạng thái cho NHIỀU buổi một lần (1 query) — tránh N+1 ở
     * trang danh sách buổi học.
     *
     * @param int[] $sessionIds
     * @return array<int, array<string,int>> map session_id => [status => số lượng]
     */
    public function countBySessionIds(array $sessionIds): array
    {
        $sessionIds = array_values(array_filter(array_map('intval', $sessionIds), static fn (int $i): bool => $i > 0));
        if ($sessionIds === []) {
            return [];
        }

        $sql    = $this->sql();
        $select = $sql->select(AttendanceRecordMapper::TABLE_NAME);
        $select->columns([
            'session_id',
            'status',
            'cnt' => new Expression('COUNT(*)'),
        ]);
        $select->where->in('session_id', $sessionIds);
        $select->group(['session_id', 'status']);

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[(int) $row['session_id']][(string) $row['status']] = (int) $row['cnt'];
        }

        return $out;
    }

    /**
     * Lưu CẢ BẢNG điểm danh của 1 buổi trong MỘT transaction (module/Attendance/CLAUDE.md):
     * n học sinh cùng thành công hoặc cùng hỏng, không lưu từng dòng rời rạc.
     *
     * Upsert theo UNIQUE (session_id, student_id): điểm danh lại là SỬA dòng cũ, không thêm dòng.
     * Học sinh không có trong $rows thì record cũ (nếu có) giữ nguyên — Service quyết định
     * gửi xuống những ai, mapper không tự xóa.
     *
     * @param array<int, array{status:string, note:?string}> $rows map student_id => giá trị
     */
    public function saveRecordsForSession(int $sessionId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $existing = $this->getRecordsBySession($sessionId);

        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($rows as $studentId => $row) {
                $studentId = (int) $studentId;
                $data      = [
                    'status' => $row['status'],
                    'note'   => ($row['note'] ?? '') !== '' ? $row['note'] : null,
                ];

                $sql = $this->sql();
                if (isset($existing[$studentId])) {
                    $update = $sql->update(AttendanceRecordMapper::TABLE_NAME);
                    $update->set($data);
                    $update->where(['id = ?' => (int) $existing[$studentId]->getId()]);
                    $sql->prepareStatementForSqlObject($update)->execute();

                    continue;
                }

                $insert = $sql->insert(AttendanceRecordMapper::TABLE_NAME);
                $insert->values($data + [
                    'session_id' => $sessionId,
                    'student_id' => $studentId,
                ]);
                $sql->prepareStatementForSqlObject($insert)->execute();
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }
}
