<?php

declare(strict_types=1);

namespace Classroom;

use Application\Service\ClassroomDeletionService;
use Classroom\Controller\ClassroomController;
use Classroom\Model\Classroom\ClassroomMapper;
use Classroom\Model\ClassroomStudent\ClassroomStudentMapper;
use Classroom\Service\ClassroomService;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\Adapter;
use Laminas\Router\Http\Segment;
use User\Service\UserService;

return [
    'router' => [
        'routes' => [
            // index, create, edit/:id  — action bắt buộc bắt đầu bằng chữ nên không nuốt /:id/students
            'classrooms' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/classrooms[/:action[/:id]]',
                    'constraints' => [
                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                        'id'     => '[0-9]+',
                    ],
                    'defaults'    => [
                        'controller' => ClassroomController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            // /classrooms/:id/students
            'classroom_students' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/classrooms/:id/students',
                    'constraints' => ['id' => '[0-9]+'],
                    'defaults'    => [
                        'controller' => ClassroomController::class,
                        'action'     => 'students',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            ClassroomController::class => static fn (ContainerInterface $c): ClassroomController
                => new ClassroomController(
                    $c->get(ClassroomService::class),
                    $c->get(UserService::class),
                    $c->get(ClassroomDeletionService::class),
                ),
        ],
    ],
    'service_manager' => [
        'factories' => [
            ClassroomMapper::class        => static fn (ContainerInterface $c): ClassroomMapper
                => new ClassroomMapper($c->get(Adapter::class)),
            ClassroomStudentMapper::class => static fn (ContainerInterface $c): ClassroomStudentMapper
                => new ClassroomStudentMapper($c->get(Adapter::class)),
            ClassroomService::class       => static fn (ContainerInterface $c): ClassroomService
                => new ClassroomService(
                    $c->get(ClassroomMapper::class),
                    $c->get(ClassroomStudentMapper::class),
                    $c->get(UserService::class),
                    $c->get(Adapter::class),
                ),
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [__DIR__ . '/../view'],
    ],
];
