<?php

namespace Tests\Unit\Services;

use App\Models\Attendance;
use App\Models\WorkShift;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Tests\TestCase;
use Tests\Unit\Concerns\InteractsWithPrivateMethods;

// Ngày 43: Unit Test cho AttendanceService — chỉ nhắm vào các công thức tính
// TRỄ/VỀ SỚM/OT/nhãn lịch sử (đều `private`, không đụng route/HTTP nào), khác
// hẳn AttendanceTest.php/AttendanceHistoryTest.php (mục 16/18, Feature Test —
// đi qua route thật + SQLite in-memory). Model WorkShift/Attendance dùng ở
// đây đều KHÔNG save() — chỉ là object mang thuộc tính thuần túy để truyền
// vào hàm tính. Kế thừa Tests\TestCase (boot Laravel, KHÔNG dùng
// RefreshDatabase — không tạo bảng nào) thay vì PHPUnit\Framework\TestCase
// trần: Eloquent cần connection resolver để tính getDateFormat() ngay khi
// gán 1 giá trị Carbon vào field có cast 'datetime' (first_check_in_at/
// last_check_out_at), dù không thật sự chạm DB.
class AttendanceServiceCalculationTest extends TestCase
{
    use InteractsWithPrivateMethods;

    private function makeService(): AttendanceService
    {
        return $this->instantiateWithoutConstructor(AttendanceService::class);
    }

    private function makeWorkShift(array $overrides = []): WorkShift
    {
        return new WorkShift(array_merge([
            'start_time' => '08:00',
            'end_time' => '17:00',
            'late_grace_minutes' => 5,
            'early_leave_grace_minutes' => 5,
        ], $overrides));
    }

    /* ------------------------------ calculateLateMinutes ------------------------------ */

    public function test_check_in_before_shift_start_is_not_late(): void
    {
        $service = $this->makeService();
        $workShift = $this->makeWorkShift();
        $checkIn = Carbon::parse('2026-01-05 07:50:00');

        $result = $this->callPrivateMethod($service, 'calculateLateMinutes', [$workShift, $checkIn]);

        $this->assertSame(0, $result);
    }

    public function test_check_in_within_grace_period_is_not_late(): void
    {
        $service = $this->makeService();
        // late_grace_minutes=5, vào lúc 08:05 -> đúng ranh giới ân hạn.
        $workShift = $this->makeWorkShift();
        $checkIn = Carbon::parse('2026-01-05 08:05:00');

        $result = $this->callPrivateMethod($service, 'calculateLateMinutes', [$workShift, $checkIn]);

        // Điều kiện dùng gt (strictly greater than) — đúng ranh giới ân hạn
        // vẫn tính là KHÔNG trễ.
        $this->assertSame(0, $result);
    }

    public function test_check_in_after_grace_period_is_late(): void
    {
        $service = $this->makeService();
        $workShift = $this->makeWorkShift();
        // Ân hạn hết lúc 08:05, vào lúc 08:20 -> trễ 15 phút TÍNH TỪ MỐC ÂN HẠN
        // (không phải từ start_time gốc).
        $checkIn = Carbon::parse('2026-01-05 08:20:00');

        $result = $this->callPrivateMethod($service, 'calculateLateMinutes', [$workShift, $checkIn]);

        $this->assertSame(15, $result);
    }

    /* ---------------------------- calculateEarlyLeaveMinutes --------------------------- */

    public function test_check_out_after_shift_end_is_not_early_leave(): void
    {
        $service = $this->makeService();
        $workShift = $this->makeWorkShift();
        $checkOut = Carbon::parse('2026-01-05 17:10:00');

        $result = $this->callPrivateMethod($service, 'calculateEarlyLeaveMinutes', [$workShift, $checkOut]);

        $this->assertSame(0, $result);
    }

    public function test_check_out_within_grace_period_is_not_early_leave(): void
    {
        $service = $this->makeService();
        // early_leave_grace_minutes=5, ra lúc 16:55 -> đúng ranh giới ân hạn.
        $workShift = $this->makeWorkShift();
        $checkOut = Carbon::parse('2026-01-05 16:55:00');

        $result = $this->callPrivateMethod($service, 'calculateEarlyLeaveMinutes', [$workShift, $checkOut]);

        $this->assertSame(0, $result);
    }

    public function test_check_out_before_grace_period_is_early_leave(): void
    {
        $service = $this->makeService();
        $workShift = $this->makeWorkShift();
        // Ân hạn bắt đầu tính sớm từ 16:55, ra lúc 16:30 -> về sớm 25 phút.
        $checkOut = Carbon::parse('2026-01-05 16:30:00');

        $result = $this->callPrivateMethod($service, 'calculateEarlyLeaveMinutes', [$workShift, $checkOut]);

        $this->assertSame(25, $result);
    }

    /* ----------------------------- calculateOvertimeMinutes ---------------------------- */

    public function test_check_out_exactly_at_shift_end_has_no_overtime(): void
    {
        $service = $this->makeService();
        $workShift = $this->makeWorkShift();
        $checkOut = Carbon::parse('2026-01-05 17:00:00');

        $result = $this->callPrivateMethod($service, 'calculateOvertimeMinutes', [$workShift, $checkOut]);

        $this->assertSame(0, $result);
    }

    public function test_check_out_after_shift_end_calculates_overtime(): void
    {
        $service = $this->makeService();
        $workShift = $this->makeWorkShift();
        $checkOut = Carbon::parse('2026-01-05 18:30:00');

        $result = $this->callPrivateMethod($service, 'calculateOvertimeMinutes', [$workShift, $checkOut]);

        $this->assertSame(90, $result);
    }

