<?php

declare(strict_types=1);

namespace Attendance\Service;

use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Attendance\Filter\AttendanceSession\SessionSaveFilter;
use Attendance\Model\AttendanceRecord\AttendanceRecordMapper;
use Attendance\Model\AttendanceRecord\AttendanceRecordModel;
use Attendance\Model\AttendanceSession\AttendanceSessionMapper;
use Attendance\Model\AttendanceSession\AttendanceSessionModel;
use Attendance\Model\AttendanceSessionStudent\AttendanceSessionStudentMapper;
use Attendance\Model\AttendanceSummary;
use Classroom\Service\ClassroomService;
use Laminas\Db\Adapter\Adapter;
use User\Service\UserService;

/**
 * Nghiệp vụ buổi học + điểm danh + thống kê chuyên cần.
 * `summary()` là HỢP ĐỒNG (docs/04-contracts.md) — đổi chữ ký là breaking change với Report.
 *
 * - Kiểm GIÁ TRỊ đầu vào: SessionSaveFilter + AttendanceSheetBuilder tại đây (ném ValidationException).
 * - Kiểm QUYỀN sở hữu: isTeacherOf/isStudentIn (ném AccessDeniedException).
 * Controller không làm cả hai việc trên. Xem module/Attendance/CLAUDE.md.
 */
class AttendanceService
{
    private const ROLE_ADMIN   = 'admin';
    private const ROLE_TEACHER = 'teacher';
    private const ROLE_STUDENT = 'student';

    public function __construct(
        private readonly AttendanceSessionMapper $sessionMapper,
        private readonly AttendanceRecordMapper $recordMapper,
        private readonly AttendanceSessionStudentMapper $rosterMapper,
        private readonly ClassroomService $classroomService,
        private readonly UserService $userService,
        private readonly AttendanceSheetBuilder $sheetBuilder,
        private readonly Adapter $adapter,
    ) {
    }

    // ── Hợp đồng liên module ────────────────────────────────────────────────

