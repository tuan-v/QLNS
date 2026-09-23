<?php

namespace Tests\Unit\Services;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\LeaveAccrualService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Concerns\InteractsWithPrivateMethods;

// "Tích lũy phép năm theo tháng + thưởng thâm niên" (2026-09-24, theo yêu
// cầu người dùng — xem comment đầu LeaveAccrualService.php). Chỉ nhắm vào
// targetAllocatedDays() — hàm THUẦN, không đụng DB — khác Feature Test
// (LeaveAccrualSyncTest.php) test resolveOrSyncBalance()/job thật qua DB.
// Employee/LeaveType dựng bằng `new` (không save()) — chỉ mang thuộc tính
// thuần túy để truyền vào hàm tính, giống LeaveRequestServiceCalculationTest.php.
class LeaveAccrualServiceTest extends TestCase
{
    use InteractsWithPrivateMethods;

    private function makeService(): LeaveAccrualService
    {
        return $this->instantiateWithoutConstructor(LeaveAccrualService::class);
    }

    private function makeEmployee(string $hireDate): Employee
    {
        return new Employee(['hire_date' => $hireDate]);
    }

    private function makeLeaveType(float $entitlement = 12): LeaveType
    {
        return new LeaveType(['annual_entitlement_days' => $entitlement]);
    }

    /* ------------------------ Năm đầu tiên (tích lũy cứ 30 ngày làm) ------------------------ */

    public function test_hire_year_accrual_is_zero_on_the_hire_day(): void
    {
        $service = $this->makeService();
        $employee = $this->makeEmployee('2026-03-10');

        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(), 2026, Carbon::parse('2026-03-10'));

        $this->assertSame(0.0, $result);
    }

    public function test_hire_year_accrual_is_still_zero_before_30_days_worked(): void
    {
        $service = $this->makeService();
        $employee = $this->makeEmployee('2026-03-10');

        // 2026-04-08 mới là ngày thứ 29 kể từ 2026-03-10 -> chưa đủ 30.
        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(), 2026, Carbon::parse('2026-04-08'));

        $this->assertSame(0.0, $result);
    }

    public function test_hire_year_accrual_earns_first_day_after_exactly_30_days_worked(): void
    {
        $service = $this->makeService();
        $employee = $this->makeEmployee('2026-03-10');

        // 2026-04-09 = đúng ngày thứ 30 kể từ 2026-03-10.
        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(), 2026, Carbon::parse('2026-04-09'));

        $this->assertSame(1.0, $result);
    }

    public function test_hire_year_accrual_grows_every_30_days_worked(): void
    {
        $service = $this->makeService();
        $employee = $this->makeEmployee('2026-03-10');

        // 60 ngày kể từ 2026-03-10 -> đã được 2 ngày phép.
        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(), 2026, Carbon::parse('2026-05-09'));

        $this->assertSame(2.0, $result);
    }

    public function test_hire_year_accrual_reaches_full_entitlement_by_year_end_when_hired_january_first(): void
    {
        $service = $this->makeService();
        $employee = $this->makeEmployee('2026-01-01');

        // 364 ngày kể từ 1/1 -> intdiv(364, 30) = 12, đúng bằng cả năm.
        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(), 2026, Carbon::parse('2026-12-31'));

        $this->assertSame(12.0, $result);
    }

    public function test_hire_year_accrual_never_exceeds_annual_entitlement(): void
    {
        $service = $this->makeService();
        $employee = $this->makeEmployee('2026-01-01');

        // Loại phép chỉ có 3 ngày/năm -> dù đã tích đủ 12 phần 30-ngày cũng không vượt quá 3.
        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(3), 2026, Carbon::parse('2026-12-31'));

        $this->assertSame(3.0, $result);
    }

    /* --------------------------- Từ năm thứ 2 trở đi (cấp đủ ngay) --------------------------- */

    public function test_established_employee_gets_full_entitlement_immediately_without_seniority(): void
    {
        $service = $this->makeService();
        // Vào làm 2024 -> năm 2026 là năm thứ 3, chưa đủ 5 năm.
        $employee = $this->makeEmployee('2024-06-01');

        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(), 2026, Carbon::parse('2026-01-01'));

        $this->assertSame(12.0, $result);
    }

    public function test_seniority_bonus_not_applied_before_anniversary_date_in_that_year(): void
    {
        $service = $this->makeService();
        // Tròn 5 năm đúng ngày 10/6 -> trước ngày đó trong năm 2026 CHƯA cộng.
        $employee = $this->makeEmployee('2021-06-10');

        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(), 2026, Carbon::parse('2026-06-09'));

        $this->assertSame(12.0, $result);
    }

    public function test_seniority_bonus_applies_starting_exact_anniversary_day(): void
    {
        $service = $this->makeService();
        $employee = $this->makeEmployee('2021-06-10');

        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(), 2026, Carbon::parse('2026-06-10'));

        $this->assertSame(13.0, $result);
    }

    public function test_ten_years_of_service_adds_two_extra_days(): void
    {
        $service = $this->makeService();
        $employee = $this->makeEmployee('2016-06-10');

        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(), 2026, Carbon::parse('2026-06-10'));

        $this->assertSame(14.0, $result);
    }

    public function test_used_up_entitlement_is_not_replenished_mid_year_for_established_employee(): void
    {
        // targetAllocatedDays() KHÔNG đọc used_days — luôn trả về đúng TỔNG
        // được cấp cho năm đó, không phụ thuộc đã dùng bao nhiêu. Việc
        // "dùng hết thì hết, không được cấp bù giữa năm" nằm ở chỗ
        // resolveOrSyncBalance() chỉ TĂNG allocated_days (không liên quan
        // used_days) — xác nhận công thức KHÔNG đổi dù gọi lại nhiều lần
        // trong cùng 1 năm cho employee đã qua năm đầu.
        $service = $this->makeService();
        $employee = $this->makeEmployee('2024-01-01');
        $leaveType = $this->makeLeaveType();

        $january = $service->targetAllocatedDays($employee, $leaveType, 2026, Carbon::parse('2026-01-15'));
        $december = $service->targetAllocatedDays($employee, $leaveType, 2026, Carbon::parse('2026-12-15'));

        $this->assertSame(12.0, $january);
        $this->assertSame(12.0, $december);
    }

    /* --------------------------------------- Edge cases --------------------------------------- */

    public function test_leave_type_without_entitlement_always_returns_zero(): void
    {
        $service = $this->makeService();
        $employee = $this->makeEmployee('2016-06-10'); // 10 năm thâm niên, vẫn không được cộng gì

        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(0), 2026, Carbon::parse('2026-06-10'));

        $this->assertSame(0.0, $result);
    }

    public function test_year_before_hire_year_returns_zero(): void
    {
        $service = $this->makeService();
        $employee = $this->makeEmployee('2026-05-01');

        $result = $service->targetAllocatedDays($employee, $this->makeLeaveType(), 2025, Carbon::parse('2026-06-01'));

        $this->assertSame(0.0, $result);
    }
}
