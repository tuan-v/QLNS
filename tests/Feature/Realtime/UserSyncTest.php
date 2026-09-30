<?php

namespace Tests\Feature\Realtime;

use App\Events\ResourceChanged;
use App\Events\UserDataChanged;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

// Realtime cho TOÀN hệ thống (mục 50 CODE_MAP): trait BroadcastsChanges tự bắn
// (1) ResourceChanged cho trang quản lý, (2) UserDataChanged riêng cho CHỦ dữ
// liệu — để nhân viên thường cũng không phải F5. Test này khóa: kênh user-sync
// chỉ chính chủ nghe được, kênh chung mới ánh xạ đúng quyền, và model thật sự
// phát tín hiệu tới đúng người.
class UserSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Cùng lý do ở ResourceSyncTest::setUp() — phải đổi driver broadcast
        // thật + require lại routes/channels.php mới đo đúng logic phân quyền.
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
        $this->seed();
    }

    private function makeUser(string $roleName = 'Employee'): User
    {
        $user = User::create([
            'email' => 'sync-'.uniqid().'@qlns.local', 'user_name' => 'Sync User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);

        return $user;
    }

    private function makeEmployee(?User $user = null): Employee
    {
        return Employee::create([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
            'department_id' => Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()])->id,
            'user_id' => $user?->id,
        ]);
    }

    private function authChannel(User $user, string $channel): int
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'Secret@123',
        ])->json('access_token');

        return $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => $channel,
        ], ['Authorization' => 'Bearer '.$token])->status();
    }

    public function test_user_sync_channel_is_only_open_to_its_owner(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();

        $this->assertSame(200, $this->authChannel($owner, "private-user-sync.{$owner->id}"));
        $this->assertSame(403, $this->authChannel($other, "private-user-sync.{$owner->id}"));
    }

    public function test_new_shared_resources_follow_the_permission_of_their_page(): void
    {
        $employee = $this->makeUser('Employee');
        $hr = $this->makeUser('HR');

        // Ca làm việc là tín hiệu công khai — mọi nhân viên đều nghe được.
        $this->assertSame(200, $this->authChannel($employee, 'private-resource-sync.work_shifts_public'));

        foreach (['attendances', 'leave_requests', 'employee_contracts', 'employee_documents', 'employee_transfers', 'employee_shift_assignments'] as $resource) {
            $this->assertSame(403, $this->authChannel($employee, "private-resource-sync.{$resource}"), $resource);
            $this->assertSame(200, $this->authChannel($hr, "private-resource-sync.{$resource}"), $resource);
        }
    }

    public function test_attendance_change_notifies_the_owner_and_the_management_pages(): void
    {
        $user = $this->makeUser();
        $employee = $this->makeEmployee($user);
        $shift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);

        Event::fake([UserDataChanged::class, ResourceChanged::class]);

        Attendance::create([
            'employee_id' => $employee->id, 'work_shift_id' => $shift->id,
            'attendance_date' => now()->toDateString(), 'first_check_in_at' => now(),
        ]);

        Event::assertDispatched(UserDataChanged::class, fn (UserDataChanged $e) => $e->userId === $user->id && $e->resource === 'attendance');
        Event::assertDispatched(ResourceChanged::class, fn (ResourceChanged $e) => $e->resource === 'attendances');
    }

    public function test_employee_without_a_login_account_only_notifies_management_pages(): void
    {
        $employee = $this->makeEmployee();
        $type = LeaveType::first();

        Event::fake([UserDataChanged::class, ResourceChanged::class]);

        LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'from_date' => now()->addDay()->toDateString(), 'to_date' => now()->addDay()->toDateString(),
            'total_days' => 1, 'reason' => 'Viec rieng', 'status' => 'pending',
        ]);

        Event::assertNotDispatched(UserDataChanged::class);
        Event::assertDispatched(ResourceChanged::class, fn (ResourceChanged $e) => $e->resource === 'leave_requests');
    }

    public function test_updating_a_leave_balance_with_increment_still_notifies_the_owner(): void
    {
        $user = $this->makeUser();
        $employee = $this->makeEmployee($user);
        $type = LeaveType::where('annual_entitlement_days', '>', 0)->first();
        $balance = \App\Models\LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => now()->year,
            'allocated_days' => 12, 'used_days' => 0,
        ]);

        Event::fake([UserDataChanged::class]);
        $balance->increment('used_days', 1);

        Event::assertDispatched(UserDataChanged::class, fn (UserDataChanged $e) => $e->userId === $user->id && $e->resource === 'leave_balances');
    }

    public function test_closing_a_payroll_notifies_every_employee_on_it_about_payslips(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $employeeA = $this->makeEmployee($userA);
        $employeeB = $this->makeEmployee($userB);
        $admin = User::where('email', 'admin@qlns.local')->firstOrFail();
        $payroll = Payroll::create([
            'period_month' => now()->month, 'period_year' => now()->year,
            'status' => 'processing', 'currency' => 'VND', 'created_by' => $admin->id,
        ]);
        foreach ([$employeeA, $employeeB] as $employee) {
            PayrollDetail::create([
                'payroll_id' => $payroll->id, 'employee_id' => $employee->id,
                'base_salary' => 10000000, 'standard_work_days' => 22, 'actual_work_days' => 22,
            ]);
        }

        Event::fake([UserDataChanged::class]);
        $payroll->update(['status' => 'closed']);

        foreach ([$userA, $userB] as $user) {
            Event::assertDispatched(UserDataChanged::class, fn (UserDataChanged $e) => $e->userId === $user->id && $e->resource === 'payslips');
        }
    }
}
