<?php

declare(strict_types=1);

namespace Report\Model\StudentReport;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

class StudentReportMapper
{
    public const TABLE_NAME = 'student_report';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function getStudentReport(int $id): ?StudentReportModel
    {
        if ($id <= 0) {
            return null;
        }

        $sql = $this->sql();
        $select = $sql->select(StudentReportMapper::TABLE_NAME);
        $select->where(['id = ?' => $id]);
        $select->limit(1);
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row ? (new StudentReportModel())->exchangeArray((array) $row) : null;
    }

    /** @return StudentReportModel[] */
    public function searchTeacherReport(int $classroomId, int $teacherId): array
    {
        $sql = $this->sql();
        $select = $sql->select(StudentReportMapper::TABLE_NAME);
        $select->where(['classroom_id = ?' => $classroomId]);
        $select->where->nest()
            ->equalTo('teacher_id', $teacherId)
            ->or
            ->equalTo('status', StudentReportModel::STATUS_PUBLISHED)
            ->unnest();
        $select->order(['period_label DESC', 'created_at DESC']);

        return $this->hydrateAll($sql->prepareStatementForSqlObject($select)->execute());
    }

    /** @return StudentReportModel[] */
    public function searchPublishedForStudent(int $studentId): array
    {
        $sql = $this->sql();
        $select = $sql->select(StudentReportMapper::TABLE_NAME);
        $select->where(['student_id = ?' => $studentId, 'status = ?' => StudentReportModel::STATUS_PUBLISHED]);
        $select->order(['period_label DESC', 'published_at DESC']);

        return $this->hydrateAll($sql->prepareStatementForSqlObject($select)->execute());
    }

    public function findDuplicate(int $studentId, int $classroomId, string $periodLabel, ?int $exceptId = null): ?StudentReportModel
    {
        $sql = $this->sql();
        $select = $sql->select(StudentReportMapper::TABLE_NAME);
        $select->where([
            'student_id = ?' => $studentId,
            'classroom_id = ?' => $classroomId,
            'period_label = ?' => $periodLabel,
        ]);
        if ($exceptId !== null) {
            $select->where(['id != ?' => $exceptId]);
        }
        $select->limit(1);
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row ? (new StudentReportModel())->exchangeArray((array) $row) : null;
    }

    /** Số report của 1 lớp — Application hỏi trước khi cho xóa lớp (docs/04-contracts.md). */
    public function countStudentReportByClassroom(int $classroomId): int
    {
        $sql    = $this->sql();
        $select = $sql->select(StudentReportMapper::TABLE_NAME);
        $select->columns(['cnt' => new Expression('COUNT(*)')]);
        $select->where(['classroom_id = ?' => $classroomId]);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return (int) ($row['cnt'] ?? 0);
    }

    public function saveStudentReport(StudentReportModel $item): StudentReportModel
    {
        $sql = $this->sql();
        $data = [
            'student_id' => $item->getStudentId(),
            'classroom_id' => $item->getClassroomId(),
            'teacher_id' => $item->getTeacherId(),
            'period_label' => $item->getPeriodLabel(),
            'content' => $item->getContent(),
        ];

        if ($item->getId()) {
            throw new \LogicException('Dùng updateAttrsStudentReport() để sửa report đã tồn tại.');
        }
        $data['status'] = StudentReportModel::STATUS_DRAFT;
        $insert = $sql->insert(StudentReportMapper::TABLE_NAME);
        $insert->values($data);
        $result = $sql->prepareStatementForSqlObject($insert)->execute();
        $item->setId((int) $result->getGeneratedValue());
        return $item;
    }

    public function updateAttrsStudentReport(int $id, string $periodLabel, string $content): bool
    {
        $sql = $this->sql();
        $update = $sql->update(StudentReportMapper::TABLE_NAME);
        $update->set(['period_label' => $periodLabel, 'content' => $content]);
        $update->where(['id = ?' => $id, 'status = ?' => StudentReportModel::STATUS_DRAFT]);
        if ($sql->prepareStatementForSqlObject($update)->execute()->count() > 0) {
            return true;
        }

        // MySQL trả 0 khi dữ liệu mới giống hệt dữ liệu cũ. Đọc lại để phân biệt no-op hợp lệ
        // với race mà report vừa được publish giữa lúc mở form và lúc bấm lưu.
        $current = $this->getStudentReport($id);
        return $current !== null
            && !$current->isPublished()
            && $current->getPeriodLabel() === $periodLabel
            && $current->getContent() === $content;
    }

    public function publishStudentReport(int $id): bool
    {
        $sql = $this->sql();
        $update = $sql->update(StudentReportMapper::TABLE_NAME);
        $update->set(['status' => StudentReportModel::STATUS_PUBLISHED, 'published_at' => date('Y-m-d H:i:s')]);
        $update->where(['id = ?' => $id, 'status = ?' => StudentReportModel::STATUS_DRAFT]);
        return $sql->prepareStatementForSqlObject($update)->execute()->count() > 0;
    }

    /** @return StudentReportModel[] */
    private function hydrateAll(iterable $result): array
    {
        $out = [];
        foreach ($result as $row) {
            $out[] = (new StudentReportModel())->exchangeArray((array) $row);
        }
        return $out;
    }
}
