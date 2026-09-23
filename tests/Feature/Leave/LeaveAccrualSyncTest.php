<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\LeaveAccrualService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "Tích lũy phép năm theo tháng + thưởng thâm niên" (2026-09-24, theo yêu
// cầu người dùng — xem comment đầu LeaveAccrualService.php). Test
// resolveOrSyncBalance() (đụng DB thật) + job `leave:sync-accrual` — khác
// LeaveAccrualServiceTest.php (Unit Test, chỉ test công thức thuần
// targetAllocatedDays()).
class LeaveAccrualSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(array $overrides = []): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYears(3),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
            'employment_status' => 'active',
        ], $overrides));
    }

    private function makeLeaveType(array $overrides = []): LeaveType
    {
        return LeaveType::create(array_merge([
            'code' => 'lt-'.uniqid(),
            'name' => 'Loai phep '.uniqid(),
            'annual_entitlement_days' => 12,
            'is_paid' => true,
            'allow_carry_forward' => false,
            'is_active' => true,
        ], $overrides));
    }

    /* ------------------------------ resolveOrSyncBalance() ------------------------------ */

    public function test_resolve_or_sync_balance_creates_row_with_correct_initial_value(): void
    {
        // 45 ngày trước -> intdiv(45, 30) = 1 (cứ đủ 30 ngày làm mới được 1 ngày).
        $employee = $this->makeEmployee(['hire_date' => now()->subDays(45)]);
        $leaveType = $this->makeLeaveType();
        $service = app(LeaveAccrualService::class);

        $balance = $service->resolveOrSyncBalance($employee, $leaveType, now()->year);

        $this->assertEquals(1, $balance->allocated_days);
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'allocated_days' => 1,
        ]);
    }

    public function test_resolve_or_sync_balance_bumps_up_existing_row_when_target_increased(): void
    {
        // 65 ngày trước -> intdiv(65, 30) = 2.
        $employee = $this->makeEmployee(['hire_date' => now()->subDays(65)]);
        $leaveType = $this->makeLeaveType();
        $service = app(LeaveAccrualService::class);
        // Giả lập bản ghi CŨ (chưa đồng bộ), đang thấp hơn mức "nên có" hôm nay.
        $balance = LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'year' => now()->year, 'allocated_days' => 1, 'used_days' => 1,
        ]);

        $result = $service->resolveOrSyncBalance($employee, $leaveType, now()->year);

        $this->assertEquals(2, $result->allocated_days);
        // used_days KHÔNG bị đụng vào.
        $this->assertEquals(1, $result->fresh()->used_days);
    }

    public function test_resolve_or_sync_balance_never_decreases_allocated_days(): void
    {
        $employee = $this->makeEmployee(['hire_date' => now()->subDays(45)]);
        $leaveType = $this->makeLeaveType();
        $service = app(LeaveAccrualService::class);
        // Bản ghi đã được HR chỉnh tay cao hơn công thức (vd cấp bù thủ công).
        LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'year' => now()->year, 'allocated_days' => 8,
        ]);

        $result = $service->resolveOrSyncBalance($employee, $leaveType, now()->year);

        $this->assertEquals(8, $result->allocated_days);
    }

    /* --------------------------------- leave:sync-accrual --------------------------------- */

    public function test_sync_command_creates_balances_for_active_and_probation_employees(): void
    {
        $leaveType = $this->makeLeaveType();
        $active = $this->makeEmployee(['hire_date' => now()->subYears(3), 'employment_status' => 'active']);
        $probation = $this->makeEmployee(['hire_date' => now()->subYears(3), 'employment_status' => 'probation']);

        $this->artisan('leave:sync-accrual')->assertExitCode(0);

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $active->id, 'leave_type_id' => $leaveType->id, 'allocated_days' => 12,
        ]);
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $probation->id, 'leave_type_id' => $leaveType->id, 'allocated_days' => 12,
        ]);
    }

    public function test_sync_command_skips_resigned_employees(): void
    {
        $this->makeLeaveType();
        $resigned = $this->makeEmployee(['hire_date' => now()->subYears(3), 'employment_status' => 'resigned']);

        $this->artisan('leave:sync-accrual');

        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertNotNull($resigned->id);
    }

    public function test_sync_command_skips_leave_types_without_entitlement(): void
    {
        $employee = $this->makeEmployee(['hire_date' => now()->subYears(3)]);
        $this->makeLeaveType(['annual_entitlement_days' => 0, 'code' => 'unpaid-'.uniqid()]);

        $this->artisan('leave:sync-accrual');

        $this->assertDatabaseCount('leave_balances', 0);
        $this->assertNotNull($employee->id);
    }

    public function test_sync_command_is_idempotent_across_repeated_runs(): void
    {
        $leaveType = $this->makeLeaveType();
        $employee = $this->makeEmployee(['hire_date' => now()->subYears(3)]);

        $this->artisan('leave:sync-accrual');
        $this->artisan('leave:sync-accrual');
        $this->artisan('leave:sync-accrual');

        $this->assertDatabaseCount('leave_balances', 1);
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'allocated_days' => 12,
        ]);
    }
}
