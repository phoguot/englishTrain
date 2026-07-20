<?php

declare(strict_types=1);

namespace Attendance\Service;

use Attendance\Model\AttendanceRecord\AttendanceRecordModel;

/**
 * Dựng + validate bảng điểm danh từ form động (số học sinh thay đổi theo lớp).
 * Không dùng InputFilter vì cấu trúc là mảng n phần tử theo student_id — theo mẫu
 * Assignment\Service\QuizJsonBuilder (.claude/rules/03-view-ui.md).
 * Logic thuần, không đụng DB.
 *
 * POST shape mong đợi:
 *   status[<student_id>] = "present" | "absent" | "late" | "excused"
 *   note[<student_id>]   = "..."
 *
 * Chỉ nhận học sinh nằm trong $allowedStudentIds — id lạ bị BỎ QUA lặng lẽ (chống tamper:
 * người ngoài không đoán được mình có ghi được vào lớp khác hay không). Trạng thái sai
 * giá trị thì báo lỗi, vì đó là lỗi hiển thị được cho giáo viên.
 */
class AttendanceSheetBuilder
{
    /**
     * @param array<int|string, mixed> $rawStatus       mảng "status" từ POST
     * @param array<int|string, mixed> $rawNote         mảng "note" từ POST
     * @param int[]                    $allowedStudentIds học sinh được phép ghi cho buổi này
     * @param array<int,string>        $studentNames    map id => tên, để câu lỗi gọi đúng tên
     * @return array{errors: string[], rows: array<int, array{status:string, note:?string}>}
     */
    public function build(array $rawStatus, array $rawNote, array $allowedStudentIds, array $studentNames = []): array
    {
        $allowed = array_fill_keys(array_map('intval', $allowedStudentIds), true);
        $errors  = [];
        $rows    = [];

        foreach ($rawStatus as $studentId => $status) {
            $studentId = (int) $studentId;
            if (!isset($allowed[$studentId])) {
                continue;
            }

            $status = is_string($status) ? trim($status) : '';
            if ($status === '') {
                // Bỏ trống = chưa điểm danh em này. KHÔNG suy ra absent
                // (module/Attendance/CLAUDE.md: thiếu record ≠ vắng).
                continue;
            }

            if (!in_array($status, AttendanceRecordModel::STATUSES, true)) {
                $name     = $studentNames[$studentId] ?? ('học sinh #' . $studentId);
                $errors[] = 'Trạng thái điểm danh của ' . $name . ' không hợp lệ.';

                continue;
            }

            $note = $rawNote[$studentId] ?? null;
            $note = is_string($note) ? trim(strip_tags($note)) : '';
            if (mb_strlen($note) > 255) {
                $note = mb_substr($note, 0, 255);
            }

            $rows[$studentId] = [
                'status' => $status,
                'note'   => $note !== '' ? $note : null,
            ];
        }

        if ($errors === [] && $rows === []) {
            $errors[] = 'Chưa chọn trạng thái cho học sinh nào.';
        }

        return ['errors' => $errors, 'rows' => $rows];
    }
}
