<?php

declare(strict_types=1);

namespace User\Service;

use Laminas\Session\Container as SessionContainer;
use RuntimeException;
use User\OAuth\OAuthCallbackResult;
use User\OAuth\OAuthProviderRegistry;
use User\OAuth\OAuthProviderType;

class OAuthService
{
    private const SESSION_NS = 'oauth_flow';
    private const FLOW_TTL_SECONDS = 600;

    private SessionContainer $session;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly OAuthProviderRegistry $providers,
        private readonly array $config,
        private readonly string $publicBaseUrl,
    ) {
        $this->session = new SessionContainer(self::SESSION_NS);
    }

    /** @return string[] */
    public function enabledProviders(): array
    {
        $enabled = [];
        foreach (OAuthProviderType::ALL as $provider) {
            if ($this->isConfigured($provider)) {
                $enabled[] = $provider;
            }
        }

        return $enabled;
    }

    public function startLogin(string $provider): string
    {
        return $this->start($provider, 'login', null, null);
    }

    public function startLinkCode(string $provider, string $tokenHash): string
    {
        return $this->start($provider, 'link_code', null, $tokenHash);
    }

    public function startSelfLink(string $provider, int $userId): string
    {
        return $this->start($provider, 'self_link', $userId, null);
    }

    /**
     * Chỉ chép callback nhạy cảm vào session tạm để controller redirect sang URL sạch.
     * Không validate, không gọi provider và không ném exception ở request còn code/state trên URL.
     *
     * @param array<string,mixed> $query
     */
    public function captureCallback(array $query): void
    {
        $scalar = static function (string $key, int $maxLength) use ($query): ?string {
            if (!is_scalar($query[$key] ?? null)) {
                return null;
            }
            $value = (string) $query[$key];

            return strlen($value) <= $maxLength ? $value : null;
        };
        $this->session->callback_pending = [
            'code' => $scalar('code', 4096),
            'state' => $scalar('state', 512),
            'error' => $scalar('error', 255),
        ];
    }

    public function finishCallback(string $provider): OAuthCallbackResult
    {
        $flow = is_array($this->session->flow ?? null) ? $this->session->flow : [];
        $pending = is_array($this->session->callback_pending ?? null) ? $this->session->callback_pending : [];
        unset($this->session->flow, $this->session->callback_pending);

        $issuedAt = is_int($flow['issued_at'] ?? null) ? $flow['issued_at'] : 0;
        $expectedState = is_string($flow['state'] ?? null) ? $flow['state'] : '';
        $actualState = is_string($pending['state'] ?? null) ? $pending['state'] : '';
        if (
            ($flow['provider'] ?? null) !== $provider
            || $issuedAt < time() - self::FLOW_TTL_SECONDS
            || $expectedState === ''
            || $actualState === ''
            || !hash_equals($expectedState, $actualState)
        ) {
            throw new RuntimeException('Phiên đăng nhập ngoài không hợp lệ hoặc đã hết hạn.');
        }
        if (is_string($pending['error'] ?? null) && $pending['error'] !== '') {
            throw new RuntimeException('Người dùng không hoàn tất cấp quyền đăng nhập.');
        }
        $code = is_string($pending['code'] ?? null) ? $pending['code'] : '';
        if ($code === '') {
            throw new RuntimeException('Nhà cung cấp không trả về mã đăng nhập hợp lệ.');
        }

        $identity = $this->providers->get($provider)->fetchIdentity(
            $code,
            $this->callbackUri($provider),
            is_string($flow['code_verifier'] ?? null) ? $flow['code_verifier'] : null,
        );

        return new OAuthCallbackResult(
            is_string($flow['mode'] ?? null) ? $flow['mode'] : 'login',
            $identity,
            is_int($flow['user_id'] ?? null) ? $flow['user_id'] : null,
            is_string($flow['token_hash'] ?? null) ? $flow['token_hash'] : null,
        );
    }

    private function start(string $provider, string $mode, ?int $userId, ?string $tokenHash): string
    {
        if (!$this->isConfigured($provider)) {
            throw new RuntimeException('Nhà cung cấp đăng nhập chưa được cấu hình.');
        }
        $state = bin2hex(random_bytes(32));
        $codeVerifier = OAuthProviderType::supportsPkce($provider)
            ? $this->base64Url(random_bytes(32))
            : null;
        $this->session->flow = [
            'provider' => $provider,
            'mode' => $mode,
            'state' => $state,
            'issued_at' => time(),
            'code_verifier' => $codeVerifier,
            'user_id' => $userId,
            'token_hash' => $tokenHash,
        ];

        return $this->providers->get($provider)->authorizationUrl(
            $this->callbackUri($provider),
            $state,
            $codeVerifier === null ? null : $this->base64Url(hash('sha256', $codeVerifier, true)),
        );
    }

    private function isConfigured(string $provider): bool
    {
        $config = $this->config[$provider] ?? null;

        return is_array($config)
            && ($config['enabled'] ?? false) === true
            && is_string($config['client_id'] ?? null)
            && $config['client_id'] !== ''
            && is_string($config['client_secret'] ?? null)
            && $config['client_secret'] !== ''
            && in_array($provider, $this->providers->names(), true);
    }

    private function callbackUri(string $provider): string
    {
        if ($this->publicBaseUrl === '' || !preg_match('#^https?://#', $this->publicBaseUrl)) {
            throw new RuntimeException('Chưa cấu hình địa chỉ công khai của hệ thống.');
        }

        return rtrim($this->publicBaseUrl, '/') . '/auth/' . rawurlencode($provider) . '/callback';
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
