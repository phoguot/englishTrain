<?php

declare(strict_types=1);

namespace Assignment\Filter\Assignment;

use Laminas\InputFilter\FileInput;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\File\Extension;
use Laminas\Validator\File\Size;
use Laminas\Validator\File\UploadFile;

/**
 * Validate file câu hỏi trắc nghiệm giáo viên tải lên (bước nhập hàng loạt).
 * Chỉ kiểm phần "vỏ" của file: có upload thật không, đuôi file, dung lượng.
 * Nội dung bên trong do QuizImportService phân tích (định dạng bảng/đoạn văn thay đổi được,
 * không hợp với InputFilter — cùng lý do QuizJsonBuilder tách riêng).
 */
class QuizImportFilter extends InputFilter
{
    /** File nhỏ (danh sách câu hỏi), 2MB là quá đủ — chặn sớm để không phải parse file rác. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @var string[] .doc/.odt cũ không đọc được bằng ext-zip + PhpSpreadsheet, không nhận. */
    public const ALLOWED_EXTENSIONS = ['xlsx', 'xls', 'csv', 'docx'];

    public function __construct()
    {
        $input = new FileInput('quiz_file');
        $input->setRequired(true);

        // FileInput tự gắn sẵn UploadFile ở đầu chuỗi; khai lại để đổi message sang tiếng Việt.
        $input->getValidatorChain()
            ->attach(new UploadFile([
                'messages' => [
                    UploadFile::NO_FILE       => 'Vui lòng chọn file câu hỏi.',
                    UploadFile::INI_SIZE      => 'File vượt quá dung lượng máy chủ cho phép.',
                    UploadFile::FORM_SIZE     => 'File vượt quá dung lượng cho phép.',
                    UploadFile::PARTIAL       => 'File tải lên chưa hoàn tất, vui lòng thử lại.',
                    UploadFile::NO_TMP_DIR    => 'Máy chủ không nhận được file. Vui lòng thử lại.',
                    UploadFile::CANT_WRITE    => 'Máy chủ không ghi được file tạm. Vui lòng thử lại.',
                    UploadFile::EXTENSION     => 'Máy chủ từ chối định dạng file này.',
                    UploadFile::ATTACK        => 'File tải lên không hợp lệ.',
                    UploadFile::FILE_NOT_FOUND => 'Không tìm thấy file tải lên.',
                    UploadFile::UNKNOWN       => 'Không tải được file lên. Vui lòng thử lại.',
                ],
            ]))
            ->attach(new Size([
                'max'      => self::MAX_BYTES,
                'messages' => [
                    Size::TOO_BIG => sprintf('File quá lớn (tối đa %d MB).', (int) (self::MAX_BYTES / 1024 / 1024)),
                ],
            ]))
            ->attach(new Extension([
                'extension' => self::ALLOWED_EXTENSIONS,
                'messages'  => [
                    Extension::FALSE_EXTENSION => 'Chỉ nhận file .xlsx, .xls, .csv hoặc .docx. '
                        . 'File .doc cũ vui lòng mở bằng Word rồi lưu lại thành .docx.',
                ],
            ]));

        $this->add($input);
    }
}
