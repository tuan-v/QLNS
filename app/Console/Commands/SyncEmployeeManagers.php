<?php

namespace App\Console\Commands;

use App\Services\ReportingLineService;
use Illuminate\Console\Command;

// Chạy 1 lần sau khi triển khai (2026-09-29) — đưa manager_id của dữ liệu cũ
// (trước đây HR chọn tay) về đúng quy tắc mới "quản lý = Trưởng phòng", xem
// ReportingLineService. Chạy lại bao nhiêu lần cũng an toàn (chỉ ghi dòng lệch).
class SyncEmployeeManagers extends Command
{
    protected $signature = 'employees:sync-managers';

    protected $description = 'Đồng bộ lại quản lý trực tiếp của mọi nhân viên theo Trưởng phòng của phòng ban';

    public function handle(ReportingLineService $reportingLineService): int
    {
        $changed = $reportingLineService->syncAll();

        $this->info("Đã cập nhật quản lý trực tiếp cho {$changed} nhân viên.");

        return self::SUCCESS;
    }
}
