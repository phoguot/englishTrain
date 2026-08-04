<?php

declare(strict_types=1);

namespace Application\Service;

use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Classroom\Service\ClassroomService;
use User\Model\User\UserModel;
use User\Service\UserService;

/** Điều phối đổi role và hard-delete để quan hệ lớp hiện hành không bị mồ côi. */
class UserAccountAdministrationService
{
    public function __construct(
        private readonly UserService $userService,
        private readonly ClassroomService $classroomService,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, int $actorId): UserModel
    {
        $current = $this->userService->find($id);
        if ($current === null) {
            throw new NotFoundException('Tài khoản không tồn tại.');
        }
        // `role` là TINYINT (UserModel::ROLE_*). 0 = form không gửi vai trò, không phải vai trò hợp lệ.
        $newRole = is_scalar($data['role'] ?? null) ? (int) $data['role'] : 0;
        $newStatus = is_scalar($data['status'] ?? null) ? (int) $data['status'] : $current->status;
        if ($id === $actorId && ($newRole !== $current->role || $newStatus !== $current->status)) {
            throw new ValidationException([
                'role' => 'Bạn không thể tự đổi vai trò hoặc tự khóa tài khoản đang đăng nhập.',
                'status' => 'Bạn không thể tự đổi vai trò hoặc tự khóa tài khoản đang đăng nhập.',
            ]);
        }
        if (
            $newRole !== 0
            && $newRole !== $current->role
            && $this->classroomService->hasCurrentUserReference($id)
        ) {
            throw new ValidationException([
                'role' => 'Hãy chuyển lớp giáo viên phụ trách hoặc gỡ học sinh khỏi lớp trước khi đổi vai trò.',
            ]);
        }

        return $this->userService->update($id, $data);
    }

    public function delete(int $id, int $actorId): void
    {
        $user = $this->userService->find($id);
        if ($user === null) {
            throw new NotFoundException('Tài khoản không tồn tại.');
        }
        if ($this->classroomService->hasCurrentUserReference($id)) {
            throw new ValidationException([
                'delete' => 'Hãy chuyển lớp giáo viên phụ trách hoặc gỡ học sinh khỏi lớp trước khi xóa tài khoản.',
            ]);
        }

        $this->userService->delete($id, $actorId);
    }
}
