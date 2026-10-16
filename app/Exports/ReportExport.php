<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Xuất Excel cho module Báo cáo (ReportService) — mỗi báo cáo 2 sheet: theo kỳ và theo phòng
// ban. Chỉ lo trình bày, số liệu lấy nguyên từ ReportService (khớp màn hình).
class ReportExport
{
    private const FILL_HEADER = 'DBEAFE';

    private const MONEY = '#,##0';

    private const TITLES = [
        'workforce' => 'BÁO CÁO BIẾN ĐỘNG NHÂN SỰ',
        'attendance' => 'BÁO CÁO CHẤM CÔNG & NGHỈ PHÉP',
        'payroll' => 'BÁO CÁO CHI PHÍ LƯƠNG',
    ];

    public function toSpreadsheet(string $type, array $data, array $params): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $period = 'Từ tháng '.$this->month($params['from']).' đến tháng '.$this->month($params['to']);

        [$periodColumns, $departmentColumns] = $this->columns($type);

        $this->writeSheet($spreadsheet->getActiveSheet(), 'Theo kỳ', self::TITLES[$type], $period, $periodColumns, $data['periods']);
        $this->writeSheet($spreadsheet->createSheet(), 'Theo phòng ban', self::TITLES[$type], $period, $departmentColumns, $data['by_department']);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @return array{0: array, 1: array} [cột theo kỳ, cột theo phòng ban] — [tiêu đề, khóa, kiểu] */
    private function columns(string $type): array
    {
        return match ($type) {
            'workforce' => [
                [['Kỳ', 'label', 'text'], ['Số nhân viên cuối kỳ', 'headcount', 'int'], ['Tuyển mới', 'hires', 'int'], ['Nghỉ việc', 'leavers', 'int'], ['Tỷ lệ nghỉ việc (%)', 'turnover_rate', 'pct']],
                [['Phòng ban', 'department', 'text'], ['Số nhân viên hiện tại', 'headcount', 'int']],
            ],
            'attendance' => [
                [['Kỳ', 'label', 'text'], ['Ngày công', 'work_days', 'num'], ['Nghỉ phép có lương', 'paid_leave_days', 'num'], ['Nghỉ không lương', 'unpaid_leave_days', 'num'], ['Vắng không phép', 'absent_days', 'num'], ['Tỷ lệ đi làm (%)', 'attendance_rate', 'pct'], ['Tỷ lệ nghỉ phép (%)', 'leave_rate', 'pct'], ['Tỷ lệ vắng (%)', 'absence_rate', 'pct'], ['Lượt đi muộn', 'late_count', 'int'], ['Tỷ lệ đi muộn (%)', 'late_rate', 'pct']],
                [['Phòng ban', 'department', 'text'], ['Ngày công', 'work_days', 'num'], ['Tỷ lệ đi làm (%)', 'attendance_rate', 'pct'], ['Tỷ lệ nghỉ phép (%)', 'leave_rate', 'pct'], ['Tỷ lệ vắng (%)', 'absence_rate', 'pct'], ['Tỷ lệ đi muộn (%)', 'late_rate', 'pct']],
            ],
            'payroll' => [
                [['Kỳ', 'label', 'text'], ['Số người nhận lương', 'employees', 'int'], ['Tổng thu nhập (gross)', 'gross', 'money'], ['BH công ty đóng (21,5%)', 'employer_insurance', 'money'], ['Tổng chi phí', 'total_cost', 'money'], ['Thực lĩnh (net)', 'net', 'money']],
                [['Phòng ban', 'department', 'text'], ['Số lượt nhận lương', 'employees', 'int'], ['Tổng thu nhập (gross)', 'gross', 'money'], ['BH công ty đóng (21,5%)', 'employer_insurance', 'money'], ['Tổng chi phí', 'total_cost', 'money'], ['Thực lĩnh (net)', 'net', 'money']],
            ],
        };
    }

    private function writeSheet(Worksheet $ws, string $name, string $title, string $period, array $columns, array $rows): void
    {
        $ws->setTitle($name);
        $lastColumn = chr(ord('A') + count($columns) - 1);

        $ws->setCellValue('A1', $title);
        $ws->mergeCells("A1:{$lastColumn}1");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->setCellValue('A2', $period.' · Xuất lúc '.now()->format('H:i d/m/Y'));
        $ws->mergeCells("A2:{$lastColumn}2");
        $ws->getStyle('A2')->getFont()->setItalic(true);

        foreach ($columns as $index => [$label]) {
            $ws->setCellValue(chr(ord('A') + $index).'4', $label);
        }
        $header = $ws->getStyle("A4:{$lastColumn}4");
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FILL_HEADER);
        $header->getAlignment()->setWrapText(true)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);

        $rowNumber = 5;
        foreach ($rows as $row) {
            foreach ($columns as $index => [, $key, $kind]) {
                $cell = chr(ord('A') + $index).$rowNumber;
                $ws->setCellValue($cell, $row[$key] ?? null);
                $format = match ($kind) {
                    'money', 'int' => self::MONEY,
                    'num', 'pct' => '#,##0.0',
                    default => null,
                };
                if ($format) {
                    $ws->getStyle($cell)->getNumberFormat()->setFormatCode($format);
                }
            }
            $rowNumber++;
        }

        $last = max(4, $rowNumber - 1);
        $ws->getStyle("A4:{$lastColumn}{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        foreach (range('A', $lastColumn) as $column) {
            $ws->getColumnDimension($column)->setWidth($column === 'A' ? 26 : 18);
        }
        $ws->getRowDimension(4)->setRowHeight(32);
        $ws->freezePane('A5');
    }

    private function month(string $key): string
    {
        [$year, $month] = explode('-', $key);

        return (int) $month.'/'.$year;
    }
}
