<?php

namespace Tests\Feature;

use App\Events\ResourceChanged;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\AttendanceAdjustmentService;
use App\Services\AttendanceLocationService;
use App\Services\DepartmentService;
use App\Services\EmployeeService;
use App\Services\PayrollService;
use App\Services\PermissionService;
use App\Services\PositionService;
use App\Services\RoleService;
use App\Services\WorkShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

// "Trang quản lý tự làm mới realtime" mở rộng ra TOÀN BỘ trang còn lại (mục
// 34 CODE_MAP, 2026-09-25, theo yêu cầu người dùng "làm toàn bộ trang cũng có
// realtime") — 1 Event/kênh dùng chung (ResourceChanged, resource-sync.{resource}),
// tham số hóa qua $resource thay vì viết riêng cho từng trang. Test này xác
// nhận: (1) bảng ánh xạ quyền trong routes/channels.php khớp ĐÚNG permission
// route tương ứng (sai 1 dòng là cả trang mất realtime hoặc rò rỉ quyền xem
// tín hiệu), (2) mỗi Service thật sự bắn đúng sự kiện sau khi Thêm/Sửa/Xóa.
class ResourceSyncTest extends TestCase
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

    private function adminToken(): string
    {
        return $this->loginAs('admin@qlns.local', 'Admin@123');
    }

    private function makeDepartment(): Department
    {
        return Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
            'department_id' => $this->makeDepartment()->id,
        ], $overrides));
    }

    private function makeEmployeeWithLogin(string $roleName = 'Employee'): User
    {
        $user = User::create([
            'email' => 'sync-'.uniqid().'@qlns.local', 'user_name' => 'Sync User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);

        return $user;
    }

    private function assertChannelAuthorized(string $resource, string $token): void
    {
        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-resource-sync.{$resource}",
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
    }

    private function assertChannelForbidden(string $resource, string $token): void
    {
        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-resource-sync.{$resource}",
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(403);
    }

    public function test_admin_can_authorize_every_resource_channel(): void
    {
        $token = $this->adminToken();

        foreach (['departments', 'positions', 'roles', 'payrolls', 'employees', 'work_shifts', 'attendance_locations', 'attendance_adjustments'] as $resource) {
            $this->assertChannelAuthorized($resource, $token);
        }
    }

    public function test_plain_employee_cannot_authorize_any_management_resource_channel(): void
    {
        $token = $this->loginAs($this->makeEmployeeWithLogin('Employee')->email, 'Secret@123');

        foreach (['departments', 'positions', 'roles', 'payrolls', 'employees', 'work_shifts', 'attendance_locations', 'attendance_adjustments'] as $resource) {
            $this->assertChannelForbidden($resource, $token);
        }
    }

    public function test_unknown_resource_name_is_always_forbidden(): void
    {
        $token = $this->adminToken();

        $this->assertChannelForbidden('something-not-mapped', $token);
    }

    public function test_department_mutations_dispatch_resource_changed(): void
    {
        Event::fake([ResourceChanged::class]);
        $department = $this->makeDepartment();

        app(DepartmentService::class)->create(['name' => 'Phong moi', 'code' => 'IGNORED']);
        app(DepartmentService::class)->update($department, ['name' => 'Phong da doi ten']);
        app(DepartmentService::class)->delete($department);

        Event::assertDispatched(ResourceChanged::class, fn (ResourceChanged $e) => $e->resource === 'departments');
        Event::assertDispatchedTimes(ResourceChanged::class, 3);
    }

    public function test_position_mutations_dispatch_resource_changed(): void
    {
        Event::fake([ResourceChanged::class]);
        $department = $this->makeDepartment();
        $position = app(PositionService::class)->create(['name' => 'Nhan vien test', 'department_id' => $department->id]);

        app(PositionService::class)->update($position, ['name' => 'Doi ten']);
        app(PositionService::class)->delete($position);

        Event::assertDispatched(ResourceChanged::class, fn (ResourceChanged $e) => $e->resource === 'positions');
        Event::assertDispatchedTimes(ResourceChanged::class, 3);
    }

    public function test_role_and_permission_mutations_dispatch_resource_changed_as_roles(): void
    {
        Event::fake([ResourceChanged::class]);
        $role = app(RoleService::class)->create(['name' => 'Role test '.uniqid()]);
        app(RoleService::class)->update($role, ['description' => 'Mo ta']);

        $permission = app(PermissionService::class)->create(['code' => 'test.action', 'name' => 'Test action']);
        app(PermissionService::class)->update($permission, ['name' => 'Test action 2']);
        app(PermissionService::class)->delete($permission);
        app(RoleService::class)->delete($role);

        Event::assertDispatched(ResourceChanged::class, fn (ResourceChanged $e) => $e->resource === 'roles');
        Event::assertDispatchedTimes(ResourceChanged::class, 6);
    }

    public function test_work_shift_mutations_dispatch_resource_changed(): void
    {
        Event::fake([ResourceChanged::class]);
        $workShift = app(WorkShiftService::class)->create([
            'name' => 'Ca test', 'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);

        app(WorkShiftService::class)->update($workShift, ['name' => 'Ca da doi ten']);
        app(WorkShiftService::class)->delete($workShift);

        Event::assertDispatched(ResourceChanged::class, fn (ResourceChanged $e) => $e->resource === 'work_shifts');
        Event::assertDispatchedTimes(ResourceChanged::class, 3);
    }

    public function test_attendance_location_mutations_dispatch_resource_changed(): void
    {
        Event::fake([ResourceChanged::class]);
        $location = app(AttendanceLocationService::class)->create([
            'name' => 'Van phong test', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);

        app(AttendanceLocationService::class)->update($location, ['name' => 'Da doi ten', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32']);
        app(AttendanceLocationService::class)->delete($location);

        Event::assertDispatched(ResourceChanged::class, fn (ResourceChanged $e) => $e->resource === 'attendance_locations');
        Event::assertDispatchedTimes(ResourceChanged::class, 3);
    }

    public function test_attendance_adjustment_request_and_decision_dispatch_resource_changed(): void
    {
        Event::fake([ResourceChanged::class]);
        $employee = $this->makeEmployee();
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(),
            'first_check_in_at' => now()->setTime(8, 0),
            'last_check_out_at' => now()->setTime(17, 0),
            'late_minutes' => 10,
        ]);
        $hr = $this->makeEmployeeWithLogin('HR');

        $adjustment = app(AttendanceAdjustmentService::class)->requestForEmployee($employee, [
            'type' => 'excuse',
            'attendance_id' => $attendance->id,
            'reason' => 'Ket xe',
        ], $employee->user_id ?? $hr->id);
        app(AttendanceAdjustmentService::class)->decide($adjustment, 'approved', null, $hr->id);

        Event::assertDispatched(ResourceChanged::class, fn (ResourceChanged $e) => $e->resource === 'attendance_adjustments');
        Event::assertDispatchedTimes(ResourceChanged::class, 2);
    }

    public function test_employee_update_and_delete_dispatch_resource_changed(): void
    {
        Event::fake([ResourceChanged::class]);
        $employee = $this->makeEmployee();

        app(EmployeeService::class)->update($employee, ['full_name' => 'Ten moi']);
        app(EmployeeService::class)->delete($employee);

        Event::assertDispatched(ResourceChanged::class, fn (ResourceChanged $e) => $e->resource === 'employees');
        Event::assertDispatchedTimes(ResourceChanged::class, 2);
    }

    public function test_payroll_close_and_mark_as_paid_dispatch_resource_changed(): void
    {
        Event::fake([ResourceChanged::class]);
        $admin = User::where('email', 'admin@qlns.local')->firstOrFail();
        $payroll = Payroll::create([
            'period_month' => now()->month, 'period_year' => now()->year,
            'status' => 'processing', 'currency' => 'VND', 'created_by' => $admin->id,
        ]);

        app(PayrollService::class)->close($payroll, $admin);
        app(PayrollService::class)->markAsPaid($payroll->fresh());

        Event::assertDispatched(ResourceChanged::class, fn (ResourceChanged $e) => $e->resource === 'payrolls');
        Event::assertDispatchedTimes(ResourceChanged::class, 2);
    }
}
