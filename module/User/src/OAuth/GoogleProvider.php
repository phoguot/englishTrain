<?php

declare(strict_types=1);

namespace User\OAuth;

class GoogleProvider extends AbstractOAuthProvider
{
    public function name(): string
    {
        return OAuthProviderType::GOOGLE;
    }

    public function authorizationUrl(string $redirectUri, string $state, ?string $codeChallenge): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => OAuthProviderType::GOOGLE_SCOPE,
            'state' => $state,
        ];
        if ($codeChallenge !== null) {
            $params['code_challenge'] = $codeChallenge;
            $params['code_challenge_method'] = 'S256';
        }

        return OAuthProviderType::GOOGLE_AUTHORIZATION_URL . '?' . http_build_query($params);
    }

    public function fetchIdentity(string $authorizationCode, string $redirectUri, ?string $codeVerifier): OAuthIdentity
    {
        $params = [
            'code' => $authorizationCode,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ];
        if ($codeVerifier !== null) {
            $params['code_verifier'] = $codeVerifier;
        }
        $token = $this->postForm(OAuthProviderType::GOOGLE_TOKEN_URL, $params);
        $accessToken = $this->requiredString($token, 'access_token');
        $profile = $this->getJson(
            OAuthProviderType::GOOGLE_PROFILE_URL,
            [],
            ['Authorization' => 'Bearer ' . $accessToken],
        );
        $email = is_string($profile['email'] ?? null) ? $profile['email'] : null;

        return new OAuthIdentity($this->name(), $this->requiredString($profile, 'sub'), $email);
    }
}
