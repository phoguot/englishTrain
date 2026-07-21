<?php

declare(strict_types=1);

namespace User\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\ValidationException;
use RuntimeException;
use User\Service\OAuthService;
use User\Service\UserIdentityService;
use User\OAuth\OAuthProviderType;

class AccountIdentityController extends BaseController
{
    protected const ALLOWED_ROLES = [BaseController::ROLE_ANY];

    public function __construct(
        private readonly UserIdentityService $identityService,
        private readonly OAuthService $oauthService,
    ) {
    }

    public function indexAction(): mixed
    {
        $model = $this->getViewModel();
        $model->setVariables([
            'identities' => $this->identityService->listForUser((int) $this->currentUserId()),
            'providers' => OAuthProviderType::ALL,
            'enabledProviders' => $this->oauthService->enabledProviders(),
            'providerLabels' => OAuthProviderType::LABELS,
        ]);

        return $model;
    }

    public function linkAction(): mixed
    {
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận liên kết từ biểu mẫu xác nhận.');
        }
        $provider = (string) $this->params()->fromRoute('provider', '');
        try {
            return $this->redirect()->toUrl($this->oauthService->startSelfLink(
                $provider,
                (int) $this->currentUserId(),
            ));
        } catch (RuntimeException) {
            $this->flashMessenger()->addErrorMessage('Nhà cung cấp đăng nhập chưa sẵn sàng.');

            return $this->redirect()->toRoute('account_identities');
        }
    }

    public function unlinkAction(): mixed
    {
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Chỉ chấp nhận ngắt liên kết từ biểu mẫu xác nhận.');
        }
        $provider = (string) $this->params()->fromRoute('provider', '');
        try {
            $this->identityService->unlink((int) $this->currentUserId(), ['provider' => $provider]);
            $this->flashMessenger()->addSuccessMessage('Đã ngắt liên kết tài khoản đăng nhập.');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->flashMessenger()->addErrorMessage(reset($errors) ?: 'Không thể ngắt liên kết.');
        }

        return $this->redirect()->toRoute('account_identities');
    }
}
