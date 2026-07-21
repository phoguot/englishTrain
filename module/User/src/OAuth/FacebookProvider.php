<?php

declare(strict_types=1);

namespace User\OAuth;

class FacebookProvider extends AbstractOAuthProvider
{
    public function __construct(
        \Laminas\Http\Client $httpClient,
        string $clientId,
        string $clientSecret,
        private readonly string $apiVersion,
    ) {
        parent::__construct($httpClient, $clientId, $clientSecret);
    }

    public function name(): string
    {
        return OAuthProviderType::FACEBOOK;
    }

    public function authorizationUrl(string $redirectUri, string $state, ?string $codeChallenge): string
    {
        return sprintf(
            OAuthProviderType::FACEBOOK_AUTHORIZATION_URL . '?%s',
            rawurlencode($this->apiVersion),
            http_build_query([
                'client_id' => $this->clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => OAuthProviderType::FACEBOOK_SCOPE,
                'state' => $state,
            ]),
        );
    }

    public function fetchIdentity(string $authorizationCode, string $redirectUri, ?string $codeVerifier): OAuthIdentity
    {
        $token = $this->postForm(
            sprintf(OAuthProviderType::FACEBOOK_TOKEN_URL, rawurlencode($this->apiVersion)),
            [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $redirectUri,
                'code' => $authorizationCode,
            ],
        );
        $accessToken = $this->requiredString($token, 'access_token');
        // Facebook mặc định bật "Require app secret proof for server API calls":
        // mọi Graph call bằng access token phải kèm HMAC-SHA256 của token với app secret.
        $profile = $this->getJson(
            sprintf(OAuthProviderType::FACEBOOK_PROFILE_URL, rawurlencode($this->apiVersion)),
            [
                'fields' => 'id,email',
                'appsecret_proof' => hash_hmac('sha256', $accessToken, $this->clientSecret),
            ],
            ['Authorization' => 'Bearer ' . $accessToken],
        );
        $email = is_string($profile['email'] ?? null) ? $profile['email'] : null;

        return new OAuthIdentity($this->name(), $this->requiredString($profile, 'id'), $email);
    }
}
