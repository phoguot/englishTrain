<?php

declare(strict_types=1);

namespace Assignment\Model\Submission;

use Laminas\Db\Adapter\Adapter;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Sql;

/**
 * Toàn bộ SQL bảng `submission`. Không nộp = KHÔNG có row — không tự tạo row rỗng.
 * Xem module/Assignment/CLAUDE.md và .claude/rules/02-database.md.
 */
class SubmissionMapper
{
    public const TABLE_NAME = 'submission';

    public function __construct(private readonly Adapter $adapter)
    {
    }

    private function sql(): Sql
    {
        return new Sql($this->adapter);
    }

    public function getSubmission(int $id): ?SubmissionModel
    {
        if ($id <= 0) {
            return null;
        }

        $sql    = $this->sql();
        $select = $sql->select(SubmissionMapper::TABLE_NAME);
        $select->where(['id = ?' => $id]);
        $select->limit(1);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row ? (new SubmissionModel())->exchangeArray((array) $row) : null;
    }

    public function getByAssignmentAndStudent(int $assignmentId, int $studentId): ?SubmissionModel
    {
        $sql    = $this->sql();
        $select = $sql->select(SubmissionMapper::TABLE_NAME);
        $select->where([
            'assignment_id = ?' => $assignmentId,
            'student_id = ?'    => $studentId,
        ]);
        $select->limit(1);

        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return $row ? (new SubmissionModel())->exchangeArray((array) $row) : null;
    }

    /** Danh sách bài nộp của 1 assignment (cho teacher chấm). @return SubmissionModel[] */
    public function searchByAssignment(int $assignmentId): array
    {
        $sql    = $this->sql();
        $select = $sql->select(SubmissionMapper::TABLE_NAME);
        $select->where(['assignment_id = ?' => $assignmentId]);
        $select->order('submitted_at ASC');

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $out[] = (new SubmissionModel())->exchangeArray((array) $row);
        }

