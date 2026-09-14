<?php

namespace Tests\Feature\Payroll;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeShiftAssignment;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Feature Test cho PayrollService — đi qua DB thật (SQLite in-memory), khác
// PersonalIncomeTaxCalculatorTest (Unit, hàm thuần không đụng DB). Kịch bản
// chính (test_generates_correct_payroll_for_a_standard_scenario) đối chiếu
// đúng số đã kiểm tra tay qua tinker trên DB thật trước khi viết test này,
// không phải số bịa ra sau khi xem code chạy.
class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(array $overrides = []): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => '2025-01-01',
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
            'employment_status' => 'active',
        ], $overrides));
    }

    private function makeWorkShift(array $overrides = []): WorkShift
    {
        return WorkShift::create(array_merge([
            'code' => 'CA-'.uniqid(),
            'name' => 'Ca test',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'standard_work_minutes' => 240,
            'work_coefficient' => 0.5,
        ], $overrides));
    }

    private function assignShift(Employee $employee, WorkShift $workShift, array $workDays = [1, 2, 3, 4, 5]): EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'effective_from' => '2025-01-01',
            'work_days' => $workDays,
            'status' => 'active',
        ]);
    }

    private function makeAttendance(Employee $employee, WorkShift $workShift, string $date, array $overrides = []): Attendance
    {
        return Attendance::create(array_merge([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => $date,
            'first_check_in_at' => $date.' 08:00:00',
            'last_check_out_at' => $date.' 12:00:00',
            'scheduled_work_minutes' => 240,
            'actual_work_minutes' => 240,
            'overtime_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'status' => 'completed',
        ], $overrides));
    }

    private function makeContract(Employee $employee, array $overrides = []): EmployeeContract
    {
        return EmployeeContract::create(array_merge([
            'employee_id' => $employee->id,
            'contract_number' => 'HD-'.uniqid(),
            'contract_type' => 'chinh_thuc',
            'start_date' => '2025-01-01',
            'agreed_salary' => 15_000_000,
            'insurance_salary' => 15_000_000,
            'status' => 'active',
        ], $overrides));
    }

    private function makeLeaveType(bool $isPaid, array $overrides = []): LeaveType
    {
        return LeaveType::create(array_merge([
            'code' => 'lt-'.uniqid(),
            'name' => 'Loai phep test',
            'annual_entitlement_days' => 12,
            'is_paid' => $isPaid,
            'is_active' => true,
        ], $overrides));
    }

    private function makeLeaveRequest(Employee $employee, LeaveType $leaveType, string $fromDate, string $toDate, float $totalDays, array $overrides = []): LeaveRequest
    {
        return LeaveRequest::create(array_merge([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'start_session' => 'full',
            'end_session' => 'full',
            'total_days' => $totalDays,
            'reason' => 'Test',
            'status' => 'approved',
        ], $overrides));
    }

    private function creator(): User
    {
        return User::create([
            'email' => 'payroll-creator-'.uniqid().'@qlns.local',
            'user_name' => 'Payroll Creator',
            'password' => bcrypt('Secret@123'),
            'status' => 'active',
        ]);
    }

    private function service(): PayrollService
    {
        return app(PayrollService::class);
    }

    public function test_generates_correct_payroll_for_a_standard_scenario(): void
    {
        // Kịch bản đã kiểm tra tay qua tinker trên DB thật trước khi viết
        // test này (xem review PayrollService) — 8/2026 có đúng 21 ngày công
        // chuẩn (T2-T6), ca test hệ số 0.5, 3 ngày có mặt (1 ngày có 60' OT),
        // 1 ngày nghỉ không lương.
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift();
        $this->assignShift($employee, $workShift);
        $this->makeContract($employee);

        $this->makeAttendance($employee, $workShift, '2026-08-03', ['overtime_minutes' => 60, 'actual_work_minutes' => 300]);
        $this->makeAttendance($employee, $workShift, '2026-08-04');
        $this->makeAttendance($employee, $workShift, '2026-08-05');

        $unpaidLeaveType = $this->makeLeaveType(isPaid: false);
        $this->makeLeaveRequest($employee, $unpaidLeaveType, '2026-08-06', '2026-08-06', 1);

        $payroll = $this->service()->generateForPeriod(8, 2026, $this->creator());

        $this->assertSame('processing', $payroll->status);
        $this->assertCount(1, $payroll->details);

        $detail = $payroll->details()->where('employee_id', $employee->id)->first();
        $this->assertEqualsWithDelta(21.0, (float) $detail->standard_work_days, 0.001);
        $this->assertEqualsWithDelta(1.5, (float) $detail->actual_work_days, 0.001);
        $this->assertSame(60, $detail->overtime_minutes);
        $this->assertEqualsWithDelta(714_285.71, (float) $detail->unpaid_leave_deduction, 0.01);
        $this->assertEqualsWithDelta(1_575_000.00, (float) $detail->insurance_amount, 0.01);
        $this->assertEqualsWithDelta(14_419_642.86, (float) $detail->gross_salary, 0.01);
        $this->assertEqualsWithDelta(1_844_642.86, (float) $detail->taxable_income, 0.01);
        $this->assertEqualsWithDelta(92_232.14, (float) $detail->personal_income_tax, 0.01);
        $this->assertEqualsWithDelta(12_752_410.72, (float) $detail->net_salary, 0.01);
        $this->assertEqualsWithDelta(12_752_410.72, (float) $payroll->total_payroll_amount, 0.01);
    }

    public function test_cannot_generate_duplicate_period(): void
    {
        $employee = $this->makeEmployee();
        $this->makeContract($employee);
        $creator = $this->creator();

        $this->service()->generateForPeriod(9, 2026, $creator);

        $this->expectException(ValidationException::class);
        $this->service()->generateForPeriod(9, 2026, $creator);
    }

    public function test_probation_employee_is_included(): void
    {
        $employee = $this->makeEmployee(['employment_status' => 'probation']);
        $this->makeContract($employee);

        $payroll = $this->service()->generateForPeriod(1, 2027, $this->creator());

        $this->assertCount(1, $payroll->details);
    }

    public function test_resigned_employee_is_excluded(): void
    {
        $employee = $this->makeEmployee(['employment_status' => 'resigned']);
        $this->makeContract($employee);

        $payroll = $this->service()->generateForPeriod(2, 2027, $this->creator());

        $this->assertCount(0, $payroll->details);
    }

    public function test_employee_without_active_contract_is_skipped_not_failed(): void
    {
        $withoutContract = $this->makeEmployee();
        $withContract = $this->makeEmployee();
        $this->makeContract($withContract);

        $payroll = $this->service()->generateForPeriod(3, 2027, $this->creator());

        $this->assertCount(1, $payroll->details);
        $this->assertSame($withContract->id, $payroll->details()->first()->employee_id);
    }

    public function test_paid_leave_does_not_create_unpaid_deduction(): void
    {
        $employee = $this->makeEmployee();
        $this->makeContract($employee);
        $paidLeaveType = $this->makeLeaveType(isPaid: true);
        $this->makeLeaveRequest($employee, $paidLeaveType, '2027-04-06', '2027-04-07', 2);

        $payroll = $this->service()->generateForPeriod(4, 2027, $this->creator());

        $detail = $payroll->details()->first();
        $this->assertEqualsWithDelta(0.0, (float) $detail->unpaid_leave_deduction, 0.001);
    }

    public function test_standard_work_days_excludes_holiday_falling_on_weekday(): void
    {
        // Thang 9/2026 co 22 ngay thuong (T2-T6); 2026-09-02 la Thu 4 (ngay
        // thuong) -> co le thi con 21.
        Holiday::create(['holiday_date' => '2026-09-02', 'name' => 'Ngay le test', 'is_paid' => true]);
        $employee = $this->makeEmployee();
        $this->makeContract($employee);

        $payroll = $this->service()->generateForPeriod(9, 2026, $this->creator());

        $detail = $payroll->details()->first();
        $this->assertEqualsWithDelta(21.0, (float) $detail->standard_work_days, 0.001);
    }

    public function test_close_transitions_from_processing_to_closed(): void
    {
        $employee = $this->makeEmployee();
        $this->makeContract($employee);
        $payroll = $this->service()->generateForPeriod(5, 2027, $this->creator());
        $closer = $this->creator();

        $closed = $this->service()->close($payroll, $closer);

        $this->assertSame('closed', $closed->status);
        $this->assertSame($closer->id, $closed->closed_by);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_cannot_close_a_payroll_that_is_already_closed(): void
    {
        $employee = $this->makeEmployee();
        $this->makeContract($employee);
        $payroll = $this->service()->generateForPeriod(6, 2027, $this->creator());
        $closer = $this->creator();
        $this->service()->close($payroll, $closer);

        $this->expectException(ValidationException::class);
        $this->service()->close($payroll, $closer);
    }

    public function test_mark_as_paid_transitions_from_closed_to_paid(): void
    {
        $employee = $this->makeEmployee();
        $this->makeContract($employee);
        $payroll = $this->service()->generateForPeriod(7, 2027, $this->creator());
        $this->service()->close($payroll, $this->creator());

        $paid = $this->service()->markAsPaid($payroll->fresh());

        $this->assertSame('paid', $paid->status);
        $this->assertNotNull($paid->paid_at);
    }

    public function test_cannot_mark_as_paid_before_closing(): void
    {
        $employee = $this->makeEmployee();
        $this->makeContract($employee);
        $payroll = $this->service()->generateForPeriod(8, 2027, $this->creator());

        $this->expectException(ValidationException::class);
        $this->service()->markAsPaid($payroll);
    }
}
