<?php

declare(strict_types=1);

namespace User\Controller;

use Application\Controller\BaseController;
use Application\Exception\AccessDeniedException;
use Application\Exception\ValidationException;
use User\Service\AuthService;
use User\Service\OAuthService;
use User\Service\RememberMeService;
use User\OAuth\OAuthProviderType;

/**
 * Đăng nhập / đăng xuất. Công khai (ROLE_GUEST): trang login ai cũng vào được,
 * logout khi chưa đăng nhập chỉ redirect về login — vô hại.
 */
class AuthController extends BaseController
{
    protected const ALLOWED_ROLES = [BaseController::ROLE_GUEST];

    public function __construct(
        private readonly AuthService $auth,
        private readonly OAuthService $oauthService,
        private readonly RememberMeService $rememberMe,
    ) {
    }

    public function loginAction(): mixed
    {
        // Đã đăng nhập rồi thì khỏi xem trang login.
        if ($this->auth->currentUserId() !== null) {
            return $this->redirect()->toRoute('dashboard');
        }

        $request = $this->getRequest();

        if ($request->isPost()) {
            $post = $this->getAllPostParams();
            $errors = [];
            try {
                if ($this->auth->attempt($post)) {
                    if ($this->auth->wantedRememberMe()) {
                        $userId = $this->auth->currentUserId();
                        if ($userId !== null) {
                            $this->getResponse()->getHeaders()->addHeader($this->rememberMe->issueCookie($userId));
                        }
                    }

                    return $this->redirect()->toRoute('dashboard');
                }

                $errors['login'] = 'Tên đăng nhập hoặc mật khẩu không đúng.';
            } catch (ValidationException $e) {
                $errors = $e->getErrors();
            }

            $model = $this->getViewModel();
            $model->setVariables([
                'username' => is_scalar($post['username'] ?? null) ? (string) $post['username'] : '',
                'errors' => $errors,
                'enabledProviders' => $this->oauthService->enabledProviders(),
                'providerLabels' => OAuthProviderType::LABELS,
            ]);

            return $model;
        }

        $model = $this->getViewModel();
        $model->setVariables([
            'username' => '',
            'errors' => [],
            'enabledProviders' => $this->oauthService->enabledProviders(),
            'providerLabels' => OAuthProviderType::LABELS,
        ]);

        return $model;
    }

    public function logoutAction(): mixed
    {
        if (!$this->getRequest()->isPost()) {
            throw new AccessDeniedException('Vui lòng đăng xuất bằng nút xác nhận trong hệ thống.');
        }

        $cookieHeader = $this->getRequest()->getCookie();
        $raw = $cookieHeader !== false && $cookieHeader->offsetExists(RememberMeService::COOKIE_NAME)
            ? $cookieHeader->offsetGet(RememberMeService::COOKIE_NAME)
            : null;
        if (is_string($raw) && $raw !== '') {
            $this->rememberMe->revokeCookie($raw);
        }
        $this->getResponse()->getHeaders()->addHeader($this->rememberMe->clearCookie());

        $this->auth->logout();
        $this->flashMessenger()->addSuccessMessage('Bạn đã đăng xuất.');

        return $this->redirect()->toRoute('login');
    }
}
