<?php

namespace App\Exports;

use App\Services\AttendanceSheetService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Dựng file Excel "Bảng chấm công tháng" từ dữ liệu AttendanceSheetService::build().
// Chỉ lo trình bày (bố cục, màu, viền) — không tính toán gì ở đây.
class AttendanceSheetExport
{
    private const HEADER_ROW = 4; // Dòng "Ngày"; dòng 5 là "Thứ", dữ liệu từ dòng 6.

    private const FIXED_COLUMNS = ['STT', 'Mã NV', 'Họ và tên', 'Phòng ban'];

    private const TOTAL_COLUMNS = [
        'Công chuẩn', 'Công thực tế', 'Phép có lương', 'Nghỉ không lương', 'Nghỉ lễ', 'Vắng', 'Chưa tính công', 'Giờ OT', 'Công hưởng lương',
    ];

    private const FILL_WEEKEND = 'E5E7EB';

    private const FILL_HOLIDAY = 'CCFBF1';

    private const FILL_MAKEUP = 'FEF3C7';

    private const FILL_HEADER = 'DBEAFE';

    private const FILL_ABSENT = 'FEE2E2';

    public function toSpreadsheet(array $sheet): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle(sprintf('T%02d-%d', $sheet['month'], $sheet['year']));
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $days = $sheet['days'];
        $firstDayCol = count(self::FIXED_COLUMNS) + 1;
        $firstTotalCol = $firstDayCol + count($days);
        $lastCol = $firstTotalCol + count(self::TOTAL_COLUMNS) - 1;
        $lastColLetter = Coordinate::stringFromColumnIndex($lastCol);

        $this->writeTitle($ws, $sheet, $lastColLetter);
        $this->writeHeader($ws, $days, $firstDayCol, $firstTotalCol);
        $lastDataRow = $this->writeRows($ws, $sheet['rows'], $days, $firstDayCol, $firstTotalCol);
        $this->styleTable($ws, $days, $firstDayCol, $firstTotalCol, $lastCol, $lastDataRow);
        $this->writeLegend($ws, $lastDataRow + 2);

