<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Repositories\LeaveBalanceRepository;
use Carbon\Carbon;

// "Tích lũy phép năm theo tháng làm + thưởng thâm niên" (2026-09-24, theo
// yêu cầu người dùng — trước đó LeaveBalanceRepository::findOrCreateForYear()
// cấp THẲNG nguyên annual_entitlement_days (12) ngay từ khi tạo dòng đầu
// tiên, sai với BLLĐ Điều 113/114: chưa làm đủ 12 tháng thì phép năm phải
// tính theo tỷ lệ số tháng đã làm; cứ đủ 5 năm làm việc thì +1 ngày/năm).
// Đã hỏi lại và CHỐT với người dùng qua nhiều câu hỏi (+ 1 lần sửa lại công
// thức năm đầu sau khi người dùng chỉ ra vẫn còn sai — xem mục 3 dưới):
//  1) Năm dương lịch ĐẦU TIÊN đi làm (year === hire_date.year): cứ ĐỦ 30
//     ngày làm việc tính từ ĐÚNG ngày vào làm (không phải theo tháng dương
//     lịch) thì mới được cộng 1 ngày phép — ngày đầu tiên đi làm CHƯA có
//     ngày phép nào (khác bản đầu tôi làm: tính theo tháng dương lịch đã
//     "chạm" tới, cho ngay 1 ngày từ hôm đầu — SAI, đã bị người dùng bắt lỗi).
//     Từ năm dương lịch THỨ 2 trở đi: cấp ĐỦ nguyên hạn mức (+ thâm niên)
//     NGAY khi dòng LeaveBalance của năm đó được tạo — KHÔNG tích lũy dần
//     nữa. Dùng hết thì hết, KHÔNG có cơ chế "bù" lại giữa năm dù có làm
//     thêm bao nhiêu tháng nữa — phải chờ sang năm sau.
//  2) Đồng bộ HẰNG NGÀY qua job [`SyncLeaveAccrual`](app/Console/Commands/SyncLeaveAccrual.php)
//     (không phải hằng tháng) — vì mốc thâm niên (mục 3) cần ĐÚNG NGÀY.
//  3) Thưởng thâm niên (+1 ngày/5 năm, Điều 114) áp dụng ĐÚNG ngày tròn năm
//     gia nhập (hire_date anniversary) — không phải đầu năm dương lịch.
//
// TOÀN BỘ công thức gom về đúng 1 hàm targetAllocatedDays() — dùng CHUNG ở
// 3 nơi: tạo/đồng bộ LeaveBalance (resolveOrSyncBalance(), gọi lúc nộp đơn
// VÀ trong job hằng ngày), và hiển thị số dư khi CHƯA có bản ghi nào
// (LeaveRequestService::listBalancesForEmployee(), chỉ đọc không tạo) —
// tránh lệch công thức giữa các nơi.
class LeaveAccrualService
{
    public function __construct(private readonly LeaveBalanceRepository $leaveBalanceRepository)
    {
    }

    // "asOf" mặc định = hôm nay — truyền riêng khi cần tính cho 1 thời điểm
    // khác (test, hoặc job chạy bù cho ngày trước đó).
    public function targetAllocatedDays(Employee $employee, LeaveType $leaveType, int $year, Carbon $asOf): float
    {
        $base = (float) $leaveType->annual_entitlement_days;

        // Loại phép KHÔNG có quỹ theo năm ("Nghỉ khác theo chế độ/luật",
        // "Nghỉ không lương"...) — không tích lũy/thưởng gì cả, giữ nguyên
        // hành vi cũ (thường = 0, xem LeaveRequestService::create() — loại
        // này không bị giới hạn theo quỹ khi nộp đơn).
        if ($base <= 0) {
            return $base;
        }

        $hireDate = Carbon::parse($employee->hire_date);

        if ($year < $hireDate->year) {
            return 0.0; // Chưa vào làm — không nên xảy ra trong luồng thật.
        }

        if ($year === $hireDate->year) {
            return $this->accrualForHireYear($hireDate, $base, $asOf);
        }

        return $base + $this->seniorityBonus($hireDate, $year, $asOf);
    }