    /* -------------------------------- deriveHistoryStatus ------------------------------ */

    public function test_derive_history_status_null_attendance_is_absent(): void
    {
        $service = $this->makeService();

        $result = $this->callPrivateMethod($service, 'deriveHistoryStatus', [null]);

        $this->assertSame('absent', $result);
    }

    public function test_derive_history_status_without_check_in_is_absent(): void
    {
        $service = $this->makeService();
        $attendance = new Attendance(['first_check_in_at' => null]);

        $result = $this->callPrivateMethod($service, 'deriveHistoryStatus', [$attendance]);

        $this->assertSame('absent', $result);
    }

    public function test_derive_history_status_late_and_not_excused_is_late(): void
    {
        $service = $this->makeService();
        $attendance = new Attendance([
            'first_check_in_at' => Carbon::parse('2026-01-05 08:20:00'),
            'last_check_out_at' => Carbon::parse('2026-01-05 17:00:00'),
            'late_minutes' => 20,
            'early_leave_minutes' => 0,
            'late_excused' => false,
        ]);

        $result = $this->callPrivateMethod($service, 'deriveHistoryStatus', [$attendance]);

        $this->assertSame('late', $result);
    }

    // Ngày 42 — HR đã duyệt "Xin miễn trừ đi muộn": late_minutes vẫn > 0
    // nhưng late_excused=true thì KHÔNG còn coi là "late" nữa.
    public function test_derive_history_status_late_but_excused_falls_through_to_full(): void
    {
        $service = $this->makeService();
        $attendance = new Attendance([
            'first_check_in_at' => Carbon::parse('2026-01-05 08:20:00'),
            'last_check_out_at' => Carbon::parse('2026-01-05 17:00:00'),
            'late_minutes' => 20,
            'early_leave_minutes' => 0,
            'late_excused' => true,
        ]);

        $result = $this->callPrivateMethod($service, 'deriveHistoryStatus', [$attendance]);

        $this->assertSame('full', $result);
    }

    public function test_derive_history_status_missing_check_out_is_insufficient(): void
    {
        $service = $this->makeService();
        $attendance = new Attendance([
            'first_check_in_at' => Carbon::parse('2026-01-05 08:00:00'),
            'last_check_out_at' => null,
            'late_minutes' => 0,
        ]);

        $result = $this->callPrivateMethod($service, 'deriveHistoryStatus', [$attendance]);

        $this->assertSame('insufficient', $result);
    }

    public function test_derive_history_status_early_leave_is_insufficient(): void
    {
        $service = $this->makeService();
        $attendance = new Attendance([
            'first_check_in_at' => Carbon::parse('2026-01-05 08:00:00'),
            'last_check_out_at' => Carbon::parse('2026-01-05 16:30:00'),
            'late_minutes' => 0,
            'early_leave_minutes' => 30,
        ]);

        $result = $this->callPrivateMethod($service, 'deriveHistoryStatus', [$attendance]);

        $this->assertSame('insufficient', $result);
    }

    public function test_derive_history_status_full_day_is_full(): void
    {
        $service = $this->makeService();
        $attendance = new Attendance([
            'first_check_in_at' => Carbon::parse('2026-01-05 08:00:00'),
            'last_check_out_at' => Carbon::parse('2026-01-05 17:00:00'),
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
        ]);

        $result = $this->callPrivateMethod($service, 'deriveHistoryStatus', [$attendance]);

        $this->assertSame('full', $result);
    }

    /* ----------------------------------- ipInCidr -------------------------------------- */

    public function test_ip_in_cidr_exact_match_without_slash(): void
    {
        $service = $this->makeService();

        $result = $this->callPrivateMethod($service, 'ipInCidr', ['192.168.1.10', '192.168.1.10']);

        $this->assertTrue($result);
    }

    public function test_ip_in_cidr_exact_match_without_slash_mismatch(): void
    {
        $service = $this->makeService();

        $result = $this->callPrivateMethod($service, 'ipInCidr', ['192.168.1.11', '192.168.1.10']);

        $this->assertFalse($result);
    }

    public function test_ip_in_cidr_matches_within_range(): void
    {
        $service = $this->makeService();

        $result = $this->callPrivateMethod($service, 'ipInCidr', ['172.19.5.42', '172.19.0.0/16']);

        $this->assertTrue($result);
    }

    public function test_ip_in_cidr_does_not_match_outside_range(): void
    {
        $service = $this->makeService();

        $result = $this->callPrivateMethod($service, 'ipInCidr', ['172.20.5.42', '172.19.0.0/16']);

        $this->assertFalse($result);
    }

    /* --------------------------------- distanceMeters ----------------------------------- */

    public function test_distance_meters_same_point_is_zero(): void
    {
        $service = $this->makeService();

        $result = $this->callPrivateMethod($service, 'distanceMeters', [21.0285, 105.8542, 21.0285, 105.8542]);

        $this->assertEqualsWithDelta(0.0, $result, 0.001);
    }

    public function test_distance_meters_known_short_distance(): void
    {
        $service = $this->makeService();
        // 2 điểm cách nhau đúng 0.001 độ vĩ tuyến (~111m) tại xích đạo gần
        // đúng — dùng để kiểm công thức Haversine cho ra kết quả HỢP LÝ
        // (không cần chính xác tuyệt đối, chỉ cần đúng bậc độ lớn).
        $result = $this->callPrivateMethod($service, 'distanceMeters', [0.0, 0.0, 0.001, 0.0]);

        $this->assertEqualsWithDelta(111.19, $result, 1.0);
    }
}
