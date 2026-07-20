<?php

declare(strict_types=1);

namespace Attendance\Model;

/**
 * Thống kê chuyên cần của 1 học sinh trong 1 lớp, 1 kỳ.
 * HỢP ĐỒNG liên module (docs/04-contracts.md) — Report nhận cái này, không đụng bảng.
 *
 * Quy tắc tính (chỉ tính Ở ĐÂY, view không tính lại — module/Attendance/CLAUDE.md):
 * - `totalSessions` = số buổi học sinh CÓ record. Buổi trước khi em vào lớp là THIẾU record,
 *   không phải vắng, nên không vào mẫu số.
 * - `late` tính là CÓ đi học → nằm ở tử số. `excused` không ở tử số nhưng vẫn ở mẫu số.
 * - `rate` = (present + late) / totalSessions, làm tròn 2 số.
 * - `totalSessions = 0` → `rate = null`, KHÔNG phải 0 (0% đọc thành "không bao giờ đi học",
 *   thực tế là "lớp chưa có buổi nào").
 */
final readonly class AttendanceSummary
{
    public function __construct(
        public int $totalSessions,
        public int $present,
        public int $absent,
        public int $late,
        public int $excused,
        public ?float $rate,
    ) {
    }

    /**
     * Dựng từ số đếm theo trạng thái. Đây là NƠI DUY NHẤT công thức chuyên cần tồn tại.
     *
     * @param array<string,int> $counts map status => số lượng
     */
    public static function fromCounts(array $counts): self
    {
        $present = (int) ($counts['present'] ?? 0);
        $absent  = (int) ($counts['absent'] ?? 0);
        $late    = (int) ($counts['late'] ?? 0);
        $excused = (int) ($counts['excused'] ?? 0);

        $total = $present + $absent + $late + $excused;

        return new self(
            $total,
            $present,
            $absent,
            $late,
            $excused,
            $total === 0 ? null : round(($present + $late) / $total, 2),
        );
    }

    /** Chuyên cần dạng chữ cho view: "80%" hoặc "—" khi chưa có buổi nào. */
    public function getRateForHuman(): string
    {
        return $this->rate === null ? '—' : (string) round($this->rate * 100) . '%';
    }
}