    /**
     * Thống kê chuyên cần 1 học sinh trong 1 lớp, 1 kỳ. Report gọi cái này, không đụng bảng.
     *
     * Mẫu số chỉ gồm buổi mà học sinh CÓ record — em vào lớp giữa kỳ thì các buổi trước đó
     * là THIẾU record, không phải vắng (module/Attendance/CLAUDE.md).
     * Công thức nằm trong AttendanceSummary::fromCounts(), không tính lại ở đây hay ở view.
     *
     * @param string $periodLabel `2026-07` (tháng) hoặc `2026` (năm); chuỗi khác → tính TẤT CẢ buổi
     */
    public function summary(int $studentId, int $classroomId, string $periodLabel): AttendanceSummary
    {
        $sessions = $this->sessionsOfStudent($studentId, $classroomId, $periodLabel);

        $counts = [];
        foreach ($sessions as $entry) {
            $status          = (string) $entry['record']->getStatus();
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return AttendanceSummary::fromCounts($counts);
    }

    // ── Dùng nội bộ cho controller của module Attendance ────────────────────

    /**
     * Danh sách buổi học của 1 lớp kèm số liệu từng trạng thái.
     * admin xem mọi lớp; teacher chỉ lớp mình phụ trách.
     *
     * @return array{classroom: \Classroom\Model\Classroom\ClassroomDto, rows: array<int, array<string,mixed>>}
     */
    public function listSessionRows(int $classroomId, int $userId, string $role): array
    {
        $classroom = $this->assertCanViewClassroom($classroomId, $userId, $role);

        $sessions = $this->sessionMapper->searchAttendanceSession($classroomId);
        if ($sessions === []) {
            return ['classroom' => $classroom, 'rows' => []];
        }

        $sessionIds = array_map(static fn (AttendanceSessionModel $s): int => (int) $s->getId(), $sessions);
        $counts     = $this->recordMapper->countBySessionIds($sessionIds);
        $rosterCounts = $this->rosterMapper->countBySessionIds($sessionIds);

        $rows = [];
        foreach ($sessions as $session) {
            $id = (int) $session->getId();
            $c  = $counts[$id] ?? [];

            $rows[] = [
                'id'            => $id,
                'sessionDate'   => $session->getSessionDateForHuman(),
                'note'          => $session->getNote(),
                'present'       => $c[AttendanceRecordModel::STATUS_PRESENT] ?? 0,
                'late'          => $c[AttendanceRecordModel::STATUS_LATE] ?? 0,
                'absent'        => $c[AttendanceRecordModel::STATUS_ABSENT] ?? 0,
                'excused'       => $c[AttendanceRecordModel::STATUS_EXCUSED] ?? 0,
                'markedCount'   => array_sum($c),
                'totalStudents' => max($rosterCounts[$id] ?? 0, array_sum($c)),
            ];
        }

        return ['classroom' => $classroom, 'rows' => $rows];
    }

    /**
     * Tạo buổi học. Nhận dữ liệu POST THÔ — validate bằng SessionSaveFilter ngay tại đây.
     *
     * @param array<string,mixed> $data dữ liệu POST thô
     * @throws ValidationException dữ liệu sai HOẶC lớp đã có buổi trong ngày đó
     */
    public function createSession(array $data, int $userId, string $role): AttendanceSessionModel
    {
        $values      = $this->validateSession($data, $userId, $role);
        $classroomId = (int) $values['classroom_id'];

        // InArray trong Filter mới chỉ chống tamper dropdown — quyền sở hữu vẫn kiểm ở đây.
        $this->assertCanEditClassroom($classroomId, $userId, $role);

        // Kiểm trùng TRƯỚC khi insert: để MySQL ném lỗi UNIQUE ra thì user thấy trang trắng.
        $existing = $this->sessionMapper->getAttendanceSessionByDate($classroomId, $values['session_date']);
        if ($existing !== null) {
            throw new ValidationException(
                ['session_date' => 'Lớp này đã có buổi học ngày ' . $existing->getSessionDateForHuman() . '.'],
                ['existingSessionId' => (int) $existing->getId()],
            );
        }

        $session = new AttendanceSessionModel();
        $session->setClassroomId($classroomId);
        $session->setSessionDate($values['session_date']);
        $session->setNote(($values['note'] ?? '') !== '' ? $values['note'] : null);
        $session->setCreatedBy($userId);

        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();
        try {
            $saved = $this->sessionMapper->saveAttendanceSession($session);
            $this->rosterMapper->saveRoster(
                (int) $saved->getId(),
                $this->classroomService->studentIds($classroomId),
            );
            $connection->commit();

            return $saved;
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }

    /**
     * Dữ liệu màn hình điểm danh: buổi học + bảng học sinh kèm trạng thái đã lưu (nếu có).
     *
     * Danh sách học sinh = roster được chụp khi tạo buổi ∪ những em đã có record.
     * Phép hợp là cố ý: em bị gỡ khỏi lớp vẫn phải hiện trong lịch sử buổi cũ, nên KHÔNG
     * lọc theo `classroom_student` kiểu INNER (module/Attendance/CLAUDE.md).
     *
     * @return array{
     *     session: AttendanceSessionModel,
     *     classroom: \Classroom\Model\Classroom\ClassroomDto,
     *     rows: array<int, array{studentId:int, name:string, status:?string, note:?string, isMember:bool}>
     * }
     */
    public function getSheet(int $sessionId, int $userId, string $role): array
    {
        $session   = $this->getSessionOrFail($sessionId);
        $classroom = $this->assertCanViewClassroom((int) $session->getClassroomId(), $userId, $role);

        $memberIds = $this->rosterMapper->getStudentIds($sessionId);
        $records   = $this->recordMapper->getRecordsBySession($sessionId);

        $memberSet  = array_fill_keys($memberIds, true);
        $studentIds = array_values(array_unique(array_merge($memberIds, array_keys($records))));

        $names = $this->userService->findMany($studentIds);

        $rows = [];
        foreach ($studentIds as $studentId) {
            $studentId = (int) $studentId;
            $record    = $records[$studentId] ?? null;

            $rows[] = [
                'studentId' => $studentId,
                'name'      => $names[$studentId]->fullName ?? '(không rõ)',
                'status'    => $record?->getStatus(),
                'note'      => $record?->getNote(),
                'isMember'  => isset($memberSet[$studentId]),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcoll($a['name'], $b['name']));

        return ['session' => $session, 'classroom' => $classroom, 'rows' => $rows];
    }

    /**
     * Lưu CẢ BẢNG điểm danh một lần (transaction nằm trong mapper).
     * Nhận POST thô; AttendanceSheetBuilder lo kiểm giá trị và lọc id lạ.
     *
     * @param array<string,mixed> $data dữ liệu POST thô
     * @throws ValidationException
     */
    public function saveSheet(int $sessionId, array $data, int $userId, string $role): AttendanceSessionModel
    {
        $sheet = $this->getSheet($sessionId, $userId, $role);

        // Đọc được KHÔNG có nghĩa là ghi được: admin mở xem bảng này thoải mái nhưng chặn ở đây.
        $this->assertCanEditClassroom((int) $sheet['session']->getClassroomId(), $userId, $role);

        $allowedIds = array_map(static fn (array $r): int => $r['studentId'], $sheet['rows']);
        $names      = array_column($sheet['rows'], 'name', 'studentId');

        $built = $this->sheetBuilder->build(
            is_array($data['status'] ?? null) ? $data['status'] : [],
            is_array($data['note'] ?? null) ? $data['note'] : [],
            $allowedIds,
            $names,
        );

        if ($built['errors'] !== []) {
            throw new ValidationException(['sheet' => implode(' ', $built['errors'])]);
        }

        $this->recordMapper->saveRecordsForSession($sessionId, $built['rows']);

        return $sheet['session'];
    }

    /**
     * Lịch sử chuyên cần của 1 học sinh.
     * - student: chỉ xem của CHÍNH MÌNH, mọi lớp.
     * - teacher: bắt buộc truyền $classroomId, phải phụ trách lớp đó và em đó phải ở trong lớp.
     * - admin: xem được mọi học sinh; truyền $classroomId thì lọc theo lớp, không truyền thì mọi lớp.
     *
     * @return array{
     *     studentName: string,
     *     summary: AttendanceSummary,
     *     rows: array<int, array{date:string, classroomName:string, status:string, statusLabel:string, note:?string}>
     * }
     */
    public function getStudentHistory(int $studentId, ?int $classroomId, int $userId, string $role): array
    {
        if ($role === self::ROLE_ADMIN) {
            // admin xem tất cả: chỉ kiểm lớp có tồn tại không khi có lọc theo lớp.
            if ($classroomId !== null && $classroomId > 0) {
                $this->assertCanViewClassroom($classroomId, $userId, $role);
            }
        } elseif ($role === self::ROLE_STUDENT) {
            if ($studentId !== $userId) {
                throw new AccessDeniedException('Bạn chỉ xem được chuyên cần của chính mình.');
            }
        } elseif ($role === self::ROLE_TEACHER) {
            if ($classroomId === null || $classroomId <= 0) {
                throw new NotFoundException('Thiếu thông tin lớp cần xem.');
            }
            $this->assertCanViewClassroom($classroomId, $userId, $role);
        } else {
            throw new AccessDeniedException('Bạn không có quyền xem chuyên cần.');
        }

        $entries = $this->sessionsOfStudent($studentId, $classroomId, null);
        if (
            $role === self::ROLE_TEACHER
            && !$this->classroomService->isStudentIn($studentId, (int) $classroomId)
            && $entries === []
        ) {
            throw new AccessDeniedException('Học sinh không thuộc lớp này và không có lịch sử trong lớp.');
        }

        $student  = $this->userService->find($studentId);
        if ($student === null && $entries === []) {
            throw new NotFoundException('Học sinh không tồn tại.');
        }

        $classroomIds = array_values(array_unique(array_map(
            static fn (array $entry): int => (int) $entry['session']->getClassroomId(),
            $entries,
        )));
        $classrooms = $this->classroomService->findMany($classroomIds);

        $counts = [];
        $rows   = [];
        foreach ($entries as $entry) {
            $record          = $entry['record'];
            $status          = (string) $record->getStatus();
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            $rows[] = [
                'date'          => $entry['session']->getSessionDateForHuman(),
                'classroomName' => $classrooms[(int) $entry['session']->getClassroomId()]->name ?? '(không rõ)',
                'status'        => $status,
                'statusLabel'   => $record->getStatusLabel(),
                'note'          => $record->getNote(),
            ];
        }

        return [
            'studentName' => $student?->fullName ?? '(tài khoản đã xóa)',
            'summary'     => AttendanceSummary::fromCounts($counts),
            'rows'        => $rows,
        ];
    }

    // ── Nội bộ ──────────────────────────────────────────────────────────────

    /**
     * Ghép record của 1 học sinh với buổi học tương ứng, lọc theo lớp + kỳ.
     * 2 query (record của em đó, rồi session theo id) — không N+1, và mỗi mapper
     * vẫn chỉ đụng bảng của mình.
     *
     * @return array<int, array{record: AttendanceRecordModel, session: AttendanceSessionModel}> mới nhất trước
     */
    private function sessionsOfStudent(int $studentId, ?int $classroomId, ?string $periodLabel): array
    {
        $records = $this->recordMapper->searchStudentRecord($studentId);
        if ($records === []) {
            return [];
        }

        $sessions = $this->sessionMapper->getAttendanceSessionsByIds(
            array_map(static fn (AttendanceRecordModel $r): int => (int) $r->getSessionId(), $records),
        );

        [$from, $to] = $periodLabel === null ? [null, null] : $this->periodRange($periodLabel);

        $out = [];
        foreach ($records as $record) {
            $session = $sessions[(int) $record->getSessionId()] ?? null;
            if ($session === null) {
                continue;
            }
            if ($classroomId !== null && (int) $session->getClassroomId() !== $classroomId) {
                continue;
            }

            $date = (string) $session->getSessionDate();
            if ($from !== null && ($date < $from || $date > $to)) {
                continue;
            }

            $out[] = ['record' => $record, 'session' => $session];
        }

        usort(
            $out,
            static fn (array $a, array $b): int
                => strcmp((string) $b['session']->getSessionDate(), (string) $a['session']->getSessionDate()),
        );

        return $out;
    }

    /**
     * Đổi period_label thành khoảng ngày. Dùng chung định dạng với `student_report.period_label`.
     *
     * Dự án chốt period_label là **THEO THÁNG**, định dạng `2026-07` — không làm theo quý.
     * Nhãn không đúng dạng đó → trả [null, null] = tính tất cả buổi, thay vì đoán sai rồi ra
     * số liệu lệch mà không ai biết.
     *
     * @return array{0: ?string, 1: ?string} [from, to] định dạng Y-m-d
     */
    private function periodRange(string $periodLabel): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', trim($periodLabel), $m) !== 1) {
            return [null, null];
        }

        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            return [null, null];
        }

        $from = sprintf('%04d-%02d-01', (int) $m[1], $month);

        return [$from, date('Y-m-t', (int) strtotime($from))];
    }

