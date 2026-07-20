<?php

declare(strict_types=1);

namespace Report\Filter\StudentReport;

use Classroom\Service\ClassroomService;
use Laminas\Filter\Callback;
use Laminas\Filter\StringTrim;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\InArray;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\Regex;
use Report\Service\ReportHtmlSanitizer;

class StudentReportSaveFilter extends InputFilter
{
    /** @param array<string,mixed> $rawData */
    public function __construct(
        ClassroomService $classroomService,
        ReportHtmlSanitizer $sanitizer,
        int $teacherId,
        array $rawData,
        ?int $historicalStudentId = null,
    ) {
        $classroomIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $classroomService->listRowsForActor($teacherId, 'teacher'),
        );
        $classroomId = is_numeric($rawData['classroom_id'] ?? null) ? (int) $rawData['classroom_id'] : 0;
        $studentIds = in_array($classroomId, $classroomIds, true)
            ? $classroomService->studentIds($classroomId)
            : [];
        if ($historicalStudentId !== null && $historicalStudentId > 0) {
            $studentIds[] = $historicalStudentId;
            $studentIds = array_values(array_unique($studentIds));
        }

        $this->add(['name' => 'id', 'required' => false, 'filters' => [['name' => ToInt::class]]]);
        $this->add([
            'name' => 'classroom_id',
            'required' => true,
            'filters' => [['name' => ToInt::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng chọn lớp.']]],
                ['name' => InArray::class, 'options' => ['haystack' => $classroomIds, 'messages' => [InArray::NOT_IN_ARRAY => 'Lớp không hợp lệ hoặc bạn không phụ trách lớp này.']]],
            ],
        ]);
        $this->add([
            'name' => 'student_id',
            'required' => true,
            'filters' => [['name' => ToInt::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng chọn học sinh.']]],
                ['name' => InArray::class, 'options' => ['haystack' => $studentIds, 'messages' => [InArray::NOT_IN_ARRAY => 'Học sinh không thuộc lớp đã chọn.']]],
            ],
        ]);
        $this->add([
            'name' => 'period_label',
            'required' => true,
            'filters' => [['name' => StringTrim::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng nhập kỳ báo cáo.']]],
                ['name' => Regex::class, 'options' => ['pattern' => '/^\d{4}-(0[1-9]|1[0-2])$/', 'messages' => [Regex::NOT_MATCH => 'Kỳ báo cáo phải theo định dạng YYYY-MM, ví dụ 2026-07.']]],
            ],
        ]);
        $this->add([
            'name' => 'content',
            'required' => true,
            'filters' => [
                ['name' => StringTrim::class],
                ['name' => Callback::class, 'options' => ['callback' => [$sanitizer, 'sanitize']]],
            ],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng nhập nội dung nhận xét.']]],
            ],
        ]);
    }
}
