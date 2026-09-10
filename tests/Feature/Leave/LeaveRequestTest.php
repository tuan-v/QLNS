<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
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
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ], $overrides));
    }

    private function makeEmployeeWithLogin(): array
    {
        $user = User::create([
            'email' => 'leave-'.uniqid().'@qlns.local', 'user_name' => 'Leave User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        \App\Models\Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(['user_id' => $user->id]);

        return [$employee, $user];
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

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/leave-requests', []);

        $response->assertStatus(401);
    }

    public function test_store_requires_leave_type_id_and_dates(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/leave-requests', [], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors(['leave_type_id', 'from_date', 'to_date', 'reason']);
    }

    public function test_leave_type_must_be_active(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType(['is_active' => false]);
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi phep',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('leave_type_id');
    }

    public function test_single_day_request_requires_matching_start_and_end_session(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'start_session' => 'am',
            'end_session' => 'pm',
            'reason' => 'Nghi phep',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('end_session');
    }

    // Thu 6 -> Thu 2 tuan sau (4 ngay lich: Sau, T7, CN, Hai) chi tinh 2 ngay
    // lam viec (Sau + Hai) — T7/CN khong tinh vao phep (xac nhan nghiep vu
    // Ngay 36).
    public function test_total_days_excludes_weekend(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $friday = Carbon::parse('next friday');
        $monday = $friday->copy()->addDays(3);

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $friday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi le',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('total_days', 2);
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'total_days' => 2,
            'status' => 'pending',
        ]);
    }

    public function test_half_day_request_counts_as_half(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'start_session' => 'am',
            'end_session' => 'am',
            'reason' => 'Nghi nua ngay',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('total_days', 0.5);
    }

    // Ca ngay xin nghi roi dung vao T7+CN (khong co ngay lam viec nao) thi
    // chan luon, khong tao don 0 ngay.
    public function test_request_covering_only_weekend_is_rejected(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $saturday = Carbon::parse('next saturday');
        $sunday = $saturday->copy()->addDay();

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $saturday->toDateString(),
            'to_date' => $sunday->toDateString(),
            'reason' => 'Nghi cuoi tuan',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('to_date');
    }

    public function test_request_exceeding_remaining_balance_is_rejected(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        // Chi 1 ngay phep — xin 2 ngay lam viec lien tiep se vuot quy.
        $leaveType = $this->makeLeaveType(['annual_entitlement_days' => 1]);
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');
        $tuesday = $monday->copy()->addDay();

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $tuesday->toDateString(),
            'reason' => 'Xin nghi 2 ngay',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('to_date');
    }

    // Gui don KHONG duoc tu dong tru quy phep (quyet dinh nghiep vu da chot
    // voi nguoi dung — chi tru khi duyet, Ngay 37) — used_days phai giu = 0
    // ngay sau khi tao don thanh cong.
    public function test_creating_request_does_not_deduct_balance_yet(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi 1 ngay',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => $monday->year,
            'used_days' => 0,
        ]);
    }

    public function test_employee_can_list_own_leave_requests(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi 1 ngay',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $response = $this->getJson('/api/v1/leave-requests/me', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $response->assertJsonPath('0.employee_id', $employee->id);
    }

    public function test_hr_can_list_all_leave_requests(): void
    {
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->getJson('/api/v1/leave-requests', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
    }

    public function test_employee_cannot_list_all_leave_requests(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/leave-requests', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(403);
    }
}
