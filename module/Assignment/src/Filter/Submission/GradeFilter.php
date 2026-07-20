<?php

declare(strict_types=1);

namespace Assignment\Filter\Submission;

use Laminas\Filter\StringTrim;
use Laminas\Filter\StripTags;
use Laminas\Filter\ToFloat;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Between;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * Validate form chấm điểm. Thang điểm 0–100 (khớp thang auto_score của QuizGrader) —
 * quy ước chung cho mọi loại bài tập, xem module/Assignment/CLAUDE.md.
 */
class GradeFilter extends InputFilter
{
    public function __construct()
    {
        $this->add([
            'name'       => 'score',
            'required'   => true,
            'filters'    => [['name' => ToFloat::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng nhập điểm.']]],
                [
                    'name'    => Between::class,
                    'options' => [
                        'min'      => 0,
                        'max'      => 100,
                        'messages' => [Between::NOT_BETWEEN => 'Điểm phải trong khoảng 0–100.'],
                    ],
                ],
            ],
        ]);

        $this->add([
            'name'       => 'feedback',
            'required'   => false,
            'filters'    => [['name' => StringTrim::class], ['name' => StripTags::class]],
            'validators' => [
                ['name' => StringLength::class, 'options' => ['max' => 2000, 'messages' => [StringLength::TOO_LONG => 'Nhận xét tối đa 2000 ký tự.']]],
            ],
        ]);
    }
}
