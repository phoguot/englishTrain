<?php

declare(strict_types=1);

namespace Attendance\Service;

use Application\Exception\AccessDeniedException;
use Attendance\Model\AttendanceRecord\AttendanceRecordMapper;
use Attendance\Model\AttendanceRecord\AttendanceRecordModel;
use Attendance\Model\AttendanceSession\AttendanceSessionMapper;
use Attendance\Model\AttendanceSession\AttendanceSessionModel;
use Classroom\Service\ClassroomService;
use User\Model\User\UserModel;
use User\Service\UserService;

/**
 * Học phí theo buổi dạy: điểm danh xong là ra tiền.
 *
 * CHỈ ĐỌC — không ghi bảng nào, không có bảng tiền riêng. Tiền của một học sinh trong một buổi
 * = `attendance_session.fee_per_session` nếu record của em đó `isPaid()`, ngược lại 0.
 * Quy tắc "trạng thái nào tính tiền" khai duy nhất ở `AttendanceRecordModel::PAID_STATUSES`.
 *
 * Quyền: **chỉ giáo viên phụ trách lớp**. Student và cả admin đều không xem được — chặn ở
 * TuitionController (ALLOWED_ROLES) và kiểm lại trong monthlyTuition().
 *
 * Xem module/Attendance/CLAUDE.md.
 */
class TuitionService
{
    public function __construct(
        private readonly AttendanceSessionMapper $sessionMapper,
        private readonly AttendanceRecordMapper $recordMapper,
        private readonly ClassroomService $classroomService,
        private readonly UserService $userService,
        private readonly ClassroomAccessGuard $accessGuard,
    ) {
    }

