<?php

declare(strict_types=1);

namespace Assignment;

use Assignment\Controller\AssignmentController;
use Assignment\Controller\SubmissionController;
use Assignment\Model\Assignment\AssignmentMapper;
use Assignment\Model\Submission\SubmissionMapper;
use Assignment\Service\AssignmentService;
use Assignment\Service\QuizGrader;
use Assignment\Service\QuizJsonBuilder;
use Assignment\Service\R2StorageService;
use Assignment\Service\SubmissionService;
use Classroom\Service\ClassroomService;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\Adapter;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use User\Service\UserService;

return [
    'router' => [
        'routes' => [
            'assignments' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/assignments',
                    'defaults' => [
                        'controller' => AssignmentController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'assignment_create' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/assignments/create',
                    'defaults' => [
                        'controller' => AssignmentController::class,
                        'action'     => 'create',
                    ],
                ],
            ],
            'assignment_edit' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/assignments/edit/:id',
                    'constraints' => ['id' => '[0-9]+'],
                    'defaults'    => [
                        'controller' => AssignmentController::class,
                        'action'     => 'edit',
                    ],
                ],
            ],
            'assignment_submit' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/assignments/:id/submit',
                    'constraints' => ['id' => '[0-9]+'],
                    'defaults'    => [
                        'controller' => AssignmentController::class,
                        'action'     => 'submit',
                    ],
                ],
            ],
            // Không đụng assignment_edit/assignment_submit dù cùng tiền tố /assignments/:
            // route đó có 2 segment sau /assignments/, route này chỉ có 1 (:id) — Laminas phân biệt
            // theo số segment + constraint numeric, không phụ thuộc thứ tự khai báo.
            'assignment_view' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/assignments/:id',
                    'constraints' => ['id' => '[0-9]+'],
                    'defaults'    => [
                        'controller' => AssignmentController::class,
                        'action'     => 'view',
                    ],
                ],
            ],
            'assignment_upload_url' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/assignments/:id/upload-url',
                    'constraints' => ['id' => '[0-9]+'],
                    'defaults'    => [
                        'controller' => AssignmentController::class,
                        'action'     => 'uploadUrl',
                    ],
                ],
            ],
            'assignment_upload_done' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/assignments/:id/upload-done',
                    'constraints' => ['id' => '[0-9]+'],
                    'defaults'    => [
                        'controller' => AssignmentController::class,
                        'action'     => 'uploadDone',
                    ],
                ],
            ],
            'submission_grade' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/submissions/:id/grade',
                    'constraints' => ['id' => '[0-9]+'],
                    'defaults'    => [
                        'controller' => SubmissionController::class,
                        'action'     => 'grade',
                    ],
                ],
            ],
            'submission_video_url' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/submissions/:id/video-url',
                    'constraints' => ['id' => '[0-9]+'],
                    'defaults'    => [
                        'controller' => SubmissionController::class,
                        'action'     => 'videoUrl',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            AssignmentController::class => static fn (ContainerInterface $c): AssignmentController
                => new AssignmentController(
                    $c->get(AssignmentService::class),
                    $c->get(SubmissionService::class),
                    $c->get(ClassroomService::class),
                ),
            SubmissionController::class => static fn (ContainerInterface $c): SubmissionController
                => new SubmissionController($c->get(SubmissionService::class)),
        ],
    ],
    'service_manager' => [
        'factories' => [
            AssignmentMapper::class  => static fn (ContainerInterface $c): AssignmentMapper
                => new AssignmentMapper($c->get(Adapter::class)),
            SubmissionMapper::class  => static fn (ContainerInterface $c): SubmissionMapper
                => new SubmissionMapper($c->get(Adapter::class)),
            QuizGrader::class        => static fn (): QuizGrader => new QuizGrader(),
            QuizJsonBuilder::class   => static fn (): QuizJsonBuilder => new QuizJsonBuilder(),
            R2StorageService::class  => static function (ContainerInterface $c): R2StorageService {
                $config = $c->get('config')['r2'] ?? [];

                return new R2StorageService(is_array($config) ? $config : []);
            },
            AssignmentService::class => static fn (ContainerInterface $c): AssignmentService
                => new AssignmentService(
                    $c->get(AssignmentMapper::class),
                    $c->get(SubmissionMapper::class),
                    $c->get(ClassroomService::class),
                    $c->get(QuizJsonBuilder::class),
                ),
            SubmissionService::class => static fn (ContainerInterface $c): SubmissionService
                => new SubmissionService(
                    $c->get(AssignmentMapper::class),
                    $c->get(SubmissionMapper::class),
                    $c->get(ClassroomService::class),
                    $c->get(UserService::class),
                    $c->get(QuizGrader::class),
                    $c->get(R2StorageService::class),
                ),
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [__DIR__ . '/../view'],
    ],
];
