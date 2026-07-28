<?php

declare(strict_types=1);

namespace Report\Service;

use Application\Exception\AccessDeniedException;
use Application\Exception\NotFoundException;
use Application\Exception\ValidationException;
use Assignment\Service\SubmissionService;
use Attendance\Service\AttendanceService;
use Classroom\Model\Classroom\ClassroomDto;
use Classroom\Service\ClassroomService;
use Report\Filter\StudentReport\StudentReportSaveFilter;
use Report\Model\StudentReport\StudentReportMapper;
use Report\Model\StudentReport\StudentReportModel;
use User\Service\UserService;

class ReportService
{
    public function __construct(
        private readonly StudentReportMapper $reportMapper,
        private readonly ClassroomService $classroomService,
        private readonly UserService $userService,
        private readonly AttendanceService $attendanceService,
        private readonly SubmissionService $submissionService,
        private readonly ReportHtmlSanitizer $htmlSanitizer,
    ) {
    }

    /**
     * Lớp này đã có report nào chưa — Application hỏi trước khi cho xóa lớp,
     * để không xóa mất report đã viết cho học sinh (docs/04-contracts.md).
     *
     * Đây là ngoại lệ duy nhất của luật "không ai gọi ngược vào Report": chỉ ĐẾM,
     * không trả nội dung report ra ngoài.
     */
    public function countByClassroom(int $classroomId): int
    {
        return $this->reportMapper->countStudentReportByClassroom($classroomId);
    }

    /** @return array{classroom:ClassroomDto,students:array,reports:array} */
    public function listForTeacher(int $classroomId, int $teacherId): array
    {
        $classroom = $this->assertTeacherOwnsClassroom($classroomId, $teacherId, false);
        $studentIds = $this->classroomService->studentIds($classroomId);
        $reports = $this->reportMapper->searchTeacherReport($classroomId, $teacherId);
        $members = $this->userService->findMany($studentIds);
        $reportStudentIds = array_map(
            static fn (StudentReportModel $report): int => (int) $report->getStudentId(),
            $reports,
        );
        $studentNames = $this->userService->findMany(
            array_values(array_unique(array_merge($studentIds, $reportStudentIds))),
        );

        $rows = [];
        foreach ($reports as $report) {
            $studentId = (int) $report->getStudentId();
            $rows[] = ['report' => $report, 'studentName' => $studentNames[$studentId]->fullName ?? '(không rõ)'];
        }

        return ['classroom' => $classroom, 'students' => $members, 'reports' => $rows];
    }

    /** @return array{student:object,classroom:ClassroomDto,attendance:object,avgScore:?float,counts:array} */
    public function getComposerData(int $studentId, int $classroomId, string $periodLabel, int $teacherId): array
    {
        $classroom = $this->assertTeacherOwnsClassroom($classroomId, $teacherId, true);
        if (!$this->classroomService->isStudentIn($studentId, $classroomId)) {
            throw new AccessDeniedException('Học sinh không thuộc lớp này.');
        }

        return $this->buildComposerData($studentId, $classroom, $periodLabel);
    }

    /** @return array{student:object,classroom:ClassroomDto,attendance:object,avgScore:?float,counts:array} */
    public function getEditComposerData(int $reportId, string $periodLabel, int $teacherId): array
    {
        $report = $this->getOwnedReport($reportId, $teacherId);
        $classroom = $this->assertTeacherOwnsClassroom((int) $report->getClassroomId(), $teacherId, true);

        return $this->buildComposerData((int) $report->getStudentId(), $classroom, $periodLabel);
    }

    /** @return array{student:object,classroom:ClassroomDto,attendance:object,avgScore:?float,counts:array} */
    private function buildComposerData(int $studentId, ClassroomDto $classroom, string $periodLabel): array
    {
        $student = $this->userService->find($studentId);
        if ($student === null || $student->role !== 'student') {
            throw new NotFoundException('Học sinh không tồn tại.');
        }

        $classroomId = $classroom->id;

        return [
            'student' => $student,
            'classroom' => $classroom,
            'attendance' => $this->attendanceService->summary($studentId, $classroomId, $periodLabel),
            'avgScore' => $this->submissionService->avgScore($studentId, $classroomId, $periodLabel),
            'counts' => $this->submissionService->countByStatus($studentId, $classroomId, $periodLabel),
        ];
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, int $teacherId): StudentReportModel
    {
        $values = $this->validate($data, $teacherId);
        $classroomId = (int) $values['classroom_id'];
        $studentId = (int) $values['student_id'];
        $this->assertTeacherOwnsClassroom($classroomId, $teacherId, true);
        if (!$this->classroomService->isStudentIn($studentId, $classroomId)) {
            throw new AccessDeniedException('Học sinh không thuộc lớp này.');
        }
        $this->assertNoDuplicate($studentId, $classroomId, (string) $values['period_label']);

        $report = new StudentReportModel();
        $report->setStudentId($studentId);
        $report->setClassroomId($classroomId);
        $report->setTeacherId($teacherId);
        $report->setPeriodLabel((string) $values['period_label']);
        $report->setContent((string) $values['content']);

        return $this->reportMapper->saveStudentReport($report);
    }

