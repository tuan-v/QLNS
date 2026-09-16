<?php

namespace Tests\Unit\Services;

use App\Models\Attendance;
use App\Models\WorkShift;
use App\Services\WorkTimeCalculationService;
use Tests\TestCase;

// Unit test cho WorkTimeCalculationService — bảng bậc thang dùng CHUNG cho
// AttendanceService (tổng ngày công hiển thị) và PayrollService (ngày công
// tính lương), kết hợp % giờ làm + trần phút trễ + work_coefficient của ca
// (2026-09-16, theo yêu cầu người dùng — bản đầu bỏ hẳn work_coefficient,
// sau đó sửa lại vì bug thật: nhân viên chia ca sáng+chiều mỗi ca 0.5 công
// làm đủ CẢ 2 ca bị tính thành 2.0 thay vì đúng 1.0 nếu bỏ work_coefficient).
// Model dùng ở đây đều KHÔNG save() — chỉ là object mang thuộc tính thuần
// túy để truyền vào hàm tính, giống AttendanceServiceCalculationTest.php.
class WorkTimeCalculationServiceTest extends TestCase
{
    private function service(): WorkTimeCalculationService
    {
        return new WorkTimeCalculationService();
    }

    private function makeWorkShift(int $standardWorkMinutes = 480, ?float $workCoefficient = null): WorkShift
    {
        $attributes = ['standard_work_minutes' => $standardWorkMinutes];

        if ($workCoefficient !== null) {
            $attributes['work_coefficient'] = $workCoefficient;
        }

        return new WorkShift($attributes);
    }

    private function makeAttendance(int $actualWorkMinutes, int $lateMinutes = 0, int $earlyLeaveMinutes = 0): Attendance
    {
        return new Attendance([
            'actual_work_minutes' => $actualWorkMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
        ]);
    }

    /* --------------------------- Bậc theo % giờ làm --------------------------- */

    public function test_full_shift_gives_one_full_day(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }

    public function test_working_over_shift_without_ot_is_capped_at_one(): void
    {
        // Làm 600/480 phút (125%) nhưng không đăng ký OT chính thức — vẫn
        // chỉ tối đa 1.0, phần dư phải đi qua overtime_minutes riêng.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(600),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }

    public function test_exactly_seventy_five_percent_gives_zero_point_seven_five(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(360), // 360/480 = 75%
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.75, $result);
    }

