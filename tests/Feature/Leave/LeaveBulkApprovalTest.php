<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Duyệt/Từ chối đơn nghỉ phép HÀNG LOẠT (2026-09-25, theo yêu cầu người dùng,
// kèm ảnh tham khảo "Bulk Actions") — xem LeaveApprovalService::bulkDecide().
class LeaveBulkApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function loginAs(string $email, string $password): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ])->json('access_token');
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYears(3),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ], $overrides));
    }

    private function makeEmployeeWithLogin(string $roleName = 'Employee', array $overrides = []): array
    {
        $user = User::create([
            'email' => 'bulklv-'.uniqid().'@qlns.local', 'user_name' => 'Bulk Leave User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(array_merge(['user_id' => $user->id], $overrides));

        return [$employee, $user];
    }

    private function makeLeaveType(): LeaveType
    {
        return LeaveType::create([
            'code' => 'lt-'.uniqid(), 'name' => 'Loai phep '.uniqid(),
            'annual_entitlement_days' => 12, 'is_paid' => true,
            'allow_carry_forward' => false, 'is_active' => true,
        ]);
    }

    private function makeLeaveRequest(Employee $employee, LeaveType $leaveType, array $overrides = []): LeaveRequest
    {
        $monday = Carbon::parse('next monday');

        return LeaveRequest::create(array_merge([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'start_session' => 'full',
            'end_session' => 'full',
            'total_days' => 1,
            'reason' => 'Nghi phep test',
            'status' => 'pending',
        ], $overrides));
    }

    public function test_requires_authentication(): void
    {
        $this->putJson('/api/v1/leave-requests/bulk-decide', [
            'leave_request_ids' => [1], 'status' => 'approved',
        ])->assertStatus(401);
    }

    public function test_manager_can_approve_multiple_subordinate_requests_at_once(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$sub1] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        [$sub2] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $r1 = $this->makeLeaveRequest($sub1, $leaveType);
        $r2 = $this->makeLeaveRequest($sub2, $leaveType);
        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $response = $this->putJson('/api/v1/leave-requests/bulk-decide', [
            'leave_request_ids' => [$r1->id, $r2->id],
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('succeeded', [$r1->id, $r2->id]);
        $this->assertSame('manager_approved', $r1->fresh()->status);
        $this->assertSame('manager_approved', $r2->fresh()->status);
    }

    // Đơn KHÔNG PHẢI cấp dưới của Manager đang bấm không được duyệt hàng loạt
    // theo — phải rơi vào "failed", KHÔNG rớt cả loạt.
    public function test_request_from_a_non_subordinate_fails_without_blocking_others(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$sub] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        [$stranger] = $this->makeEmployeeWithLogin('Employee');
        $leaveType = $this->makeLeaveType();
        $ownRequest = $this->makeLeaveRequest($sub, $leaveType);
        $strangerRequest = $this->makeLeaveRequest($stranger, $leaveType);
        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $response = $this->putJson('/api/v1/leave-requests/bulk-decide', [
            'leave_request_ids' => [$ownRequest->id, $strangerRequest->id],
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('succeeded', [$ownRequest->id]);
        $failed = collect($response->json('failed'));
        $this->assertSame($strangerRequest->id, $failed->first()['id']);
    }

    public function test_hr_can_reject_multiple_requests_with_a_shared_comment(): void
    {
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        [$noManagerEmployee] = $this->makeEmployeeWithLogin('Employee');
        $leaveType = $this->makeLeaveType();
        $r1 = $this->makeLeaveRequest($noManagerEmployee, $leaveType);
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->putJson('/api/v1/leave-requests/bulk-decide', [
            'leave_request_ids' => [$r1->id],
            'status' => 'rejected',
            'comment' => 'Trung ngay cao diem',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $this->assertSame('rejected', $r1->fresh()->status);
    }
}
