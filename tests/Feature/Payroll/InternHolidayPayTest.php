<?php

namespace Tests\Feature\Payroll;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Holiday;
use App\Models\HolidayRule;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\EmployeeContractService;
use App\Services\HolidayService;
use App\Services\PayrollService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Thực tập sinh (hợp đồng 'thuc_tap'): ngày lễ có cờ intern_paid=false thì vẫn tính
// trong công chuẩn nhưng không có công -> không được trả lương ngày đó. Cờ chọn theo
// từng quy tắc/dịp nghỉ; Quốc khánh mặc định không hưởng lương.
class InternHolidayPayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function hr(): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'hr@qlns.local', 'password' => 'Hr@123456'])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function employeeWithContract(string $contractType): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $employee = Employee::create([
            'full_name' => 'NV '.$contractType, 'company_email' => uniqid().'@qlns.local', 'hire_date' => '2026-01-01',
            'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        app(EmployeeContractService::class)->create($employee, [
            'contract_type' => $contractType, 'start_date' => '2026-01-01', 'agreed_salary' => 5_000_000,
        ]);

        return $employee->fresh();
    }

    // Đi làm đủ mọi ngày T2–T6 tháng 9/2026 trừ 2 ngày nghỉ Quốc khánh (1–2/9).
    private function workSeptember(Employee $employee, WorkShift $shift): void
    {
        foreach (CarbonPeriod::create('2026-09-01', '2026-09-30') as $date) {
            if ($date->isWeekend() || in_array($date->toDateString(), ['2026-09-01', '2026-09-02'], true)) {
                continue;
            }
            Attendance::create([
                'employee_id' => $employee->id, 'work_shift_id' => $shift->id, 'attendance_date' => $date->toDateString(),
                'first_check_in_at' => $date->toDateString().' 08:00:00', 'last_check_out_at' => $date->toDateString().' 17:00:00',
                'actual_work_minutes' => 480, 'status' => 'completed', 'approval_status' => 'approved',
            ]);
        }
    }

    public function test_intern_contract_sets_intern_status(): void
    {
        $this->assertSame('intern', $this->employeeWithContract('thuc_tap')->employment_status);
    }

    public function test_intern_is_not_paid_for_a_holiday_marked_unpaid_for_interns(): void
    {
        foreach (['2026-09-01', '2026-09-02'] as $date) {
            Holiday::create(['holiday_date' => $date, 'name' => 'Quốc khánh', 'group_code' => 'manual-qk', 'source' => 'manual', 'intern_paid' => false]);
        }
        $shift = WorkShift::create(['code' => 'CA-X', 'name' => 'Ca X', 'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480]);
        $intern = $this->employeeWithContract('thuc_tap');
        $staff = $this->employeeWithContract('chinh_thuc');
        $this->workSeptember($intern, $shift);
        $this->workSeptember($staff, $shift);
        $this->travelTo(Carbon::parse('2026-10-01 09:00'));

        $payroll = app(PayrollService::class)->generateForPeriod(9, 2026, User::where('email', 'hr@qlns.local')->first());
        $internDetail = $payroll->details()->where('employee_id', $intern->id)->sole();
        $staffDetail = $payroll->details()->where('employee_id', $staff->id)->sole();

        // 9/2026: 22 ngày T2–T6. Nhân viên chính thức: lễ trừ khỏi công chuẩn -> 20/20, đủ lương.
        $this->assertEqualsWithDelta(20, (float) $staffDetail->standard_work_days, 0.001);
        $this->assertEqualsWithDelta(5_000_000, (float) $staffDetail->base_salary, 0.01);
        // Thực tập sinh: lễ không lương vẫn trong công chuẩn -> 20/22.
        $this->assertEqualsWithDelta(22, (float) $internDetail->standard_work_days, 0.001);
        $this->assertEqualsWithDelta(5_000_000 / 22 * 20, (float) $internDetail->base_salary, 0.01);
        $this->assertEqualsWithDelta(0, (float) $internDetail->insurance_amount, 0.01);
    }

    public function test_intern_is_paid_when_the_holiday_allows_it(): void
    {
        $payroll = app(PayrollService::class);
        Holiday::create(['holiday_date' => '2026-09-02', 'name' => 'Lễ có lương', 'group_code' => 'manual-a', 'source' => 'manual', 'intern_paid' => true]);

        $this->assertSame(21, $payroll->standardWorkDaysFor(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), forIntern: true));
    }

    public function test_flag_follows_rule_and_can_be_toggled_per_occasion(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02'));
        app(HolidayService::class)->generateForYear(2027);

        // Quốc khánh mặc định thực tập sinh không hưởng lương, Giỗ Tổ thì có.
        $this->assertFalse((bool) Holiday::whereDate('holiday_date', '2027-09-02')->value('intern_paid'));
        $this->assertTrue((bool) Holiday::whereDate('holiday_date', '2027-04-16')->value('intern_paid'));

        // Đổi theo quy tắc -> áp cho các ngày sắp tới của quy tắc.
        $rule = HolidayRule::where('name', 'Quốc khánh')->first();
        $this->putJson("/api/v1/holidays/rules/{$rule->id}", ['intern_paid' => true], $this->hr())->assertOk();
        $this->assertTrue((bool) Holiday::whereDate('holiday_date', '2027-09-02')->value('intern_paid'));

        // Đổi riêng 1 dịp nghỉ.
        $group = Holiday::whereDate('holiday_date', '2027-04-16')->value('group_code');
        $this->postJson("/api/v1/holidays/groups/{$group}/intern-paid", ['intern_paid' => false], $this->hr())
            ->assertOk()
            ->assertJsonPath('intern_paid', false);
        $this->assertSame(0, Holiday::where('group_code', $group)->where('intern_paid', true)->count());
    }
}
