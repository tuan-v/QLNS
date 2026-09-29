<?php

namespace App\Console\Commands;

use App\Services\ResignationService;
use Illuminate\Console\Command;

// Chạy hằng ngày (routes/console.php) — đơn nghỉ việc ĐÃ DUYỆT mà đã qua ngày
// làm việc cuối thì chuyển nhân viên sang "Đã nghỉ việc" + chấm dứt hợp đồng
// còn hiệu lực. Xem ResignationService::applyIfDue().
class ApplyResignations extends Command
{
    protected $signature = 'resignations:apply';

    protected $description = 'Áp dụng các đơn nghỉ việc đã duyệt đã qua ngày làm việc cuối (chuyển nhân viên sang "Đã nghỉ việc")';

    public function handle(ResignationService $resignationService): int
    {
        $count = $resignationService->applyAllDue();

        $this->info("Đã áp dụng {$count} đơn nghỉ việc.");

        return self::SUCCESS;
    }
}
