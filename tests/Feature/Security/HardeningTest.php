<?php

namespace Tests\Feature\Security;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\RefreshToken;
use App\Models\ResignationRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeContractService;
use App\Services\ResignationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Vá lỗ hổng sau đợt rà soát bảo mật 2026-09-30 (mục 57 CODE_MAP): giới hạn số lần
// gọi, chặn HR tự cấp vai trò Admin, khóa tài khoản khi hết quan hệ lao động.
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function auth(string $email, string $password): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function makeEmployee(bool $withUser = false): Employee
    {
        $user = $withUser ? User::create([
            'email' => 'hard-'.uniqid().'@qlns.local', 'user_name' => 'Hard User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]) : null;
        if ($user) {
            Role::where('name', 'Employee')->first()->users()->attach($user->id);
        }

        return Employee::create([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => $user?->email ?? uniqid().'@qlns.local',
            'hire_date' => now()->subYear(),
            'code' => 'NV-'.uniqid(),
            'employment_status' => 'active',
            'department_id' => Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()])->id,
            'user_id' => $user?->id,
        ]);
    }

    public function test_api_errors_are_json_even_without_an_accept_header(): void
    {
        // Trước đây thiếu Accept: application/json thì lỗi xác thực bị chuyển hướng tới
        // route 'login' không tồn tại -> 500 thay vì 401.
        $this->call('POST', '/api/v1/auth/login', ['email' => 'nobody@qlns.local', 'password' => 'wrong-password'])
            ->assertStatus(401)
            ->assertJsonStructure(['message']);

        $this->call('GET', '/api/v1/employees')->assertStatus(401)->assertJsonStructure(['message']);
    }

    // ------------------------------------------------------------ rate limits

    public function test_login_is_throttled_per_email_after_too_many_attempts(): void
    {
        config(['rate_limits.enabled' => true]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@qlns.local', 'password' => 'wrong-password'])
                ->assertStatus(401);
        }

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@qlns.local', 'password' => 'wrong-password']);
        $response->assertStatus(429)->assertHeader('Retry-After');
        $this->assertStringContainsString('quá nhiều lần', $response->json('message'));

        // Email khác vẫn thử được (giới hạn theo cặp email+IP, không khóa cả IP ngay).
        $this->postJson('/api/v1/auth/login', ['email' => 'other@qlns.local', 'password' => 'wrong-password'])
            ->assertStatus(401);
    }

    public function test_password_reset_endpoints_are_throttled(): void
    {
        config(['rate_limits.enabled' => true]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@qlns.local'])->assertStatus(200);
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@qlns.local'])->assertStatus(429);
    }

    public function test_general_api_calls_are_throttled_per_session(): void
    {
        config(['rate_limits.enabled' => true, 'rate_limits.api_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/health')->assertStatus(200);
        }

        $this->getJson('/api/v1/health')->assertStatus(429);
    }

    // ------------------------------------------------- privilege escalation

    public function test_hr_cannot_create_an_account_with_the_admin_role(): void
    {
        $employee = $this->makeEmployee();
        $adminRole = Role::where('name', 'Admin')->first();

        $this->postJson("/api/v1/employees/{$employee->id}/account", ['role_ids' => [$adminRole->id]], $this->auth('hr@qlns.local', 'Hr@123456'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_ids');

        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_hr_can_still_create_accounts_with_ordinary_roles(): void
    {
        $employee = $this->makeEmployee();
        $managerRole = Role::where('name', 'Manager')->first();

        $this->postJson("/api/v1/employees/{$employee->id}/account", ['role_ids' => [$managerRole->id]], $this->auth('hr@qlns.local', 'Hr@123456'))
            ->assertStatus(201);
    }

    public function test_admin_can_assign_the_admin_role(): void
    {
        $employee = $this->makeEmployee();
        $adminRole = Role::where('name', 'Admin')->first();

        $this->postJson("/api/v1/employees/{$employee->id}/account", ['role_ids' => [$adminRole->id]], $this->auth('admin@qlns.local', 'Admin@123'))
            ->assertStatus(201);
    }

    public function test_role_dropdown_hides_the_admin_role_from_hr_but_not_from_admin(): void
    {
        $hrNames = collect($this->getJson('/api/v1/roles', $this->auth('hr@qlns.local', 'Hr@123456'))->json())->pluck('name');
        $adminNames = collect($this->getJson('/api/v1/roles', $this->auth('admin@qlns.local', 'Admin@123'))->json())->pluck('name');

        $this->assertFalse($hrNames->contains('Admin'));
        $this->assertTrue($hrNames->contains('Manager'));
        $this->assertTrue($adminNames->contains('Admin'));
    }

    // ------------------------------------------------ account lock on leaving

    public function test_applied_resignation_locks_the_account_and_revokes_refresh_tokens(): void
    {
        $employee = $this->makeEmployee(withUser: true);
        $user = $employee->user;
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret@123'])->assertStatus(200);
        $this->assertSame(1, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());

        ResignationRequest::create([
            'employee_id' => $employee->id, 'last_working_date' => now()->subDay()->toDateString(),
            'reason' => 'Chuyen cong tac', 'status' => ResignationRequest::STATUS_NOTIFIED, 'requires_approval' => false,
        ]);

        $this->assertSame(1, app(ResignationService::class)->applyAllDue());

        $this->assertSame('inactive', $user->fresh()->status);
        $this->assertSame(0, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret@123'])->assertStatus(401);
    }

    public function test_terminating_the_last_contract_locks_the_account_and_a_new_contract_reopens_it(): void
    {
        $employee = $this->makeEmployee(withUser: true);
        $service = app(EmployeeContractService::class);
        $contract = $service->create($employee, [
            'contract_type' => 'chinh_thuc', 'start_date' => now()->subMonth()->toDateString(), 'agreed_salary' => 10000000,
        ]);

        $service->terminate($contract->fresh());
        $this->assertSame('inactive', $employee->user->fresh()->status);

        // Tuyển lại: ký hợp đồng mới -> tài khoản được mở lại.
        $service->create($employee->fresh(), [
            'contract_type' => 'chinh_thuc', 'start_date' => now()->toDateString(), 'agreed_salary' => 11000000,
        ]);
        $this->assertSame('active', $employee->user->fresh()->status);
        $this->assertSame(2, EmployeeContract::where('employee_id', $employee->id)->count());
    }

    public function test_deleting_an_employee_locks_their_account(): void
    {
        $employee = $this->makeEmployee(withUser: true);

        $this->deleteJson("/api/v1/employees/{$employee->id}", [], $this->auth('hr@qlns.local', 'Hr@123456'))
            ->assertSuccessful();

        $this->assertSame('inactive', $employee->user->fresh()->status);
    }
}
