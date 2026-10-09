<?php

namespace Tests\Feature\Holiday;

use App\Models\Holiday;
use App\Models\HolidayRule;
use App\Models\Notification;
use App\Services\HolidayService;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayTest extends TestCase
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

    private function employee(): array
    {
        return $this->headers('employee@qlns.local', 'Employee@123');
    }

    private function service(): HolidayService
    {
        return app(HolidayService::class);
    }

    private function datesOf(?string $source = null): array
    {
        return Holiday::query()
            ->when($source, fn ($q) => $q->where('source', $source))
            ->orderBy('holiday_date')
            ->pluck('holiday_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
    }

    public function test_generates_statutory_holidays_from_solar_and_lunar_rules(): void
    {
        $this->service()->generateForYear(2026);

        $dates = $this->datesOf();
        // Tết Nguyên Đán 2026 (mùng 1 = 17/2): nghỉ từ 30 Tết tới mùng 4.
        foreach (['2026-02-16', '2026-02-17', '2026-02-18', '2026-02-19', '2026-02-20'] as $tet) {
            $this->assertContains($tet, $dates);
        }
        $this->assertContains('2026-01-01', $dates);
        $this->assertContains('2026-04-26', $dates); // Giỗ Tổ 10/3 âm
        $this->assertContains('2026-04-30', $dates);
        $this->assertContains('2026-05-01', $dates);
        // Quốc khánh tự chọn ngày liền kề: 2/9/2026 là Thứ 4 -> nghỉ 1/9-2/9 (đúng lịch chính thức).
        $this->assertContains('2026-09-01', $dates);
        $this->assertContains('2026-09-02', $dates);
        $this->assertNotContains('2026-09-03', $dates);
    }

    public function test_vietnam_culture_day_applies_from_2026_only(): void
    {
        $this->service()->generateForYear(2025);
        $this->service()->generateForYear(2026);

        $dates = $this->datesOf();
        $this->assertContains('2026-11-24', $dates);
        $this->assertNotContains('2025-11-24', $dates);
    }

    public function test_past_days_corrected_to_the_official_schedule_are_not_regenerated(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06'));
        $this->service()->generateForYear(2026);

        // Ngày đã qua được sửa theo lịch thực tế (vd Tết bắt đầu từ 13/2 thay vì 16/2).
        Holiday::where('holiday_date', '2026-02-16')->update(['holiday_date' => '2026-02-13']);
        $this->service()->generateForYear(2026);

        $dates = $this->datesOf();
        $this->assertContains('2026-02-13', $dates);
        $this->assertNotContains('2026-02-16', $dates);
    }

    public function test_holiday_on_weekend_gets_a_compensatory_day_on_the_next_working_day(): void
    {
        $this->service()->generateForYear(2026);

        // Giỗ Tổ 26/4/2026 là Chủ nhật -> nghỉ bù Thứ 2 27/4, cùng dịp.
        $this->assertSame(['2026-04-27'], $this->datesOf(Holiday::SOURCE_COMPENSATORY));
        $compensatory = Holiday::where('holiday_date', '2026-04-27')->first();
        $this->assertSame(
            Holiday::where('holiday_date', '2026-04-26')->value('group_code'),
            $compensatory->group_code,
        );
    }

    public function test_generation_is_idempotent_and_keeps_deleted_occasions_deleted(): void
    {
        $this->service()->generateForYear(2026);
        $this->assertSame(['created' => 0, 'removed' => 0], $this->service()->generateForYear(2026));

        $group = Holiday::where('holiday_date', '2026-05-01')->value('group_code');
        $this->service()->deleteGroup($group);
        $this->service()->generateForYear(2026);

        $this->assertNotContains('2026-05-01', $this->datesOf());
    }

    public function test_standard_work_days_exclude_generated_holidays(): void
    {
        $this->service()->generateForYear(2026);

        // Tháng 4/2026 có 22 ngày T2-T6, trừ nghỉ bù 27/4 và 30/4 -> 20.
        $days = app(PayrollService::class)->standardWorkDaysFor(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'));
        $this->assertSame(20, $days);
    }

    public function test_every_logged_in_user_can_view_but_only_holiday_managers_can_change(): void
    {
        $this->service()->generateForYear(2026);

        $this->getJson('/api/v1/holidays?year=2026', $this->employee())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Tết Dương lịch');

        $this->postJson('/api/v1/holidays/generate', ['year' => 2027], $this->employee())->assertForbidden();
        $this->postJson('/api/v1/holidays', ['name' => 'X'], $this->employee())->assertForbidden();
        $this->getJson('/api/v1/holidays/rules', $this->employee())->assertForbidden();

        $this->postJson('/api/v1/holidays/generate', ['year' => 2027], $this->hr())->assertOk();
    }

    public function test_hr_can_add_a_company_holiday_by_lunar_date(): void
    {
        // Rằm tháng 8 năm 2026 = 25/9/2026, nghỉ 2 ngày.
        $this->postJson('/api/v1/holidays', [
            'name' => 'Trung thu công ty',
            'calendar' => 'lunar',
            'lunar_day' => 15,
            'lunar_month' => 8,
            'lunar_year' => 2026,
            'duration_days' => 2,
            'is_paid' => true,
        ], $this->hr())
            ->assertCreated()
            ->assertJsonPath('start_date', '2026-09-25')
            ->assertJsonPath('end_date', '2026-09-26')
            ->assertJsonPath('source', 'manual');
    }

    public function test_cannot_add_a_holiday_on_a_day_that_is_already_off(): void
    {
        $this->service()->generateForYear(2026);

        $this->postJson('/api/v1/holidays', [
            'name' => 'Trùng ngày',
            'calendar' => 'solar',
            'start_date' => '2026-09-02',
            'duration_days' => 1,
        ], $this->hr())->assertStatus(422)->assertJsonValidationErrors('start_date');
    }

    public function test_lunar_conversion_rejects_a_leap_month_that_does_not_exist(): void
    {
        $this->getJson('/api/v1/holidays/convert?lunar_day=1&lunar_month=3&lunar_year=2026&lunar_leap=1', $this->hr())
            ->assertStatus(422);
        $this->getJson('/api/v1/holidays/convert?lunar_day=1&lunar_month=1&lunar_year=2027', $this->hr())
            ->assertOk()
            ->assertJsonPath('date', '2027-02-06');
    }

    public function test_upcoming_holiday_is_notified_once_to_active_users(): void
    {
        $this->service()->generateForYear(2026);
        Holiday::query()->update(['confirmed_at' => now()]);
        $this->travelTo(Carbon::parse('2026-04-22 08:00'));

        $this->artisan('holidays:notify')->assertSuccessful();

        $notifications = Notification::where('type', 'holiday.upcoming')->get();
        $this->assertNotEmpty($notifications);
        $this->assertStringContainsString('Giỗ Tổ Hùng Vương', $notifications->first()->title);
        $this->assertStringContainsString('nghỉ bù 27/04', $notifications->first()->message);
        $this->assertStringContainsString('Ngày đi làm lại: Thứ 3 28/04/2026', $notifications->first()->message);

        $count = $notifications->count();
        $this->artisan('holidays:notify')->assertSuccessful();
        $this->assertSame($count, Notification::where('type', 'holiday.upcoming')->count());
        $this->assertNotNull(Holiday::where('holiday_date', '2026-04-26')->value('notified_at'));
    }

    public function test_holiday_outside_the_notice_window_is_not_notified_yet(): void
    {
        $this->service()->generateForYear(2026);
        $this->travelTo(Carbon::parse('2026-04-10 08:00'));

        $this->artisan('holidays:notify')->assertSuccessful();

        $this->assertSame(0, Notification::where('type', 'holiday.upcoming')->count());
    }

    public function test_system_rule_cannot_be_deleted_but_its_schedule_can_be_adjusted(): void
    {
        $rule = HolidayRule::where('name', 'Quốc khánh')->first();

        $this->deleteJson("/api/v1/holidays/rules/{$rule->id}", [], $this->hr())->assertStatus(422);

        // Năm đó nghỉ 1/9-2/9 thay vì 2/9-3/9.
        $this->putJson("/api/v1/holidays/rules/{$rule->id}", ['auto_adjacent' => false, 'offset_days' => -1, 'name' => 'Đổi tên'], $this->hr())
            ->assertOk()
            ->assertJsonPath('name', 'Quốc khánh');

        $dates = $this->datesOf();
        $this->assertContains('2027-09-01', $dates);
        $this->assertContains('2027-09-02', $dates);
        $this->assertNotContains('2027-09-03', $dates);
    }

    public function test_rule_rejects_a_day_that_does_not_exist_in_the_month(): void
    {
        $payload = ['name' => 'Sai ngày', 'calendar' => 'solar', 'month' => 4, 'day' => 31, 'duration_days' => 1];
        $this->postJson('/api/v1/holidays/rules', $payload, $this->hr())
            ->assertStatus(422)->assertJsonValidationErrors('day');

        $this->postJson('/api/v1/holidays/rules', ['month' => 'abc', 'day' => 'x'] + $payload, $this->hr())
            ->assertStatus(422)->assertJsonValidationErrors(['month', 'day']);

        $this->postJson('/api/v1/holidays/rules', ['calendar' => 'lunar', 'month' => 8, 'day' => 31] + $payload, $this->hr())
            ->assertStatus(422)->assertJsonValidationErrors('day');
    }

    public function test_custom_recurring_rule_is_generated_and_removed_with_the_rule(): void
    {
        $id = $this->postJson('/api/v1/holidays/rules', [
            'name' => 'Ngày thành lập công ty',
            'calendar' => 'solar',
            'month' => 11,
            'day' => 20,
            'duration_days' => 1,
        ], $this->hr())->assertCreated()->json('id');

        $this->assertContains('2027-11-20', $this->datesOf());

        $this->deleteJson("/api/v1/holidays/rules/{$id}", [], $this->hr())->assertNoContent();
        $this->assertNotContains('2027-11-20', $this->datesOf());
    }
}
