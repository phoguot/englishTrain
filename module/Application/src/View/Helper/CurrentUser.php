<?php

declare(strict_types=1);

namespace Application\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use User\Service\AuthService;
use User\Service\UserService;

/**
 * Cho layout/navbar biết ai đang đăng nhập mà view không phải gọi Service trực tiếp.
 * Trả null nếu chưa đăng nhập, ngược lại: ['id','role','name','csrfToken'].
 * `role` là TINYINT (`UserModel::ROLE_*`) — view so bằng hằng, không so chuỗi.
 */
class CurrentUser extends AbstractHelper
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly UserService $users,
    ) {
    }

    /** @return array{id:int,role:int,name:string,csrfToken:string}|null */
    public function __invoke(): ?array
    {
        $id = $this->auth->currentUserId();
        if ($id === null) {
            return null;
        }

        $dto = $this->users->find($id);

        return [
            'id'   => $id,
            'role' => (int) $this->auth->currentRole(),
            'name' => $dto?->fullName ?? '',
            'csrfToken' => $this->auth->csrfToken(),
        ];
    }
}