    public function test_just_below_seventy_five_percent_falls_to_fifty_percent_bracket(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(359), // 359/480 = 74.79%
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.5, $result);
    }

    public function test_exactly_fifty_percent_gives_zero_point_five(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(240),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.5, $result);
    }

    public function test_exactly_twenty_five_percent_gives_zero_point_two_five(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(120),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.25, $result);
    }

    public function test_below_twenty_five_percent_gives_zero(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(119),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.0, $result);
    }

    public function test_no_attendance_minutes_gives_zero(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(0),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.0, $result);
    }

    // Bậc tính theo % CA CỦA CHÍNH NGÀY ĐÓ, không phải phút tuyệt đối — ca 4
    // tiếng làm đủ 240/240 (100%) vẫn phải ra tròn 1.0 công như ca 8 tiếng.
    public function test_bracket_is_relative_to_the_shifts_own_length(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(240),
            $this->makeWorkShift(240),
        );

        $this->assertSame(1.0, $result);
    }

    public function test_missing_standard_work_minutes_gives_zero(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480),
            $this->makeWorkShift(0),
        );

        $this->assertSame(0.0, $result);
    }

    public function test_null_work_shift_gives_zero(): void
    {
        $result = $this->service()->dayEquivalentFor($this->makeAttendance(480), null);

        $this->assertSame(0.0, $result);
    }

    /* ------------------------- Trần theo phút trễ/về sớm ------------------------ */

    public function test_late_exactly_fifteen_minutes_is_not_penalized(): void
    {
        // Làm đủ giờ (bậc 1.0) nhưng trễ ĐÚNG 15 phút -> ranh giới KHÔNG phạt.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480, lateMinutes: 15),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }

    public function test_late_sixteen_minutes_caps_at_zero_point_seven_five(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480, lateMinutes: 16),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.75, $result);
    }

    public function test_late_thirty_minutes_caps_at_zero_point_seven_five(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480, lateMinutes: 30),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.75, $result);
    }

    public function test_late_thirty_one_minutes_caps_at_zero_point_five(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480, lateMinutes: 31),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.5, $result);
    }

    public function test_late_sixty_minutes_caps_at_zero_point_five(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480, lateMinutes: 60),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.5, $result);
    }

    public function test_late_over_sixty_minutes_caps_at_zero_point_two_five(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480, lateMinutes: 61),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.25, $result);
    }

    public function test_early_leave_uses_same_penalty_table_as_late(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480, earlyLeaveMinutes: 45),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.5, $result);
    }

    // Trễ và về sớm CÙNG xảy ra 1 ngày -> lấy số phút LỚN HƠN giữa 2 cái để
    // tra bảng phạt (không cộng dồn 2 số phút lại).
    public function test_late_and_early_leave_together_uses_the_larger_minutes(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480, lateMinutes: 10, earlyLeaveMinutes: 40),
            $this->makeWorkShift(480),
        );

        // max(10, 40) = 40 -> khung 30-60 -> trần 0.5.
        $this->assertSame(0.5, $result);
    }

    // Kết hợp: làm đủ giờ (bậc giờ làm = 1.0) NHƯNG trễ nhiều (trần phạt
    // 0.25) -> kết quả cuối lấy số THẤP HƠN (0.25), không phải trung bình.
    public function test_full_hours_but_very_late_takes_the_lower_of_the_two(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480, lateMinutes: 90),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.25, $result);
    }

    // Ngược lại: giờ làm ít (bậc 0.5) nhưng KHÔNG trễ -> trần phạt vẫn 1.0,
    // kết quả cuối lấy số THẤP HƠN là bậc giờ làm (0.5), không bị phạt thêm.
    public function test_low_hours_without_lateness_is_not_further_penalized(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(240, lateMinutes: 0, earlyLeaveMinutes: 0),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.5, $result);
    }

    /* ------------------------ work_coefficient (ca nửa ngày) ------------------------ */

    public function test_half_day_shift_worked_fully_gives_half_a_day(): void
    {
        // Ca nửa ngày (chuẩn 240 phút, work_coefficient=0.5) làm đủ 100% giờ
        // của CHÍNH ca đó -> bậc giờ làm = 1.0, nhưng ca này chỉ đáng 0.5
        // ngày -> kết quả cuối phải là 0.5, không phải 1.0.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(240),
            $this->makeWorkShift(240, workCoefficient: 0.5),
        );

        $this->assertSame(0.5, $result);
    }

    // Bug thật đã vấp (phát hiện qua câu hỏi người dùng, tái hiện bằng tinker
    // trước khi sửa): nếu bỏ work_coefficient, nhân viên chia ca sáng+chiều
    // (mỗi ca 0.5 công) làm đủ CẢ 2 ca sẽ bị cộng thành 2.0 thay vì đúng 1.0.
    // Test này khóa lại đúng phép cộng của 1 ngày chia 2 ca.
    public function test_two_half_day_shifts_worked_fully_sum_to_one_full_day(): void
    {
        $morningShift = $this->makeWorkShift(240, workCoefficient: 0.5);
        $afternoonShift = $this->makeWorkShift(240, workCoefficient: 0.5);

        $morning = $this->service()->dayEquivalentFor($this->makeAttendance(240), $morningShift);
        $afternoon = $this->service()->dayEquivalentFor($this->makeAttendance(240), $afternoonShift);

        $this->assertSame(0.5, $morning);
        $this->assertSame(0.5, $afternoon);
        $this->assertSame(1.0, $morning + $afternoon);
    }

    // Chỉ làm nửa Ca sáng (nửa ngày, work_coefficient=0.5) -> bậc giờ làm
    // 0.5 (làm 50% của ca) NHÂN work_coefficient 0.5 = 0.25, không phải 0.5.
    public function test_partial_hours_on_a_half_day_shift_multiplies_both_factors(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(120), // 120/240 = 50% cua ca
            $this->makeWorkShift(240, workCoefficient: 0.5),
        );

        $this->assertSame(0.25, $result);
    }

    public function test_missing_work_coefficient_defaults_to_one(): void
    {
        // WorkShift chưa set work_coefficient (vd object thuần chưa qua DB
        // default) -> coi như ca đầy đủ (1.0), không được coi là 0.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }
}
