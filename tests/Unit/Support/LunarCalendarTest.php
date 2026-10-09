<?php

namespace Tests\Unit\Support;

use App\Support\LunarCalendar;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

// Đối chiếu với lịch thật (ngày Tết, Giỗ Tổ, tháng nhuận đã được công bố).
class LunarCalendarTest extends TestCase
{
    public function test_lunar_new_year_dates_match_the_real_calendar(): void
    {
        $this->assertSame('2024-02-10', LunarCalendar::toSolar(1, 1, 2024)->toDateString());
        $this->assertSame('2025-01-29', LunarCalendar::toSolar(1, 1, 2025)->toDateString());
        $this->assertSame('2026-02-17', LunarCalendar::toSolar(1, 1, 2026)->toDateString());
        $this->assertSame('2027-02-06', LunarCalendar::toSolar(1, 1, 2027)->toDateString());
    }

    public function test_hung_kings_commemoration_dates(): void
    {
        $this->assertSame('2023-04-29', LunarCalendar::toSolar(10, 3, 2023)->toDateString());
        $this->assertSame('2025-04-07', LunarCalendar::toSolar(10, 3, 2025)->toDateString());
        $this->assertSame('2026-04-26', LunarCalendar::toSolar(10, 3, 2026)->toDateString());
    }

    public function test_leap_month_is_handled(): void
    {
        // Năm Quý Mão 2023 có tháng 2 nhuận, bắt đầu 22/3/2023.
        $this->assertSame('2023-03-22', LunarCalendar::toSolar(1, 2, 2023, true)->toDateString());
        $this->assertSame(
            ['day' => 1, 'month' => 2, 'year' => 2023, 'leap' => true],
            LunarCalendar::fromSolar(Carbon::parse('2023-03-22')),
        );
    }

    public function test_leap_month_that_does_not_exist_returns_null(): void
    {
        $this->assertNull(LunarCalendar::toSolar(1, 3, 2026, true));
    }

    public function test_solar_to_lunar_round_trip_and_labels(): void
    {
        $this->assertSame(
            ['day' => 15, 'month' => 8, 'year' => 2026, 'leap' => false],
            LunarCalendar::fromSolar(Carbon::parse('2026-09-25')),
        );
        $this->assertSame('Mùng 1 tháng 1 (Bính Ngọ)', LunarCalendar::label(Carbon::parse('2026-02-17')));
        $this->assertSame('Đinh Mùi', LunarCalendar::canChiYear(2027));
    }
}
