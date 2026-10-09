<?php

namespace Tests\Unit\Services;

use App\Models\Attendance;
use App\Models\WorkShift;
use App\Services\WorkTimeCalculationService;
use Tests\TestCase;

// Unit test cho WorkTimeCalculationService — dùng CHUNG cho AttendanceService
// (tổng ngày công hiển thị) và PayrollService (ngày công tính lương).
//
// Viết lại toàn bộ 2026-09-29 (theo yêu cầu người dùng) sau khi phát hiện
// bug thật: đi làm ĐÚNG GIỜ (late=0, early=0) nhưng actual_work_minutes hụt
// vài phút so với chuẩn ca vẫn bị bậc thang "% giờ làm" cũ phạt xuống 0.75.
// Công thức mới: CHỈ MỘT quy tắc duy nhất — trễ/về sớm <= 30 phút (hoặc có
// late_excused) luôn đủ 1.0; vượt 30 phút mới phạt LIÊN TỤC theo đúng tỉ lệ
// actual/standard (không bậc thang).
//
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

    private function makeAttendance(int $actualWorkMinutes, int $lateMinutes = 0, int $earlyLeaveMinutes = 0, bool $lateExcused = false): Attendance
    {
        return new Attendance([
            'actual_work_minutes' => $actualWorkMinutes,
            'late_minutes' => $lateMinutes,
            'early_leave_minutes' => $earlyLeaveMinutes,
            'late_excused' => $lateExcused,
        ]);
    }

    /* --------------------------- Điều kiện tiên quyết --------------------------- */

    public function test_no_actual_minutes_gives_zero_regardless_of_lateness(): void
    {
        // Chưa checkout (actual_work_minutes = 0) -> chưa có gì để tính
        // công, bất kể trễ bao nhiêu hay có được miễn trừ hay không.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(0, lateMinutes: 5, lateExcused: true),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.0, $result);
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

    /* ------------------------ Trong ngưỡng cho phép (<= 30 phút) ------------------------ */

    public function test_on_time_full_shift_gives_one_full_day(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }

    // Bug thật đã vấp (Nguyễn Ánh, 2026-09-28): đi đúng giờ (late=0, early=0)
    // nhưng actual_work_minutes hụt vài phút so với chuẩn ca (làm tròn giờ
    // chấm công) -> vẫn phải tính đủ 1.0, không được phạt theo % giờ làm
    // nữa khi không hề trễ/sớm.
    public function test_on_time_with_slightly_fewer_actual_minutes_still_gives_full_day(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(476, lateMinutes: 0, earlyLeaveMinutes: 0),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }

    public function test_late_exactly_thirty_minutes_is_the_boundary_still_full_day(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(450, lateMinutes: 30),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }

    public function test_early_leave_within_threshold_gives_full_day(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(450, earlyLeaveMinutes: 30),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }

    /* ------------------------ Vượt ngưỡng (> 30 phút) -> phạt liên tục ------------------------ */

    public function test_late_thirty_one_minutes_penalizes_by_continuous_ratio(): void
    {
        // Trễ 31 phút (vượt ngưỡng đúng 1 phút) -> chuyển sang phạt theo tỉ
        // lệ actual/standard thực tế, không còn bậc thang.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(449, lateMinutes: 31), // 449/480 = 0.9354...
            $this->makeWorkShift(480),
        );

        $this->assertEqualsWithDelta(449 / 480, $result, 0.0001);
    }

    // Chứng minh KHÔNG còn hiện tượng "rơi cliff": 2 mức phút trễ khác nhau
    // (đều vượt ngưỡng, cùng actual_work_minutes) phải cho ra CÙNG 1 kết quả
    // — vì công thức mới chỉ quan tâm actual/standard, không quan tâm trễ
    // bao nhiêu phút MIỄN LÀ đã vượt ngưỡng 30 phút.
    public function test_penalty_depends_only_on_actual_minutes_not_on_how_late(): void
    {
        $shift = $this->makeWorkShift(480);

        $result35 = $this->service()->dayEquivalentFor($this->makeAttendance(400, lateMinutes: 35), $shift);
        $result90 = $this->service()->dayEquivalentFor($this->makeAttendance(400, lateMinutes: 90), $shift);

        $this->assertSame($result35, $result90);
        $this->assertEqualsWithDelta(400 / 480, $result35, 0.0001);
    }

    public function test_working_over_shift_with_large_lateness_is_still_capped_at_one(): void
    {
        // Làm 600/480 phút (125%) nhưng trễ 45 phút (vượt ngưỡng) -> vẫn tối
        // đa 1.0, phần dư giờ phải đi qua overtime_minutes riêng, không được
        // tự cộng thêm ngày công ở đây.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(600, lateMinutes: 45),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }

    public function test_late_and_early_leave_together_uses_the_larger_minutes(): void
    {
        // late=10 (dưới ngưỡng) nhưng early=40 (vượt ngưỡng) -> lấy số lớn
        // hơn (40) để quyết định có phạt hay không.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(400, lateMinutes: 10, earlyLeaveMinutes: 40),
            $this->makeWorkShift(480),
        );

        $this->assertEqualsWithDelta(400 / 480, $result, 0.0001);
    }

    /* ------------------------ work_coefficient (ca nửa ngày) ------------------------ */

    public function test_half_day_shift_worked_fully_gives_half_a_day(): void
    {
        // Ca nửa ngày (work_coefficient=0.5) làm đủ giờ, không trễ -> kết
        // quả cuối phải là 0.5, không phải 1.0.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(240),
            $this->makeWorkShift(240, workCoefficient: 0.5),
        );

        $this->assertSame(0.5, $result);
    }

    // Bug thật đã vấp trước đây (khóa lại, không được tái diễn khi viết lại
    // công thức): nếu bỏ work_coefficient, nhân viên chia ca sáng+chiều
    // (mỗi ca 0.5 công) làm đủ CẢ 2 ca sẽ bị cộng thành 2.0 thay vì đúng 1.0.
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

    public function test_penalized_half_day_shift_multiplies_ratio_and_coefficient(): void
    {
        // Ca nửa ngày, trễ 45 phút (vượt ngưỡng), làm 120/240 phút (50% ca)
        // -> ratio 0.5 NHÂN work_coefficient 0.5 = 0.25.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(120, lateMinutes: 45),
            $this->makeWorkShift(240, workCoefficient: 0.5),
        );

        $this->assertSame(0.25, $result);
    }

    public function test_missing_work_coefficient_defaults_to_one(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(480),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }

    /* ------------------------ Miễn trừ đi muộn (late_excused) ------------------------ */

    public function test_late_excused_restores_full_day_even_with_large_lateness(): void
    {
        // Trễ 90 phút (vượt xa ngưỡng 30) nhưng đã được duyệt miễn trừ ->
        // vẫn tính đủ 1.0, bỏ qua hoàn toàn việc tính theo phút.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(390, lateMinutes: 90, lateExcused: true),
            $this->makeWorkShift(480),
        );

        $this->assertSame(1.0, $result);
    }

    public function test_late_excused_still_requires_checkout(): void
    {
        // Được duyệt miễn trừ NHƯNG chưa checkout (actual=0) -> vẫn phải là
        // 0, không được tính đủ công cho ca chưa hoàn thành.
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(0, lateMinutes: 90, lateExcused: true),
            $this->makeWorkShift(480),
        );

        $this->assertSame(0.0, $result);
    }

    public function test_late_without_excuse_beyond_threshold_is_penalized(): void
    {
        $result = $this->service()->dayEquivalentFor(
            $this->makeAttendance(450, lateMinutes: 30 + 1, lateExcused: false),
            $this->makeWorkShift(480),
        );

        $this->assertEqualsWithDelta(450 / 480, $result, 0.0001);
    }
}
