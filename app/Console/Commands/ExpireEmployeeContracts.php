<?php

namespace App\Console\Commands;

use App\Models\EmployeeContract;
use Illuminate\Console\Command;
use App\Support\Realtime;

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
        $expiring = EmployeeContract::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', now()->toDateString());

        $employeeIds = (clone $expiring)->pluck('employee_id')->unique();
        $count = $expiring->update(['status' => 'expired']);

        // ->update() hàng loạt không phát event Eloquent — báo realtime tay.
        if ($count > 0) {
            Realtime::shared('employee_contracts');
            Realtime::shared('employees');
            foreach ($employeeIds as $employeeId) {
                Realtime::forEmployee((int) $employeeId, 'contracts');
            }
        }

        $this->info("Đã chuyển {$count} hợp đồng sang trạng thái expired.");

        return self::SUCCESS;
    }
}