        $ws->freezePane(Coordinate::stringFromColumnIndex($firstDayCol).(self::HEADER_ROW + 2));
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);

        return $spreadsheet;
    }

    private function writeTitle(Worksheet $ws, array $sheet, string $lastColLetter): void
    {
        $ws->setCellValue('A1', sprintf('BẢNG CHẤM CÔNG THÁNG %02d/%d', $sheet['month'], $sheet['year']));
        $ws->mergeCells("A1:{$lastColLetter}1");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $ws->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $ws->setCellValue('A2', sprintf(
            'Công chuẩn theo lịch: %d ngày (T2–T6, đã trừ ngày lễ, cộng ngày đi làm bù; thực tập sinh xem cột "Công chuẩn") · Xuất lúc %s',
            $sheet['standard_work_days'],
            now()->format('H:i d/m/Y'),
        ));
        $ws->mergeCells("A2:{$lastColLetter}2");
        $ws->getStyle('A2')->getFont()->setItalic(true);
        $ws->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function writeHeader(Worksheet $ws, iterable $days, int $firstDayCol, int $firstTotalCol): void
    {
        $top = self::HEADER_ROW;
        $bottom = $top + 1;

        foreach (self::FIXED_COLUMNS as $i => $label) {
            $this->mergeVertical($ws, $i + 1, $top, $bottom, $label);
        }

        foreach ($days as $i => $day) {
            $col = Coordinate::stringFromColumnIndex($firstDayCol + $i);
            $ws->setCellValue("{$col}{$top}", $day['day']);
            $ws->setCellValue("{$col}{$bottom}", $day['type'] === 'makeup' ? 'Bù' : $day['weekday']);
            $ws->getColumnDimension($col)->setWidth(5);

            if ($day['holiday_name']) {
                $ws->getComment("{$col}{$top}")->getText()->createText($day['holiday_name']);
            }
        }

        foreach (self::TOTAL_COLUMNS as $i => $label) {
            $this->mergeVertical($ws, $firstTotalCol + $i, $top, $bottom, $label);
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($firstTotalCol + $i))->setWidth(11);
        }

        $ws->getColumnDimension('A')->setWidth(5);
        $ws->getColumnDimension('B')->setWidth(10);
        $ws->getColumnDimension('C')->setWidth(24);
        $ws->getColumnDimension('D')->setWidth(16);
        $ws->getRowDimension($top)->setRowHeight(30);
    }

    private function mergeVertical(Worksheet $ws, int $colIndex, int $top, int $bottom, string $label): void
    {
        $col = Coordinate::stringFromColumnIndex($colIndex);
        $ws->setCellValue("{$col}{$top}", $label);
        $ws->mergeCells("{$col}{$top}:{$col}{$bottom}");
    }

    private function writeRows(Worksheet $ws, iterable $rows, iterable $days, int $firstDayCol, int $firstTotalCol): int
    {
        $rowIndex = self::HEADER_ROW + 2;

        foreach ($rows as $i => $row) {
            $ws->setCellValue("A{$rowIndex}", $i + 1);
            $ws->setCellValueExplicit("B{$rowIndex}", (string) $row['employee_code'], DataType::TYPE_STRING);
            $ws->setCellValue("C{$rowIndex}", $row['full_name']);
            $ws->setCellValue("D{$rowIndex}", $row['department'] ?? '');

            foreach ($days as $d => $day) {
                $col = Coordinate::stringFromColumnIndex($firstDayCol + $d);
                $value = $row['cells'][$day['date']] ?? '';
                if ($value !== '') {
                    $ws->setCellValue("{$col}{$rowIndex}", $value);
                }
                if ($value === AttendanceSheetService::MARK_ABSENT) {
                    $this->fill($ws, "{$col}{$rowIndex}", self::FILL_ABSENT);
                }
            }

            $totals = [
                $row['standard_work_days'], $row['actual_work_days'], $row['paid_leave_days'], $row['unpaid_leave_days'],
                $row['holiday_days'], $row['absent_days'], $row['pending_days'], $row['overtime_hours'], $row['paid_days'],
            ];
            foreach ($totals as $t => $value) {
                $ws->setCellValue(Coordinate::stringFromColumnIndex($firstTotalCol + $t).$rowIndex, $value);
            }

            $rowIndex++;
        }

        return max($rowIndex - 1, self::HEADER_ROW + 1);
    }

    private function styleTable(Worksheet $ws, iterable $days, int $firstDayCol, int $firstTotalCol, int $lastCol, int $lastDataRow): void
    {
        $top = self::HEADER_ROW;
        $lastColLetter = Coordinate::stringFromColumnIndex($lastCol);
        $headerRange = "A{$top}:{$lastColLetter}".($top + 1);

        $ws->getStyle($headerRange)->getFont()->setBold(true);
        $ws->getStyle($headerRange)->getAlignment()->setWrapText(true);
        $this->fill($ws, $headerRange, self::FILL_HEADER);

        // Tô cả cột ngày cuối tuần / lễ / đi làm bù (đè màu nền tiêu đề).
        foreach ($days as $i => $day) {
            $color = match ($day['type']) {
                'weekend' => self::FILL_WEEKEND,
                'holiday' => self::FILL_HOLIDAY,
                'makeup' => self::FILL_MAKEUP,
                default => null,
            };
            if ($color !== null) {
                $col = Coordinate::stringFromColumnIndex($firstDayCol + $i);
                $this->fill($ws, "{$col}{$top}:{$col}{$lastDataRow}", $color);
            }
        }

        $tableRange = "A{$top}:{$lastColLetter}{$lastDataRow}";
        $ws->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $ws->getStyle($tableRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $firstDayLetter = Coordinate::stringFromColumnIndex($firstDayCol);
        $ws->getStyle("{$firstDayLetter}{$top}:{$lastColLetter}{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("A{$top}:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $paidCol = Coordinate::stringFromColumnIndex($lastCol);
        $ws->getStyle("{$paidCol}".($top + 2).":{$paidCol}{$lastDataRow}")->getFont()->setBold(true);

        // Số để định dạng General (mặc định): Excel tự hiện 1 / 0.5 / 0.75, không thừa ".00".
        $firstTotalLetter = Coordinate::stringFromColumnIndex($firstTotalCol);
        $ws->getStyle("{$firstTotalLetter}{$top}:{$firstTotalLetter}{$lastDataRow}")
            ->getBorders()->getLeft()->setBorderStyle(Border::BORDER_MEDIUM);
    }

    private function writeLegend(Worksheet $ws, int $row): void
    {
        $items = [
            'Ghi chú:' => null,
            'Số (1, 0.5, …): công của ngày — chỉ tính ca đã được duyệt' => null,
            AttendanceSheetService::MARK_HOLIDAY.': Nghỉ lễ' => self::FILL_HOLIDAY,
            AttendanceSheetService::MARK_UNPAID_HOLIDAY.': Nghỉ lễ không hưởng lương (thực tập sinh) — ngày này vẫn tính trong công chuẩn của người đó' => self::FILL_HOLIDAY,
            AttendanceSheetService::MARK_PAID_LEAVE.': Nghỉ phép có lương · '.AttendanceSheetService::MARK_UNPAID_LEAVE.': Nghỉ không lương' => null,
            AttendanceSheetService::MARK_PENDING.': Đã chấm vào nhưng chưa tính công (chờ duyệt hoặc chưa chấm ra) · '.AttendanceSheetService::MARK_REJECTED.': Chấm công bị từ chối' => null,
            AttendanceSheetService::MARK_ABSENT.': Vắng (ngày làm việc theo lịch, không chấm công, không có đơn nghỉ)' => self::FILL_ABSENT,
            AttendanceSheetService::MARK_OVERTIME.': Làm OT ngày khác (không tính công ngày, giờ OT ở cột "Giờ OT")' => null,
            'Cột xám: Thứ 7 / Chủ nhật · Cột vàng "Bù": ngày đi làm bù' => self::FILL_MAKEUP,
            'Công hưởng lương = min(công chuẩn, công thực tế + phép có lương) — khớp cách tính bảng lương' => null,
        ];

        foreach ($items as $text => $color) {
            $ws->setCellValue("C{$row}", $text);
            if ($color !== null) {
                $this->fill($ws, "B{$row}", $color);
            }
            $row++;
        }
        $ws->getStyle('C'.($row - count($items)))->getFont()->setBold(true);
    }

    private function fill(Worksheet $ws, string $range, string $rgb): void
    {
        $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }
}
