<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\User;
use App\Repositories\PayrollDetailRepository;
use App\Repositories\PayrollRepository;
use App\Services\Payroll\PersonalIncomeTaxCalculator;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    // Hệ số OT cố định 150%, chưa phân biệt OT ngày thường/cuối tuần/lễ
    // (300%) như luật thực tế (KISS, có thể nâng cấp sau).
    private const OVERTIME_MULTIPLIER = 1.5;

    // OT chỉ được TRẢ LƯƠNG khi (1) đã được HR duyệt (Attendance::
    // overtime_approved, xem AttendanceAdjustmentService type "overtime") VÀ
    // (2) đạt ngưỡng tối thiểu 30 phút THỰC TẾ đi trễ so với giờ tan ca
    // (2026-09-21, theo yêu cầu người dùng — phương án B). overtime_minutes
    // lưu trên Attendance đã bị trừ sẵn OVERTIME_GRACE_MINUTES=5 phút ân hạn
    // (xem AttendanceService::calculateOvertimeMinutes()), nên ngưỡng tương
    // đương khi so với overtime_minutes ĐÃ LƯU là 30 - 5 = 25 phút, không
    // phải 30 — không cộng dồn/trừ lại ân hạn thêm 1 lần nữa ở đây.
    private const OVERTIME_MINIMUM_PAYABLE_MINUTES = 25;

    // Bảo hiểm bắt buộc phía người lao động (BHXH 8% + BHYT 1.5% + BHTN 1%) —
    // cùng cảnh báo như PersonalIncomeTaxCalculator: số theo luật hiện hành,
    // cần xác nhận lại trước khi dùng dữ liệu thật.
    private const INSURANCE_RATE = 0.105;

    public function __construct(
        private readonly PayrollRepository $payrollRepository,
        private readonly PayrollDetailRepository $payrollDetailRepository,
        private readonly PersonalIncomeTaxCalculator $taxCalculator,
        private readonly WorkTimeCalculationService $workTimeCalculationService,
    ) {
    }

    public function generateForPeriod(int $month, int $year, User $creator): Payroll
    {
        if ($this->payrollRepository->findByPeriod($month, $year)) {
            throw ValidationException::withMessages([
                'period' => 'Bảng lương cho kỳ này đã tồn tại.',
            ]);
        }

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $standardWorkDays = $this->standardWorkDaysFor($start, $end);

        // Bảng lương chỉ tính MỘT LẦN và không tự tính lại, mà chỉ bản ghi đã
        // duyệt mới có công — tạo khi còn lượt chấm công chờ duyệt sẽ làm nhân
        // viên bị thiếu lương mà không ai hay. Chỉ xét bản ghi ĐÃ chấm công ra:
        // bản ghi quên chấm ra không duyệt được (phải qua "Xin điều chỉnh công"
        // trước) nên không được chặn bảng lương vô thời hạn.
        $pendingApprovalCount = Attendance::whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->where('approval_status', Attendance::APPROVAL_PENDING)
            ->whereNotNull('last_check_out_at')
            ->count();

        if ($pendingApprovalCount > 0) {
            throw ValidationException::withMessages([
                'period' => "Còn {$pendingApprovalCount} bản ghi chấm công trong kỳ này chưa được duyệt. Vui lòng duyệt hoặc từ chối hết ở màn \"Duyệt chấm công\" trước khi tính lương.",
            ]);
        }

        return DB::transaction(function () use ($start, $end, $month, $year, $creator, $standardWorkDays) {
            $payroll = $this->payrollRepository->create([
                'period_month' => $month,
                'period_year' => $year,
                'status' => 'processing',
                'currency' => 'VND',
                'created_by' => $creator->id,
            ]);

            $totalNet = 0.0;

            // probation vẫn được tính lương — dùng ĐÚNG agreed_salary trên
            // hợp đồng đang active của họ, không tự áp % giảm cho thử việc:
            // hợp đồng thử việc thường đã ký sẵn đúng mức lương thỏa thuận
            // (tối thiểu 85% theo luật), Payroll không nên tự đoán lại số đó.
            $employees = Employee::whereIn('employment_status', ['active', 'probation'])->get();

            foreach ($employees as $employee) {
                $contract = $this->contractDuringPeriod($employee, $start, $end);

                if (! $contract) {
                    continue; // Không có hợp đồng nào hiệu lực trong kỳ thì bỏ qua, không chặn cả lô.
                }

                $detail = $this->buildDetailForEmployee($employee, $contract, $start, $end, $standardWorkDays);
                $this->payrollDetailRepository->createForPayroll($payroll, $detail);
                $totalNet += $detail['net_salary'];
            }

            $this->payrollRepository->update($payroll, ['total_payroll_amount' => round($totalNet, 2)]);

            return $payroll;
        });
    }

    private function buildDetailForEmployee(Employee $employee, $contract, Carbon $start, Carbon $end, int $standardWorkDays): array
    {
        $unpaidLeaveDays = $this->leaveDaysFor($employee, $start, $end, isPaid: false);
        $paidLeaveDays = $this->leaveDaysFor($employee, $start, $end, isPaid: true);

        $agreedSalary = (float) $contract->agreed_salary;
        $dailyRate = $standardWorkDays > 0 ? $agreedSalary / $standardWorkDays : 0.0;

        $workMetrics = $this->calculateWorkedMetrics($employee, $start, $end, $dailyRate);
        $actualWorkDays = $workMetrics['actual_work_days'];
        $overtimeMinutes = $workMetrics['overtime_minutes'];
        $overtimeAmount = $workMetrics['overtime_amount'];

        // Lương CHỈ trả cho đúng số ngày có công (chấm công thật) hoặc nghỉ
        // phép CÓ LƯƠNG đã duyệt — không còn lấy nguyên lương hợp đồng rồi
        // trừ ngược như trước (bug thật đã vấp: đi làm 2/22 ngày, không nộp
        // đơn xin nghỉ phép nào, vẫn nhận đủ lương vì code cũ chỉ trừ theo
        // đơn nghỉ KHÔNG LƯƠNG đã duyệt, không quan tâm có thật sự đi làm
        // hay không). min() để phòng actualWorkDays+paidLeaveDays vượt quá
        // standardWorkDays (trùng ngày dữ liệu, hoặc làm dư giờ nhiều ngày).
        $compensatedDays = min($standardWorkDays, $actualWorkDays + $paidLeaveDays);
        $baseSalary = round($dailyRate * $compensatedDays, 2);

        // Vẫn tính + hiển thị để tham khảo trên phiếu lương — không còn trực
        // tiếp trừ vào gross nữa (đã tự động không được tính vào $compensatedDays).
        $unpaidLeaveDeduction = round($dailyRate * $unpaidLeaveDays, 2);
        $insuranceAmount = round((float) $contract->insurance_salary * self::INSURANCE_RATE, 2);

        $grossSalary = round($baseSalary + $overtimeAmount, 2);
        $personalIncomeTax = $this->taxCalculator->calculate($grossSalary - $insuranceAmount);
        $netSalary = round($grossSalary - $insuranceAmount - $personalIncomeTax, 2);

        return [
            'employee_id' => $employee->id,
            'employee_contract_id' => $contract->id,
            'base_salary' => $baseSalary,
            'standard_work_days' => $standardWorkDays,
            'actual_work_days' => $actualWorkDays,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_amount' => $overtimeAmount,
            'total_allowance' => 0,
            'insurance_amount' => $insuranceAmount,
            'unpaid_leave_deduction' => $unpaidLeaveDeduction,
            'other_deduction' => 0,
            'gross_salary' => $grossSalary,
            'taxable_income' => max(0, $grossSalary - $insuranceAmount - 11_000_000),
            'personal_income_tax' => $personalIncomeTax,
            'net_salary' => $netSalary,
        ];
    }

    // Payroll TỰ tính ngày công quy đổi từ dữ liệu chấm công qua
    // WorkTimeCalculationService (dùng CHUNG với AttendanceService — bậc
    // thang theo % giờ làm so với ca, kết hợp trần theo phút đi muộn/về
    // sớm). OT vẫn tính RIÊNG bằng overtime_minutes (không nằm trong bậc
    // thang trên, tránh tính trùng), theo đúng standard_work_minutes của
    // từng ca — không dùng hằng số 8h cố định cho mọi nhân viên.
    private function calculateWorkedMetrics(Employee $employee, Carbon $start, Carbon $end, float $dailyRate): array
    {
        // CHỈ tính bản ghi HR đã duyệt (approval_status=approved) — chờ duyệt
        // hoặc bị từ chối thì không có công/lương (yêu cầu 2026-09-21). Cùng
        // luật với AttendanceService::summarizeHistory() để số trên màn hình
        // lịch sử khớp số ở phiếu lương.
        $attendances = Attendance::where('employee_id', $employee->id)
            ->where('approval_status', Attendance::APPROVAL_APPROVED)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->with('workShift')
            ->get();

        $dayEquivalent = 0.0;
        $overtimeMinutes = 0;
        $overtimeAmount = 0.0;

        foreach ($attendances as $attendance) {
            $standardMinutes = $attendance->workShift?->standard_work_minutes;

            if (! $standardMinutes) {
                continue; // Ca đã bị xóa hoặc thiếu số phút chuẩn — bỏ qua an toàn.
            }

            $dayEquivalent += $this->workTimeCalculationService->dayEquivalentFor($attendance, $attendance->workShift);

            // Chưa duyệt HOẶC chưa đạt ngưỡng tối thiểu thì KHÔNG cộng vào cả
            // overtime_minutes lẫn overtime_amount hiển thị trên phiếu lương
            // — tránh lệch giữa số phút hiện ra và số tiền thực trả.
            $isPayableOvertime = $attendance->overtime_approved
                && $attendance->overtime_minutes >= self::OVERTIME_MINIMUM_PAYABLE_MINUTES;

            if ($isPayableOvertime) {
                $overtimeMinutes += $attendance->overtime_minutes;
                $hourlyRateForShift = $dailyRate / ($standardMinutes / 60);
                $overtimeAmount += ($attendance->overtime_minutes / 60) * $hourlyRateForShift * self::OVERTIME_MULTIPLIER;
            }
        }

        return [
            'actual_work_days' => round($dayEquivalent, 2),
            'overtime_minutes' => $overtimeMinutes,
            'overtime_amount' => round($overtimeAmount, 2),
        ];
    }

    // Lấy hợp đồng ÁP DỤNG CHO ĐÚNG KỲ đang tính lương — dựa vào KHOẢNG THỜI
    // GIAN (start_date/end_date) hợp đồng chồng lấp với kỳ, KHÔNG dùng
    // status hiện tại. Lý do: từ khi có auto-expire (job hằng ngày) và
    // auto-supersede (ký hợp đồng mới tự chuyển hợp đồng cũ sang expired —
    // xem EmployeeContractService::create()), status của 1 hợp đồng thay đổi
    // theo THỜI GIAN THỰC, không còn phản ánh đúng "hợp đồng nào từng hiệu
    // lực cho kỳ nào" trong lịch sử. Bug thật đã vấp: chốt lương THÁNG 1 sau
    // khi đã sang tháng 2 (quy trình bình thường, không phải edge case) —
    // hợp đồng tháng 1 đã bị auto-expire/supersede, lọc theo status=active
    // sẽ vớ nhầm hợp đồng tháng 2 (sai lương/BH dùng để tính) thay vì báo
    // đúng "không có hợp đồng" hay lấy đúng hợp đồng tháng 1.
    private function contractDuringPeriod(Employee $employee, Carbon $start, Carbon $end)
    {
        return $employee->contracts()
            ->where('start_date', '<=', $end->toDateString())
            ->where(function ($query) use ($start) {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $start->toDateString());
            })
            ->latest('start_date')
            ->first();
    }

    // Ngày công chuẩn = ngày thường (T2-T6) trong tháng, trừ đi ngày lễ RƠI
    // ĐÚNG vào ngày thường (lễ trùng T7/CN không trừ thêm vì vốn dĩ đã nghỉ).
    private function standardWorkDaysFor(Carbon $start, Carbon $end): int
    {
        $weekdayCount = 0;

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (! $date->isWeekend()) {
                $weekdayCount++;
            }
        }

        $holidaysOnWeekdays = Holiday::whereBetween('holiday_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->filter(fn (Holiday $holiday) => ! $holiday->holiday_date->isWeekend())
            ->count();

        return $weekdayCount - $holidaysOnWeekdays;
    }

    // Tổng số ngày nghỉ phép ĐÃ DUYỆT trong kỳ, lọc theo đúng 1 chiều
    // có lương/không lương (leave_types.is_paid) — dùng chung cho cả
    // paidLeaveDays (tính như đi làm) lẫn unpaidLeaveDays (chỉ để hiển thị
    // tham khảo, xem buildDetailForEmployee()).
    private function leaveDaysFor(Employee $employee, Carbon $start, Carbon $end, bool $isPaid): float
    {
        return (float) LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('from_date', '<=', $end->toDateString())
            ->where('to_date', '>=', $start->toDateString())
            ->whereHas('leaveType', fn ($query) => $query->where('is_paid', $isPaid))
            ->sum('total_days');
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->payrollRepository->paginate(perPage: $perPage, filters: $filters);
    }

    // Lịch sử phiếu lương của 1 nhân viên — dùng chung cho cả nhân viên tự
    // xem (PayrollController::mine()) lẫn HR xem 1 nhân viên bất kỳ ở tab
    // "Lương / Phép" của Chi tiết nhân viên (PayrollController::forEmployee()).
    public function listPayslipsForEmployee(Employee $employee)
    {
        return $this->payrollDetailRepository->listForEmployee($employee);
    }

    public function find(int $id): ?Payroll
    {
        return $this->payrollRepository->find($id);
    }

    public function close(Payroll $payroll, User $closer): Payroll
    {
        if ($payroll->status !== 'processing') {
            throw ValidationException::withMessages([
                'status' => 'Chỉ có thể chốt bảng lương đang ở trạng thái "Đang xử lý".',
            ]);
        }

        return DB::transaction(function () use ($payroll, $closer) {
            $payroll->update(['status' => 'closed', 'closed_by' => $closer->id, 'closed_at' => now()]);

            return $payroll;
        });
    }

    public function markAsPaid(Payroll $payroll): Payroll
    {
        if ($payroll->status !== 'closed') {
            throw ValidationException::withMessages([
                'status' => 'Chỉ có thể đánh dấu đã trả cho bảng lương đã chốt.',
            ]);
        }

        return DB::transaction(function () use ($payroll) {
            $payroll->update(['status' => 'paid', 'paid_at' => now()]);

            return $payroll;
        });
    }
}
