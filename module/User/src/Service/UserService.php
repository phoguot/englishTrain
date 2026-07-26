<?php

declare(strict_types=1);

namespace User\Service;

use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Laminas\Db\Adapter\Adapter;
use Throwable;
use User\Filter\User\UserListFilter;
use User\Filter\User\UserSaveFilter;
use User\Model\User\UserDto;
use User\Model\User\UserMapper;
use User\Model\User\UserModel;
use User\Model\UserLoginToken\UserLoginTokenMapper;

/**
 * Cung cấp danh tính người dùng cho các module khác — luôn trả UserDto (không password_hash).
 * Hợp đồng public: docs/04-contracts.md.
 */
class UserService
{
    public function __construct(
        private readonly UserMapper $userMapper,
        private readonly UserIdentityService $identityService,
        private readonly RememberMeService $rememberMeService,
        private readonly UserLoginTokenMapper $loginTokenMapper,
        private readonly Adapter $adapter,
    ) {
    }

    public function find(int $id): ?UserDto
    {
        return $this->userMapper->getUser($id)?->toDto();
    }

    /**
     * Lấy nhiều người một lần — DÙNG CÁI NÀY để tránh N+1, đừng loop find().
     *
     * @param int[] $ids
     * @return array<int, UserDto> map id => UserDto
     */
    public function findMany(array $ids): array
    {
        $out = [];
        foreach ($this->userMapper->getUsersByIds($ids) as $id => $model) {
            $out[$id] = $model->toDto();
        }

        return $out;
    }

    /**
     * Danh sách user đang hoạt động theo role (sắp theo tên) — cho dropdown giáo viên,
     * checkbox học sinh. Trả UserDto[] (không password_hash).
     *
     * @return UserDto[]
     */
    public function findByRole(string $role): array
    {
        return array_map(
            static fn ($model): UserDto => $model->toDto(),
            $this->userMapper->getUsersByRole($role),
        );
    }

    /** @return UserModel[] */
    public function search(array $query): array
    {
        $filter = new UserListFilter();
        $filter->setData($query);
        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages($filter->getMessages());
        }
        $keyword = (string) ($filter->getValues()['q'] ?? '');

        return $this->userMapper->searchUser($keyword !== '' ? $keyword : null);
    }

    public function getEditable(int $id): UserModel
    {
        $user = $this->userMapper->getUser($id);
        if ($user === null) {
            throw new NotFoundException('Tài khoản không tồn tại.');
        }

        return $user;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): UserModel
    {
        $values = $this->validate($data, null);
        $user = new UserModel();
        $this->fill($user, $values, true);

        return $this->userMapper->saveUser($user);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): UserModel
    {
        $user = $this->getEditable($id);
        $values = $this->validate($data, $id);
        $this->fill($user, $values, false);
        $saved = $this->userMapper->saveUser($user);

        if ((string) ($values['password'] ?? '') !== '') {
            // Đổi mật khẩu phải thu hồi mọi cookie "ghi nhớ đăng nhập" đang phát hành —
            // remember-me bỏ qua bước nhập mật khẩu nên thiết bị cũ phải đăng nhập lại.
            $this->rememberMeService->revokeAllForUser($id);
        }

        return $saved;
    }

    public function delete(int $id, int $actorId): void
    {
        if ($id === $actorId) {
            throw new AccessDeniedException('Bạn không thể tự xóa tài khoản đang đăng nhập.');
        }
        $this->getEditable($id);
        $connection = $this->adapter->getDriver()->getConnection();
        $connection->beginTransaction();
        try {
            $this->identityService->deleteAllForUser($id);
            $this->rememberMeService->purgeAllForUser($id);
            // Magic-link đăng nhập: dọn cả token của user bị xóa lẫn token do user đó từng tạo,
            // để không còn ID mồ côi giống pattern của identity/link-token.
            $this->loginTokenMapper->deleteByUser($id);
            $this->loginTokenMapper->deleteByCreator($id);
            if (!$this->userMapper->deleteUser($id)) {
                throw new NotFoundException('Tài khoản không còn tồn tại.');
            }
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollback();
            throw $e;
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validate(array $data, ?int $id): array
    {
        $filter = new UserSaveFilter($this->userMapper, $id);
        $filter->setData($data);
        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages($filter->getMessages());
        }

        return $filter->getValues();
    }

    /** @param array<string,mixed> $values */
    private function fill(UserModel $user, array $values, bool $isCreate): void
    {
        $user->setRole((string) $values['role']);
        $user->setFullName((string) $values['full_name']);
        $user->setEmail(($values['email'] ?? '') !== '' ? (string) $values['email'] : null);
        $user->setPhone(($values['phone'] ?? '') !== '' ? (string) $values['phone'] : null);
        $user->setUsername((string) $values['username']);
        $user->setStatus((int) $values['status']);
        $password = (string) ($values['password'] ?? '');
        if ($isCreate || $password !== '') {
            $user->setPasswordHash(password_hash($password, PASSWORD_DEFAULT));
        }
    }
}
