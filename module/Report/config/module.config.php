<?php

declare(strict_types=1);

namespace Report;

use Assignment\Service\SubmissionService;
use Attendance\Service\AttendanceService;
use Classroom\Service\ClassroomService;
use Interop\Container\ContainerInterface;
use Laminas\Db\Adapter\Adapter;
use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Report\Controller\ReportController;
use Report\Model\StudentReport\StudentReportMapper;
use Report\Service\ReportHtmlSanitizer;
use Report\Service\ReportService;
use User\Service\UserService;

return [
    'router' => ['routes' => [
        'reports' => ['type' => Literal::class, 'options' => ['route' => '/reports', 'defaults' => ['controller' => ReportController::class, 'action' => 'index']]],
        'report_create' => ['type' => Literal::class, 'options' => ['route' => '/reports/create', 'defaults' => ['controller' => ReportController::class, 'action' => 'create']]],
        'report_edit' => ['type' => Segment::class, 'options' => ['route' => '/reports/edit/:id', 'constraints' => ['id' => '[0-9]+'], 'defaults' => ['controller' => ReportController::class, 'action' => 'edit']]],
        'report_publish' => ['type' => Segment::class, 'options' => ['route' => '/reports/:id/publish', 'constraints' => ['id' => '[0-9]+'], 'defaults' => ['controller' => ReportController::class, 'action' => 'publish']]],
        'my_reports' => ['type' => Literal::class, 'options' => ['route' => '/my-reports', 'defaults' => ['controller' => ReportController::class, 'action' => 'myReports']]],
    ]],
    'controllers' => ['factories' => [
        ReportController::class => static fn (ContainerInterface $c): ReportController => new ReportController($c->get(ReportService::class)),
    ]],
    'service_manager' => ['factories' => [
        StudentReportMapper::class => static fn (ContainerInterface $c): StudentReportMapper => new StudentReportMapper($c->get(Adapter::class)),
        ReportHtmlSanitizer::class => static fn (): ReportHtmlSanitizer => new ReportHtmlSanitizer(),
        ReportService::class => static fn (ContainerInterface $c): ReportService => new ReportService(
            $c->get(StudentReportMapper::class),
            $c->get(ClassroomService::class),
            $c->get(UserService::class),
            $c->get(AttendanceService::class),
            $c->get(SubmissionService::class),
            $c->get(ReportHtmlSanitizer::class),
        ),
    ]],
    'view_manager' => ['template_path_stack' => [__DIR__ . '/../view']],
];
