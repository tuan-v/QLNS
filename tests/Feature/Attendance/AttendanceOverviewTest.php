<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "Tổng hợp chấm công trong ngày" (2026-09-23, theo yêu cầu người dùng — thay
// cho màn "Duyệt chấm công" cũ chỉ liệt kê bản ghi ĐÃ CÓ): liệt kê MỌI ca được
// gán cho MỌI nhân viên vào 1 ngày, kể cả ai chưa chấm công. Test riêng khỏi
// AttendanceApprovalTest.php (đó là test cho việc DUYỆT — PUT .../approval —
// vẫn không đổi ở thay đổi này).
class AttendanceOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function loginAs(string $email, string $password): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ])->json('access_token');
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ], $overrides));
    }

    private function makeWorkShift(array $overrides = []): WorkShift
    {
        return WorkShift::create(array_merge([
            'code' => 'CA-'.uniqid(),
            'name' => 'Ca test',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'standard_work_minutes' => 480,
        ], $overrides));
    }

    private function assignShift(Employee $employee, WorkShift $workShift, string $date, array $overrides = []): EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::create(array_merge([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'effective_from' => $date,
            'work_days' => [1, 2, 3, 4, 5, 6, 7],
            'status' => 'active',
        ], $overrides));
    }

    private function makeAttendance(Employee $employee, WorkShift $workShift, string $date, array $overrides = []): Attendance
    {
        return Attendance::create(array_merge([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => $date,
            'first_check_in_at' => $date.' 08:00:00',
            'last_check_out_at' => $date.' 17:00:00',
            'actual_work_minutes' => 540,
            'status' => 'completed',
            'approval_status' => 'pending',
        ], $overrides));
    }

    private function makeLeaveRequest(Employee $employee, string $fromDate, string $toDate): LeaveRequest
    {
        $leaveType = LeaveType::first() ?? LeaveType::create([
            'code' => 'lt-'.uniqid(), 'name' => 'Loai phep test',
            'annual_entitlement_days' => 12, 'is_paid' => true, 'is_active' => true,
        ]);

        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'start_session' => 'full',
            'end_session' => 'full',
            'total_days' => 1,
            'reason' => 'Test nghi phep',
            'status' => 'approved',
        ]);
    }

    private function overviewUrl(array $query = []): string
    {
        return '/api/v1/attendances/overview'.($query ? '?'.http_build_query($query) : '');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson($this->overviewUrl())->assertStatus(401);
    }

    public function test_employee_without_view_all_is_forbidden(): void
    {
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $this->getJson($this->overviewUrl(), ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    }

    // Manager có attendance.view_all (xem được) nhưng KHÔNG có attendance.approve
    // (không duyệt được) — trang này chỉ cần quyền XEM, nút Duyệt/Từ chối do
    // Frontend tự ẩn theo quyền riêng, Backend vẫn chặn ở endpoint approval.
    public function test_manager_can_view_but_not_approve(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift();
        $this->assignShift($employee, $workShift, '2026-01-01');
        $attendance = $this->makeAttendance($employee, $workShift, '2026-01-05');
        $managerToken = $this->loginAs('manager@qlns.local', 'Manager@123');

        $this->getJson($this->overviewUrl(['date' => '2026-01-05']), ['Authorization' => 'Bearer '.$managerToken])
            ->assertStatus(200);

        $this->putJson('/api/v1/attendances/'.$attendance->id.'/approval', ['status' => 'approved'], ['Authorization' => 'Bearer '.$managerToken])
            ->assertStatus(403);
    }

    public function test_overview_combines_checked_in_absent_and_on_leave_employees(): void
    {
        $workShift = $this->makeWorkShift();

        $checkedIn = $this->makeEmployee();
        $this->assignShift($checkedIn, $workShift, '2026-01-01');
        $this->makeAttendance($checkedIn, $workShift, '2026-01-05');

        $absent = $this->makeEmployee();
        $this->assignShift($absent, $workShift, '2026-01-01');
        // Không tạo attendance nào -> vắng.

        $onLeave = $this->makeEmployee();
        $this->assignShift($onLeave, $workShift, '2026-01-01');
        $this->makeLeaveRequest($onLeave, '2026-01-05', '2026-01-05');

        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $response = $this->getJson($this->overviewUrl(['date' => '2026-01-05']), ['Authorization' => 'Bearer '.$hrToken]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(3, $data['rows']);

        $byEmployee = collect($data['rows'])->keyBy('employee.id');
        $this->assertSame('completed', $byEmployee[$checkedIn->id]['status']);
        $this->assertSame('pending', $byEmployee[$checkedIn->id]['attendance']['approval_status']);
        $this->assertSame('absent', $byEmployee[$absent->id]['status']);
        $this->assertNull($byEmployee[$absent->id]['attendance']);
        $this->assertSame('on_leave', $byEmployee[$onLeave->id]['status']);
        $this->assertNull($byEmployee[$onLeave->id]['attendance']);

        $this->assertSame(3, $data['summary']['total']);
        $this->assertSame(1, $data['summary']['completed']);
        $this->assertSame(1, $data['summary']['absent']);
        $this->assertSame(1, $data['summary']['on_leave']);
        $this->assertSame(0, $data['summary']['pending']);
        $this->assertSame(0, $data['summary']['needs_review']);
        $this->assertSame(1, $data['summary']['awaiting_approval']);
    }

    // 2026-09-24, sửa lỗi thật: 1 bản gán ca "mồ côi" (Ca làm việc đã bị xóa
    // mềm mà bản gán còn sót — trước đây xảy ra khi xóa Ca không gỡ bản gán,
    // đã chặn ở nguồn qua WorkShiftService::delete(), xem WorkShiftTest.php)
    // từng làm SẬP NGUYÊN TRANG này với lỗi "Attempt to read property id on
    // null". Test này ép tạo trạng thái mồ côi TRỰC TIẾP qua xóa mềm thẳng
    // WorkShift (bỏ qua tầng Service) để xác nhận lưới an toàn ở
    // AttendanceService::dailyOverview() (bỏ qua bản gán mồ côi thay vì
    // sập) vẫn hoạt động dù mồ côi phát sinh từ đường nào khác trong tương
    // lai — không chỉ riêng đường xóa Ca đã được vá.
    public function test_overview_ignores_orphaned_assignment_pointing_to_a_deleted_work_shift(): void
    {
        $goodShift = $this->makeWorkShift();
        $good = $this->makeEmployee();
        $this->assignShift($good, $goodShift, '2026-01-01');
        $this->makeAttendance($good, $goodShift, '2026-01-05');

        $deletedShift = $this->makeWorkShift();
        $orphan = $this->makeEmployee();
        $this->assignShift($orphan, $deletedShift, '2026-01-01');
        $deletedShift->delete(); // Xóa mềm thẳng, không qua WorkShiftService::delete().

        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $response = $this->getJson($this->overviewUrl(['date' => '2026-01-05']), ['Authorization' => 'Bearer '.$hrToken]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $byEmployee = collect($data['rows'])->keyBy('employee.id');
        $this->assertArrayHasKey($good->id, $byEmployee);
        $this->assertArrayNotHasKey($orphan->id, $byEmployee);
    }

    // Đang trong ca, đúng giờ, CHƯA chấm công ra — status phải là 'pending'
    // ("Đang trong ca"), không được suy diễn nhầm thành thiếu công như
    // deriveHistoryStatus() (mục 18) vẫn dùng cho màn Lịch sử.
    public function test_in_progress_shift_shows_pending_not_insufficient(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift();
        $this->assignShift($employee, $workShift, '2026-01-01');
        $this->makeAttendance($employee, $workShift, '2026-01-05', [
            'last_check_out_at' => null, 'actual_work_minutes' => 0, 'status' => 'pending',
        ]);
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $data = $this->getJson($this->overviewUrl(['date' => '2026-01-05']), ['Authorization' => 'Bearer '.$hrToken])
            ->assertStatus(200)->json('data');

        $this->assertSame('pending', $data['rows'][0]['status']);
        // 2026-09-23: đã chấm công VÀO là duyệt được rồi (không cần chờ chấm
        // công ra) -> có 1 người đang chờ duyệt.
        $this->assertSame(1, $data['summary']['awaiting_approval']);
    }

    // "Đang trong ca" phải thắng "Vắng" khi gộp theo nhân viên (2026-09-23,
    // sửa theo phản hồi người dùng: 1 người rõ ràng đang trong ca sáng
    // nhưng vẫn bị thẻ tổng quan tính là "Vắng" vì ca chiều của họ chưa ai
    // đụng tới — "đang trong ca" là sự thật NGAY LÚC NÀY, phải ưu tiên hơn).
    public function test_summary_prioritizes_in_progress_shift_over_absent_shift(): void
    {
        $morning = $this->makeWorkShift(['start_time' => '06:00', 'end_time' => '12:00']);
        $afternoon = $this->makeWorkShift(['start_time' => '13:00', 'end_time' => '18:00']);
        $employee = $this->makeEmployee();
        $this->assignShift($employee, $morning, '2026-01-01');
        $this->assignShift($employee, $afternoon, '2026-01-01');
        // Ca sáng đang trong ca (chưa chấm công ra), ca chiều chưa ai đụng tới -> vắng.
        $this->makeAttendance($employee, $morning, '2026-01-05', [
            'last_check_out_at' => null, 'actual_work_minutes' => 0, 'status' => 'pending',
        ]);
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $data = $this->getJson($this->overviewUrl(['date' => '2026-01-05']), ['Authorization' => 'Bearer '.$hrToken])
            ->assertStatus(200)->json('data');

        $this->assertCount(2, $data['rows']);
        $this->assertSame(1, $data['summary']['total']);
        $this->assertSame(1, $data['summary']['pending']);
        $this->assertSame(0, $data['summary']['absent']);
    }

    // Thẻ tổng quan đếm theo NHÂN VIÊN, không theo CA (2026-09-23, theo phản
    // hồi người dùng): 1 người có 2 ca cùng ngày (mục 14) chỉ tính 1 lần,
    // rơi vào nhóm ĐÁNG CHÚ Ý HƠN theo thứ tự ưu tiên needs_review > absent >
    // pending > completed > on_leave. Bảng `rows` vẫn 1 dòng/ca như cũ (test
    // employee_with_two_shifts_same_day_produces_two_rows ở trên).
    public function test_summary_counts_employees_not_shifts_when_one_employee_has_multiple_shifts(): void
    {
        $morning = $this->makeWorkShift(['start_time' => '06:00', 'end_time' => '12:00']);
        $afternoon = $this->makeWorkShift(['start_time' => '13:00', 'end_time' => '18:00']);

        // Cả 2 ca đều vắng -> 1 người vắng, không phải 2.
        $bothAbsent = $this->makeEmployee();
        $this->assignShift($bothAbsent, $morning, '2026-01-01');
        $this->assignShift($bothAbsent, $afternoon, '2026-01-01');

        // Ca sáng hoàn tất, ca chiều vắng -> "vắng" thắng vì đáng chú ý hơn.
        $mixedAbsentCompleted = $this->makeEmployee();
        $this->assignShift($mixedAbsentCompleted, $morning, '2026-01-01');
        $this->assignShift($mixedAbsentCompleted, $afternoon, '2026-01-01');
        $this->makeAttendance($mixedAbsentCompleted, $morning, '2026-01-05');

        // Ca sáng cần xem lại, ca chiều hoàn tất -> "cần xem lại" thắng (ưu tiên cao nhất).
        $mixedNeedsReviewCompleted = $this->makeEmployee();
        $this->assignShift($mixedNeedsReviewCompleted, $morning, '2026-01-01');
        $this->assignShift($mixedNeedsReviewCompleted, $afternoon, '2026-01-01');
        $this->makeAttendance($mixedNeedsReviewCompleted, $morning, '2026-01-05', ['status' => 'needs_review']);
        $this->makeAttendance($mixedNeedsReviewCompleted, $afternoon, '2026-01-05');

        // Cả 2 ca đều nghỉ phép -> "nghỉ phép" (chỉ khi TẤT CẢ ca đều nghỉ phép).
        $bothOnLeave = $this->makeEmployee();
        $this->assignShift($bothOnLeave, $morning, '2026-01-01');
        $this->assignShift($bothOnLeave, $afternoon, '2026-01-01');
        $this->makeLeaveRequest($bothOnLeave, '2026-01-05', '2026-01-05');

        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $data = $this->getJson($this->overviewUrl(['date' => '2026-01-05']), ['Authorization' => 'Bearer '.$hrToken])
            ->assertStatus(200)->json('data');

        // 4 người x 2 ca = 8 dòng ở bảng, nhưng tổng quan chỉ đếm 4 người —
        // $bothAbsent VÀ $mixedAbsentCompleted đều rơi vào "vắng" (ca còn lại
        // của $mixedAbsentCompleted đã hoàn tất, nhưng "vắng" đáng chú ý hơn
        // nên thắng) -> absent = 2, không phải 4 (nếu đếm theo ca sẽ ra 4).
        $this->assertCount(8, $data['rows']);
        $this->assertSame(4, $data['summary']['total']);
        $this->assertSame(2, $data['summary']['absent']);
        $this->assertSame(1, $data['summary']['needs_review']);
        $this->assertSame(1, $data['summary']['on_leave']);
        $this->assertSame(0, $data['summary']['completed']);
        $this->assertSame(0, $data['summary']['pending']);
    }

    public function test_filters_by_department(): void
    {
        $deptA = Department::create(['name' => 'Phong A '.uniqid(), 'code' => 'DA-'.uniqid()]);
        $deptB = Department::create(['name' => 'Phong B '.uniqid(), 'code' => 'DB-'.uniqid()]);
        $workShift = $this->makeWorkShift();
        $employeeA = $this->makeEmployee(['department_id' => $deptA->id]);
        $employeeB = $this->makeEmployee(['department_id' => $deptB->id]);
        $this->assignShift($employeeA, $workShift, '2026-01-01');
        $this->assignShift($employeeB, $workShift, '2026-01-01');
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $data = $this->getJson($this->overviewUrl(['date' => '2026-01-05', 'department_id' => $deptA->id]), [
            'Authorization' => 'Bearer '.$hrToken,
        ])->assertStatus(200)->json('data');

        $this->assertCount(1, $data['rows']);
        $this->assertSame($employeeA->id, $data['rows'][0]['employee']['id']);
    }

    public function test_filters_by_work_shift(): void
    {
        $employee = $this->makeEmployee();
        $morning = $this->makeWorkShift(['start_time' => '06:00', 'end_time' => '12:00']);
        $afternoon = $this->makeWorkShift(['start_time' => '13:00', 'end_time' => '18:00']);
        $this->assignShift($employee, $morning, '2026-01-01');
        $this->assignShift($employee, $afternoon, '2026-01-01');
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $data = $this->getJson($this->overviewUrl(['date' => '2026-01-05', 'work_shift_id' => $afternoon->id]), [
            'Authorization' => 'Bearer '.$hrToken,
        ])->assertStatus(200)->json('data');

        $this->assertCount(1, $data['rows']);
        $this->assertSame($afternoon->id, $data['rows'][0]['work_shift']['id']);
    }

    public function test_filters_by_status(): void
    {
        $workShift = $this->makeWorkShift();
        $present = $this->makeEmployee();
        $this->assignShift($present, $workShift, '2026-01-01');
        $this->makeAttendance($present, $workShift, '2026-01-05');
        $absent = $this->makeEmployee();
        $this->assignShift($absent, $workShift, '2026-01-01');
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $data = $this->getJson($this->overviewUrl(['date' => '2026-01-05', 'status' => 'absent']), [
            'Authorization' => 'Bearer '.$hrToken,
        ])->assertStatus(200)->json('data');

        $this->assertCount(1, $data['rows']);
        $this->assertSame($absent->id, $data['rows'][0]['employee']['id']);
    }

    // Lọc theo approval_status chỉ khớp bản ghi CÓ approval_status (loại bỏ
    // vắng/nghỉ phép — vốn approval_status luôn null vì chưa hề chấm công).
    public function test_filters_by_approval_status(): void
    {
        $workShift = $this->makeWorkShift();
        $approved = $this->makeEmployee();
        $this->assignShift($approved, $workShift, '2026-01-01');
        $this->makeAttendance($approved, $workShift, '2026-01-05', ['approval_status' => 'approved']);
        $pending = $this->makeEmployee();
        $this->assignShift($pending, $workShift, '2026-01-01');
        $this->makeAttendance($pending, $workShift, '2026-01-05', ['approval_status' => 'pending']);
        $absent = $this->makeEmployee();
        $this->assignShift($absent, $workShift, '2026-01-01');
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $data = $this->getJson($this->overviewUrl(['date' => '2026-01-05', 'approval_status' => 'pending']), [
            'Authorization' => 'Bearer '.$hrToken,
        ])->assertStatus(200)->json('data');

        $this->assertCount(1, $data['rows']);
        $this->assertSame($pending->id, $data['rows'][0]['employee']['id']);
    }

    // Ca chỉ gán Thứ 2-6 (work_days) — không hiện ở ngày Thứ 7/CN, khác lỗi
    // "hiện tất cả rồi đánh dấu vắng" (sẽ làm sai tổng, vd nhân viên không
    // làm việc cuối tuần bị tính vắng oan).
    public function test_excludes_employees_not_assigned_on_that_day_of_week(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift();
        $this->assignShift($employee, $workShift, '2026-01-01', ['work_days' => [1, 2, 3, 4, 5]]);
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        // 2026-01-10 là Thứ 7.
        $data = $this->getJson($this->overviewUrl(['date' => '2026-01-10']), ['Authorization' => 'Bearer '.$hrToken])
            ->assertStatus(200)->json('data');

        $this->assertCount(0, $data['rows']);
    }

    // 1 nhân viên có 2 ca cùng ngày (mục 14) -> 2 dòng riêng, không gộp lại.
    public function test_employee_with_two_shifts_same_day_produces_two_rows(): void
    {
        $employee = $this->makeEmployee();
        $morning = $this->makeWorkShift(['start_time' => '06:00', 'end_time' => '12:00']);
        $afternoon = $this->makeWorkShift(['start_time' => '13:00', 'end_time' => '18:00']);
        $this->assignShift($employee, $morning, '2026-01-01');
        $this->assignShift($employee, $afternoon, '2026-01-01');
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $data = $this->getJson($this->overviewUrl(['date' => '2026-01-05']), ['Authorization' => 'Bearer '.$hrToken])
            ->assertStatus(200)->json('data');

        $this->assertCount(2, $data['rows']);
        // Sắp theo giờ bắt đầu ca tăng dần.
        $this->assertSame($morning->id, $data['rows'][0]['work_shift']['id']);
        $this->assertSame($afternoon->id, $data['rows'][1]['work_shift']['id']);
    }

    public function test_defaults_to_today_when_date_is_omitted(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift();
        $this->assignShift($employee, $workShift, now()->subDay()->toDateString());
        $this->makeAttendance($employee, $workShift, now()->toDateString());
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $data = $this->getJson($this->overviewUrl(), ['Authorization' => 'Bearer '.$hrToken])
            ->assertStatus(200)->json('data');

        $this->assertCount(1, $data['rows']);
    }

    // Trả kèm dữ liệu thiết bị của từng log để mở "Chi tiết" không cần gọi
    // thêm request (xem AttendanceLogList.vue dùng chung với màn Duyệt chấm công cũ).
    public function test_includes_device_log_data(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift();
        $this->assignShift($employee, $workShift, '2026-01-01');
        $attendance = $this->makeAttendance($employee, $workShift, '2026-01-05');
        $attendance->logs()->create([
            'employee_id' => $employee->id,
            'event_type' => 'check_in',
            'occurred_at' => '2026-01-05 08:00:00',
            'method' => 'device',
            'device_name' => 'iPhone (iOS 17.2) · Safari',
            'ip_address' => '10.0.0.9',
        ]);
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $data = $this->getJson($this->overviewUrl(['date' => '2026-01-05']), ['Authorization' => 'Bearer '.$hrToken])
            ->assertStatus(200)->json('data');

        $this->assertSame('iPhone (iOS 17.2) · Safari', $data['rows'][0]['attendance']['logs'][0]['device_name']);
    }
}