    /**
     * Bảng học phí một lớp trong một tháng — dùng chung cho trang web và cả 2 file Excel,
     * để số trên màn hình và số trong file không bao giờ lệch nhau.
     *
     * - `daily`: mỗi dòng là một học sinh trong một buổi (chỉ những em CÓ record).
     * - `summary`: mỗi dòng là một học sinh, gồm cả em trong lớp mà tháng này chưa đi buổi nào
     *   (0 buổi, 0 đ) — để cuối tháng nhìn ra ai chưa học.
     *
     * @param string $month `YYYY-MM`; chuỗi không đúng dạng → tháng hiện tại (xem monthRange()).
     * @return array{
     *     classroom: \Classroom\Model\Classroom\ClassroomDto,
     *     month: string,
     *     monthLabel: string,
     *     sessionCount: int,
     *     daily: array<int, array{date:string, shiftLabel:?string, studentId:int, studentName:string,
     *                             status:string, statusLabel:string, note:?string, fee:float, amount:float}>,
     *     summary: array<int, array{studentId:int, studentName:string, paidSessions:int,
     *                               absentSessions:int, excusedSessions:int, totalAmount:float}>,
     *     grandTotal: float
     * }
     */
    public function monthlyTuition(int $classroomId, string $month, int $userId, int $role): array
    {
        // Học phí chỉ là việc của giáo viên đứng lớp: admin cũng không xem được (khác điểm danh,
        // nơi ClassroomAccessGuard cho admin xem mọi lớp). Kiểm lại tại đây, không chỉ dựa vào
        // ALLOWED_ROLES của TuitionController.
        if ($role !== UserModel::ROLE_TEACHER) {
            throw new AccessDeniedException('Chỉ giáo viên phụ trách lớp mới xem được thống kê học phí.');
        }

        $classroom = $this->accessGuard->assertCanView($classroomId, $userId, $role);

        [$normalizedMonth, $from, $to] = $this->monthRange($month);

        $sessions = $this->sessionMapper->searchAttendanceSessionInRange($classroomId, $from, $to);
        $records  = $this->recordMapper->getRecordsBySessionIds(
            array_map(static fn (AttendanceSessionModel $s): int => (int) $s->getId(), $sessions),
        );

        // Học sinh cần hiển thị = em có record trong tháng ∪ em đang trong lớp.
        // Phép hợp là cố ý: em đã rời lớp vẫn phải còn trong bảng tiền của tháng em đã học.
        $studentIds = $this->classroomService->studentIds($classroomId);
        foreach ($records as $bySession) {
            $studentIds = array_merge($studentIds, array_keys($bySession));
        }
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));

        $users = $this->userService->findMany($studentIds);
        $names = [];
        foreach ($studentIds as $studentId) {
            $names[$studentId] = $users[$studentId]->fullName ?? '(tài khoản đã xóa)';
        }

        $daily      = [];
        $summary    = [];
        $grandTotal = 0.0;

        foreach ($studentIds as $studentId) {
            $summary[$studentId] = [
                'studentId'       => $studentId,
                'studentName'     => $names[$studentId],
                'paidSessions'    => 0,
                'absentSessions'  => 0,
                'excusedSessions' => 0,
                'totalAmount'     => 0.0,
            ];
        }

        foreach ($sessions as $session) {
            $sessionId = (int) $session->getId();
            $fee       = $session->getFeePerSession();

            foreach ($records[$sessionId] ?? [] as $studentId => $record) {
                $studentId = (int) $studentId;
                $amount    = $record->isPaid() ? $fee : 0.0;

                $daily[] = [
                    'date'        => $session->getSessionDateForHuman(),
                    'shiftLabel'  => $session->getShiftLabel(),
                    'studentId'   => $studentId,
                    'studentName' => $names[$studentId] ?? '(tài khoản đã xóa)',
                    'status'      => (string) $record->getStatus(),
                    'statusLabel' => $record->getStatusLabel(),
                    'note'        => $record->getNote(),
                    'fee'         => $fee,
                    'amount'      => $amount,
                ];

                if (!isset($summary[$studentId])) {
                    $summary[$studentId] = [
                        'studentId'       => $studentId,
                        'studentName'     => $names[$studentId] ?? '(tài khoản đã xóa)',
                        'paidSessions'    => 0,
                        'absentSessions'  => 0,
                        'excusedSessions' => 0,
                        'totalAmount'     => 0.0,
                    ];
                }

                if ($record->isPaid()) {
                    $summary[$studentId]['paidSessions']++;
                    $summary[$studentId]['totalAmount'] += $amount;
                    $grandTotal                          += $amount;
                } elseif ($record->getStatus() === AttendanceRecordModel::STATUS_EXCUSED) {
                    $summary[$studentId]['excusedSessions']++;
                } else {
                    $summary[$studentId]['absentSessions']++;
                }
            }
        }

        usort(
            $summary,
            static fn (array $a, array $b): int => strcoll($a['studentName'], $b['studentName']),
        );

        return [
            'classroom'    => $classroom,
            'month'        => $normalizedMonth,
            'monthLabel'   => 'Tháng ' . substr($normalizedMonth, 5, 2) . '/' . substr($normalizedMonth, 0, 4),
            'sessionCount' => count($sessions),
            'daily'        => $daily,
            'summary'      => array_values($summary),
            'grandTotal'   => $grandTotal,
        ];
    }

    /**
     * Đổi nhãn tháng thành khoảng ngày. Định dạng `YYYY-MM` giống `student_report.period_label`.
     *
     * Khác `AttendanceService::periodRange()` một cách CÓ Ý: ở đó nhãn sai nghĩa là "tính tất cả
     * các buổi", còn bảng tiền luôn phải thuộc về một tháng cụ thể → nhãn sai thì lùi về
     * tháng hiện tại, không bao giờ trả khoảng rỗng.
     *
     * @return array{0: string, 1: string, 2: string} [month `Y-m`, from `Y-m-d`, to `Y-m-d`]
     */
    public function monthRange(string $month): array
    {
        $month = trim($month);
        if (
            preg_match('/^(\d{4})-(\d{2})$/', $month, $m) !== 1
            || (int) $m[2] < 1
            || (int) $m[2] > 12
        ) {
            $month = date('Y-m');
        }

        $from = $month . '-01';

        return [$month, $from, date('Y-m-t', (int) strtotime($from))];
    }
}