    // Năm dương lịch nhân viên MỚI vào — cứ ĐỦ 30 ngày làm việc tính từ
    // ĐÚNG ngày vào làm thì mới cộng 1 ngày phép (2026-09-24, theo yêu cầu
    // người dùng — sửa lại từ bản tính theo THÁNG dương lịch, SAI vì cho
    // ngay 1 ngày từ hôm đầu tiên đi làm). Không cần chặn trần riêng theo số
    // tháng còn lại của năm — số ngày từ lúc vào làm tới hết năm đó (tối đa
    // ~366 ngày, kể cả năm nhuận) chia 30 rồi làm tròn xuống tự nhiên không
    // bao giờ vượt quá 12, nên chỉ cần chặn theo $base là đủ.
    private function accrualForHireYear(Carbon $hireDate, float $base, Carbon $asOf): float
    {
        $effectiveAsOf = $asOf->lessThan($hireDate) ? $hireDate : $asOf;
        // diff()->days (DateInterval thuần) — cùng lý do đã áp dụng ở
        // seniorityBonus(): Carbon 3 diffInDays() trả về float, ép sang int
        // cho intdiv() có thể gây cảnh báo deprecated.
        $daysWorked = $hireDate->diff($effectiveAsOf)->days;

        return (float) min(intdiv($daysWorked, 30), $base);
    }

    // Số ngày CỘNG THÊM do thâm niên — chỉ tính các mốc 5 năm ĐÃ QUA tính
    // đến đúng ngày tròn năm gia nhập gần nhất KHÔNG VƯỢT QUÁ asOf (hoặc hết
    // ngày 31/12 của "year" nếu asOf đã sang năm sau) — nên chỉ có hiệu lực
    // ĐÚNG từ ngày tròn năm trong năm đó, không phải từ đầu năm dương lịch.
    private function seniorityBonus(Carbon $hireDate, int $year, Carbon $asOf): int
    {
        $cutoff = $asOf->year > $year ? Carbon::create($year, 12, 31) : $asOf;
        // Dùng diff()->y (DateInterval thuần, số năm TRÒN chính xác theo lịch)
        // thay vì diffInYears() (Carbon 3 trả về float xấp xỉ theo số ngày
        // trung bình/năm — sai lệch đúng NGÀY biên mốc tròn năm, không hợp
        // để xét "đúng ngày tròn năm" như yêu cầu).
        $yearsOfService = $hireDate->diff($cutoff)->y;

        return intdiv($yearsOfService, 5);
    }

    // Tạo (nếu chưa có)/đồng bộ TĂNG bản LeaveBalance của 1 nhân viên + loại
    // phép + năm cho ĐÚNG mức "nên có" tại thời điểm asOf — AN TOÀN gọi lại
    // nhiều lần (idempotent: chỉ TĂNG allocated_days, không bao giờ giảm,
    // không đụng carried_forward_days/adjusted_days/used_days). Dùng cả lúc
    // nộp đơn (tạo lười — xem LeaveRequestService::create()) lẫn trong job
    // đồng bộ hằng ngày (SyncLeaveAccrual).
    public function resolveOrSyncBalance(Employee $employee, LeaveType $leaveType, int $year, ?Carbon $asOf = null): LeaveBalance
    {
        $asOf ??= now();
        $target = $this->targetAllocatedDays($employee, $leaveType, $year, $asOf);

        $balance = $this->leaveBalanceRepository->findOrCreateForYear($employee, $leaveType, $year, $target);

        if ($target > (float) $balance->allocated_days) {
            $balance = $this->leaveBalanceRepository->update($balance, ['allocated_days' => $target]);
        }

        return $balance;
    }
}
