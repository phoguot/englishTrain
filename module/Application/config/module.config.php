<?php

declare(strict_types=1);

namespace Application;

use Application\Controller\BaseController;
use Application\Controller\IndexController;
use Application\Service\DashboardService;
use Application\Service\UserAccountAdministrationService;
use Application\View\Helper\CurrentUser;
use Assignment\Service\AssignmentService;
use Classroom\Service\ClassroomService;
use Interop\Container\ContainerInterface;
use Laminas\Router\Http\Literal;
use User\Service\AuthService;
use User\Service\UserService;

return [
    'router' => [
        'routes' => [
            'home' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            IndexController::class => static fn (ContainerInterface $c): IndexController
                => new IndexController($c->get(DashboardService::class)),
        ],
        // Tiêm AuthService vào MỌI controller kế thừa BaseController — auth wiring tập trung 1 chỗ.
        'initializers' => [
            static function (ContainerInterface $container, object $instance): void {
                if ($instance instanceof BaseController) {
                    $instance->setAuthService($container->get(AuthService::class));
                    $instance->setContainer($container);
                }
            },
        ],
    ],
    'service_manager' => [
        'factories' => [
            DashboardService::class => static fn (ContainerInterface $c): DashboardService
                => new DashboardService(
                    $c->get(UserService::class),
                    $c->get(ClassroomService::class),
                    $c->get(AssignmentService::class),
                ),
            UserAccountAdministrationService::class => static fn (ContainerInterface $c): UserAccountAdministrationService
                => new UserAccountAdministrationService(
                    $c->get(UserService::class),
                    $c->get(ClassroomService::class),
                ),
        ],
    ],
    'view_helpers' => [
        'factories' => [
            CurrentUser::class => static fn (ContainerInterface $c): CurrentUser
                => new CurrentUser($c->get(AuthService::class), $c->get(UserService::class)),
        ],
        'aliases' => [
            'currentUser' => CurrentUser::class,
        ],
    ],
    'view_manager' => [
        'display_not_found_reason' => false,
        'display_exceptions'       => false,
        'not_found_template'       => 'error/404',
        'exception_template'       => 'error/index',
        'template_map'             => [
            'layout/layout'           => __DIR__ . '/../view/layout/layout.phtml',
            'application/index/index' => __DIR__ . '/../view/application/index/index.phtml',
            'error/403'               => __DIR__ . '/../view/error/403.phtml',
            'error/404'               => __DIR__ . '/../view/error/404.phtml',
            'error/index'             => __DIR__ . '/../view/error/index.phtml',
        ],
        'template_path_stack'      => [__DIR__ . '/../view'],
    ],
];
