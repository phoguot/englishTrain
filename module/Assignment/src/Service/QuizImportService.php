<?php

declare(strict_types=1);

namespace Assignment\Service;

use Application\Exception\ValidationException;
use Assignment\Filter\Assignment\QuizImportFilter;
use DOMDocument;
use DOMElement;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;
use ZipArchive;

/**
 * Đọc file Excel (.xlsx/.xls/.csv) hoặc Word (.docx) thành danh sách câu hỏi trắc nghiệm
 * đúng shape quiz_json: [{question, options[], correct_index}].
 *
 * Chỉ *đọc và dựng danh sách* — không lưu gì cả. Giáo viên vẫn xem lại/sửa trên form rồi mới
 * bấm lưu, lúc đó QuizJsonBuilder kiểm lần cuối. Vì vậy ở đây dòng hỏng chỉ sinh "warning"
 * (bỏ qua dòng đó, báo cho giáo viên biết) chứ không chặn cả file — trừ khi không đọc được
 * câu nào thì mới ném ValidationException.
 *
 * Logic thuần (chỉ đụng file tạm, không đụng DB) — unit test được.
 *
 * Định dạng nhận vào:
 *
 * A. Excel/CSV — mỗi dòng 1 câu:
 *      | Câu hỏi | Đáp án A | Đáp án B | Đáp án C | Đáp án D | Đáp án đúng |
 *    - Có dòng tiêu đề: tìm cột theo tên ("câu hỏi", "đáp án đúng"), các cột còn lại là đáp án.
 *    - Không có tiêu đề: cột đầu = câu hỏi, cột cuối = đáp án đúng, giữa = các đáp án.
 *    - Ô "đáp án đúng" nhận: A/B/C/D, 1/2/3/4, hoặc chép nguyên nội dung đáp án đúng.
 *
 * B. Word .docx — mỗi câu một khối dòng:
 *      Câu 1: Thủ đô của Anh là gì?
 *      A. Paris
 *      *B. London          ← dấu * đánh dấu đáp án đúng
 *      C. Rome
 *      Đáp án: B           ← hoặc ghi riêng một dòng như thế này
 */
class QuizImportService
{
    /** Chặn file khổng lồ làm treo trình duyệt khi dựng form. */
    public const MAX_QUESTIONS = 200;

    private const MAX_OPTIONS = 10;

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @param array<string,mixed> $data POST thô (đã gồm $_FILES qua getAllPostParams())
     * @return array{questions: array<int, array{question:string,options:string[],correct_index:int}>, warnings: string[]}
     * @throws ValidationException file sai định dạng hoặc không đọc được câu nào
     */
    public function import(array $data): array
    {
        $filter = new QuizImportFilter();
        $filter->setData($data);

        if (!$filter->isValid()) {
            $errors = [];
            foreach ($filter->getMessages() as $field => $messages) {
                $errors[(string) $field] = implode(' ', array_map('strval', (array) $messages));
            }

            throw new ValidationException($errors);
        }

        /** @var array{name?:string,tmp_name?:string} $file */
        $file      = (array) $filter->getValues()['quiz_file'];
        $path      = (string) ($file['tmp_name'] ?? '');
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        $parsed = $extension === 'docx'
            ? $this->parseDocx($path)
            : $this->parseSpreadsheet($path, $extension);

        $result = $this->normalize($parsed['questions'], $parsed['warnings']);

        if ($result['questions'] === []) {
            throw new ValidationException([
                'quiz_file' => 'Không đọc được câu hỏi nào từ file. Vui lòng kiểm tra lại định dạng theo hướng dẫn bên dưới.',
            ]);
        }

        return $result;
    }

    // ── Excel / CSV ─────────────────────────────────────────────────────────

