<?php

declare(strict_types=1);

namespace Classroom\Filter\Classroom;

use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Callback;

class ClassroomStudentsFilter extends InputFilter
{
    /** @param int[] $validStudentIds */
    public function __construct(array $validStudentIds)
    {
        $valid = array_fill_keys(array_map('intval', $validStudentIds), true);
        $this->add([
            'name' => 'student_ids',
            'required' => false,
            'allow_empty' => true,
            'validators' => [[
                'name' => Callback::class,
                'options' => [
                    'callback' => static function (mixed $value) use ($valid): bool {
                        if ($value === null || $value === []) {
                            return true;
                        }
                        if (!is_array($value)) {
                            return false;
                        }
                        foreach ($value as $id) {
                            if (!is_scalar($id) || !ctype_digit((string) $id) || !isset($valid[(int) $id])) {
                                return false;
                            }
                        }

                        return true;
                    },
                    'messages' => [Callback::INVALID_VALUE => 'Danh sách học sinh không hợp lệ. Vui lòng chọn lại.'],
                ],
            ]],
        ]);
    }
}
