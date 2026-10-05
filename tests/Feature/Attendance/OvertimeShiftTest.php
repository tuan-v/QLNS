<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeShiftAssignment;
use App\Models\Holiday;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\PayrollService;
use App\Services\WorkShiftService;
use App\Services\WorkTimeCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "OT ngày khác" (type overtime_shift): đăng ký trước ngày + khung giờ; HR duyệt
// tạo ca OT 1 ngày; mọi phút làm trong khung là OT đã duyệt, không có công ngày;
// lương OT theo hệ số ngày (thường 150%, T7/CN 200%, lễ 300%).
class OvertimeShiftTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // Thứ 2, 02/11/2026. Thứ 7 gần nhất: 07/11.
        $this->travelTo(Carbon::parse('2026-11-02 09:00'));

        $department = Department::create(['name' => 'Phong OT', 'code' => 'PB-OT']);
        $this->user = User::create([
            'email' => 'ot-'.uniqid().'@qlns.local', 'user_name' => 'OT User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($this->user->id);
        $this->employee = Employee::create([
            'full_name' => 'Nhan vien OT', 'company_email' => 'ot@qlns.local', 'hire_date' => '2025-01-01',
            'code' => 'NV-OT', 'department_id' => $department->id, 'user_id' => $this->user->id,
            'employment_status' => 'active',
        ]);
        $regular = WorkShift::create([
            'code' => 'CA-HC', 'name' => 'Ca hanh chinh', 'start_time' => '08:00', 'end_time' => '17:00',
            'standard_work_minutes' => 480,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $this->employee->id, 'work_shift_id' => $regular->id,
            'effective_from' => '2025-01-01', 'work_days' => [1, 2, 3, 4, 5], 'status' => 'active',
        ]);
    }

    private function headers(string $email, string $password): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function employeeHeaders(): array
    {
        return $this->headers($this->user->email, 'Secret@123');
    }

    private function hrHeaders(): array
    {
        return $this->headers('hr@qlns.local', 'Hr@123456');
    }

    private function requestOt(string $date, string $start = '08:00', string $end = '12:00')
    {
        return $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'overtime_shift', 'attendance_date' => $date,
            'custom_start_time' => $start, 'custom_end_time' => $end, 'reason' => 'Chay du an gap',
        ], $this->employeeHeaders());
    }

    public function test_approved_overtime_shift_counts_every_minute_in_the_window_as_approved_overtime(): void
    {
        $id = $this->requestOt('2026-11-07')->assertCreated()->json('data.id')
            ?? AttendanceAdjustment::where('type', 'overtime_shift')->value('id');
        // Chưa duyệt -> chưa có ca OT nào.
        $this->assertSame(0, WorkShift::where('is_overtime', true)->count());

        $this->putJson("/api/v1/attendances/adjustments/{$id}", ['status' => 'approved'], $this->hrHeaders())->assertOk();

        $shift = WorkShift::where('is_overtime', true)->sole();
        $this->assertSame('Ca OT 08:00-12:00', $shift->name);
        $this->assertSame($shift->id, AttendanceAdjustment::find($id)->work_shift_id);
        // Ca OT không hiện trong danh sách ca.
        $listed = collect($this->getJson('/api/v1/work-shifts?per_page=100', $this->hrHeaders())->json('data'))->pluck('id');
        $this->assertNotContains($shift->id, $listed);

        // Thứ 7: vào sớm 10 phút, ra trễ 30 phút -> chỉ tính 4 giờ trong khung.
        $this->travelTo(Carbon::parse('2026-11-07 07:50'));
        $this->postJson('/api/v1/attendances/check-in', ['work_shift_id' => $shift->id], $this->employeeHeaders())->assertCreated();
        $this->travelTo(Carbon::parse('2026-11-07 12:30'));
        $this->postJson('/api/v1/attendances/check-out', ['work_shift_id' => $shift->id], $this->employeeHeaders())->assertCreated();

        $attendance = Attendance::where('work_shift_id', $shift->id)->sole();
        $this->assertSame(240, $attendance->overtime_minutes);
        $this->assertTrue((bool) $attendance->overtime_approved);
        $this->assertSame(0, $attendance->late_minutes);
        $this->assertSame(0, $attendance->early_leave_minutes);
        $this->assertSame(0.0, app(WorkTimeCalculationService::class)->dayEquivalentFor($attendance, $shift));
    }

    public function test_request_is_rejected_on_a_regular_working_day_but_allowed_on_a_holiday(): void
    {
        $this->requestOt('2026-11-03')->assertStatus(422)->assertJsonValidationErrors('attendance_date');

        Holiday::create(['holiday_date' => '2026-11-24', 'name' => 'Ngày Văn hóa Việt Nam', 'group_code' => 'manual-ot', 'source' => 'manual']);
        $this->requestOt('2026-11-24')->assertCreated();
        // Trùng ngày với đơn đang chờ.
        $this->requestOt('2026-11-24', '13:00', '17:00')->assertStatus(422)->assertJsonValidationErrors('attendance_date');
        // Giờ kết thúc phải sau giờ bắt đầu.
        $this->requestOt('2026-11-08', '12:00', '08:00')->assertStatus(422)->assertJsonValidationErrors('custom_end_time');
    }

    public function test_overtime_multiplier_follows_the_type_of_day(): void
    {
        Holiday::create(['holiday_date' => '2026-11-24', 'name' => 'Lễ', 'group_code' => 'manual-a', 'source' => 'manual']);
        Holiday::create(['holiday_date' => '2026-11-20', 'makeup_date' => '2026-11-14', 'name' => 'Hoán đổi', 'group_code' => 'manual-b', 'source' => 'swap']);
        $payroll = app(PayrollService::class);

        $this->assertSame(1.5, $payroll->overtimeMultiplierFor(Carbon::parse('2026-11-02')));
        $this->assertSame(2.0, $payroll->overtimeMultiplierFor(Carbon::parse('2026-11-07')));
        $this->assertSame(3.0, $payroll->overtimeMultiplierFor(Carbon::parse('2026-11-24')));
        $this->assertSame(1.5, $payroll->overtimeMultiplierFor(Carbon::parse('2026-11-14'))); // Thứ 7 đi làm bù
    }

    public function test_payroll_pays_weekend_overtime_shift_at_200_percent_of_the_regular_hourly_rate(): void
    {
        EmployeeContract::create([
            'employee_id' => $this->employee->id, 'contract_number' => 'HD-OT', 'contract_type' => 'chinh_thuc',
            'start_date' => '2025-01-01', 'agreed_salary' => 15_000_000, 'insurance_salary' => 15_000_000, 'status' => 'active',
        ]);
        $otShift = app(WorkShiftService::class)->createOvertimeOneOff('08:00', '12:00');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_shift_id' => $otShift->id, 'attendance_date' => '2026-11-07',
            'first_check_in_at' => '2026-11-07 08:00:00', 'last_check_out_at' => '2026-11-07 12:00:00',
            'actual_work_minutes' => 240, 'overtime_minutes' => 240, 'overtime_approved' => true,
            'status' => 'completed', 'approval_status' => 'approved',
        ]);
        $this->travelTo(Carbon::parse('2026-12-01 09:00'));

        $detail = app(PayrollService::class)->generateForPeriod(11, 2026, User::where('email', 'hr@qlns.local')->first())
            ->details()->where('employee_id', $this->employee->id)->sole();

        // Tháng 11/2026: 21 ngày công chuẩn. Đơn giá giờ theo ca mặc định (không theo ca OT 4 giờ).
        $defaultMinutes = (int) (WorkShift::where('is_default', true)->value('standard_work_minutes') ?: 480);
        $expected = 15_000_000 / 21 / ($defaultMinutes / 60) * 4 * 2.0;

        $this->assertEqualsWithDelta(0.0, (float) $detail->actual_work_days, 0.001);
        $this->assertSame(240, $detail->overtime_minutes);
        $this->assertEqualsWithDelta($expected, (float) $detail->overtime_amount, 0.01);
    }
}
