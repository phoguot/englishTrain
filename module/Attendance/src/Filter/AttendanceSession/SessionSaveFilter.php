<?php

declare(strict_types=1);

namespace Attendance\Filter\AttendanceSession;

use Classroom\Service\ClassroomService;
use Laminas\Filter\PregReplace;
use Laminas\Filter\StringTrim;
use Laminas\Filter\StripTags;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Date;
use Laminas\Validator\Digits;
use Laminas\Validator\InArray;
use Laminas\Validator\LessThan;
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
    public function __construct(ClassroomService $classroomService, int $teacherId, int $role)
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
            'name'       => 'shift_label',
            'required'   => false,
            'filters'    => [
                ['name' => StringTrim::class],
                ['name' => StripTags::class],
            ],
            'validators' => [
                [
                    'name'    => StringLength::class,
                    'options' => ['max' => 50, 'messages' => [StringLength::TOO_LONG => 'Nhãn ca học tối đa 50 ký tự.']],
                ],
            ],
        ]);

        // Đơn giá buổi này. Để TRỐNG là hợp lệ → Service lấy đơn giá mặc định của lớp.
        // Bộ filter/validator y hệt Classroom\Filter\Classroom\ClassroomSaveFilter — sửa thì sửa cả 2.
        $this->add([
            'name'       => 'fee_per_session',
            'required'   => false,
            'filters'    => [
                ['name' => StringTrim::class],
                ['name' => PregReplace::class, 'options' => ['pattern' => '/[.,\s]/', 'replacement' => '']],
            ],
            'validators' => [
                ['name' => Digits::class, 'options' => ['messages' => [Digits::NOT_DIGITS => 'Đơn giá buổi học chỉ được chứa chữ số.']]],
                [
                    'name'    => LessThan::class,
                    'options' => [
                        'max'      => 100000000,
                        'messages' => [LessThan::NOT_LESS => 'Đơn giá buổi học phải nhỏ hơn 100.000.000 VNĐ.'],
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