    /**
     * @return array{questions: array<int, array{question:string,options:string[],correct_index:int}>, warnings: string[]}
     * @throws ValidationException
     */
    private function parseSpreadsheet(string $path, string $extension): array
    {
        $readerType = match ($extension) {
            'xls'   => 'Xls',
            'csv'   => 'Csv',
            default => 'Xlsx',
        };

        try {
            $reader = IOFactory::createReader($readerType);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getActiveSheet();
            $rows  = $sheet->toArray(null, true, false, false);
        } catch (Throwable) {
            // Đuôi file đúng nhưng nội dung hỏng / không phải file bảng tính thật.
            throw new ValidationException([
                'quiz_file' => 'Không mở được file bảng tính. File có thể bị hỏng hoặc sai định dạng.',
            ]);
        }

        $rows = array_values(array_filter(
            array_map(static fn (array $row): array => array_values($row), $rows),
            fn (array $row): bool => $this->rowHasContent($row),
        ));

        if ($rows === []) {
            return ['questions' => [], 'warnings' => []];
        }

        $columnCount = max(array_map('count', $rows));
        $layout      = $this->detectLayout($rows[0], $columnCount);
        $startRow    = $layout['hasHeader'] ? 1 : 0;

        $questions = [];
        $warnings  = [];

        for ($i = $startRow, $total = count($rows); $i < $total; $i++) {
            $row  = $rows[$i];
            $line = $i + 1;

            $question = $this->cellText($row[$layout['questionColumn']] ?? null);
            $options  = [];
            foreach ($layout['optionColumns'] as $column) {
                $option = $this->cellText($row[$column] ?? null);
                if ($option !== '') {
                    $options[] = $option;
                }
            }
            $answer = $this->cellText($row[$layout['answerColumn']] ?? null);

            if ($question === '') {
                $warnings[] = "Dòng {$line}: bỏ qua vì thiếu nội dung câu hỏi.";
                continue;
            }

            $questions[] = [
                'question'      => $question,
                'options'       => $options,
                'correct_index' => $this->resolveCorrectIndex($answer, $options),
            ];
        }

        return ['questions' => $questions, 'warnings' => $warnings];
    }

    /**
     * Cột nào là câu hỏi / đáp án / đáp án đúng.
     *
     * @param array<int, mixed> $firstRow
     * @return array{hasHeader:bool, questionColumn:int, answerColumn:int, optionColumns:int[]}
     */
    private function detectLayout(array $firstRow, int $columnCount): array
    {
        $lastColumn = max(1, $columnCount - 1);

        $default = [
            'hasHeader'      => false,
            'questionColumn' => 0,
            'answerColumn'   => $lastColumn,
            // Cột giữa (không phải câu hỏi, không phải đáp án đúng) mới là các phương án.
            'optionColumns'  => $lastColumn >= 2 ? range(1, $lastColumn - 1) : [],
        ];

        $questionColumn = null;
        $answerColumn   = null;
        $labels         = [];
        for ($i = 0; $i < $columnCount; $i++) {
            $label      = $this->slug($this->cellText($firstRow[$i] ?? null));
            $labels[$i] = $label;

            if ($answerColumn === null && ($this->contains($label, ['dap an dung', 'da dung', 'correct', 'answer key', 'key']))) {
                $answerColumn = $i;
                continue;
            }
            if ($questionColumn === null && $this->contains($label, ['cau hoi', 'question', 'noi dung cau hoi'])) {
                $questionColumn = $i;
            }
        }

        if ($questionColumn === null && $answerColumn === null) {
            return $default;
        }

        $questionColumn = $questionColumn ?? 0;
        $answerColumn   = $answerColumn ?? $lastColumn;

        $optionColumns = [];
        for ($i = 0; $i < $columnCount; $i++) {
            if ($i !== $questionColumn && $i !== $answerColumn && $labels[$i] !== '') {
                $optionColumns[] = $i;
            }
        }

        return [
            'hasHeader'      => true,
            'questionColumn' => $questionColumn,
            'answerColumn'   => $answerColumn,
            'optionColumns'  => $optionColumns === [] ? $default['optionColumns'] : $optionColumns,
        ];
    }

    // ── Word .docx ──────────────────────────────────────────────────────────

