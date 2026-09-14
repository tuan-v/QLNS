<?php

namespace App\Services;

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
    // Giả định đơn giản hóa (KISS, có thể nâng cấp ở "Cấu hình công thức
    // lương" sau): 8h/ngày công chuẩn cho MỌI nhân viên, không phân biệt
    // theo standard_work_minutes riêng của từng ca; hệ số OT cố định 150%,
    // chưa phân biệt OT ngày thường/cuối tuần/lễ (300%) như luật thực tế.
    private const STANDARD_DAILY_HOURS = 8;
    private const OVERTIME_MULTIPLIER = 1.5;

    // Bảo hiểm bắt buộc phía người lao động (BHXH 8% + BHYT 1.5% + BHTN 1%) —
    // cùng cảnh báo như PersonalIncomeTaxCalculator: số theo luật hiện hành,
    // cần xác nhận lại trước khi dùng dữ liệu thật.
    private const INSURANCE_RATE = 0.105;

    public function __construct(
        private readonly PayrollRepository $payrollRepository,
        private readonly PayrollDetailRepository $payrollDetailRepository,
        private readonly AttendanceService $attendanceService,
        private readonly PersonalIncomeTaxCalculator $taxCalculator,
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
                $contract = $employee->contracts()->where('status', 'active')->latest('start_date')->first();

                if (! $contract) {
                    continue; // Không có hợp đồng active thì bỏ qua, không chặn cả lô.
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
        $history = $this->attendanceService->history($employee, $start->toDateString(), $end->toDateString(), null, null);
        $actualWorkDays = $history['summary']['total_work_days'];
        $overtimeMinutes = $this->attendanceService->sumOvertimeMinutesForEmployee($employee, $start->toDateString(), $end->toDateString());
        $unpaidLeaveDays = $this->unpaidLeaveDaysFor($employee, $start, $end);

        $baseSalary = (float) $contract->agreed_salary;
        $dailyRate = $standardWorkDays > 0 ? $baseSalary / $standardWorkDays : 0.0;
        $hourlyRate = $dailyRate / self::STANDARD_DAILY_HOURS;

        $unpaidLeaveDeduction = round($dailyRate * $unpaidLeaveDays, 2);
        $overtimeAmount = round(($overtimeMinutes / 60) * $hourlyRate * self::OVERTIME_MULTIPLIER, 2);
        $insuranceAmount = round((float) $contract->insurance_salary * self::INSURANCE_RATE, 2);

        $grossSalary = round($baseSalary - $unpaidLeaveDeduction + $overtimeAmount, 2);
        $personalIncomeTax = $this->taxCalculator->calculate($grossSalary - $insuranceAmount);
        $netSalary = round($grossSalary - $insuranceAmount - $personalIncomeTax, 2);

        return [
            'employee_id' => $employee->id,
            'employee_contract_id' => $contract->id,
            'base_salary' => $baseSalary,
            'standard_work_days' => $standardWorkDays,
            'actual_work_days' => $actualWorkDays,
            'overtime_minutes' => $overtimeMinutes,
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

    // Chỉ trừ ngày nghỉ KHÔNG LƯƠNG đã duyệt (leave_types.is_paid=false) —
    // nghỉ phép năm có lương không được trừ vào lương, đã tính đủ ngày công.
    private function unpaidLeaveDaysFor(Employee $employee, Carbon $start, Carbon $end): float
    {
        return (float) LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('from_date', '<=', $end->toDateString())
            ->where('to_date', '>=', $start->toDateString())
            ->whereHas('leaveType', fn ($query) => $query->where('is_paid', false))
            ->sum('total_days');
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->payrollRepository->paginate(perPage: $perPage, filters: $filters);
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
