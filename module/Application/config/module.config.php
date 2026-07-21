<?php

declare(strict_types=1);

namespace Application;

use Application\Controller\BaseController;
use Application\Controller\IndexController;
use Application\Controller\LandingController;
use Application\Controller\SystemLogController;
use Application\Model\SystemLog\SystemLogMapper;
use Application\Service\DashboardService;
use Application\Service\SystemLogService;
use Application\Service\UserAccountAdministrationService;
use Application\View\Helper\CurrentUser;
use Assignment\Service\AssignmentService;
use Classroom\Service\ClassroomService;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\Adapter;
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
                        'controller' => LandingController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'dashboard' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/dashboard',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'system_logs' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/system-logs',
                    'defaults' => [
                        'controller' => SystemLogController::class,
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
            LandingController::class => static fn (): LandingController => new LandingController(),
            SystemLogController::class => static fn (ContainerInterface $c): SystemLogController
                => new SystemLogController($c->get(SystemLogService::class)),
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
            SystemLogMapper::class => static fn (ContainerInterface $c): SystemLogMapper
                => new SystemLogMapper($c->get(Adapter::class)),
            SystemLogService::class => static fn (ContainerInterface $c): SystemLogService
                => new SystemLogService(
                    $c->get(SystemLogMapper::class),
                    $c->get(AuthService::class),
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
        // ViewJsonStrategy bắt buộc phải khai ở đây thì JsonModel (AssignmentController::uploadUrlAction,
        // uploadDoneAction, SubmissionController) mới được render bằng JSON renderer. Thiếu dòng này,
        // Laminas vẫn dùng PhpRenderer mặc định + InjectTemplateListener tự gắn template theo quy ước
        // "{module}/{controller}/{action}" (vd "assignment/assignment/upload-url") — không có file .phtml
        // nào tên vậy nên resolver ném lỗi thay vì trả JSON.
        'strategies'               => ['ViewJsonStrategy'],
        'display_not_found_reason' => false,
        'display_exceptions'       => false,
        'not_found_template'       => 'error/404',
        'exception_template'       => 'error/index',
        'template_map'             => [
            'layout/layout'           => __DIR__ . '/../view/layout/layout.phtml',
            'application/index/index' => __DIR__ . '/../view/application/index/index.phtml',
            'application/landing/index' => __DIR__ . '/../view/application/landing/index.phtml',
            'application/system-log/index' => __DIR__ . '/../view/application/system-log/index.phtml',
            'error/403'               => __DIR__ . '/../view/error/403.phtml',
            'error/404'               => __DIR__ . '/../view/error/404.phtml',
            'error/index'             => __DIR__ . '/../view/error/index.phtml',
        ],
        'template_path_stack'      => [__DIR__ . '/../view'],
    ],
];
