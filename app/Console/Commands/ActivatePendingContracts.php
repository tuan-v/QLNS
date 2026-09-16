<?php

namespace App\Console\Commands;

use App\Models\EmployeeContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Chạy hằng ngày TRƯỚC contracts:expire (xem routes/console.php) — hợp đồng
// ký trước ngày bắt đầu được tạo với status="pending" (xem
// EmployeeContractService::create()), tự nó KHÔNG bao giờ chuyển sang
// "active" nếu không có job này chạy đúng ngày start_date tới. Khi kích
// hoạt, supersede luôn hợp đồng "active" khác của CÙNG nhân viên (nếu có)
// sang "expired" — đúng lúc, không sớm hơn như trước đây (bug đã sửa: tạo
// hợp đồng ký trước sẽ auto-supersede hợp đồng đang thật sự áp dụng NGAY
// LÚC TẠO, dù còn cả tháng nữa mới tới hạn).
class ActivatePendingContracts extends Command
{
    protected $signature = 'contracts:activate-pending';

    protected $description = 'Kích hoạt các hợp đồng "pending" đã tới ngày bắt đầu (start_date <= hôm nay), đồng thời supersede hợp đồng active cũ của cùng nhân viên';

    public function handle(): int
    {
        $dueContracts = EmployeeContract::query()
            ->where('status', 'pending')
            ->whereDate('start_date', '<=', now()->toDateString())
            ->get();

        foreach ($dueContracts as $contract) {
            DB::transaction(function () use ($contract) {
                EmployeeContract::query()
                    ->where('employee_id', $contract->employee_id)
                    ->where('status', 'active')
                    ->update(['status' => 'expired']);

                $contract->update(['status' => 'active']);
            });
        }

        $this->info("Đã kích hoạt {$dueContracts->count()} hợp đồng tới ngày bắt đầu.");

        return self::SUCCESS;
    }
}
