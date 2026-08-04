<?php

declare(strict_types=1);

namespace Assignment\Filter\Assignment;

use Assignment\Model\Assignment\AssignmentModel;
use Laminas\Filter\StringTrim;
use Laminas\Filter\StripTags;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Date;
use Laminas\Validator\InArray;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;
use User\Model\User\UserModel;
use User\Service\UserService;

/**
 * Validate form tạo/sửa bài tập. Dùng chung create + update (id optional).
 * classroom_id kiểm thuộc quyền teacher ở Controller (giống ClassroomController::handleSave()
 * kiểm teacher_id) — InputFilter chỉ kiểm là số dương.
 * quiz_json KHÔNG validate ở đây — cấu trúc động nhiều câu hỏi, xem QuizJsonBuilder.
 * Tập `type` hợp lệ phụ thuộc loại giáo viên (haystack dựng từ UserService, giống ClassroomSaveFilter):
 * giáo viên `general` không có lựa chọn `video`.
 */
class AssignmentSaveFilter extends InputFilter
{
    /**
     * @param UserService $userService để dựng haystack loại bài theo loại giáo viên
     * @param int $teacherId giáo viên đang tạo/sửa bài — chỉ giáo viên tiếng Anh mới có loại `video`
     * @param string|null $currentType loại của bài đang sửa (null khi tạo mới). Giữ nguyên trong
     *        haystack để giáo viên đã bị chuyển sang `general` vẫn sửa được bài video cũ của mình.
     */
    public function __construct(UserService $userService, int $teacherId, ?string $currentType = null)
    {
        $allowedTypes = [AssignmentModel::TYPE_QUIZ, AssignmentModel::TYPE_ESSAY];
        if (
            $userService->find($teacherId)?->canAssignVideo() === true
            || $currentType === AssignmentModel::TYPE_VIDEO
        ) {
            $allowedTypes[] = AssignmentModel::TYPE_VIDEO;
        }

        $this->add(['name' => 'id', 'required' => false, 'filters' => [['name' => ToInt::class]]]);

        $this->add([
            'name'       => 'classroom_id',
            'required'   => true,
            'filters'    => [['name' => ToInt::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng chọn lớp.']]],
            ],
        ]);

        $this->add([
            'name'       => 'title',
            'required'   => true,
            'filters'    => [['name' => StringTrim::class], ['name' => StripTags::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng nhập tiêu đề bài tập.']]],
                ['name' => StringLength::class, 'options' => ['max' => 200, 'messages' => [StringLength::TOO_LONG => 'Tiêu đề tối đa 200 ký tự.']]],
            ],
        ]);

        $this->add([
            'name'       => 'description',
            'required'   => false,
            'filters'    => [['name' => StringTrim::class]],
        ]);

        $this->add([
            'name'       => 'type',
            'required'   => true,
            'validators' => [
                [
                    'name'    => InArray::class,
                    'options' => [
                        'haystack' => $allowedTypes,
                        'messages' => [
                            InArray::NOT_IN_ARRAY => 'Loại bài tập không hợp lệ. Bài dạng video chỉ dành cho giáo viên tiếng Anh.',
                        ],
                    ],
                ],
            ],
        ]);

        $this->add([
            'name'       => 'deadline_at',
            'required'   => false,
            'filters'    => [['name' => StringTrim::class]],
            'validators' => [
                [
                    'name'    => Date::class,
                    'options' => [
                        'format'   => 'Y-m-d\\TH:i',
                        'strict'   => true,
                        'messages' => [
                            Date::INVALID      => 'Hạn nộp không hợp lệ.',
                            Date::INVALID_DATE => 'Hạn nộp không hợp lệ.',
                            Date::FALSEFORMAT  => 'Hạn nộp phải đúng định dạng ngày và giờ.',
                        ],
                    ],
                ],
            ],
        ]);

        $this->add([
            'name'       => 'status',
            'required'   => true,
            'validators' => [
                [
                    'name'    => InArray::class,
                    'options' => [
                        'haystack' => [AssignmentModel::STATUS_DRAFT, AssignmentModel::STATUS_PUBLISHED, AssignmentModel::STATUS_CLOSED],
                        'messages' => [InArray::NOT_IN_ARRAY => 'Trạng thái không hợp lệ.'],
                    ],
                ],
            ],
        ]);
    }
}
