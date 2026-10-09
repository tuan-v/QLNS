<?php

namespace Tests\Feature\Employee;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\EmployeeContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// Tài khoản thực tập sinh: vai trò "Intern" tự gán theo hợp đồng thực tập — chỉ
// chấm công (kèm xin điều chỉnh), xin nghỉ ốm/không lương, xem phiếu lương. Lên
// thử việc/chính thức thì tự về vai trò "Employee".
class InternAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    private function headers(string $email, string $password): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    // Thực tập sinh có tài khoản (HR chọn vai trò "Employee" như bình thường).
    private function internWithAccount(): array
    {
        $department = Department::create(['name' => 'Phong TTS', 'code' => 'PB-TTS']);
        $employee = Employee::create([
            'full_name' => 'Thuc tap sinh', 'company_email' => 'tts@qlns.local', 'hire_date' => '2026-01-01',
            'code' => 'NV-TTS', 'department_id' => $department->id,
        ]);
        app(EmployeeContractService::class)->create($employee, [
            'contract_type' => 'thuc_tap', 'start_date' => '2026-01-01', 'agreed_salary' => 3_000_000,
        ]);

        $this->postJson("/api/v1/employees/{$employee->id}/account", [
            'role_ids' => [Role::where('name', 'Employee')->value('id')],
        ], $this->headers('hr@qlns.local', 'Hr@123456'))->assertCreated();

        $user = User::where('email', 'tts@qlns.local')->sole();
        $user->forceFill(['password' => bcrypt('Secret@123')])->save();

        return [$employee->fresh(), $user];
    }

    public function test_intern_account_gets_intern_role_and_only_basic_permissions(): void
    {
        [$employee, $user] = $this->internWithAccount();
        $this->assertSame(['Intern'], $user->roles()->pluck('name')->all());

        $headers = $this->headers('tts@qlns.local', 'Secret@123');
        $this->getJson('/api/v1/attendances/history/me', $headers)->assertOk();
        $this->getJson('/api/v1/payrolls/me', $headers)->assertOk();
        $this->getJson('/api/v1/leave-requests/me', $headers)->assertOk();
        // Không có quyền nào khác: danh sách nhân viên, đơn nghỉ việc.
        $this->getJson('/api/v1/employees', $headers)->assertForbidden();
        $this->getJson('/api/v1/resignations/me', $headers)->assertForbidden();
    }

    public function test_intern_only_sees_and_can_request_sick_or_unpaid_leave(): void
    {
        [$employee] = $this->internWithAccount();
        $shift = WorkShift::create(['code' => 'CA-T', 'name' => 'Ca T', 'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $shift->id, 'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5], 'status' => 'active',
        ]);
        $headers = $this->headers('tts@qlns.local', 'Secret@123');

        $codes = collect($this->getJson('/api/v1/leave-types', $headers)->assertOk()->json())->pluck('code')->sort()->values()->all();
        $this->assertSame(['sick', 'unpaid'], $codes);

        $nextMonday = now()->next('Monday')->toDateString();
        $payload = ['from_date' => $nextMonday, 'to_date' => $nextMonday, 'start_session' => 'full', 'end_session' => 'full', 'reason' => 'Om'];

        $this->postJson('/api/v1/leave-requests', $payload + ['leave_type_id' => LeaveType::where('code', 'annual')->value('id')], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('leave_type_id');
        $this->postJson('/api/v1/leave-requests', $payload + ['leave_type_id' => LeaveType::where('code', 'sick')->value('id')], $headers)
            ->assertCreated();
    }

    public function test_role_switches_back_to_employee_when_promoted(): void
    {
        [$employee, $user] = $this->internWithAccount();

        app(EmployeeContractService::class)->create($employee, [
            'contract_type' => 'thu_viec', 'start_date' => now()->toDateString(), 'agreed_salary' => 6_000_000,
        ]);

        $this->assertSame('probation', $employee->fresh()->employment_status);
        $this->assertSame(['Employee'], $user->fresh()->roles()->pluck('name')->all());
    }
}
