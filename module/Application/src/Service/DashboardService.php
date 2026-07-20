<?php

declare(strict_types=1);

namespace Application\Service;

use Assignment\Service\AssignmentService;
use Classroom\Service\ClassroomService;
use User\Service\UserService;

/** Tổng hợp dashboard qua hợp đồng public của các module, không query DB trực tiếp. */
class DashboardService
{
    public function __construct(
        private readonly UserService $userService,
        private readonly ClassroomService $classroomService,
        private readonly AssignmentService $assignmentService,
    ) {
    }

    /** @return array<string,mixed> */
    public function forActor(int $userId, string $role): array
    {
        $user = $this->userService->find($userId);
        $classrooms = match ($role) {
            'student' => $this->classroomService->listForStudent($userId),
            'teacher', 'admin' => $this->classroomService->listRowsForActor($userId, $role),
            default => [],
        };

        $data = [
            'userId' => $userId,
            'role' => $role,
            'fullName' => $user?->fullName ?? '',
            'classrooms' => $classrooms,
            'adminStats' => [],
            'pendingAssignments' => [],
            'upcomingAssignments' => [],
            'gradedAssignments' => [],
        ];

        if ($role === 'admin') {
            $data['adminStats'] = [
                'classrooms' => count($classrooms),
                'teachers' => count($this->userService->findByRole('teacher')),
                'students' => count($this->userService->findByRole('student')),
            ];
        } elseif ($role === 'teacher') {
            $data['pendingAssignments'] = $this->assignmentService->dashboardForTeacher($userId);
        } elseif ($role === 'student') {
            $studentData = $this->assignmentService->dashboardForStudent($userId);
            $data['upcomingAssignments'] = $studentData['upcoming'];
            $data['gradedAssignments'] = $studentData['graded'];
        }

        return $data;
    }
}
