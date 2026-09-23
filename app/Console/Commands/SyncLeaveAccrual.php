<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\LeaveAccrualService;
use Illuminate\Console\Command;

// "Tích lũy phép năm theo tháng + thưởng thâm niên" (2026-09-24, theo yêu
// cầu người dùng — xem comment đầu LeaveAccrualService.php) — chạy HẰNG
// NGÀY (không phải hằng tháng) vì mốc thâm niên (+1 ngày/5 năm, Điều 114
// BLLĐ) cần ĐÚNG NGÀY tròn năm gia nhập, không thể đợi qua tháng mới cộng.
// Idempotent: chạy lại nhiều lần trong ngày (hoặc lỡ trùng lịch) không sao
// — resolveOrSyncBalance() chỉ TĂNG allocated_days, không bao giờ giảm.
class SyncLeaveAccrual extends Command
{
    protected $signature = 'leave:sync-accrual';

    protected $description = 'Đồng bộ số ngày phép năm tích lũy (theo tháng làm + thưởng thâm niên) cho toàn bộ nhân viên active/probation';

    public function handle(LeaveAccrualService $leaveAccrualService): int
    {
        $employees = Employee::whereIn('employment_status', ['active', 'probation'])->get();
        // Loại phép annual_entitlement_days = 0 ("Nghỉ khác theo chế độ/luật",
        // "Nghỉ không lương") không có quỹ theo năm — không cần đồng bộ (xem
        // targetAllocatedDays()).
        $leaveTypes = LeaveType::active()->where('annual_entitlement_days', '>', 0)->get();
        $year = now()->year;

        $increased = 0;
        foreach ($employees as $employee) {
            foreach ($leaveTypes as $leaveType) {
                $balance = $leaveAccrualService->resolveOrSyncBalance($employee, $leaveType, $year);
                if ($balance->wasChanged('allocated_days')) {
                    $increased++;
                }
            }
        }

        $this->info("Đã kiểm tra {$employees->count()} nhân viên x {$leaveTypes->count()} loại phép — tăng quỹ phép cho {$increased} bản ghi.");

        return self::SUCCESS;
    }
}
