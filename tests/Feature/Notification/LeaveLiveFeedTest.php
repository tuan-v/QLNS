<?php

namespace Tests\Feature\Notification;

use App\Events\LeaveRequestChanged;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

// 2026-09-25, theo yêu cầu người dùng: "duyệt ... nhưng bên tài khoản nhân sự
// phải F5 lại mới thấy" — trang "Duyệt nghỉ phép" (LeaveManagement.vue) phải
// tự làm mới cho MỌI Manager/HR đang mở sẵn trang, không chỉ người vừa thao
// tác. Xem cùng khuôn AttendanceLiveFeedTest.php.
class LeaveLiveFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Cùng lý do ở AttendanceLiveFeedTest::setUp() — phải đổi driver
        // broadcast thật + require lại routes/channels.php mới đo đúng logic.
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
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
            // Đã qua năm đầu nên được cấp đủ quỹ phép ngay, không ảnh hưởng
            // bởi tích lũy (giống LeaveApprovalTest::makeEmployee()).
            'hire_date' => now()->subYears(3),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ], $overrides));
    }

    private function makeEmployeeWithLogin(string $roleName = 'Employee', array $overrides = []): array
    {
        $user = User::create([
            'email' => 'leavefeed-'.uniqid().'@qlns.local', 'user_name' => 'Leave Feed User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(array_merge(['user_id' => $user->id], $overrides));

        return [$employee, $user];
    }

    private function makeLeaveType(): LeaveType
    {
        return LeaveType::create([
            'code' => 'lt-'.uniqid(),
            'name' => 'Loai phep '.uniqid(),
            'annual_entitlement_days' => 12,
            'is_paid' => true,
            'allow_carry_forward' => false,
            'is_active' => true,
        ]);
    }

    public function test_hr_can_authorize_the_leave_live_feed_channel(): void
    {
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-leave-requests.live-feed',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
    }

    public function test_employee_without_approval_permission_cannot_authorize_the_channel(): void
    {
        [, $employeeUser] = $this->makeEmployeeWithLogin('Employee');
        $token = $this->loginAs($employeeUser->email, 'Secret@123');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-leave-requests.live-feed',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(403);
    }

    public function test_submitting_a_leave_request_dispatches_leave_request_changed(): void
    {
        Event::fake([LeaveRequestChanged::class]);
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [, $employeeUser] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($employeeUser->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi phep test',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        Event::assertDispatched(LeaveRequestChanged::class, function (LeaveRequestChanged $event) {
            return $event->leaveRequest->status === 'pending';
        });
        // Không liên quan gì tới usage của $managerUser ở test này ngoài việc
        // thiết lập quan hệ quản lý — tránh cảnh báo biến không dùng.
        $this->assertNotNull($managerUser->id);
    }

    public function test_deciding_a_leave_request_dispatches_leave_request_changed(): void
    {
        Event::fake([LeaveRequestChanged::class]);
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $leaveType = $this->makeLeaveType();
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $subordinate->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => Carbon::parse('next monday')->toDateString(),
            'to_date' => Carbon::parse('next monday')->toDateString(),
            'start_session' => 'full',
            'end_session' => 'full',
            'total_days' => 1,
            'reason' => 'Nghi phep test',
            'status' => 'pending',
        ]);
        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $response = $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        Event::assertDispatched(LeaveRequestChanged::class, function (LeaveRequestChanged $event) use ($leaveRequest) {
            return $event->leaveRequest->id === $leaveRequest->id && $event->leaveRequest->status === 'manager_approved';
        });
    }
}
