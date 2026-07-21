<?php

declare(strict_types=1);

namespace User\OAuth;

class ZaloProvider extends AbstractOAuthProvider
{
    public function name(): string
    {
        return OAuthProviderType::ZALO;
    }

    public function authorizationUrl(string $redirectUri, string $state, ?string $codeChallenge): string
    {
        $params = [
            'app_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];
        // Zalo v4 bắt buộc PKCE (chỉ hỗ trợ S256, không nhận code_challenge_method).
        if ($codeChallenge !== null) {
            $params['code_challenge'] = $codeChallenge;
        }

        return OAuthProviderType::ZALO_AUTHORIZATION_URL . '?' . http_build_query($params);
    }

    public function fetchIdentity(string $authorizationCode, string $redirectUri, ?string $codeVerifier): OAuthIdentity
    {
        $tokenParams = [
            'app_id' => $this->clientId,
            'code' => $authorizationCode,
            'grant_type' => 'authorization_code',
        ];
        if ($codeVerifier !== null) {
            $tokenParams['code_verifier'] = $codeVerifier;
        }
        $token = $this->postForm(
            OAuthProviderType::ZALO_TOKEN_URL,
            $tokenParams,
            ['secret_key' => $this->clientSecret],
        );
        $accessToken = $this->requiredString($token, 'access_token');
        $profile = $this->getJson(
            OAuthProviderType::ZALO_PROFILE_URL,
            ['fields' => 'id,name'],
            ['access_token' => $accessToken],
        );

        return new OAuthIdentity($this->name(), $this->requiredString($profile, 'id'), null);
    }
}
