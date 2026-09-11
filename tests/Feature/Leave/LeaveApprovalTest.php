<?php

namespace Tests\Feature\Leave;

use App\Mail\LeaveDecisionMail;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LeaveApprovalTest extends TestCase
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

    private function makeEmployeeWithLogin(string $roleName = 'Employee', array $overrides = []): array
    {
        $user = User::create([
            'email' => 'appr-'.uniqid().'@qlns.local', 'user_name' => 'Approval User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(array_merge(['user_id' => $user->id], $overrides));

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

    public function test_manager_can_approve_forwarding_to_hr(): void
    {
        Mail::fake();
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($subordinate, $leaveType);
        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $response = $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'manager_approved');
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leaveRequest->id,
            'approver_employee_id' => $manager->id,
            'approval_level' => 1,
            'decision' => 'approved',
        ]);
        // Cấp 1 duyệt xong CHƯA trừ quỹ — chỉ trừ khi HR duyệt xong cấp cuối.
        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $subordinate->id,
            'leave_type_id' => $leaveType->id,
        ]);
        // Chưa phải quyết định CUỐI (còn chờ HR) — chưa gửi mail thông báo.
        Mail::assertNothingSent();
    }

    public function test_manager_can_reject_directly_without_going_to_hr(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($subordinate, $leaveType);
        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $response = $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'rejected',
            'comment' => 'Trung lich cong tac',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'rejected');
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leaveRequest->id,
            'approval_level' => 1,
            'decision' => 'rejected',
        ]);
    }

    public function test_manager_cannot_decide_for_employee_not_their_subordinate(): void
    {
        [, $otherManagerUser] = $this->makeEmployeeWithLogin('Manager');
        [$realManager] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $realManager->id]);
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($subordinate, $leaveType);
        $token = $this->loginAs($otherManagerUser->email, 'Secret@123');

        $response = $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    public function test_hr_can_finalize_after_manager_approved_and_deducts_balance(): void
    {
        [$manager] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($subordinate, $leaveType, ['status' => 'manager_approved', 'total_days' => 2]);
        LeaveBalance::create([
            'employee_id' => $subordinate->id,
            'leave_type_id' => $leaveType->id,
            'year' => $leaveRequest->from_date->year,
            'allocated_days' => 12,
            'used_days' => 0,
        ]);
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leaveRequest->id,
            'approval_level' => 2,
            'decision' => 'approved',
        ]);
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $subordinate->id,
            'leave_type_id' => $leaveType->id,
            'used_days' => 2,
        ]);
    }

    public function test_hr_can_decide_directly_when_employee_has_no_manager(): void
    {
        [$employee] = $this->makeEmployeeWithLogin('Employee');
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($employee, $leaveType);
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => $leaveRequest->from_date->year,
            'allocated_days' => 12,
            'used_days' => 0,
        ]);
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('leave_approvals', [
            'leave_request_id' => $leaveRequest->id,
            'approval_level' => 2,
            'decision' => 'approved',
        ]);
    }

    public function test_manager_cannot_decide_at_hr_stage(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($subordinate, $leaveType, ['status' => 'manager_approved']);
        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $response = $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_cannot_decide_already_finalized_request(): void
    {
        [$employee] = $this->makeEmployeeWithLogin('Employee');
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($employee, $leaveType, ['status' => 'approved']);
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'rejected',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_employee_without_approve_permission_cannot_decide(): void
    {
        [$employee, $employeeUser] = $this->makeEmployeeWithLogin('Employee');
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($employee, $leaveType);
        $token = $this->loginAs($employeeUser->email, 'Secret@123');

        $response = $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(403);
    }

    public function test_mail_is_sent_to_employee_when_hr_gives_final_approval(): void
    {
        Mail::fake();
        [$manager] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($subordinate, $leaveType, ['status' => 'manager_approved']);
        LeaveBalance::create([
            'employee_id' => $subordinate->id,
            'leave_type_id' => $leaveType->id,
            'year' => $leaveRequest->from_date->year,
            'allocated_days' => 12,
            'used_days' => 0,
        ]);
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'approved',
            'comment' => 'Chuc nghi phep vui ve',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        Mail::assertQueued(LeaveDecisionMail::class, function (LeaveDecisionMail $mail) use ($subordinate) {
            return $mail->hasTo($subordinate->company_email)
                && $mail->leaveRequest->status === 'approved'
                && $mail->comment === 'Chuc nghi phep vui ve';
        });
    }

    public function test_mail_is_sent_to_employee_when_rejected(): void
    {
        Mail::fake();
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($subordinate, $leaveType);
        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'rejected',
            'comment' => 'Trung lich cong tac',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        Mail::assertQueued(LeaveDecisionMail::class, function (LeaveDecisionMail $mail) use ($subordinate) {
            return $mail->hasTo($subordinate->company_email)
                && $mail->leaveRequest->status === 'rejected';
        });
    }
}
