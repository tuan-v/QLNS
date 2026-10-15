<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Services\LeaveBalanceService;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Quản lý số dư phép (2026-10-15): HR điều chỉnh có lý do, chuyển phép tối đa N ngày hết hạn
// 31/03, thanh toán phép chưa nghỉ khi nghỉ việc (phép âm không trừ lương).
class LeaveBalanceManagementTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->annual = LeaveType::where('code', 'annual')->firstOrFail();
    }

    private function employee(array $overrides = [], bool $withUser = false): Employee
    {
        $user = $withUser ? User::create([
            'email' => 'bal-'.uniqid().'@qlns.local', 'user_name' => 'Bal User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]) : null;
        $user && Role::where('name', 'Employee')->first()->users()->attach($user->id);

        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(), 'company_email' => uniqid().'@qlns.local',
            'hire_date' => '2024-01-01', 'code' => 'NV-'.uniqid(), 'employment_status' => 'active',
            'department_id' => Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()])->id,
            'user_id' => $user?->id,
        ], $overrides));
    }

    private function as(string $email, string $password): array
    {
        $this->tokens[$email] ??= $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$email]];
    }

    private function balance(Employee $employee, int $year, array $values): LeaveBalance
    {
        return LeaveBalance::updateOrCreate(
            ['employee_id' => $employee->id, 'leave_type_id' => $this->annual->id, 'year' => $year],
            array_merge(['allocated_days' => 12, 'carried_forward_days' => 0, 'adjusted_days' => 0, 'used_days' => 0], $values),
        );
    }

    // ---------------------------------------------------------------- điều chỉnh tay

    public function test_hr_adjusts_with_reason_history_is_kept_and_employee_is_notified(): void
    {
        $employee = $this->employee([], withUser: true);
        $hr = $this->as('hr@qlns.local', 'Hr@123456');
        $year = now()->year;

        $this->postJson("/api/v1/leave-balances/{$employee->id}/adjust", [
            'leave_type_id' => $this->annual->id, 'year' => $year, 'days' => 2,
        ], $hr)->assertStatus(422)->assertJsonValidationErrors('reason');

        $response = $this->postJson("/api/v1/leave-balances/{$employee->id}/adjust", [
            'leave_type_id' => $this->annual->id, 'year' => $year, 'days' => 2, 'reason' => 'Thuong phep du an',
        ], $hr)->assertOk();

        $annual = collect($response->json('data'))->firstWhere('leave_type.code', 'annual');
        $this->assertSame(2.0, (float) $annual['adjusted_days']);
        $this->assertSame('manual', $annual['history'][0]['type']);
        $this->assertSame('Thuong phep du an', $annual['history'][0]['reason']);
        $this->assertTrue(Notification::where('user_id', $employee->user_id)->where('type', 'leave.balance_adjusted')->exists());

        // Nhân viên tự xem được lịch sử.
        $mine = $this->getJson('/api/v1/leave-balances/me', $this->as($employee->user->email, 'Secret@123'))->assertOk()->json('data');
        $this->assertSame('Thuong phep du an', collect($mine)->firstWhere('leave_type.code', 'annual')['history'][0]['reason']);
    }

    public function test_adjustment_cannot_make_the_balance_negative_and_needs_permission(): void
    {
        $employee = $this->employee([], withUser: true);
        $year = now()->year;
        $this->balance($employee, $year, ['allocated_days' => 3, 'used_days' => 1]);

        $this->postJson("/api/v1/leave-balances/{$employee->id}/adjust", [
            'leave_type_id' => $this->annual->id, 'year' => $year, 'days' => -20, 'reason' => 'Tru nham',
        ], $this->as('hr@qlns.local', 'Hr@123456'))->assertStatus(422)->assertJsonValidationErrors('days');

        $this->postJson("/api/v1/leave-balances/{$employee->id}/adjust", [
            'leave_type_id' => $this->annual->id, 'year' => $year, 'days' => 1, 'reason' => 'Tu cong cho minh',
        ], $this->as($employee->user->email, 'Secret@123'))->assertForbidden();
    }

    public function test_only_admin_changes_the_carry_forward_policy(): void
    {
        $payload = ['allow_carry_forward' => true, 'max_carry_forward_days' => 3, 'carry_forward_expiry' => '06-30'];

        $this->putJson("/api/v1/leave-types/{$this->annual->id}/policy", $payload, $this->as('hr@qlns.local', 'Hr@123456'))->assertForbidden();
        $this->putJson("/api/v1/leave-types/{$this->annual->id}/policy", $payload, $this->as('admin@qlns.local', 'Admin@123'))->assertOk();

        $this->assertSame('06-30', $this->annual->fresh()->carry_forward_expiry);
        $this->assertSame(3.0, (float) $this->annual->fresh()->max_carry_forward_days);
    }

    // ---------------------------------------------------------------- chuyển phép

    public function test_carry_forward_is_capped_follows_late_approvals_and_unused_part_expires(): void
    {
        $employee = $this->employee();
        $service = app(LeaveBalanceService::class);
        $previous = $this->balance($employee, 2025, ['used_days' => 4]); // còn 8

        // Đầu năm: chuyển tối đa 5 ngày.
        $this->travelTo(Carbon::parse('2026-01-05 09:00'));
        $service->syncCarryForward();
        $current = LeaveBalance::where(['employee_id' => $employee->id, 'year' => 2026])->first();
        $this->assertSame(5.0, $current->carried_forward_days);

        // Đơn năm cũ duyệt muộn -> năm cũ còn 3 -> chỉ chuyển 3 (1 dòng lịch sử, cập nhật số).
        $previous->update(['used_days' => 9]);
        $service->syncCarryForward();
        $this->assertSame(3.0, $current->fresh()->carried_forward_days);
        $this->assertSame([3.0], LeaveBalanceAdjustment::where('type', 'carry_forward')->pluck('days')->all());

        // Nghỉ 2 ngày trước hạn 31/03 (dùng phép chuyển trước).
        LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $this->annual->id,
            'from_date' => '2026-02-10', 'to_date' => '2026-02-11', 'start_session' => 'full', 'end_session' => 'full',
            'total_days' => 2, 'reason' => 'Nghi', 'status' => 'approved',
        ]);
        $current->increment('used_days', 2);

        // Qua 31/03: 3 chuyển - 2 đã dùng = 1 ngày mất.
        $this->travelTo(Carbon::parse('2026-04-01 09:00'));
        $this->assertSame(['carried' => 0, 'expired' => 1], $service->syncCarryForward());
        $current->refresh();
        $this->assertSame(-1.0, $current->adjusted_days);
        $this->assertNotNull($current->carry_expired_at);
        $this->assertSame(-1.0, LeaveBalanceAdjustment::where('type', 'carry_expired')->value('days'));

        // Chạy lại không trừ thêm.
        $this->assertSame(['carried' => 0, 'expired' => 0], $service->syncCarryForward());
        $this->assertSame(-1.0, $current->fresh()->adjusted_days);
    }

    public function test_no_carry_forward_when_policy_disallows_it(): void
    {
        $this->annual->update(['allow_carry_forward' => false]);
        $employee = $this->employee();
        $this->balance($employee, 2025, ['used_days' => 2]);

        $this->travelTo(Carbon::parse('2026-01-05 09:00'));
        app(LeaveBalanceService::class)->syncCarryForward();

        $this->assertSame(0.0, (float) LeaveBalance::where(['employee_id' => $employee->id, 'year' => 2026])->value('carried_forward_days'));
    }

    // ---------------------------------------------------------------- nghỉ việc

    private function contract(Employee $employee): void
    {
        EmployeeContract::create([
            'employee_id' => $employee->id, 'contract_number' => 'HD-'.uniqid(), 'contract_type' => 'chinh_thuc',
            'start_date' => '2024-01-01', 'agreed_salary' => 15_000_000, 'insurance_salary' => 15_000_000, 'status' => 'terminated',
        ]);
    }

    private function creator(): User
    {
        return User::where('email', 'hr@qlns.local')->first();
    }

    public function test_employee_leaving_mid_period_is_paid_and_gets_unused_leave_payout(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 09:00'));
        $leaver = $this->employee(['employment_status' => 'resigned', 'termination_date' => '2026-10-20']);
        $this->contract($leaver);
        $this->balance($leaver, 2026, ['used_days' => 4]); // còn 8
        $before = $this->employee(['employment_status' => 'resigned', 'termination_date' => '2026-09-15']);
        $this->contract($before);

        $payroll = app(PayrollService::class)->generateForPeriod(10, 2026, $this->creator());
        $detail = $payroll->details()->where('employee_id', $leaver->id)->first();

        // Nghỉ trong kỳ -> có trong bảng lương; nghỉ từ kỳ trước -> không.
        $this->assertNotNull($detail);
        $this->assertNull($payroll->details()->where('employee_id', $before->id)->first());

        $dailyRate = 15_000_000 / (float) $detail->standard_work_days;
        $this->assertSame(8.0, (float) $detail->leave_payout_days);
        $this->assertEqualsWithDelta(round($dailyRate * 8, 2), (float) $detail->leave_payout_amount, 0.01);
        // Tiền phép tính vào thu nhập chịu thuế.
        $this->assertEqualsWithDelta((float) $detail->base_salary + (float) $detail->leave_payout_amount, (float) $detail->gross_salary, 0.01);
        $this->assertTrue($detail->components()->whereHas('salaryComponent', fn ($q) => $q->where('code', 'leave_payout'))->exists());
    }

    public function test_negative_leave_on_termination_is_not_deducted(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 09:00'));
        $leaver = $this->employee(['employment_status' => 'resigned', 'termination_date' => '2026-10-20']);
        $this->contract($leaver);
        $this->balance($leaver, 2026, ['used_days' => 15]); // âm 3

        $detail = app(PayrollService::class)->generateForPeriod(10, 2026, $this->creator())
            ->details()->where('employee_id', $leaver->id)->first();

        $this->assertSame(-3.0, (float) $detail->leave_payout_days);
        $this->assertSame(0.0, (float) $detail->leave_payout_amount);
    }
}
