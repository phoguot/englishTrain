<?php

declare(strict_types=1);

namespace User\Controller;

use Application\Controller\BaseController;
use Application\Exception\ValidationException;
use RuntimeException;
use Throwable;
use User\Service\OAuthService;
use User\Service\OAuthLoginService;

class OAuthController extends BaseController
{
    protected const ALLOWED_ROLES = [BaseController::ROLE_GUEST];

    public function __construct(
        private readonly OAuthService $oauthService,
        private readonly OAuthLoginService $loginService,
    ) {
    }

    public function startAction(): mixed
    {
        if ($this->currentUserId() !== null) {
            return $this->redirect()->toRoute('account_identities');
        }
        $provider = (string) $this->params()->fromRoute('provider', '');
        try {
            return $this->redirect()->toUrl($this->oauthService->startLogin($provider));
        } catch (RuntimeException) {
            $this->flashMessenger()->addErrorMessage('Nhà cung cấp đăng nhập chưa sẵn sàng.');

            return $this->redirect()->toRoute('login');
        }
    }

    public function callbackAction(): mixed
    {
        $provider = (string) $this->params()->fromRoute('provider', '');
        $query = $this->getAllQueryParams();
        if (array_intersect(['code', 'state', 'error'], array_keys($query)) !== []) {
            $this->oauthService->captureCallback($query);

            return $this->redirect()->toRoute('oauth_callback', ['provider' => $provider]);
        }

        try {
            $completion = $this->loginService->complete($provider, $this->currentUserId());
            if ($completion->success) {
                $this->flashMessenger()->addSuccessMessage($completion->message);
            } else {
                $this->flashMessenger()->addErrorMessage($completion->message);
            }

            return $this->redirect()->toRoute($completion->route);
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->flashMessenger()->addErrorMessage(
                reset($errors) ?: 'Không thể hoàn tất liên kết tài khoản.',
            );
        } catch (Throwable) {
            $this->flashMessenger()->addErrorMessage('Không thể hoàn tất đăng nhập. Vui lòng thử lại.');
        }

        return $this->redirect()->toRoute('login');
    }
}
