<?php

declare(strict_types=1);

namespace User;

use Application\Service\UserAccountAdministrationService;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\Adapter;
use Laminas\Http\Client;
use Laminas\Http\Client\Adapter\Curl as CurlAdapter;
use Laminas\Http\Client\Adapter\Socket as SocketAdapter;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use User\Controller\AuthController;
use User\Controller\AccountIdentityController;
use User\Controller\AdminIdentityController;
use User\Controller\IdentityController;
use User\Controller\OAuthController;
use User\Controller\UserController;
use User\Model\User\UserMapper;
use User\Model\OAuthLinkThrottle\OAuthLinkThrottleMapper;
use User\Model\UserIdentity\UserIdentityMapper;
use User\Model\UserLinkToken\UserLinkTokenMapper;
use User\OAuth\FacebookProvider;
use User\OAuth\GoogleProvider;
use User\OAuth\OAuthProviderRegistry;
use User\OAuth\OAuthProviderType;
use User\OAuth\ZaloProvider;
use User\Service\AuthService;
use User\Service\OAuthService;
use User\Service\OAuthLoginService;
use User\Service\UserIdentityService;
use User\Service\UserLinkTokenService;
use User\Service\UserService;

return [
    'router' => [
        'routes' => [
            'login' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/login',
                    'defaults' => [
                        'controller' => AuthController::class,
                        'action'     => 'login',
                    ],
                ],
            ],
            'logout' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/logout',
                    'defaults' => [
                        'controller' => AuthController::class,
                        'action'     => 'logout',
                    ],
                ],
            ],
            'oauth_start' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/auth/:provider',
                    'constraints' => ['provider' => OAuthProviderType::ROUTE_PATTERN],
                    'defaults' => ['controller' => OAuthController::class, 'action' => 'start'],
                ],
            ],
            'oauth_callback' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/auth/:provider/callback',
                    'constraints' => ['provider' => OAuthProviderType::ROUTE_PATTERN],
                    'defaults' => ['controller' => OAuthController::class, 'action' => 'callback'],
                ],
            ],
            'link_account' => [
                'type' => Literal::class,
                'options' => ['route' => '/link-account', 'defaults' => ['controller' => IdentityController::class, 'action' => 'linkAccount']],
            ],
            'account_identities' => [
                'type' => Literal::class,
                'options' => ['route' => '/account/identities', 'defaults' => ['controller' => AccountIdentityController::class, 'action' => 'index']],
            ],
            'account_identity_link' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/account/identities/:provider/link',
                    'constraints' => ['provider' => OAuthProviderType::ROUTE_PATTERN],
                    'defaults' => ['controller' => AccountIdentityController::class, 'action' => 'link'],
                ],
            ],
            'account_identity_unlink' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/account/identities/:provider/unlink',
                    'constraints' => ['provider' => OAuthProviderType::ROUTE_PATTERN],
                    'defaults' => ['controller' => AccountIdentityController::class, 'action' => 'unlink'],
                ],
            ],
            'user_identity_invite' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/users/:id/identity-invite',
                    'constraints' => ['id' => '[0-9]+'],
                    'defaults' => ['controller' => AdminIdentityController::class, 'action' => 'invite'],
                ],
            ],
            'user_identity_revoke' => [
                'type' => Segment::class,
                'options' => [
                    'route' => '/users/:id/identities/:provider/revoke',
                    'constraints' => ['id' => '[0-9]+', 'provider' => OAuthProviderType::ROUTE_PATTERN],
                    'defaults' => ['controller' => AdminIdentityController::class, 'action' => 'revoke'],
                ],
            ],
            'users' => [
                'type' => Literal::class,
                'options' => ['route' => '/users', 'defaults' => ['controller' => UserController::class, 'action' => 'index']],
            ],
            'user_create' => [
                'type' => Literal::class,
                'options' => ['route' => '/users/create', 'defaults' => ['controller' => UserController::class, 'action' => 'create']],
            ],
            'user_edit' => [
                'type' => Segment::class,
                'options' => ['route' => '/users/edit/:id', 'constraints' => ['id' => '[0-9]+'], 'defaults' => ['controller' => UserController::class, 'action' => 'edit']],
            ],
            'user_delete' => [
                'type' => Segment::class,
                'options' => ['route' => '/users/delete/:id', 'constraints' => ['id' => '[0-9]+'], 'defaults' => ['controller' => UserController::class, 'action' => 'delete']],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            AuthController::class => static fn (ContainerInterface $c): AuthController
                => new AuthController($c->get(AuthService::class), $c->get(OAuthService::class)),
            OAuthController::class => static fn (ContainerInterface $c): OAuthController
                => new OAuthController(
                    $c->get(OAuthService::class),
                    $c->get(OAuthLoginService::class),
                ),
            IdentityController::class => static fn (ContainerInterface $c): IdentityController
                => new IdentityController($c->get(UserLinkTokenService::class), $c->get(OAuthService::class)),
            AccountIdentityController::class => static fn (ContainerInterface $c): AccountIdentityController
                => new AccountIdentityController($c->get(UserIdentityService::class), $c->get(OAuthService::class)),
            AdminIdentityController::class => static fn (ContainerInterface $c): AdminIdentityController
                => new AdminIdentityController(
                    $c->get(UserLinkTokenService::class),
                    $c->get(UserIdentityService::class),
                    $c->get(UserService::class),
                ),
            UserController::class => static fn (ContainerInterface $c): UserController
                => new UserController(
                    $c->get(UserService::class),
                    $c->get(UserAccountAdministrationService::class),
                    $c->get(UserIdentityService::class),
                ),
        ],
    ],
    'service_manager' => [
        'factories' => [
            UserMapper::class  => static fn (ContainerInterface $c): UserMapper
                => new UserMapper($c->get(Adapter::class)),
            UserIdentityMapper::class => static fn (ContainerInterface $c): UserIdentityMapper
                => new UserIdentityMapper($c->get(Adapter::class)),
            UserLinkTokenMapper::class => static fn (ContainerInterface $c): UserLinkTokenMapper
                => new UserLinkTokenMapper($c->get(Adapter::class)),
            OAuthLinkThrottleMapper::class => static fn (ContainerInterface $c): OAuthLinkThrottleMapper
                => new OAuthLinkThrottleMapper($c->get(Adapter::class)),
            AuthService::class => static fn (ContainerInterface $c): AuthService
                => new AuthService($c->get(UserMapper::class)),
            UserIdentityService::class => static fn (ContainerInterface $c): UserIdentityService
                => new UserIdentityService(
                    $c->get(Adapter::class),
                    $c->get(UserIdentityMapper::class),
                    $c->get(UserLinkTokenMapper::class),
                    $c->get(UserMapper::class),
                ),
            UserLinkTokenService::class => static fn (ContainerInterface $c): UserLinkTokenService
                => new UserLinkTokenService(
                    $c->get(UserLinkTokenMapper::class),
                    $c->get(OAuthLinkThrottleMapper::class),
                    $c->get(UserMapper::class),
                    $c->get(Adapter::class),
                ),
            UserService::class => static fn (ContainerInterface $c): UserService
                => new UserService(
                    $c->get(UserMapper::class),
                    $c->get(UserIdentityService::class),
                    $c->get(Adapter::class),
                ),
            OAuthProviderType::HTTP_CLIENT_SERVICE => static function (): Client {
                // maxredirects=0: không đi theo redirect (chống SSRF); luôn verify TLS khi gọi provider.
                $options = ['timeout' => 10, 'maxredirects' => 0];
                if (extension_loaded('curl')) {
                    $options['adapter'] = CurlAdapter::class;
                    $options['curloptions'] = [
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_CONNECTTIMEOUT => 10,
                    ];
                } else {
                    $options['adapter'] = SocketAdapter::class;
                    $options['sslverifypeer'] = true;
                    $options['sslverifypeername'] = true;
                }
                $client = new Client();
                $client->setOptions($options);

                return $client;
            },
            OAuthProviderRegistry::class => static function (ContainerInterface $c): OAuthProviderRegistry {
                $config = $c->get('config')['oauth'] ?? [];
                $google = is_array($config[OAuthProviderType::GOOGLE] ?? null)
                    ? $config[OAuthProviderType::GOOGLE]
                    : [];
                $facebook = is_array($config[OAuthProviderType::FACEBOOK] ?? null)
                    ? $config[OAuthProviderType::FACEBOOK]
                    : [];
                $zalo = is_array($config[OAuthProviderType::ZALO] ?? null)
                    ? $config[OAuthProviderType::ZALO]
                    : [];

                return new OAuthProviderRegistry([
                    new GoogleProvider($c->get(OAuthProviderType::HTTP_CLIENT_SERVICE), (string) ($google['client_id'] ?? ''), (string) ($google['client_secret'] ?? '')),
                    new FacebookProvider(
                        $c->get(OAuthProviderType::HTTP_CLIENT_SERVICE),
                        (string) ($facebook['client_id'] ?? ''),
                        (string) ($facebook['client_secret'] ?? ''),
                        (string) ($facebook['api_version'] ?? OAuthProviderType::FACEBOOK_DEFAULT_API_VERSION),
                    ),
                    new ZaloProvider($c->get(OAuthProviderType::HTTP_CLIENT_SERVICE), (string) ($zalo['client_id'] ?? ''), (string) ($zalo['client_secret'] ?? '')),
                ]);
            },
            OAuthService::class => static function (ContainerInterface $c): OAuthService {
                $config = $c->get('config');

                return new OAuthService(
                    $c->get(OAuthProviderRegistry::class),
                    is_array($config['oauth'] ?? null) ? $config['oauth'] : [],
                    (string) ($config['app']['public_base_url'] ?? ''),
                );
            },
            OAuthLoginService::class => static fn (ContainerInterface $c): OAuthLoginService
                => new OAuthLoginService(
                    $c->get(OAuthService::class),
                    $c->get(UserIdentityService::class),
                    $c->get(AuthService::class),
                ),
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [__DIR__ . '/../view'],
    ],
];
