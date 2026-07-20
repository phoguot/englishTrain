<?php

declare(strict_types=1);

namespace Assignment\Service;

/**
 * Dựng + validate quiz_json từ dữ liệu form động (số câu hỏi thay đổi được).
 * Không dùng InputFilter vì cấu trúc lồng nhau số lượng thay đổi — theo mẫu
 * ClassroomController::handleSave() (validate "hình dạng" ở tầng ngoài Filter).
 * Logic thuần, không đụng DB — có thể unit test nếu dự án thêm PHPUnit.
 *
 * POST shape mong đợi:
 *   questions[0][text] = "..."
 *   questions[0][options][] = "A"
 *   questions[0][options][] = "B"
 *   questions[0][correct] = 0
 */
class QuizJsonBuilder
{
    /**
     * @param array<int|string, mixed> $rawQuestions mảng "questions" lấy từ request POST
     * @return array{errors: string[], quizJson: array<int, array{question:string,options:string[],correct_index:int}>}
     */
    public function build(array $rawQuestions): array
    {
        $errors = [];
        $quiz   = [];

        if ($rawQuestions === []) {
            return ['errors' => ['Vui lòng thêm ít nhất 1 câu hỏi.'], 'quizJson' => []];
        }

        foreach (array_values($rawQuestions) as $i => $raw) {
            $n = $i + 1;

            $text = is_string($raw['text'] ?? null) ? trim($raw['text']) : '';
            if ($text === '') {
                $errors[] = "Câu {$n}: chưa nhập nội dung câu hỏi.";
            }

            $rawOptions = is_array($raw['options'] ?? null) ? array_values($raw['options']) : [];
            $options    = [];
            foreach ($rawOptions as $optionIndex => $opt) {
                $opt = is_string($opt) ? trim($opt) : '';
                $options[] = $opt;
                if ($opt === '') {
                    $optionNumber = $optionIndex + 1;
                    $errors[] = "Câu {$n}: đáp án {$optionNumber} không được để trống.";
                }
            }

            if (count($options) < 2) {
                $errors[] = "Câu {$n}: cần ít nhất 2 đáp án.";
            }

            $correctRaw = $raw['correct'] ?? null;
            $correct    = is_numeric($correctRaw) ? (int) $correctRaw : -1;

            if ($options !== [] && ($correct < 0 || $correct >= count($options))) {
                $errors[] = "Câu {$n}: chưa chọn đáp án đúng hợp lệ.";
            }

            $quiz[] = [
                'question'      => $text,
                'options'       => $options,
                'correct_index' => $correct,
            ];
        }

        return ['errors' => $errors, 'quizJson' => $quiz];
    }
}
