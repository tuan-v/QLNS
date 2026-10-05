<?php

namespace Tests\Feature\Holiday;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Holiday;
use App\Models\HolidayRule;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\AttendanceService;
use App\Services\HolidayService;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Lịch nghỉ bán tự động: tự chọn ngày liền kề, HR xác nhận, nhắc HR, chỉ thông báo
// nhân viên khi đã xác nhận, điều chỉnh ngày và hoán đổi ngày làm việc.
class HolidayConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function headers(string $email, string $password): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function hr(): array
    {
        return $this->headers('hr@qlns.local', 'Hr@123456');
    }

    private function service(): HolidayService
    {
        return app(HolidayService::class);
    }

    private function dates(): array
    {
        return Holiday::orderBy('holiday_date')->pluck('holiday_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
    }

    private function groupOf(string $date): string
    {
        return Holiday::where('holiday_date', $date)->value('group_code');
    }

    public function test_national_day_adjacent_day_is_chosen_to_join_the_weekend(): void
    {
        foreach ([2024, 2025, 2026, 2027] as $year) {
            $this->service()->generateForYear($year);
        }

        $dates = $this->dates();
        // 2024: 2/9 Thứ 2 -> 2-3/9; 2025: Thứ 3 -> 1-2/9; 2026: Thứ 4 -> 1-2/9; 2027: Thứ 5 -> 2-3/9.
        foreach (['2024-09-03', '2025-09-01', '2026-09-01', '2027-09-03'] as $expected) {
            $this->assertContains($expected, $dates);
        }
        foreach (['2024-09-01', '2025-09-03', '2026-09-03', '2027-09-01'] as $unexpected) {
            $this->assertNotContains($unexpected, $dates);
        }
    }

    public function test_generated_occasions_start_unconfirmed_and_manual_ones_are_confirmed(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02'));
        $this->service()->generateForYear(2027);

        $this->assertSame(0, Holiday::whereNotNull('confirmed_at')->count());

        $group = $this->postJson('/api/v1/holidays', [
            'name' => 'Kỷ niệm thành lập',
            'calendar' => 'solar',
            'start_date' => '2027-06-15',
            'duration_days' => 1,
        ], $this->hr())->assertCreated();

        $this->assertTrue($group->json('confirmed'));
    }

    public function test_unconfirmed_occasion_reminds_hr_once_and_does_not_notify_employees(): void
    {
        $this->travelTo(Carbon::parse('2027-04-10 08:00'));
        $this->service()->generateForYear(2027);

        $this->artisan('holidays:notify')->assertSuccessful();
        $this->artisan('holidays:notify')->assertSuccessful();

        $hr = User::where('email', 'hr@qlns.local')->first();
        $employee = User::where('email', 'employee@qlns.local')->first();

        $this->assertSame(0, Notification::where('type', 'holiday.upcoming')->count());
        $reminders = Notification::where('type', 'holiday.confirm_needed')->where('user_id', $hr->id)->get();
        $this->assertNotEmpty($reminders);
        $this->assertSame($reminders->pluck('data')->map(fn ($d) => $d['group_code'])->unique()->count(), $reminders->count());
        $this->assertSame(0, Notification::where('type', 'holiday.confirm_needed')->where('user_id', $employee->id)->count());
    }

    public function test_confirmed_occasion_is_notified_to_employees(): void
    {
        $this->travelTo(Carbon::parse('2027-04-10 08:00'));
        $this->service()->generateForYear(2027);
        $group = $this->groupOf('2027-04-16'); // Giỗ Tổ 2027

        $this->postJson("/api/v1/holidays/groups/{$group}/confirm", [], $this->hr())
            ->assertOk()
            ->assertJsonPath('confirmed', true);

        $this->artisan('holidays:notify')->assertSuccessful();

        $this->assertTrue(
            Notification::where('type', 'holiday.upcoming')->get()->contains(fn ($n) => $n->data['group_code'] === $group),
        );
    }

    public function test_employee_cannot_confirm_or_adjust(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02'));
        $this->service()->generateForYear(2027);
        $group = $this->groupOf('2027-04-16');
        $employee = $this->headers('employee@qlns.local', 'Employee@123');

        $this->postJson("/api/v1/holidays/groups/{$group}/confirm", [], $employee)->assertForbidden();
        $this->postJson("/api/v1/holidays/groups/{$group}/swaps", ['off_date' => '2027-04-19', 'makeup_date' => '2027-04-24'], $employee)->assertForbidden();
    }

    public function test_confirmed_occasion_is_not_overwritten_when_the_rule_changes(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02'));
        $this->service()->generateForYear(2027);
        $this->postJson('/api/v1/holidays/groups/'.$this->groupOf('2027-09-02').'/confirm', [], $this->hr())->assertOk();

        $rule = HolidayRule::where('name', 'Quốc khánh')->first();
        $this->putJson("/api/v1/holidays/rules/{$rule->id}", ['auto_adjacent' => false, 'offset_days' => -1], $this->hr())->assertOk();

        $dates = $this->dates();
        $this->assertContains('2027-09-03', $dates);
        $this->assertNotContains('2027-09-01', $dates);
    }

    public function test_adding_and_removing_days_unconfirms_and_survives_regeneration(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02'));
        $this->service()->generateForYear(2027);
        $group = $this->groupOf('2027-02-05'); // Tết 2027
        $this->postJson("/api/v1/holidays/groups/{$group}/confirm", [], $this->hr())->assertOk();

        // Theo thông báo chính thức: nghỉ thêm 12/2, bỏ ngày nghỉ bù 11/2.
        $this->postJson("/api/v1/holidays/groups/{$group}/days", ['date' => '2027-02-12'], $this->hr())
            ->assertCreated()
            ->assertJsonPath('confirmed', false);
        $this->deleteJson('/api/v1/holidays/days/'.Holiday::where('holiday_date', '2027-02-11')->value('id'), [], $this->hr())
            ->assertOk();

        $this->service()->generateForYear(2027);

        $dates = $this->dates();
        $this->assertContains('2027-02-12', $dates);
        $this->assertNotContains('2027-02-11', $dates);
    }

    public function test_swap_makes_the_makeup_weekend_a_working_day(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02'));
        $this->service()->generateForYear(2027);
        $group = $this->groupOf('2027-09-02'); // Quốc khánh 2027: 2/9 (Thứ 5), 3/9 (Thứ 6)

        // Nghỉ thêm Thứ 3 31/8, đi làm bù Thứ 7 11/9.
        $this->postJson("/api/v1/holidays/groups/{$group}/swaps", [
            'off_date' => '2027-08-31',
            'makeup_date' => '2027-09-11',
        ], $this->hr())
            ->assertCreated()
            ->assertJsonPath('makeup_days.0.date', '2027-09-11');

        // Tháng 9/2027: 22 ngày T2-T6 - 2/9 - 3/9 + làm bù 11/9 = 21.
        $payroll = app(PayrollService::class);
        $this->assertSame(21, $payroll->standardWorkDaysFor(Carbon::parse('2027-09-01'), Carbon::parse('2027-09-30')));

        // Ngày làm bù được xét ca như Thứ 3 (ngày đã nghỉ thay) -> nhân viên có ca T2-T6 chấm công được.
        $this->assertSame(2, Holiday::effectiveWeekdayIso('2027-09-11'));
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $employee = Employee::create([
            'full_name' => 'Nhan vien hoan doi', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => '2026-01-01', 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        $shift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca hanh chinh',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $shift->id,
            'effective_from' => '2026-01-01', 'work_days' => [1, 2, 3, 4, 5], 'status' => 'active',
        ]);

        $attendance = app(AttendanceService::class);
        $this->assertCount(1, $attendance->listActiveAssignmentsForDate($employee, Carbon::parse('2027-09-11')));
        $this->assertCount(0, $attendance->listActiveAssignmentsForDate($employee, Carbon::parse('2027-09-18')));
    }

    public function test_swap_rejects_weekend_off_day_and_weekday_makeup_day(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02'));
        $this->service()->generateForYear(2027);
        $group = $this->groupOf('2027-09-02');

        $this->postJson("/api/v1/holidays/groups/{$group}/swaps", ['off_date' => '2027-09-04', 'makeup_date' => '2027-09-11'], $this->hr())
            ->assertStatus(422)->assertJsonValidationErrors('off_date');
        $this->postJson("/api/v1/holidays/groups/{$group}/swaps", ['off_date' => '2027-08-31', 'makeup_date' => '2027-09-13'], $this->hr())
            ->assertStatus(422)->assertJsonValidationErrors('makeup_date');
    }

    public function test_draft_changes_are_saved_together_and_confirm_the_schedule(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02'));
        $this->service()->generateForYear(2027);
        $group = $this->groupOf('2027-09-02');

        $this->postJson("/api/v1/holidays/groups/{$group}/apply", [
            'remove_ids' => [Holiday::where('holiday_date', '2027-09-03')->value('id')],
            'add_dates' => ['2027-09-01'],
            'swaps' => [['off_date' => '2027-08-31', 'makeup_date' => '2027-09-11']],
        ], $this->hr())
            ->assertOk()
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('makeup_days.0.date', '2027-09-11');

        $dates = $this->dates();
        $this->assertContains('2027-09-01', $dates);
        $this->assertContains('2027-08-31', $dates);
        $this->assertNotContains('2027-09-03', $dates);
    }

    public function test_one_invalid_change_saves_nothing(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02'));
        $this->service()->generateForYear(2027);
        $group = $this->groupOf('2027-09-02');
        $before = $this->dates();

        $this->postJson("/api/v1/holidays/groups/{$group}/apply", [
            'remove_ids' => [Holiday::where('holiday_date', '2027-09-03')->value('id')],
            'add_dates' => ['2027-09-01'],
            'swaps' => [['off_date' => '2027-08-31', 'makeup_date' => '2027-09-13']], // Thứ 2 — không hợp lệ
        ], $this->hr())->assertStatus(422);

        $this->assertSame($before, $this->dates());
        $this->assertSame(0, Holiday::where('group_code', $group)->whereNotNull('confirmed_at')->count());
    }
}
