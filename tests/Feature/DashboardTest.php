<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function loginAs(string $email, string $password): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        return $response->json('access_token');
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYears(2),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ], $overrides));
    }

    private function makeEmployeeWithLogin(string $roleName = 'Employee', array $overrides = []): array
    {
        $user = User::create([
            'email' => 'dash-'.uniqid().'@qlns.local', 'user_name' => 'Dash User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(array_merge(['user_id' => $user->id], $overrides));

        return [$employee, $user];
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
    }

    public function test_hr_sees_company_wide_scope(): void
    {
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('scope', 'company');
        $response->assertJsonStructure([
            'employee_stats' => ['total', 'active', 'new_this_month'],
            'attendance_today' => ['present', 'total', 'rate'],
            'leave_pending' => ['pending_manager', 'pending_hr', 'total'],
            'attendance_trend',
            'department_distribution',
        ]);
    }

    public function test_employee_sees_personal_scope(): void
    {
        [, $user] = $this->makeEmployeeWithLogin('Employee');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('scope', 'personal');
        $response->assertJsonStructure(['leave_balance', 'checked_in_today', 'my_pending_leave_count']);
    }

    public function test_leave_pending_counts_split_by_manager_and_hr_correctly(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        [$noManagerEmployee] = $this->makeEmployeeWithLogin('Employee');
        $leaveType = LeaveType::create([
            'code' => 'lt-'.uniqid(), 'name' => 'Loai phep', 'annual_entitlement_days' => 12,
            'is_paid' => true, 'allow_carry_forward' => false, 'is_active' => true,
        ]);

        // Có quản lý, chưa ai duyệt -> tính vào pending_manager.
        LeaveRequest::create([
            'employee_id' => $subordinate->id, 'leave_type_id' => $leaveType->id,
            'from_date' => now()->addDays(3)->toDateString(), 'to_date' => now()->addDays(3)->toDateString(),
            'start_session' => 'full', 'end_session' => 'full', 'total_days' => 1,
            'reason' => 'x', 'status' => 'pending',
        ]);
        // Không có quản lý -> bỏ qua cấp 1, tính vào pending_hr dù status vẫn 'pending'.
        LeaveRequest::create([
            'employee_id' => $noManagerEmployee->id, 'leave_type_id' => $leaveType->id,
            'from_date' => now()->addDays(4)->toDateString(), 'to_date' => now()->addDays(4)->toDateString(),
            'start_session' => 'full', 'end_session' => 'full', 'total_days' => 1,
            'reason' => 'x', 'status' => 'pending',
        ]);
        // Đã qua cấp quản lý, chờ HR.
        LeaveRequest::create([
            'employee_id' => $subordinate->id, 'leave_type_id' => $leaveType->id,
            'from_date' => now()->addDays(5)->toDateString(), 'to_date' => now()->addDays(5)->toDateString(),
            'start_session' => 'full', 'end_session' => 'full', 'total_days' => 1,
            'reason' => 'x', 'status' => 'manager_approved',
        ]);

        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('leave_pending.pending_manager', 1);
        $response->assertJsonPath('leave_pending.pending_hr', 2);
        $response->assertJsonPath('leave_pending.total', 3);
    }
}
