<?php

declare(strict_types=1);

namespace Assignment\Service;

/**
 * Chấm quiz tự động. Logic thuần, không đụng DB — dễ unit test nếu dự án thêm PHPUnit
 * (xem .claude/rules/04-testing.md). Ca biên đã xử lý: câu hỏi rỗng, thiếu đáp án,
 * correct_index ngoài phạm vi, đáp án học sinh không phải số nguyên hợp lệ.
 */
class QuizGrader
{
    /**
     * @param array<int, array{question:string,options:string[],correct_index:int}> $quizJson
     * @param array<int, mixed> $studentAnswers chỉ số đáp án học sinh chọn theo từng câu (có thể thiếu)
     * @return float điểm trên thang 0–100, làm tròn 2 số (khớp DECIMAL(5,2))
     */
    public function grade(array $quizJson, array $studentAnswers): float
    {
        $total = count($quizJson);
        if ($total === 0) {
            return 0.0;
        }

        $correct = 0;
        foreach ($quizJson as $index => $question) {
            $correctIndex = $question['correct_index'] ?? null;
            if (!is_int($correctIndex)) {
                continue; // câu hỏi hỏng cấu trúc — không thể đúng, bỏ qua như sai
            }

            $studentAnswer = $studentAnswers[$index] ?? null;
            if (!is_int($studentAnswer) && !(is_string($studentAnswer) && ctype_digit($studentAnswer))) {
                continue; // bỏ trống hoặc dữ liệu rác — tính sai, không ném lỗi
            }

            if ((int) $studentAnswer === $correctIndex) {
                $correct++;
            }
        }

        return round(($correct / $total) * 100, 2);
    }
}
