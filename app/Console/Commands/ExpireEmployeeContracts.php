<?php

namespace App\Console\Commands;

use App\Models\EmployeeContract;
use Illuminate\Console\Command;

// Chạy hằng ngày (đăng ký lịch ở routes/console.php) — chuyển hợp đồng đã
// qua end_date nhưng vẫn còn "active" (HR quên/chưa kịp xử lý tay) sang
// "expired". Không đụng tới hợp đồng "terminated" (đã bị chấm dứt chủ động
// từ trước, xem EmployeeContractService::terminate()) hay hợp đồng không
// có end_date (vô thời hạn, xem StoreEmployeeContractRequest: end_date là
// nullable).
class ExpireEmployeeContracts extends Command
{
    protected $signature = 'contracts:expire';

    protected $description = 'Tự động chuyển các hợp đồng đã hết hạn (end_date < hôm nay, status=active) sang expired';

    public function handle(): int
    {
        $count = EmployeeContract::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now()->toDateString())
            ->update(['status' => 'expired']);

        $this->info("Đã chuyển {$count} hợp đồng sang trạng thái expired.");

        return self::SUCCESS;
    }
}