    public function getEditable(int $id, int $teacherId): StudentReportModel
    {
        $report = $this->getOwnedReport($id, $teacherId);
        if ($report->isPublished()) {
            throw new AccessDeniedException('Report đã xuất bản nên không thể sửa.');
        }
        $this->assertTeacherOwnsClassroom((int) $report->getClassroomId(), $teacherId, true);
        return $report;
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, int $teacherId): StudentReportModel
    {
        $report = $this->getEditable($id, $teacherId);
        $data['classroom_id'] = $report->getClassroomId();
        $data['student_id'] = $report->getStudentId();
        $values = $this->validate($data, $teacherId, (int) $report->getStudentId());
        $this->assertNoDuplicate(
            (int) $report->getStudentId(),
            (int) $report->getClassroomId(),
            (string) $values['period_label'],
            $id,
        );
        $report->setPeriodLabel((string) $values['period_label']);
        $report->setContent((string) $values['content']);
        if (!$this->reportMapper->updateAttrsStudentReport(
            $id,
            (string) $report->getPeriodLabel(),
            (string) $report->getContent(),
        )) {
            throw new AccessDeniedException('Report không còn ở trạng thái có thể sửa.');
        }
        return $report;
    }

    public function publish(int $id, int $teacherId): int
    {
        $report = $this->getEditable($id, $teacherId);
        if (!$this->reportMapper->publishStudentReport($id)) {
            throw new AccessDeniedException('Report không còn ở trạng thái có thể xuất bản.');
        }
        return (int) $report->getClassroomId();
    }

    /** @return array<int,array{report:StudentReportModel,studentName:string,classroomName:string,teacherName:string}> */
    public function listPublishedForStudent(int $studentId): array
    {
        $student = $this->userService->find($studentId);
        if ($student === null || $student->role !== 'student') {
            throw new NotFoundException('Học sinh không tồn tại.');
        }
        $reports = $this->reportMapper->searchPublishedForStudent($studentId);
        $teacherIds = array_map(static fn (StudentReportModel $r): int => (int) $r->getTeacherId(), $reports);
        $teachers = $this->userService->findMany($teacherIds);
        $classrooms = $this->classroomService->findMany(array_map(
            static fn (StudentReportModel $report): int => (int) $report->getClassroomId(),
            $reports,
        ));
        $rows = [];
        foreach ($reports as $report) {
            $classroomId = (int) $report->getClassroomId();
            $rows[] = [
                'report' => $report,
                'studentName' => $student->fullName,
                'classroomName' => $classrooms[$classroomId]->name ?? '(không rõ)',
                'teacherName' => $teachers[(int) $report->getTeacherId()]->fullName ?? '(không rõ)',
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validate(array $data, int $teacherId, ?int $historicalStudentId = null): array
    {
        $filter = new StudentReportSaveFilter(
            $this->classroomService,
            $this->htmlSanitizer,
            $teacherId,
            $data,
            $historicalStudentId,
        );
        $filter->setData($data);
        if (!$filter->isValid()) {
            throw ValidationException::fromFilterMessages($filter->getMessages());
        }
        return $filter->getValues();
    }

    private function assertTeacherOwnsClassroom(int $classroomId, int $teacherId, bool $mustBeActive): ClassroomDto
    {
        $classroom = $this->classroomService->find($classroomId);
        if ($classroom === null) {
            throw new NotFoundException('Lớp không tồn tại.');
        }
        if (!$this->classroomService->isTeacherOf($teacherId, $classroomId)) {
            throw new AccessDeniedException('Bạn không phụ trách lớp này.');
        }
        if ($mustBeActive && $classroom->isArchived()) {
            throw new AccessDeniedException('Lớp đã lưu trữ nên không thể tạo hoặc sửa report.');
        }
        return $classroom;
    }

    private function getOwnedReport(int $id, int $teacherId): StudentReportModel
    {
        $report = $this->reportMapper->getStudentReport($id);
        if ($report === null) {
            throw new NotFoundException('Report không tồn tại.');
        }
        if ((int) $report->getTeacherId() !== $teacherId
            || !$this->classroomService->isTeacherOf($teacherId, (int) $report->getClassroomId())
        ) {
            throw new AccessDeniedException('Bạn không có quyền thao tác report này.');
        }
        return $report;
    }

    private function assertNoDuplicate(int $studentId, int $classroomId, string $periodLabel, ?int $exceptId = null): void
    {
        if ($this->reportMapper->findDuplicate($studentId, $classroomId, $periodLabel, $exceptId) !== null) {
            throw new ValidationException(['period_label' => 'Học sinh này đã có report cho kỳ đã chọn.']);
        }
    }
}
