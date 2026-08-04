<?php

declare(strict_types=1);

namespace Application\Service;

use Assignment\Service\AssignmentService;
use Classroom\Service\ClassroomService;
use User\Model\User\UserModel;
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
    public function forActor(int $userId, int $role): array
    {
        $user = $this->userService->find($userId);
        $classrooms = match ($role) {
            UserModel::ROLE_STUDENT => $this->classroomService->listForStudent($userId),
            UserModel::ROLE_TEACHER, UserModel::ROLE_ADMIN => $this->classroomService->listRowsForActor($userId, $role),
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

        if ($role === UserModel::ROLE_ADMIN) {
            $data['adminStats'] = [
                'classrooms' => count($classrooms),
                'teachers' => count($this->userService->findByRole(UserModel::ROLE_TEACHER)),
                'students' => count($this->userService->findByRole(UserModel::ROLE_STUDENT)),
            ];
        } elseif ($role === UserModel::ROLE_TEACHER) {
            $data['pendingAssignments'] = $this->assignmentService->dashboardForTeacher($userId);
        } elseif ($role === UserModel::ROLE_STUDENT) {
            $studentData = $this->assignmentService->dashboardForStudent($userId);
            $data['upcomingAssignments'] = $studentData['upcoming'];
            $data['gradedAssignments'] = $studentData['graded'];
        }

        return $data;
    }
}
