<?php

declare(strict_types=1);

namespace Assignment\Filter\Submission;

use Assignment\Service\R2StorageService;
use Laminas\Filter\StringTrim;
use Laminas\Filter\ToInt;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Between;
use Laminas\Validator\InArray;
use Laminas\Validator\NotEmpty;
use Laminas\Validator\StringLength;

/**
 * Validate yêu cầu xin presigned URL upload video. Hạn size/mime lấy từ R2StorageService
 * (đọc config r2.max_upload_mb) — không hardcode, giống cách ClassroomSaveFilter nhận
 * UserService để dựng haystack. Xem docs/code-standards/crud-convention.md.
 */
class VideoUploadUrlFilter extends InputFilter
{
    public function __construct(R2StorageService $r2Storage)
    {
        $maxBytes = $r2Storage->maxUploadBytes();
        $maxMb    = (int) round($maxBytes / 1024 / 1024);

        $this->add([
            'name'       => 'filename',
            'required'   => true,
            'filters'    => [['name' => StringTrim::class]],
            'validators' => [
                ['name' => NotEmpty::class, 'options' => ['messages' => [NotEmpty::IS_EMPTY => 'Thiếu tên file.']]],
                ['name' => StringLength::class, 'options' => ['max' => 255, 'messages' => [StringLength::TOO_LONG => 'Tên file quá dài.']]],
            ],
        ]);

        $this->add([
            'name'       => 'size',
            'required'   => true,
            'filters'    => [['name' => ToInt::class]],
            'validators' => [
                [
                    'name'    => Between::class,
                    'options' => [
                        'min'      => 1,
                        'max'      => $maxBytes,
                        'messages' => [
                            Between::NOT_BETWEEN => sprintf('File quá lớn (tối đa %d MB).', $maxMb),
                        ],
                    ],
                ],
            ],
        ]);

        $this->add([
            'name'       => 'mime',
            'required'   => true,
            'validators' => [
                [
                    'name'    => InArray::class,
                    'options' => [
                        'haystack' => $r2Storage->allowedMimeTypes(),
                        'messages' => [InArray::NOT_IN_ARRAY => 'Định dạng video không được hỗ trợ.'],
                    ],
                ],
            ],
        ]);
    }
}
