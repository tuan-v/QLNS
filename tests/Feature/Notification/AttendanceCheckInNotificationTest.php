<?php

namespace Tests\Feature\Notification;

use App\Models\AttendanceLocation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// 2026-09-25, theo yêu cầu người dùng: "nếu nhân viên chấm công sẽ gửi thông
// báo cho người có quyền duyệt để họ biết" — thông báo THẬT (lưu bảng
// notifications, hiện ở chuông) cho ai có quyền attendance.approve, xem
// AttendanceService::notifyApprovers(). Khác hẳn AttendanceChecked (broadcast
// thuần, không lưu DB, xem AttendanceLiveFeedTest.php) — 2 cơ chế song song.
class AttendanceCheckInNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Http::preventStrayRequests();
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
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ], $overrides));
    }

    private function makeEmployeeWithLogin(string $roleName = 'Employee'): array
    {
        $user = User::create([
            'email' => 'checkin-'.uniqid().'@qlns.local', 'user_name' => 'CheckIn User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(['user_id' => $user->id]);

        return [$employee, $user];
    }

    private function checkIn(Employee $employee, User $user, WorkShift $workShift): void
    {
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'effective_from' => now()->subDay()->toDateString(),
            'work_days' => [1, 2, 3, 4, 5],
            'status' => 'active',
        ]);
        AttendanceLocation::create([
            'code' => 'DD-'.uniqid(), 'name' => 'Van phong', 'method' => 'wifi',
            'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
    }

    public function test_check_in_notifies_hr_and_admin_who_can_approve(): void
    {
        $this->travelTo(Carbon::parse('next monday 08:00'));
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee');
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca sang',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);

        $this->checkIn($employee, $user, $workShift);

        $admin = User::where('email', 'admin@qlns.local')->firstOrFail();
        $hr = User::where('email', 'hr@qlns.local')->firstOrFail();
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'attendance.pending_approval']);
        $this->assertDatabaseHas('notifications', ['user_id' => $hr->id, 'type' => 'attendance.pending_approval']);
        // Đúng 2 người có quyền duyệt trong dữ liệu seed mặc định — không
        // thừa thông báo cho ai khác (vd chính nhân viên vừa chấm công).
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_manager_without_approve_permission_is_not_notified(): void
    {
        $this->travelTo(Carbon::parse('next monday 08:00'));
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee');
        [, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca sang',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);

        $this->checkIn($employee, $user, $workShift);

        $this->assertDatabaseMissing('notifications', ['user_id' => $managerUser->id]);
    }

    public function test_check_out_does_not_create_an_additional_notification(): void
    {
        $monday = Carbon::parse('next monday 08:00');
        $this->travelTo($monday);
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee');
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca sang',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $this->checkIn($employee, $user, $workShift);
        $countAfterCheckIn = \App\Models\Notification::count();

        // Cùng ngày Thứ 2, chỉ đổi giờ — "next monday" tính từ mốc giờ đã
        // travelTo tới sẽ nhảy sang Thứ 2 TUẦN SAU nếu gọi lại từ đầu.
        $this->travelTo($monday->copy()->setTime(17, 0));
        $token = $this->loginAs($user->email, 'Secret@123');
        $this->postJson('/api/v1/attendances/check-out', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertSame($countAfterCheckIn, \App\Models\Notification::count());
    }
}
