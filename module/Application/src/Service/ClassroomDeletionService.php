<?php

declare(strict_types=1);

namespace Application\Service;

use Application\Exception\ValidationException;
use Assignment\Service\AssignmentService;
use Attendance\Service\AttendanceService;
use Classroom\Model\Classroom\ClassroomModel;
use Classroom\Service\ClassroomService;
use Report\Service\ReportService;

/**
 * Điều phối xóa lớp: chỉ cho xóa **lớp trống**, lớp đã có dữ liệu thì bắt lưu trữ.
 *
 * Vì sao ở module Application chứ không ở Classroom: Assignment / Attendance / Report đều
 * phụ thuộc vào `ClassroomService`, nên Classroom **không được** gọi ngược lại 3 module đó
 * (sẽ thành DI vòng tròn — `docs/04-contracts.md`). Application đứng ngoài nên hỏi được cả 4.
 * Giống hệt cách `UserAccountAdministrationService` bảo vệ hard-delete tài khoản.
 *
 * Quyền sở hữu lớp do `ClassroomService::delete()` kiểm (admin mọi lớp, teacher lớp mình).
 */
class ClassroomDeletionService
{
    public function __construct(
        private readonly ClassroomService $classroomService,
        private readonly AssignmentService $assignmentService,
        private readonly AttendanceService $attendanceService,
        private readonly ReportService $reportService,
    ) {
    }

    /**
     * @throws ValidationException lớp còn dữ liệu lịch sử → nêu rõ còn những gì, bao nhiêu
     */
    public function delete(int $id, int $userId, int $role): ClassroomModel
    {
        // Kiểm quyền TRƯỚC khi đếm: không để người ngoài dò được lớp có bao nhiêu buổi học.
        $classroom = $this->classroomService->getEditable($id, $userId, $role);

        $blockers = [];

        $assignments = $this->assignmentService->countByClassroom($id);
        if ($assignments > 0) {
            $blockers[] = $assignments . ' bài tập';
        }

        $sessions = $this->attendanceService->countSessionsInClassroom($id);
        if ($sessions > 0) {
            $blockers[] = $sessions . ' buổi học';
        }

        $reports = $this->reportService->countByClassroom($id);
        if ($reports > 0) {
            $blockers[] = $reports . ' report';
        }

        if ($blockers !== []) {
            throw new ValidationException([
                'delete' => 'Lớp "' . $classroom->getName() . '" đang có ' . implode(', ', $blockers)
                    . ' nên không xóa được. Hãy chuyển lớp sang trạng thái "Đã lưu trữ" để giữ lịch sử.',
            ]);
        }

        return $this->classroomService->delete($id, $userId, $role);
    }
}
