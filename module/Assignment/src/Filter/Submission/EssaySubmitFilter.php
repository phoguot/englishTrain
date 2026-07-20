<?php

declare(strict_types=1);

namespace Assignment\Filter\Submission;

use Laminas\Filter\StringTrim;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\NotEmpty;

/** Validate form nộp bài tự luận. */
class EssaySubmitFilter extends InputFilter
{
    public function __construct()
    {
        $this->add([
            'name'       => 'essay_text',
            'required'   => true,
            'filters'    => [['name' => StringTrim::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Vui lòng nhập nội dung bài làm.']]],
            ],
        ]);
    }
}
