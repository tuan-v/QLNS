<?php

namespace Tests\Feature\Notification;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LeaveNotificationTest extends TestCase
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
            'hire_date' => now()->subYears(3),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ], $overrides));
    }

    private function makeEmployeeWithLogin(string $roleName = 'Employee', array $overrides = []): array
    {
        $user = User::create([
            'email' => 'notif-'.uniqid().'@qlns.local', 'user_name' => 'Notif User',
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

    public function test_submitting_request_notifies_direct_manager(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate, $subUser] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($subUser->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi phep',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $managerUser->id,
            'type' => 'leave.pending_manager',
        ]);
    }

    public function test_submitting_request_without_manager_notifies_hr(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee');
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi phep',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $hrUser->id,
            'type' => 'leave.pending_hr',
        ]);
    }

    public function test_manager_approval_notifies_hr_excluding_the_approver(): void
    {
        // Trưởng phòng vừa là Manager vừa có quyền leave.approve_hr (trường
        // hợp công ty nhỏ) — không được tự thông báo cho chính mình.
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('HR');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        [, $otherHrUser] = $this->makeEmployeeWithLogin('HR');
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($subordinate, $leaveType);
        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $otherHrUser->id,
            'type' => 'leave.pending_hr',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $managerUser->id,
            'type' => 'leave.pending_hr',
        ]);
    }

    // 2026-09-24, theo yêu cầu người dùng: bỏ hẳn email cho kết quả duyệt
    // phép, chỉ còn thông báo trong-app — Mail::assertNothingSent() ở đây
    // là lưới chặn hồi quy, không phải chỗ test chính (xem 2 test tương tự
    // ở LeaveApprovalTest.php ngay tại luồng duyệt/từ chối).
    public function test_final_decision_creates_in_app_notification_only_no_email(): void
    {
        Mail::fake();
        [$manager] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate, $subUser] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
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
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $subUser->id,
            'type' => 'leave.decided',
        ]);
        Mail::assertNothingSent();
        $notification = Notification::where('user_id', $subUser->id)->where('type', 'leave.decided')->firstOrFail();
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notification->id,
            'channel' => 'in_app',
            'status' => 'sent',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', [
            'notification_id' => $notification->id,
            'channel' => 'email',
        ]);
    }

    public function test_intermediate_manager_approved_status_does_not_notify_employee(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate, $subUser] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $leaveRequest = $this->makeLeaveRequest($subordinate, $leaveType);
        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $subUser->id,
            'type' => 'leave.decided',
        ]);
    }
}
