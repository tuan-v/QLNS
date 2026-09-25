<?php

namespace Tests\Feature\Console;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "Nhắc chấm công ra nếu quá 5 phút sau giờ tan ca" (2026-09-25, theo yêu cầu
// người dùng) — xem comment đầu RemindMissingCheckout.php.
class RemindMissingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function makeEmployeeWithLogin(): array
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $user = User::create([
            'email' => 'rmc-'.uniqid().'@qlns.local', 'user_name' => 'RMC User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee = Employee::create([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
            'user_id' => $user->id,
        ]);

        return [$employee, $user];
    }

    private function makeShift(string $start, string $end): WorkShift
    {
        return WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => $start, 'end_time' => $end, 'standard_work_minutes' => 480,
            'is_active' => true,
        ]);
    }

    private function makeAttendance(Employee $employee, WorkShift $workShift, ?Carbon $checkoutReminderSentAt = null): Attendance
    {
        return Attendance::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(),
            'first_check_in_at' => now(),
            'last_check_out_at' => null,
            'checkout_reminder_sent_at' => $checkoutReminderSentAt,
            'approval_status' => Attendance::APPROVAL_APPROVED,
        ]);
    }

    public function test_reminds_when_shift_ended_more_than_5_minutes_ago(): void
    {
        Carbon::setTestNow(Carbon::parse('08:00'));
        [$employee, $user] = $this->makeEmployeeWithLogin();
        // Ca kết thúc lúc 07:54 — đã quá mốc 5 phút ân hạn (08:00 - 07:54 = 6 phút).
        $shift = $this->makeShift('00:00', '07:54');
        $attendance = $this->makeAttendance($employee, $shift);

        $this->artisan('attendance:remind-checkout')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id, 'type' => 'attendance.checkout_reminder',
        ]);
        $this->assertNotNull($attendance->fresh()->checkout_reminder_sent_at);
    }

    public function test_does_not_remind_within_5_minute_grace_period(): void
    {
        Carbon::setTestNow(Carbon::parse('08:00'));
        [$employee] = $this->makeEmployeeWithLogin();
        // Ca kết thúc lúc 07:57 — mới quá 3 phút, còn trong thời gian ân hạn.
        $shift = $this->makeShift('00:00', '07:57');
        $attendance = $this->makeAttendance($employee, $shift);

        $this->artisan('attendance:remind-checkout');

        $this->assertDatabaseCount('notifications', 0);
        $this->assertNull($attendance->fresh()->checkout_reminder_sent_at);
    }

    public function test_does_not_remind_twice_for_the_same_record(): void
    {
        Carbon::setTestNow(Carbon::parse('08:00'));
        [$employee] = $this->makeEmployeeWithLogin();
        $shift = $this->makeShift('00:00', '07:54');
        // Đã được nhắc từ trước (checkout_reminder_sent_at đã có giá trị).
        $this->makeAttendance($employee, $shift, now()->subMinute());

        $this->artisan('attendance:remind-checkout');

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_does_not_remind_when_already_checked_out(): void
    {
        Carbon::setTestNow(Carbon::parse('08:00'));
        [$employee] = $this->makeEmployeeWithLogin();
        $shift = $this->makeShift('00:00', '07:54');
        Attendance::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $shift->id,
            'attendance_date' => now()->toDateString(),
            'first_check_in_at' => now()->subHours(8),
            'last_check_out_at' => now(),
            'approval_status' => Attendance::APPROVAL_APPROVED,
        ]);

        $this->artisan('attendance:remind-checkout');

        $this->assertDatabaseCount('notifications', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
