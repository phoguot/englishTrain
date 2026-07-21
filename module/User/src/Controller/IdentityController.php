<?php

declare(strict_types=1);

namespace User\Controller;

use Application\Controller\BaseController;
use Application\Exception\ValidationException;
use RuntimeException;
use User\OAuth\OAuthProviderType;
use User\Service\OAuthService;
use User\Service\UserLinkTokenService;

class IdentityController extends BaseController
{
    protected const ALLOWED_ROLES = [BaseController::ROLE_GUEST];

    public function __construct(
        private readonly UserLinkTokenService $tokenService,
        private readonly OAuthService $oauthService,
    ) {
    }

    public function linkAccountAction(): mixed
    {
        if ($this->currentUserId() !== null) {
            return $this->redirect()->toRoute('account_identities');
        }
        $values = ['link_code' => '', 'provider' => OAuthProviderType::GOOGLE];
        $errors = [];
        if ($this->getRequest()->isPost()) {
            $post = $this->getAllPostParams();
            $values = [
                'link_code' => is_scalar($post['link_code'] ?? null) ? (string) $post['link_code'] : '',
                'provider' => is_scalar($post['provider'] ?? null) ? (string) $post['provider'] : '',
            ];
            try {
                $server = $this->getRequest()->getServer();
                $ip = is_object($server) && is_scalar($server->get('REMOTE_ADDR'))
                    ? (string) $server->get('REMOTE_ADDR')
                    : 'unknown';
                $prepared = $this->tokenService->prepareLink($post, $ip);

                return $this->redirect()->toUrl($this->oauthService->startLinkCode(
                    $prepared['provider'],
                    $prepared['token_hash'],
                ));
            } catch (ValidationException $e) {
                $errors = $e->getErrors();
            } catch (RuntimeException) {
                $errors['provider'] = 'Nhà cung cấp đăng nhập chưa sẵn sàng.';
            }
        }

        $model = $this->getViewModel();
        $model->setVariables([
            'values' => $values,
            'errors' => $errors,
            'providers' => $this->oauthService->enabledProviders(),
            'providerLabels' => OAuthProviderType::LABELS,
        ]);

        return $model;
    }
}
