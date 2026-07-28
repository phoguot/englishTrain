<?php

declare(strict_types=1);

namespace Attendance\Service;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Dựng 2 file XLSX học phí từ read model của TuitionService.
 *
 * Chỉ lo ĐỊNH DẠNG file — không tính lại tiền, không kiểm quyền. Cần số nào thì TuitionService
 * đã tính sẵn, để số trong file luôn khớp số trên trang web.
 *
 * Trả về đường dẫn file tạm; **người gọi phải `unlink()`** sau khi đẩy xuống trình duyệt
 * (xem TuitionController::exportAction).
 *
 * Cần `ext-zip` (khai trong composer.json) — thiếu extension thì PhpSpreadsheet không ghi được xlsx.
 */
class TuitionExcelWriter
{
    /** Định dạng tiền VNĐ trong Excel: 150000 → 150.000 đ, vẫn là SỐ nên cộng/lọc được. */
    private const MONEY_FORMAT = '#,##0" đ"';

    private const HEADER_FILL = 'FFE9ECEF';

    /** Dòng 1-2 là tiêu đề lớp/tháng, dòng 3 là tên cột, dữ liệu bắt đầu từ dòng 4. */
    private const HEADER_ROW = 3;

    /**
     * File 1 — chi tiết theo ngày: mỗi dòng là một học sinh trong một buổi.
     *
     * @param array<string,mixed> $data kết quả TuitionService::monthlyTuition()
     */
    public function daily(array $data): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Chi tiet theo ngay');

        $this->writeHead(
            $sheet,
            $data,
            ['Ngày học', 'Ca học', 'Học sinh', 'Trạng thái', 'Đơn giá', 'Thành tiền', 'Ghi chú'],
        );

        $row = self::HEADER_ROW + 1;
        foreach ($data['daily'] as $entry) {
            $sheet->fromArray([
                $entry['date'],
                (string) ($entry['shiftLabel'] ?? ''),
                $entry['studentName'],
                $entry['statusLabel'],
                $entry['fee'],
                $entry['amount'],
                (string) ($entry['note'] ?? ''),
                // strictNullComparison = true: KHÔNG có tham số này thì fromArray() so lỏng
                // ($value != null) và bỏ luôn mọi ô giá trị 0 → cột tiền của em vắng bị TRỐNG
                // thay vì "0 đ". Bảng tiền để trống chỗ số 0 là chỗ dễ hiểu sai nhất.
            ], null, 'A' . $row, true);
            $row++;
        }

        if ($data['daily'] !== []) {
            $sheet->setCellValue('D' . $row, 'Tổng cộng');
            $sheet->setCellValue('F' . $row, $data['grandTotal']);
            $sheet->getStyle('D' . $row . ':F' . $row)->getFont()->setBold(true);
        }

        $sheet->getStyle('E' . (self::HEADER_ROW + 1) . ':F' . $row)
            ->getNumberFormat()
            ->setFormatCode(self::MONEY_FORMAT);

        return $this->save($spreadsheet, 'G');
    }

    /**
     * File 2 — tổng hợp tháng: mỗi dòng là một học sinh, kèm tổng số buổi và tổng tiền.
     *
     * @param array<string,mixed> $data kết quả TuitionService::monthlyTuition()
     */
    public function summary(array $data): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Tong hop thang');

        $this->writeHead(
            $sheet,
            $data,
            ['Học sinh', 'Số buổi tính tiền', 'Số buổi vắng', 'Số buổi có phép', 'Tổng tiền'],
        );

        $row = self::HEADER_ROW + 1;
        foreach ($data['summary'] as $entry) {
            $sheet->fromArray([
                $entry['studentName'],
                $entry['paidSessions'],
                $entry['absentSessions'],
                $entry['excusedSessions'],
                $entry['totalAmount'],
                // Xem ghi chú strictNullComparison ở daily(): thiếu `true` là mất hết số 0.
            ], null, 'A' . $row, true);
            $row++;
        }

        if ($data['summary'] !== []) {
            $sheet->setCellValue('A' . $row, 'Tổng cộng');
            $sheet->setCellValue('E' . $row, $data['grandTotal']);
            $sheet->getStyle('A' . $row . ':E' . $row)->getFont()->setBold(true);
        }

        $sheet->getStyle('E' . (self::HEADER_ROW + 1) . ':E' . $row)
            ->getNumberFormat()
            ->setFormatCode(self::MONEY_FORMAT);

        return $this->save($spreadsheet, 'E');
    }

    /**
     * Tên file tải về: chỉ ASCII để mọi trình duyệt/Windows đều mở được, không dấu tiếng Việt.
     * Ví dụ: `hoc-phi-tong-hop-2026-07-lop-12.xlsx`.
     */
    public function fileName(string $type, int $classroomId, string $month): string
    {
        $slug = $type === 'daily' ? 'theo-ngay' : 'tong-hop';

        return sprintf('hoc-phi-%s-%s-lop-%d.xlsx', $slug, $month, $classroomId);
    }

    /**
     * Tiêu đề lớp/tháng + hàng tên cột. Người nhận file phải biết ngay file của lớp nào, tháng nào.
     *
     * @param array<string,mixed> $data
     * @param string[]            $headers
     */
    private function writeHead(Worksheet $sheet, array $data, array $headers): void
    {
        $sheet->setCellValue('A1', 'Học phí lớp: ' . $data['classroom']->name);
        $sheet->setCellValue('A2', $data['monthLabel'] . ' · ' . $data['sessionCount'] . ' buổi học');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $sheet->fromArray($headers, null, 'A' . self::HEADER_ROW);
    }

    private function save(Spreadsheet $spreadsheet, string $lastColumn): string
    {
        $sheet = $spreadsheet->getActiveSheet();
        $range = 'A' . self::HEADER_ROW . ':' . $lastColumn . self::HEADER_ROW;

        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB(self::HEADER_FILL);

        // Cuộn bảng dài mà vẫn thấy tên cột.
        $sheet->freezePane('A' . (self::HEADER_ROW + 1));

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'hocphi_');
        if ($path === false) {
            throw new RuntimeException('Không tạo được file tạm để xuất Excel.');
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
