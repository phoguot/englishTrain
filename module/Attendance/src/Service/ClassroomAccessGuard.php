<?php

declare(strict_types=1);

namespace Attendance\Service;

use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Classroom\Model\Classroom\ClassroomDto;
use Classroom\Service\ClassroomService;

/**
 * Quyền truy cập lớp trong module Attendance — MỘT bản luật duy nhất, dùng bởi
 * AttendanceService (điểm danh) và TuitionService (học phí).
 *
 * Đọc ≠ ghi, đừng gộp:
 * - ĐỌC: admin xem được mọi lớp, teacher chỉ lớp mình phụ trách.
 * - GHI: **chỉ** teacher phụ trách lớp, và lớp phải chưa lưu trữ.
 *
 * Đây chỉ là quyền theo lớp. Quyền "xem chuyên cần của chính mình" của student nằm ở
 * AttendanceService::getStudentHistory(). Xem .claude/rules/01-bao-mat.md.
 */
class ClassroomAccessGuard
{
    public const ROLE_ADMIN   = 'admin';
    public const ROLE_TEACHER = 'teacher';
    public const ROLE_STUDENT = 'student';

    public function __construct(private readonly ClassroomService $classroomService)
    {
    }

    /**
     * Quyền XEM dữ liệu của 1 lớp (danh sách buổi, bảng điểm danh, bảng học phí).
     *
     * @throws NotFoundException lớp không tồn tại
     * @throws AccessDeniedException teacher không phụ trách lớp này
     */
    public function assertCanView(int $classroomId, int $userId, string $role): ClassroomDto
    {
        $classroom = $this->findOrFail($classroomId);
        if ($role === self::ROLE_ADMIN) {
            return $classroom;
        }
        if (!$this->classroomService->isTeacherOf($userId, $classroomId)) {
            throw new AccessDeniedException('Bạn không phụ trách lớp này.');
        }

        return $classroom;
    }

    /**
     * Quyền GHI: **chỉ** giáo viên phụ trách lớp. Admin xem được nhưng KHÔNG ghi — điểm danh là
     * việc của giáo viên đứng lớp, admin ghi hộ thì không ai chịu trách nhiệm số liệu.
     *
     * Dùng cho cả 4 đường ghi: tạo buổi, sửa buổi, xóa buổi, lưu bảng điểm danh — nên câu lỗi
     * nói chung "dữ liệu điểm danh", đừng viết riêng cho một thao tác.
     *
     * @throws NotFoundException|AccessDeniedException
     */
    public function assertCanEdit(int $classroomId, int $userId, string $role): ClassroomDto
    {
        $classroom = $this->findOrFail($classroomId);
        if ($role !== self::ROLE_TEACHER || !$this->classroomService->isTeacherOf($userId, $classroomId)) {
            throw new AccessDeniedException(
                'Chỉ giáo viên phụ trách lớp mới thay đổi được buổi học và điểm danh của lớp này.',
            );
        }
        // Lớp lưu trữ là chỉ đọc (module/Classroom/CLAUDE.md) — kiểm ở Service, không chỉ ẩn nút.
        if ($classroom->isArchived()) {
            throw new AccessDeniedException('Lớp đã lưu trữ, không thể thay đổi buổi học hay điểm danh.');
        }

        return $classroom;
    }

    private function findOrFail(int $classroomId): ClassroomDto
    {
        $classroom = $this->classroomService->find($classroomId);
        if ($classroom === null) {
            throw new NotFoundException('Lớp không tồn tại.');
        }

        return $classroom;
    }
}
