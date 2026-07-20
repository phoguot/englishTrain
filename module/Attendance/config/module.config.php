<?php

declare(strict_types=1);

namespace Attendance;

use Attendance\Controller\AttendanceController;
use Attendance\Model\AttendanceRecord\AttendanceRecordMapper;
use Attendance\Model\AttendanceSession\AttendanceSessionMapper;
use Attendance\Model\AttendanceSessionStudent\AttendanceSessionStudentMapper;
use Attendance\Service\AttendanceService;
use Attendance\Service\AttendanceSheetBuilder;
use Classroom\Service\ClassroomService;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\Adapter;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use User\Service\UserService;

return [
    'router' => [
        'routes' => [
            'attendance' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/attendance',
                    'defaults' => [
                        'controller' => AttendanceController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'attendance_create' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/attendance/create',
                    'defaults' => [
                        'controller' => AttendanceController::class,
                        'action'     => 'create',
                    ],
                ],
            ],
            'attendance_student' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/attendance/student/:id',
                    'constraints' => ['id' => '[0-9]+'],
                    'defaults'    => [
                        'controller' => AttendanceController::class,
                        'action'     => 'student',
                    ],
                ],
            ],
            // Không nuốt /attendance/create (chữ, không khớp constraint số) và không nuốt
            // /attendance/student/:id (3 segment, route này chỉ 2) — như assignment_view.
            'attendance_mark' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/attendance/:sessionId',
                    'constraints' => ['sessionId' => '[0-9]+'],
                    'defaults'    => [
                        'controller' => AttendanceController::class,
                        'action'     => 'mark',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            AttendanceController::class => static fn (ContainerInterface $c): AttendanceController
                => new AttendanceController(
                    $c->get(AttendanceService::class),
                    $c->get(ClassroomService::class),
                ),
        ],
    ],
    'service_manager' => [
        'factories' => [
            AttendanceSessionMapper::class => static fn (ContainerInterface $c): AttendanceSessionMapper
                => new AttendanceSessionMapper($c->get(Adapter::class)),
            AttendanceRecordMapper::class  => static fn (ContainerInterface $c): AttendanceRecordMapper
                => new AttendanceRecordMapper($c->get(Adapter::class)),
            AttendanceSessionStudentMapper::class => static fn (ContainerInterface $c): AttendanceSessionStudentMapper
                => new AttendanceSessionStudentMapper($c->get(Adapter::class)),
            AttendanceSheetBuilder::class  => static fn (): AttendanceSheetBuilder => new AttendanceSheetBuilder(),
            AttendanceService::class       => static fn (ContainerInterface $c): AttendanceService
                => new AttendanceService(
                    $c->get(AttendanceSessionMapper::class),
                    $c->get(AttendanceRecordMapper::class),
                    $c->get(AttendanceSessionStudentMapper::class),
                    $c->get(ClassroomService::class),
                    $c->get(UserService::class),
                    $c->get(AttendanceSheetBuilder::class),
                    $c->get(Adapter::class),
                ),
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [__DIR__ . '/../view'],
    ],
];
