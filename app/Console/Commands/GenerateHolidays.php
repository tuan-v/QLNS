<?php

namespace App\Console\Commands;

use App\Services\HolidayService;
use Illuminate\Console\Command;

// Tự sinh ngày nghỉ lễ theo quy tắc (dương lịch + âm lịch) cho năm nay và năm sau,
// để HR không phải nhập tay mỗi năm. Chạy lại an toàn (không trùng, không tạo lại
// ngày HR đã xóa).
class GenerateHolidays extends Command
{
    protected $signature = 'holidays:generate {year? : Năm cần sinh (bỏ trống = năm nay và năm sau)}';

    protected $description = 'Sinh ngày nghỉ lễ theo các quy tắc lặp hằng năm';

    public function handle(HolidayService $holidayService): int
    {
        $years = $this->argument('year')
            ? [(int) $this->argument('year')]
            : [(int) now()->year, (int) now()->year + 1];

        foreach ($years as $year) {
            $result = $holidayService->generateForYear($year);
            $this->info("Năm {$year}: thêm {$result['created']} ngày, gỡ {$result['removed']} ngày.");
        }

        return self::SUCCESS;
    }
}
