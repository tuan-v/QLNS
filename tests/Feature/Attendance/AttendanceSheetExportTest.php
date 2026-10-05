<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\WorkShift;
use App\Services\AttendanceSheetService;
use App\Services\WorkShiftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

// Bảng chấm công tháng (xuất Excel): công từng ngày + tổng theo đúng luật tính lương.
class AttendanceSheetExportTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(Carbon::parse('2026-11-30 18:00'));

        $this->department = Department::create(['name' => 'Phong bang cong', 'code' => 'PB-BC']);
        $this->employee = Employee::create([
            'full_name' => 'Nguyen Van Cong', 'company_email' => 'bangcong@qlns.local',
            'hire_date' => '2026-01-01', 'code' => 'NV-BC01', 'department_id' => $this->department->id,
            'employment_status' => 'active',
        ]);
    }

    private function hr(): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'hr@qlns.local', 'password' => 'Hr@123456'])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function shift(float $coefficient): WorkShift
    {
        return WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca '.$coefficient, 'start_time' => '08:00', 'end_time' => '17:00',
            'standard_work_minutes' => 480, 'work_coefficient' => $coefficient,
        ]);
    }

    private function attendance(WorkShift $shift, string $date, string $approval): void
    {
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_shift_id' => $shift->id, 'attendance_date' => $date,
            'first_check_in_at' => $date.' 08:00:00', 'last_check_out_at' => $date.' 17:00:00',
            'actual_work_minutes' => 480, 'status' => 'completed', 'approval_status' => $approval,
        ]);
    }

    private function seedMonth(): void
    {
        // Tháng 11/2026: 1/11 là Chủ nhật; 24/11 (Thứ 3) nghỉ lễ.
        $this->attendance($this->shift(1), '2026-11-02', 'approved');   // 1 công
        $this->attendance($this->shift(0.5), '2026-11-03', 'approved'); // 0.5 công
        $this->attendance($this->shift(1), '2026-11-05', 'pending');    // chờ duyệt
        Holiday::create(['holiday_date' => '2026-11-24', 'name' => 'Ngày Văn hóa Việt Nam', 'group_code' => 'manual-bc', 'source' => 'manual']);
        LeaveRequest::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => LeaveType::where('code', 'annual')->value('id'),
            'from_date' => '2026-11-04', 'to_date' => '2026-11-04', 'start_session' => 'full', 'end_session' => 'full',
            'total_days' => 1, 'reason' => 'Viec gia dinh', 'status' => 'approved',
        ]);
    }

    public function test_sheet_marks_each_day_and_totals_match_payroll_rules(): void
    {
        $this->seedMonth();

        $sheet = app(AttendanceSheetService::class)->build(2026, 11, $this->department->id);
        $row = $sheet['rows']->sole();
        $cells = $row['cells'];

        // 21 ngày T2–T6 trừ 1 ngày lễ.
        $this->assertSame(20, $sheet['standard_work_days']);
        $this->assertEquals(1, $cells['2026-11-02']);
        $this->assertEquals(0.5, $cells['2026-11-03']);
        $this->assertSame('P', $cells['2026-11-04']);
        $this->assertSame('CD', $cells['2026-11-05']);
        $this->assertSame('V', $cells['2026-11-06']);
        $this->assertSame('', $cells['2026-11-07']); // Thứ 7
        $this->assertSame('L', $cells['2026-11-24']);

        $this->assertEquals(1.5, $row['actual_work_days']);
        $this->assertEquals(1.0, $row['paid_leave_days']);
        $this->assertSame(1, $row['holiday_days']);
        $this->assertSame(1, $row['pending_days']);
        // 20 ngày làm việc - 2 có công - 1 phép - 1 chờ duyệt = 16 ngày vắng.
        $this->assertSame(16, $row['absent_days']);
        $this->assertEquals(2.5, $row['paid_days']);
    }

    public function test_future_days_and_days_before_hire_are_left_blank(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 09:00'));
        $this->employee->update(['hire_date' => '2026-11-04']);

        $cells = app(AttendanceSheetService::class)->build(2026, 11, $this->department->id)['rows']->sole()['cells'];

        $this->assertSame('', $cells['2026-11-03']); // trước ngày vào làm
        $this->assertSame('V', $cells['2026-11-04']);
        $this->assertSame('', $cells['2026-11-11']); // chưa tới
    }

    // Đã duyệt giờ vào nhưng chưa chấm ra (đang trong ca) -> chưa có công, KHÔNG phải vắng.
    public function test_checked_in_without_check_out_is_not_absent(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_shift_id' => $this->shift(1)->id, 'attendance_date' => '2026-11-10',
            'first_check_in_at' => '2026-11-10 08:00:00', 'status' => 'checked_in', 'approval_status' => 'approved',
        ]);

        $row = app(AttendanceSheetService::class)->build(2026, 11, $this->department->id)['rows']->sole();

        $this->assertSame('CD', $row['cells']['2026-11-10']);
        $this->assertSame(1, $row['pending_days']);
    }

    // Ca OT ngày khác (Thứ 7): ô ghi "OT", không cộng công ngày, giờ OT vào cột riêng.
    public function test_overtime_shift_day_is_marked_ot_with_overtime_hours(): void
    {
        $otShift = app(WorkShiftService::class)->createOvertimeOneOff('08:00', '12:00');
        Attendance::create([
            'employee_id' => $this->employee->id, 'work_shift_id' => $otShift->id, 'attendance_date' => '2026-11-07',
            'first_check_in_at' => '2026-11-07 08:00:00', 'last_check_out_at' => '2026-11-07 12:00:00',
            'actual_work_minutes' => 240, 'overtime_minutes' => 240, 'overtime_approved' => true,
            'status' => 'completed', 'approval_status' => 'approved',
        ]);

        $row = app(AttendanceSheetService::class)->build(2026, 11, $this->department->id)['rows']->sole();

        $this->assertSame('OT', $row['cells']['2026-11-07']);
        $this->assertEquals(0, $row['actual_work_days']);
        $this->assertEquals(4.0, $row['overtime_hours']);
    }

    public function test_hr_downloads_the_excel_file(): void
    {
        $this->seedMonth();

        $response = $this->get('/api/v1/attendances/sheet/export?month=11&year=2026&department_id='.$this->department->id, $this->hr());

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('bang-cham-cong-11-2026.xlsx', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $ws = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $this->assertSame('BẢNG CHẤM CÔNG THÁNG 11/2026', $ws->getCell('A1')->getValue());
        $this->assertSame('NV-BC01', $ws->getCell('B6')->getValue());
        // Cột ngày bắt đầu từ E (= ngày 1): ngày 2 ở F, ngày 24 ở AB.
        $this->assertEquals(1, $ws->getCell('F6')->getValue());
        $this->assertSame('L', $ws->getCell('AB6')->getValue());
    }

    public function test_employee_cannot_export_and_month_is_validated(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'employee@qlns.local', 'password' => 'Employee@123'])->json('access_token');

        $this->getJson('/api/v1/attendances/sheet/export?month=11&year=2026', ['Authorization' => 'Bearer '.$token])->assertForbidden();
        $this->getJson('/api/v1/attendances/sheet/export?month=13&year=2026', $this->hr())
            ->assertStatus(422)->assertJsonValidationErrors('month');
    }
}
