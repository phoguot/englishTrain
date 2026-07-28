<?php

declare(strict_types=1);

namespace Attendance\Model\AttendanceSession;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

/**
 * Toàn bộ SQL bảng `attendance_session`. Xem .claude/rules/02-database.md.
 * Module khác KHÔNG đụng bảng này — chỉ gọi AttendanceService.
 */
class AttendanceSessionMapper
{
    public const TABLE_NAME = 'attendance_session';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function getAttendanceSession(int $id): ?AttendanceSessionModel
    {
        if ($id <= 0) {
            return null;
        }

        $sql    = $this->sql();
        $select = $sql->select(AttendanceSessionMapper::TABLE_NAME);
        $select->where(['id = ?' => $id]);
        $select->limit(1);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row ? (new AttendanceSessionModel())->exchangeArray((array) $row) : null;
    }

    /**
     * Tra buổi học theo (lớp, ngày) — dùng để báo lỗi trùng ngày TRƯỚC khi insert,
     * thay vì để MySQL ném lỗi UNIQUE ra trang trắng (module/Attendance/CLAUDE.md).
     */
    public function getAttendanceSessionByDate(int $classroomId, string $sessionDate): ?AttendanceSessionModel
    {
        $sql    = $this->sql();
        $select = $sql->select(AttendanceSessionMapper::TABLE_NAME);
        $select->where([
            'classroom_id = ?' => $classroomId,
            'session_date = ?' => $sessionDate,
        ]);
        $select->limit(1);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row ? (new AttendanceSessionModel())->exchangeArray((array) $row) : null;
    }

    /**
     * Các buổi học của 1 lớp, mới nhất trước.
     *
     * @return AttendanceSessionModel[]
     */
    public function searchAttendanceSession(int $classroomId): array
    {
        $sql    = $this->sql();
        $select = $sql->select(AttendanceSessionMapper::TABLE_NAME);
        $select->where(['classroom_id = ?' => $classroomId]);
        $select->order('session_date DESC');

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[] = (new AttendanceSessionModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    /**
     * Lấy nhiều buổi theo id trong 1 query — dùng khi dựng lịch sử chuyên cần của 1 học sinh
     * từ danh sách record của em đó (tránh N+1).
     *
     * @param int[] $ids
     * @return array<int, AttendanceSessionModel> map id => session
     */
    public function getAttendanceSessionsByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }

        $sql    = $this->sql();
        $select = $sql->select(AttendanceSessionMapper::TABLE_NAME);
        $select->where->in('id', $ids);
        $select->order('session_date DESC');

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $model                    = (new AttendanceSessionModel())->exchangeArray((array) $row);
            $out[(int) $model->getId()] = $model;
        }

        return $out;
    }

    /**
     * Buổi học của 1 lớp trong một khoảng ngày (dùng cho bảng học phí theo tháng).
     * Lọc ngày **trong SQL**, cũ nhất trước — file Excel đọc theo thứ tự thời gian.
     *
     * @param string $from định dạng Y-m-d, tính cả ngày này
     * @param string $to   định dạng Y-m-d, tính cả ngày này
     * @return AttendanceSessionModel[]
     */
    public function searchAttendanceSessionInRange(int $classroomId, string $from, string $to): array
    {
        $sql    = $this->sql();
        $select = $sql->select(AttendanceSessionMapper::TABLE_NAME);
        $select->where([
            'classroom_id = ?'  => $classroomId,
            'session_date >= ?' => $from,
            'session_date <= ?' => $to,
        ]);
        $select->order('session_date ASC');

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[] = (new AttendanceSessionModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    /** Số buổi học của 1 lớp — dùng để chặn xóa lớp còn dữ liệu lịch sử (docs/04-contracts.md). */
    public function countAttendanceSessionByClassroom(int $classroomId): int
    {
        $sql    = $this->sql();
        $select = $sql->select(AttendanceSessionMapper::TABLE_NAME);
        $select->columns(['cnt' => new Expression('COUNT(*)')]);
        $select->where(['classroom_id = ?' => $classroomId]);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return (int) ($row['cnt'] ?? 0);
    }

    public function deleteAttendanceSession(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $sql    = $this->sql();
        $delete = $sql->delete(AttendanceSessionMapper::TABLE_NAME);
        $delete->where(['id = ?' => $id]);
        $sql->prepareStatementForSqlObject($delete)->execute();
    }

    public function saveAttendanceSession(AttendanceSessionModel $item): AttendanceSessionModel
    {
        $sql  = $this->sql();
        $data = [
            'classroom_id' => $item->getClassroomId(),
            'session_date' => $item->getSessionDate(),
            'shift_label'  => $item->getShiftLabel(),
            'fee_per_session' => $item->getFeePerSession(),
            'note'         => $item->getNote(),
            'created_by'   => $item->getCreatedBy(),
        ];

        if (!$item->getId()) {
            $insert = $sql->insert(AttendanceSessionMapper::TABLE_NAME);
            $insert->values($data);
            $result = $sql->prepareStatementForSqlObject($insert)->execute();
            $item->setId((int) $result->getGeneratedValue());

            return $item;
        }

        $update = $sql->update(AttendanceSessionMapper::TABLE_NAME);
        $update->set($data);
        $update->where(['id = ?' => (int) $item->getId()]);
        $sql->prepareStatementForSqlObject($update)->execute();

        return $item;
    }
}