    /**
     * Chạy Filter class. Đây là NƠI DUY NHẤT kiểm giá trị đầu vào của buổi học.
     *
     * @param array<string,mixed> $data
     * @return array{classroom_id:int, session_date:string, note:?string}
     * @throws ValidationException
     */
    private function validateSession(array $data, int $userId, string $role): array
    {
        $filter = new SessionSaveFilter($this->classroomService, $userId, $role);
        $filter->setData($data);

        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages($filter->getMessages());
        }

        return $filter->getValues();
    }

    private function getSessionOrFail(int $sessionId): AttendanceSessionModel
    {
        $session = $this->sessionMapper->getAttendanceSession($sessionId);
        if ($session === null) {
            throw new NotFoundException('Buổi học không tồn tại.');
        }

        return $session;
    }

    /**
     * Quyền XEM điểm danh của 1 lớp: admin xem được mọi lớp, teacher chỉ lớp mình phụ trách.
     * Dùng cho các đường đọc (danh sách buổi, mở bảng điểm danh, lịch sử học sinh).
     */
    private function assertCanViewClassroom(int $classroomId, int $userId, string $role): \Classroom\Model\Classroom\ClassroomDto
    {
        $classroom = $this->classroomService->find($classroomId);
        if ($classroom === null) {
            throw new NotFoundException('Lớp không tồn tại.');
        }
        if ($role === self::ROLE_ADMIN) {
            return $classroom;
        }
        if (!$this->classroomService->isTeacherOf($userId, $classroomId)) {
            throw new AccessDeniedException('Bạn không phụ trách lớp này.');
        }

        return $classroom;
    }

    /**
     * Quyền GHI điểm danh: **chỉ** giáo viên phụ trách lớp. Admin xem được nhưng KHÔNG điểm danh —
     * điểm danh là việc của giáo viên đứng lớp, admin ghi hộ thì không ai chịu trách nhiệm số liệu.
     */
    private function assertCanEditClassroom(int $classroomId, int $userId, string $role): \Classroom\Model\Classroom\ClassroomDto
    {
        $classroom = $this->classroomService->find($classroomId);
        if ($classroom === null) {
            throw new NotFoundException('Lớp không tồn tại.');
        }
        if ($role !== self::ROLE_TEACHER || !$this->classroomService->isTeacherOf($userId, $classroomId)) {
            throw new AccessDeniedException('Chỉ giáo viên phụ trách lớp mới điểm danh được.');
        }
        // Lớp lưu trữ là chỉ đọc (module/Classroom/CLAUDE.md) — kiểm ở Service, không chỉ ẩn nút.
        if ($classroom->isArchived()) {
            throw new AccessDeniedException('Lớp đã lưu trữ, không thể điểm danh.');
        }

        return $classroom;
    }
}
