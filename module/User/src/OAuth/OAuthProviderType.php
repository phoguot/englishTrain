<?php

declare(strict_types=1);

namespace User\OAuth;

final class OAuthProviderType
{
    public const GOOGLE = 'google';
    public const FACEBOOK = 'facebook';
    public const ZALO = 'zalo';

    public const ALL = [self::GOOGLE, self::FACEBOOK, self::ZALO];

    public const LABELS = [
        self::GOOGLE => 'Google',
        self::FACEBOOK => 'Facebook',
        self::ZALO => 'Zalo',
    ];

    public const ROUTE_PATTERN = self::ZALO . '|' . self::GOOGLE . '|' . self::FACEBOOK;
    public const HTTP_CLIENT_SERVICE = 'user.oauth_http_client';

    public const GOOGLE_AUTHORIZATION_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const GOOGLE_PROFILE_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    public const GOOGLE_SCOPE = 'openid email';

    public const FACEBOOK_AUTHORIZATION_URL = 'https://www.facebook.com/%s/dialog/oauth';
    public const FACEBOOK_TOKEN_URL = 'https://graph.facebook.com/%s/oauth/access_token';
    public const FACEBOOK_PROFILE_URL = 'https://graph.facebook.com/%s/me';
    public const FACEBOOK_SCOPE = 'email';
    public const FACEBOOK_DEFAULT_API_VERSION = 'v23.0';

    public const ZALO_AUTHORIZATION_URL = 'https://oauth.zaloapp.com/v4/permission';
    public const ZALO_TOKEN_URL = 'https://oauth.zaloapp.com/v4/access_token';
    public const ZALO_PROFILE_URL = 'https://graph.zalo.me/v2.0/me';

    private function __construct()
    {
    }

    public static function supportsPkce(string $provider): bool
    {
        // Google (OIDC) và Zalo v4 đều bắt buộc PKCE; Facebook chưa hỗ trợ.
        return $provider === self::GOOGLE || $provider === self::ZALO;
    }
}
