<?php

declare(strict_types=1);

namespace User\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\ValidationException;
use User\Service\UserIdentityService;
use User\Service\UserLinkTokenService;
use User\Service\UserService;

class AdminIdentityController extends BaseController
{
    protected const ALLOWED_ROLES = ['admin'];

    public function __construct(
        private readonly UserLinkTokenService $tokenService,
        private readonly UserIdentityService $identityService,
        private readonly UserService $userService,
    ) {
    }

    public function inviteAction(): mixed
    {
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận tạo mã từ biểu mẫu quản trị.');
        }
        $userId = (int) $this->params()->fromRoute('id', 0);
        $user = $this->userService->getEditable($userId);
        $code = $this->tokenService->create($userId, (int) $this->currentUserId());
        $this->getResponse()->getHeaders()->addHeaderLine('Cache-Control', 'no-store');
        $model = $this->getViewModel();
        $model->setVariables(['user' => $user, 'linkCode' => $code]);

        return $model;
    }

    public function revokeAction(): mixed
    {
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận thu hồi từ biểu mẫu xác nhận.');
        }
        $userId = (int) $this->params()->fromRoute('id', 0);
        $provider = (string) $this->params()->fromRoute('provider', '');
        try {
            $this->identityService->unlink($userId, ['provider' => $provider]);
            $this->flashMessenger()->addSuccessMessage('Đã thu hồi liên kết đăng nhập.');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->flashMessenger()->addErrorMessage(reset($errors) ?: 'Không thể thu hồi liên kết.');
        }

        return $this->redirect()->toRoute('user_edit', ['id' => $userId]);
    }
}
