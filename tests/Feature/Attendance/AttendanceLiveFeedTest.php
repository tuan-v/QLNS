<?php

namespace Tests\Feature\Attendance;

use App\Events\AttendanceChecked;
use App\Models\AttendanceLocation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AttendanceLiveFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Cache permission:{userId} có thể còn sót từ test khác trong CÙNG
        // process PHPUnit (RefreshDatabase reset DB nhưng không tự xóa
        // cache) — cùng cách PermissionMiddlewareTest.php đã làm.
        Cache::flush();
        // phpunit.xml đặt BROADCAST_CONNECTION=null cho TOÀN BỘ test suite
        // (an toàn mặc định của Laravel — hàng trăm test khác không vô tình
        // bắn broadcast thật ra ngoài). NullBroadcaster::auth() luôn no-op
        // (200 rỗng bất kể quyền gì) — test xác thực kênh ở dưới bắt buộc
        // phải đổi sang đúng driver "reverb" thật mới đo được đúng logic
        // (đã xác nhận auth() không gọi mạng, chỉ tính HMAC cục bộ, an toàn).
        // CHỈ đổi config KHÔNG ĐỦ: mỗi driver giữ $channels RIÊNG, còn
        // routes/channels.php đã chạy 1 lần lúc app boot (lúc default vẫn
        // là "null") — phải require LẠI để đăng ký đúng vào driver hiện tại.
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
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

    private function makeEmployeeWithLogin(string $roleName = 'Employee'): array
    {
        $user = User::create([
            'email' => 'feed-'.uniqid().'@qlns.local', 'user_name' => 'Feed User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(['user_id' => $user->id]);

        return [$employee, $user];
    }

    public function test_hr_can_authorize_the_live_feed_channel(): void
    {
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-attendance.live-feed',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
    }

    public function test_employee_without_view_all_cannot_authorize_the_live_feed_channel(): void
    {
        [, $employeeUser] = $this->makeEmployeeWithLogin('Employee');
        $token = $this->loginAs($employeeUser->email, 'Secret@123');

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-attendance.live-feed',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(403);
    }

    public function test_check_in_dispatches_attendance_checked_event_and_does_not_persist_a_notification(): void
    {
        Event::fake([AttendanceChecked::class]);
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee');
        $workShift = WorkShift::create([
            'code' => 'CA001', 'name' => 'Ca sang',
            'start_time' => '08:00', 'end_time' => '17:00',
            'standard_work_minutes' => 480, 'late_grace_minutes' => 5, 'early_leave_grace_minutes' => 5,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'effective_from' => now()->subDay()->toDateString(),
            'work_days' => [1, 2, 3, 4, 5],
            'status' => 'active',
        ]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'Van phong', 'method' => 'wifi',
            'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $monday = Carbon::parse('next monday 08:00');
        $this->travelTo($monday);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        Event::assertDispatched(AttendanceChecked::class, function (AttendanceChecked $event) use ($employee) {
            return $event->employee->id === $employee->id && $event->type === 'in';
        });
        // Live-feed là broadcast thuần, KHÔNG được ghi vào bảng notifications
        // (nếu không sẽ spam hộp thư mọi HR mỗi lần bất kỳ ai chấm công).
        $this->assertDatabaseCount('notifications', 0);
    }
}
