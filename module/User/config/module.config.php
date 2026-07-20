<?php

declare(strict_types=1);

namespace User;

use Application\Service\UserAccountAdministrationService;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\Adapter;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use User\Controller\AuthController;
use User\Controller\UserController;
use User\Model\User\UserMapper;
use User\Service\AuthService;
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
                => new AuthController($c->get(AuthService::class)),
            UserController::class => static fn (ContainerInterface $c): UserController
                => new UserController(
                    $c->get(UserService::class),
                    $c->get(UserAccountAdministrationService::class),
                ),
        ],
    ],
    'service_manager' => [
        'factories' => [
            UserMapper::class  => static fn (ContainerInterface $c): UserMapper
                => new UserMapper($c->get(Adapter::class)),
            AuthService::class => static fn (ContainerInterface $c): AuthService
                => new AuthService($c->get(UserMapper::class)),
            UserService::class => static fn (ContainerInterface $c): UserService
                => new UserService($c->get(UserMapper::class)),
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [__DIR__ . '/../view'],
    ],
];