        return $out;
    }

    /**
     * Đếm bài nộp theo trạng thái cho NHIỀU assignment 1 lần — tránh N+1 ở trang danh sách.
     *
     * @param int[] $assignmentIds
     * @return array<int, array{submitted:int,graded:int,lastSubmittedAt:?string}>
     */
    public function countByAssignmentIds(array $assignmentIds): array
    {
        $assignmentIds = array_values(array_filter(array_map('intval', $assignmentIds), static fn (int $i): bool => $i > 0));
        if ($assignmentIds === []) {
            return [];
        }

        $sql    = $this->sql();
        $select = $sql->select(SubmissionMapper::TABLE_NAME);
        $select->columns([
            'assignment_id',
            'status',
            'cnt' => new Expression('COUNT(*)'),
            'last_submitted_at' => new Expression('MAX(submitted_at)'),
        ]);
        $select->where->in('assignment_id', $assignmentIds);
        $select->group(['assignment_id', 'status']);

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $aid = (int) $row['assignment_id'];
            $out[$aid] ??= ['submitted' => 0, 'graded' => 0, 'lastSubmittedAt' => null];
            $out[$aid][(string) $row['status']] = (int) $row['cnt'];
            if ((string) $row['status'] === SubmissionModel::STATUS_SUBMITTED) {
                $out[$aid]['lastSubmittedAt'] = $row['last_submitted_at'] !== null
                    ? (string) $row['last_submitted_at']
                    : null;
            }
        }

        return $out;
    }

    /**
     * Bài nộp của 1 học sinh cho NHIỀU assignment 1 lần — tránh N+1 ở trang danh sách student.
     *
     * @param int[] $assignmentIds
     * @return array<int, SubmissionModel> map assignment_id => SubmissionModel
     */
    public function getByAssignmentIdsForStudent(array $assignmentIds, int $studentId): array
    {
        $assignmentIds = array_values(array_filter(array_map('intval', $assignmentIds), static fn (int $i): bool => $i > 0));
        if ($assignmentIds === []) {
            return [];
        }

        $sql    = $this->sql();
        $select = $sql->select(SubmissionMapper::TABLE_NAME);
        $select->where->in('assignment_id', $assignmentIds);
        $select->where(['student_id = ?' => $studentId]);

        $result = $sql->prepareStatementForSqlObject($select)->execute();

        $out = [];
        foreach ($result as $row) {
            $model                       = (new SubmissionModel())->exchangeArray((array) $row);
            $out[$model->getAssignmentId()] = $model;
        }

        return $out;
    }

    /**
     * Nộp bài = upsert theo UNIQUE (assignment_id, student_id). Nộp lại là UPDATE, không INSERT thêm.
     *
     * Nộp lại bài ĐÃ CHẤM thì XOÁ điểm cũ (`score`, `feedback`, `graded_at`): điểm đó chấm cho
     * nội dung cũ, giữ lại là dữ liệu sai — form chấm sẽ điền sẵn điểm cũ và `graded_at` trỏ nhầm
     * thời điểm. Bài quay về trạng thái `submitted` chờ chấm lại.
     */
    public function upsertSubmission(SubmissionModel $item): SubmissionModel
    {
        $existing = $this->getByAssignmentAndStudent((int) $item->getAssignmentId(), (int) $item->getStudentId());

        $data = [
            'status'        => $item->getStatus(),
            'essay_text'    => $item->getEssayText(),
            'quiz_answers'  => $item->getQuizAnswers() !== null ? json_encode($item->getQuizAnswers(), JSON_UNESCAPED_UNICODE) : null,
            'video_key'     => $item->getVideoKey(),
            'video_size'    => $item->getVideoSize(),
            'auto_score'    => $item->getAutoScore(),
            'submitted_at'  => $item->getSubmittedAt(),
            'score'         => null,
            'feedback'      => null,
            'graded_at'     => null,
        ];

        $sql = $this->sql();

        if ($existing === null) {
            $data['assignment_id'] = $item->getAssignmentId();
            $data['student_id']    = $item->getStudentId();

            $insert = $sql->insert(SubmissionMapper::TABLE_NAME);
            $insert->values($data);
            $result = $sql->prepareStatementForSqlObject($insert)->execute();
            $item->setId((int) $result->getGeneratedValue());

            return $item;
        }

        $update = $sql->update(SubmissionMapper::TABLE_NAME);
        $update->set($data);
        $update->where(['id = ?' => (int) $existing->getId()]);
        $sql->prepareStatementForSqlObject($update)->execute();
        $item->setId($existing->getId());

        return $item;
    }

    /** Chấm điểm: chỉ sửa score/feedback/status/graded_at — không đụng bài làm gốc. */
    public function updateAttrsGrade(int $submissionId, float $score, ?string $feedback): bool
    {
        $sql    = $this->sql();
        $update = $sql->update(SubmissionMapper::TABLE_NAME);
        $update->set([
            'score'     => $score,
            'feedback'  => $feedback,
            'status'    => SubmissionModel::STATUS_GRADED,
            'graded_at' => date('Y-m-d H:i:s'),
        ]);
        $update->where(['id = ?' => $submissionId]);

        $rows = $sql->prepareStatementForSqlObject($update)->execute();

        return $rows->count() > 0;
    }

    /** @return float[] */
    public function getGradedScores(int $studentId, int $classroomId, ?string $from, ?string $to): array
    {
        $sql = $this->sql();
        $select = $sql->select(['s' => SubmissionMapper::TABLE_NAME]);
        $select->columns(['score']);
        $select->join(
            ['a' => \Assignment\Model\Assignment\AssignmentMapper::TABLE_NAME],
            'a.id = s.assignment_id',
            [],
        );
        $select->where([
            's.student_id = ?' => $studentId,
            's.status = ?' => SubmissionModel::STATUS_GRADED,
            'a.classroom_id = ?' => $classroomId,
        ]);
        $select->where->isNotNull('s.score');
        if ($from !== null && $to !== null) {
            $select->where->between('s.graded_at', $from . ' 00:00:00', $to . ' 23:59:59');
        }

        $scores = [];
        foreach ($sql->prepareStatementForSqlObject($select)->execute() as $row) {
            $scores[] = (float) $row['score'];
        }
        return $scores;
    }

    /**
     * Đếm trạng thái bài của một học sinh trong lớp/kỳ. `missed` chỉ tính bài đã published,
     * có deadline đã qua và không có submission.
     *
     * @return array{submitted:int,graded:int,missed:int}
     */
    public function countStudentStatuses(int $studentId, int $classroomId, ?string $from, ?string $to): array
    {
        $sql = $this->sql();
        $select = $sql->select(['a' => \Assignment\Model\Assignment\AssignmentMapper::TABLE_NAME]);
        $select->columns([
            'submitted' => new Expression("SUM(CASE WHEN s.status = 'submitted' THEN 1 ELSE 0 END)"),
            'graded' => new Expression("SUM(CASE WHEN s.status = 'graded' THEN 1 ELSE 0 END)"),
            'missed' => new Expression("SUM(CASE WHEN a.status = 'published' AND a.deadline_at < NOW() AND s.id IS NULL THEN 1 ELSE 0 END)"),
        ]);
        $select->join(
            ['s' => SubmissionMapper::TABLE_NAME],
            new Expression('s.assignment_id = a.id AND s.student_id = ?', [$studentId]),
            [],
            $select::JOIN_LEFT,
        );
        $select->where(['a.classroom_id = ?' => $classroomId]);
        if ($from !== null && $to !== null) {
            $select->where->between(
                new Expression('COALESCE(a.deadline_at, a.created_at)'),
                $from . ' 00:00:00',
                $to . ' 23:59:59',
            );
        }
        $row = $sql->prepareStatementForSqlObject($select)->execute()->current();

        return [
            'submitted' => (int) ($row['submitted'] ?? 0),
            'graded' => (int) ($row['graded'] ?? 0),
            'missed' => (int) ($row['missed'] ?? 0),
        ];
    }
}
