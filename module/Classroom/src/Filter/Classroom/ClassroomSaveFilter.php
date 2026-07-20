<?php

declare(strict_types=1);

namespace Classroom\Filter\Classroom;

use Classroom\Model\Classroom\ClassroomModel;
use Laminas\Filter\StringTrim;
use Laminas\Filter\StripTags;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\InArray;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;
use User\Service\UserService;

/**
 * Validate form tạo/sửa lớp. Dùng chung create + update (id optional).
 * MỌI kiểm tra giá trị nằm ở đây, kể cả "teacher_id có đúng là giáo viên đang hoạt động không"
 * (haystack lấy từ UserService) — Service không tự kiểm value bằng tay.
 * Xem docs/code-standards/crud-convention.md.
 */
class ClassroomSaveFilter extends InputFilter
{
    public function __construct(UserService $userService)
    {
        $teacherIds = array_map(
            static fn ($teacher): int => $teacher->id,
            $userService->findByRole('teacher'),
        );

        $this->add([
            'name'       => 'id',
            'required'   => false,
            'filters'    => [['name' => ToInt::class]],
        ]);

        $this->add([
            'name'       => 'name',
            'required'   => true,
            'filters'    => [
                ['name' => StringTrim::class],
                ['name' => StripTags::class],
            ],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng nhập tên lớp.']]],
                ['name' => StringLength::class, 'options' => ['max' => 100, 'messages' => [StringLength::TOO_LONG => 'Tên lớp tối đa 100 ký tự.']]],
            ],
        ]);

        $this->add([
            'name'       => 'teacher_id',
            'required'   => true,
            'filters'    => [['name' => ToInt::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng chọn giáo viên phụ trách.']]],
                [
                    'name'    => InArray::class,
                    'options' => [
                        'haystack' => $teacherIds,
                        'messages' => [InArray::NOT_IN_ARRAY => 'Giáo viên phụ trách không hợp lệ.'],
                    ],
                ],
            ],
        ]);

        $this->add([
            'name'       => 'schedule_note',
            'required'   => false,
            'filters'    => [
                ['name' => StringTrim::class],
                ['name' => StripTags::class],
            ],
            'validators' => [
                ['name' => StringLength::class, 'options' => ['max' => 255, 'messages' => [StringLength::TOO_LONG => 'Ghi chú lịch học tối đa 255 ký tự.']]],
            ],
        ]);

        $this->add([
            'name'       => 'status',
            'required'   => true,
            'validators' => [
                [
                    'name'    => InArray::class,
                    'options' => [
                        'haystack' => [ClassroomModel::STATUS_ACTIVE, ClassroomModel::STATUS_ARCHIVED],
                        'messages' => [InArray::NOT_IN_ARRAY => 'Trạng thái lớp không hợp lệ.'],
                    ],
                ],
            ],
        ]);
    }
}
