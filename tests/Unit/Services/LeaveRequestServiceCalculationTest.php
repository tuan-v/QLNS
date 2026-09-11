<?php

namespace Tests\Unit\Services;

use App\Models\LeaveBalance;
use App\Services\LeaveRequestService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Concerns\InteractsWithPrivateMethods;

// Ngày 43: Unit Test cho LeaveRequestService — chỉ nhắm vào các công thức quy
// đổi ngày phép (đều `private`, không đụng DB/HTTP), khác hẳn
// LeaveRequestTest.php/LeaveApprovalTest.php (mục 19/20, Feature Test — đi qua
// route thật + SQLite in-memory, gồm cả các luồng nghiệp vụ như trùng đơn,
// vượt quỹ,...). LeaveBalance dùng ở đây KHÔNG save() — chỉ mang thuộc tính
// thuần túy để truyền vào hàm tính.
class LeaveRequestServiceCalculationTest extends TestCase
{
    use InteractsWithPrivateMethods;

    private function makeService(): LeaveRequestService
    {
        return $this->instantiateWithoutConstructor(LeaveRequestService::class);
    }

    /* ------------------------------- calculateTotalDays -------------------------------- */

    public function test_single_full_day_counts_as_one(): void
    {
        $service = $this->makeService();
        // 2026-01-05 là Thứ 2.
        $from = Carbon::parse('2026-01-05');
        $to = Carbon::parse('2026-01-05');

        $result = $this->callPrivateMethod($service, 'calculateTotalDays', [$from, $to, 'full', 'full']);

        $this->assertSame(1.0, $result);
    }

    public function test_single_half_day_counts_as_half(): void
    {
        $service = $this->makeService();
        $from = Carbon::parse('2026-01-05');
        $to = Carbon::parse('2026-01-05');

        $result = $this->callPrivateMethod($service, 'calculateTotalDays', [$from, $to, 'am', 'am']);

        $this->assertSame(0.5, $result);
    }

    // 2026-01-05 (Thứ 2) -> 2026-01-09 (Thứ 6): 5 ngày làm việc liên tiếp,
    // không đụng cuối tuần nào -> đúng 5.0, không cần trừ gì.
    public function test_full_work_week_counts_all_five_days(): void
    {
        $service = $this->makeService();
        $from = Carbon::parse('2026-01-05');
        $to = Carbon::parse('2026-01-09');

        $result = $this->callPrivateMethod($service, 'calculateTotalDays', [$from, $to, 'full', 'full']);

        $this->assertSame(5.0, $result);
    }

    // 2026-01-05 (Thứ 2) -> 2026-01-12 (Thứ 2 tuần sau): bao trọn 1 cuối tuần
    // (Thứ 7 10/1 + CN 11/1) -> 8 ngày trong khoảng nhưng chỉ 6 ngày làm việc.
    public function test_range_spanning_weekend_excludes_saturday_and_sunday(): void
    {
        $service = $this->makeService();
        $from = Carbon::parse('2026-01-05');
        $to = Carbon::parse('2026-01-12');

        $result = $this->callPrivateMethod($service, 'calculateTotalDays', [$from, $to, 'full', 'full']);

        $this->assertSame(6.0, $result);
    }

    // Toàn bộ khoảng chỉ rơi vào Thứ 7 (10/1) + CN (11/1) -> không có ngày
    // làm việc nào, đúng như StoreLeaveRequest/create() đã chặn ở tầng trên.
    public function test_range_entirely_on_weekend_counts_zero(): void
    {
        $service = $this->makeService();
        $from = Carbon::parse('2026-01-10');
        $to = Carbon::parse('2026-01-11');

        $result = $this->callPrivateMethod($service, 'calculateTotalDays', [$from, $to, 'full', 'full']);

        $this->assertSame(0.0, $result);
    }

    // 2026-01-05 (Thứ 2, nghỉ chiều) -> 2026-01-07 (Thứ 4, nghỉ cả ngày):
    // 0.5 (05, pm) + 1.0 (06, full) + 1.0 (07, full) = 2.5.
    public function test_half_day_start_session_only_affects_first_day(): void
    {
        $service = $this->makeService();
        $from = Carbon::parse('2026-01-05');
        $to = Carbon::parse('2026-01-07');

        $result = $this->callPrivateMethod($service, 'calculateTotalDays', [$from, $to, 'pm', 'full']);

        $this->assertSame(2.5, $result);
    }

    // 2026-01-05 (Thứ 2, nghỉ cả ngày) -> 2026-01-07 (Thứ 4, chỉ nghỉ sáng):
    // 1.0 (05, full) + 1.0 (06, full) + 0.5 (07, am) = 2.5.
    public function test_half_day_end_session_only_affects_last_day(): void
    {
        $service = $this->makeService();
        $from = Carbon::parse('2026-01-05');
        $to = Carbon::parse('2026-01-07');

        $result = $this->callPrivateMethod($service, 'calculateTotalDays', [$from, $to, 'full', 'am']);

        $this->assertSame(2.5, $result);
    }

    /* ------------------------------- calculateHourlyDays -------------------------------- */

    public function test_two_hours_is_a_quarter_day(): void
    {
        $service = $this->makeService();
        $from = Carbon::parse('2026-01-05');

        $result = $this->callPrivateMethod($service, 'calculateHourlyDays', [$from, '09:00', '11:00']);

        $this->assertSame(0.25, $result);
    }

    public function test_four_hours_is_half_a_day(): void
    {
        $service = $this->makeService();
        $from = Carbon::parse('2026-01-05');

        $result = $this->callPrivateMethod($service, 'calculateHourlyDays', [$from, '08:00', '12:00']);

        $this->assertSame(0.5, $result);
    }

    public function test_full_eight_hours_is_one_day(): void
    {
        $service = $this->makeService();
        $from = Carbon::parse('2026-01-05');

        $result = $this->callPrivateMethod($service, 'calculateHourlyDays', [$from, '08:00', '16:00']);

        $this->assertSame(1.0, $result);
    }

    /* ---------------------------------- remainingDays ------------------------------------ */

    public function test_remaining_days_basic_arithmetic(): void
    {
        $service = $this->makeService();
        $balance = new LeaveBalance([
            'allocated_days' => 12,
            'carried_forward_days' => 2,
            'adjusted_days' => 0,
            'used_days' => 5,
        ]);

        $result = $this->callPrivateMethod($service, 'remainingDays', [$balance]);

        $this->assertSame(9.0, $result);
    }

    public function test_remaining_days_rounds_to_two_decimals(): void
    {
        $service = $this->makeService();
        $balance = new LeaveBalance([
            'allocated_days' => 12,
            'carried_forward_days' => 0,
            'adjusted_days' => 0,
            'used_days' => 0.333,
        ]);

        $result = $this->callPrivateMethod($service, 'remainingDays', [$balance]);

        $this->assertSame(11.67, $result);
    }

    public function test_remaining_days_can_go_negative_when_over_used(): void
    {
        $service = $this->makeService();
        $balance = new LeaveBalance([
            'allocated_days' => 12,
            'carried_forward_days' => 0,
            'adjusted_days' => -1,
            'used_days' => 12,
        ]);

        $result = $this->callPrivateMethod($service, 'remainingDays', [$balance]);

        $this->assertSame(-1.0, $result);
    }
}
