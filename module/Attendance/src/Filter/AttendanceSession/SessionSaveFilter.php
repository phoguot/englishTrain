<?php

declare(strict_types=1);

namespace Attendance\Filter\AttendanceSession;

use Classroom\Service\ClassroomService;
use Laminas\Filter\StringTrim;
use Laminas\Filter\StripTags;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Date;
use Laminas\Validator\InArray;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * Validate form tạo buổi học.
 * MỌI kiểm tra giá trị nằm ở đây, kể cả "classroom_id có đúng là lớp giáo viên này phụ trách
 * không" (haystack dựng từ ClassroomService) — Service không tự kiểm value bằng tay.
 *
 * Lưu ý: InArray ở đây chống tamper dropdown, KHÔNG thay được kiểm quyền sở hữu —
 * Service vẫn gọi isTeacherOf() trước khi ghi (.claude/rules/01-bao-mat.md).
 * Xem docs/code-standards/crud-convention.md.
 */
class SessionSaveFilter extends InputFilter
{
    public function __construct(ClassroomService $classroomService, int $teacherId, string $role)
    {
        $classroomIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $classroomService->listRowsForActor($teacherId, $role),
        );

        $this->add([
            'name'       => 'classroom_id',
            'required'   => true,
            'filters'    => [['name' => ToInt::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng chọn lớp.']]],
                [
                    'name'    => InArray::class,
                    'options' => [
                        'haystack' => $classroomIds,
                        'messages' => [InArray::NOT_IN_ARRAY => 'Lớp không hợp lệ hoặc bạn không phụ trách lớp này.'],
                    ],
                ],
            ],
        ]);

        $this->add([
            'name'       => 'session_date',
            'required'   => true,
            'filters'    => [['name' => StringTrim::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng chọn ngày học.']]],
                [
                    'name'    => Date::class,
                    'options' => [
                        'format'   => 'Y-m-d',
                        'messages' => [
                            Date::INVALID      => 'Ngày học không hợp lệ.',
                            Date::INVALID_DATE => 'Ngày học không hợp lệ.',
                            Date::FALSEFORMAT  => 'Ngày học phải theo định dạng ngày/tháng/năm.',
                        ],
                    ],
                ],
            ],
        ]);

        $this->add([
            'name'       => 'note',
            'required'   => false,
            'filters'    => [
                ['name' => StringTrim::class],
                ['name' => StripTags::class],
            ],
            'validators' => [
                [
                    'name'    => StringLength::class,
                    'options' => ['max' => 255, 'messages' => [StringLength::TOO_LONG => 'Ghi chú buổi học tối đa 255 ký tự.']],
                ],
            ],
        ]);
    }
}
