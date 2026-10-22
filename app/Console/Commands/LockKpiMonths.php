<?php

namespace App\Console\Commands;

use App\Services\KpiService;
use Illuminate\Console\Command;

// Chốt KPI các tháng đã qua ngày chốt (hết tháng + KpiService::LOCK_AFTER_DAYS ngày). Chạy lại an toàn.
class LockKpiMonths extends Command
{
    protected $signature = 'kpi:lock-months';

    protected $description = 'Chốt KPI tháng từ công việc dự án cho các tháng đã tới ngày chốt';

    public function handle(KpiService $kpi): int
    {
        $locked = $kpi->lockDueMonths();
        $this->info($locked ? 'Đã chốt KPI: '.implode(', ', $locked) : 'Không có tháng nào cần chốt.');

        return self::SUCCESS;
    }
}