    /**
     * @return array{questions: array<int, array{question:string,options:string[],correct_index:int}>, warnings: string[]}
     * @throws ValidationException
     */
    private function parseDocx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ValidationException([
                'quiz_file' => 'Không mở được file Word. File có thể bị hỏng hoặc không phải .docx.',
            ]);
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!is_string($xml) || $xml === '') {
            throw new ValidationException([
                'quiz_file' => 'File Word không có nội dung đọc được. Hãy lưu lại bằng Word rồi thử lại.',
            ]);
        }

        $lines = $this->docxLines($xml);

        $questions = [];
        $warnings  = [];
        $current   = null;

        foreach ($lines as $line) {
            $answerOf = $this->matchAnswerLine($line);
            if ($answerOf !== null) {
                if ($current === null) {
                    continue;
                }
                $current['answer'] = $answerOf;
                continue;
            }

            $option = $this->matchOptionLine($line);
            if ($option !== null) {
                if ($current === null) {
                    $warnings[] = 'Bỏ qua đáp án "' . $option['text'] . '" vì đứng trước câu hỏi đầu tiên.';
                    continue;
                }
                $current['options'][] = $option['text'];
                if ($option['isCorrect']) {
                    $current['answer'] = (string) count($current['options']);
                }
                continue;
            }

            if ($current !== null) {
                $questions[] = $current;
            }
            $current = ['question' => $this->stripQuestionNumber($line), 'options' => [], 'answer' => ''];
        }

        if ($current !== null) {
            $questions[] = $current;
        }

        $built = [];
        foreach ($questions as $question) {
            if ($question['question'] === '') {
                continue;
            }
            $built[] = [
                'question'      => $question['question'],
                'options'       => $question['options'],
                'correct_index' => $this->resolveCorrectIndex($question['answer'], $question['options']),
            ];
        }

        return ['questions' => $built, 'warnings' => $warnings];
    }

    /**
     * Lấy text từng dòng của document.xml. Mỗi <w:p> là một đoạn; <w:br>/<w:cr> trong đoạn
     * cũng tính là xuống dòng (giáo viên hay gõ Shift+Enter giữa các đáp án).
     *
     * @return string[] đã trim, đã bỏ dòng trống
     */
    private function docxLines(string $xml): array
    {
        $dom      = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded   = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $lines = [];
        foreach ($dom->getElementsByTagNameNS(self::WORD_NS, 'p') as $paragraph) {
            if (!$paragraph instanceof DOMElement) {
                continue;
            }
            foreach (explode("\n", $this->paragraphText($paragraph)) as $line) {
                $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return $lines;
    }

    private function paragraphText(DOMElement $paragraph): string
    {
        $text = '';
        foreach ($paragraph->getElementsByTagName('*') as $node) {
            if (!$node instanceof DOMElement || $node->namespaceURI !== self::WORD_NS) {
                continue;
            }
            $text .= match ($node->localName) {
                't'          => $node->textContent,
                'br', 'cr'   => "\n",
                'tab'        => ' ',
                default      => '',
            };
        }

        return $text;
    }

    /** "Đáp án: B" / "Answer: 2" → "B" / "2". null = không phải dòng đáp án. */
    private function matchAnswerLine(string $line): ?string
    {
        if (preg_match('/^(đáp\s*án(\s*đúng)?|dap\s*an(\s*dung)?|đ\.?a|answer|key)\s*[:\-\.]\s*(.+)$/iu', $line, $m) === 1) {
            return trim($m[4]);
        }

        return null;
    }

    /**
     * "A. London" / "*B) London" / "c - Rome" → nội dung đáp án + có phải đáp án đúng không.
     *
     * @return array{text:string, isCorrect:bool}|null
     */
    private function matchOptionLine(string $line): ?array
    {
        if (preg_match('/^(\*\s*)?([A-Za-z])\s*[\.\)\-:]\s*(.+)$/u', $line, $m) !== 1) {
            return null;
        }

        $text = trim($m[3]);
        if ($text === '') {
            return null;
        }

        // "*A. ..." hoặc "A. ...*" đều là cách đánh dấu đáp án đúng hay gặp.
        $isCorrect = trim($m[1] ?? '') !== '' || str_ends_with($text, '*');

        return ['text' => rtrim(rtrim($text, '*')), 'isCorrect' => $isCorrect];
    }

    /** "Câu 3: ..." / "3. ..." / "Question 3) ..." → bỏ phần đánh số, giữ nội dung. */
    private function stripQuestionNumber(string $line): string
    {
        $stripped = preg_replace('/^((câu|cau|question|q)\s*)?\d+\s*[:\.\)\-]\s*/iu', '', $line);

        return trim($stripped ?? $line);
    }

    // ── Dùng chung ──────────────────────────────────────────────────────────

    /**
     * Cắt bớt, bỏ câu không dùng được, gom cảnh báo. Không tự "sửa" dữ liệu của giáo viên:
     * câu thiếu đáp án đúng vẫn giữ lại (correct_index = -1) để họ chọn tay trên form.
     *
     * @param array<int, array{question:string,options:string[],correct_index:int}> $questions
     * @param string[] $warnings
     * @return array{questions: array<int, array{question:string,options:string[],correct_index:int}>, warnings: string[]}
     */
    private function normalize(array $questions, array $warnings): array
    {
        $result = [];
        foreach ($questions as $index => $question) {
            $number  = $index + 1;
            $options = array_values(array_slice($question['options'], 0, self::MAX_OPTIONS));

            if (count($question['options']) > self::MAX_OPTIONS) {
                $warnings[] = "Câu {$number}: chỉ lấy " . self::MAX_OPTIONS . ' đáp án đầu tiên.';
            }
            if (count($options) < 2) {
                $warnings[] = "Câu {$number}: bỏ qua vì có ít hơn 2 đáp án.";
                continue;
            }

            $correct = $question['correct_index'];
            if ($correct < 0 || $correct >= count($options)) {
                $correct    = -1;
                $warnings[] = "Câu {$number}: chưa xác định được đáp án đúng, vui lòng chọn lại.";
            }

            $result[] = [
                'question'      => $question['question'],
                'options'       => $options,
                'correct_index' => $correct,
            ];

            if (count($result) >= self::MAX_QUESTIONS) {
                $warnings[] = 'File có nhiều hơn ' . self::MAX_QUESTIONS
                    . ' câu — chỉ nhập ' . self::MAX_QUESTIONS . ' câu đầu tiên.';
                break;
            }
        }

        return ['questions' => $result, 'warnings' => $warnings];
    }

    /**
     * Đáp án đúng ghi kiểu gì cũng nhận: "B", "b)", "2", "Đáp án B", hoặc chép nguyên nội dung.
     *
     * @param string[] $options
     * @return int -1 = không xác định được
     */
    private function resolveCorrectIndex(string $answer, array $options): int
    {
        $answer = trim($answer);
        if ($answer === '' || $options === []) {
            return -1;
        }

        $bare = trim(preg_replace('/^(đáp\s*án|dap\s*an|answer|key)\s*[:\-\.]?\s*/iu', '', $answer) ?? $answer);
        $bare = trim($bare, " \t.):-");

        if (preg_match('/^[A-Za-z]$/', $bare) === 1) {
            $index = ord(strtoupper($bare)) - ord('A');

            return $index < count($options) ? $index : -1;
        }

        if (preg_match('/^\d+$/', $bare) === 1) {
            $index = (int) $bare - 1;

            return $index >= 0 && $index < count($options) ? $index : -1;
        }

        $needle = $this->slug($answer);
        foreach ($options as $index => $option) {
            if ($needle !== '' && $this->slug($option) === $needle) {
                return $index;
            }
        }

        return -1;
    }

    /** @param array<int, mixed> $row */
    private function rowHasContent(array $row): bool
    {
        foreach ($row as $cell) {
            if ($this->cellText($cell) !== '') {
                return true;
            }
        }

        return false;
    }

    private function cellText(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
    }

    /** Chuẩn hóa để so khớp: bỏ dấu tiếng Việt, thường hóa, gộp khoảng trắng. */
    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $map   = [
            'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
            'đ' => 'd',
        ];

        return trim(preg_replace('/\s+/u', ' ', strtr($value, $map)) ?? $value);
    }

    /** @param string[] $needles */
    private function contains(string $haystack, array $needles): bool
    {
        if ($haystack === '') {
            return false;
        }
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
