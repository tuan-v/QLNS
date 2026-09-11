<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function loginAs(string $email, string $password): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        return $response->json('access_token');
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

    private function makeEmployeeWithLogin(): array
    {
        $user = User::create([
            'email' => 'hist-'.uniqid().'@qlns.local', 'user_name' => 'Hist User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(['user_id' => $user->id]);

        return [$employee, $user];
    }

    private function makeWorkShift(string $code, array $overrides = []): WorkShift
    {
        return WorkShift::create(array_merge([
            'code' => $code,
            'name' => 'Ca '.$code,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'standard_work_minutes' => 480,
            'work_coefficient' => 0.5,
        ], $overrides));
    }

    private function assignShift(Employee $employee, WorkShift $workShift, string $effectiveFrom = '2026-01-01'): EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'effective_from' => $effectiveFrom,
            'work_days' => [1, 2, 3, 4, 5, 6, 7],
            'status' => 'active',
        ]);
    }

    private function makeAttendance(Employee $employee, WorkShift $workShift, string $date, array $overrides = []): Attendance
    {
        return Attendance::create(array_merge([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => $date,
            'status' => 'completed',
        ], $overrides));
    }

    private function makeLeaveRequest(Employee $employee, string $fromDate, string $toDate, array $overrides = []): LeaveRequest
    {
        $leaveType = LeaveType::first() ?? LeaveType::create([
            'code' => 'lt-'.uniqid(), 'name' => 'Loai phep test',
            'annual_entitlement_days' => 12, 'is_paid' => true, 'is_active' => true,
        ]);

        return LeaveRequest::create(array_merge([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'start_session' => 'full',
            'end_session' => 'full',
            'total_days' => 1,
            'reason' => 'Test nghi phep',
            'status' => 'approved',
        ], $overrides));
    }

    // 5 ngày liên tiếp (2026-01-05 -> 2026-01-09), 1 ca duy nhất, mỗi ngày 1
    // trạng thái khác nhau — kiểm cả quy tắc suy nhãn LẪN các con số thống kê.
    public function test_history_derives_status_and_summary_correctly(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA-H1');
        $this->assignShift($employee, $workShift);

        // Thứ 2 - đủ công.
        $this->makeAttendance($employee, $workShift, '2026-01-05', [
            'first_check_in_at' => '2026-01-05 08:00:00', 'last_check_out_at' => '2026-01-05 17:00:00',
            'late_minutes' => 0, 'early_leave_minutes' => 0, 'actual_work_minutes' => 540,
        ]);
        // Thứ 3 - đi muộn.
        $this->makeAttendance($employee, $workShift, '2026-01-06', [
            'first_check_in_at' => '2026-01-06 08:15:00', 'last_check_out_at' => '2026-01-06 17:00:00',
            'late_minutes' => 15, 'early_leave_minutes' => 0, 'actual_work_minutes' => 525,
        ]);
        // Thứ 4 - thiếu công (về sớm).
        $this->makeAttendance($employee, $workShift, '2026-01-07', [
            'first_check_in_at' => '2026-01-07 08:00:00', 'last_check_out_at' => '2026-01-07 16:40:00',
            'late_minutes' => 0, 'early_leave_minutes' => 20, 'actual_work_minutes' => 520,
        ]);
        // Thứ 5 - KHÔNG tạo attendance nào -> vắng.
        // Thứ 6 - thiếu công (chưa chấm công ra).
        $this->makeAttendance($employee, $workShift, '2026-01-09', [
            'first_check_in_at' => '2026-01-09 08:00:00', 'last_check_out_at' => null,
            'late_minutes' => 0, 'early_leave_minutes' => 0, 'actual_work_minutes' => 0,
        ]);

        $token = $this->loginAs($user->email, 'Secret@123');
        $response = $this->getJson('/api/v1/attendances/history/me?date_from=2026-01-05&date_to=2026-01-09', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(5, $data['rows']);

        $byDate = collect($data['rows'])->keyBy('date');
        $this->assertSame('full', $byDate['2026-01-05']['status']);
        $this->assertSame('late', $byDate['2026-01-06']['status']);
        $this->assertSame('insufficient', $byDate['2026-01-07']['status']);
        $this->assertSame('absent', $byDate['2026-01-08']['status']);
        $this->assertNull($byDate['2026-01-08']['attendance']);
        $this->assertSame('insufficient', $byDate['2026-01-09']['status']);

        // Sắp theo ngày GIẢM DẦN.
        $this->assertSame(['2026-01-09', '2026-01-08', '2026-01-07', '2026-01-06', '2026-01-05'], array_column($data['rows'], 'date'));

        // 4 dòng KHÔNG vắng, mỗi dòng work_coefficient=0.5 -> tổng 2.0.
        $this->assertEquals(2.0, $data['summary']['total_work_days']);
        $this->assertSame(540 + 525 + 520 + 0, $data['summary']['total_work_minutes']);
        $this->assertSame(1, $data['summary']['late_count']);
        $this->assertSame(1, $data['summary']['early_leave_count']);
    }

    // Ngày 42: HR đã duyệt "Xin miễn trừ đi muộn" (late_excused=true) — ngày
    // đó phải hiện "full" (không còn "late") và KHÔNG tính vào late_count,
    // dù late_minutes trong DB vẫn giữ nguyên giá trị cũ.
    public function test_history_treats_excused_late_as_full_and_excludes_from_late_count(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA-H8');
        $this->assignShift($employee, $workShift);
        // Đi muộn nhưng đã được miễn trừ.
        $this->makeAttendance($employee, $workShift, '2026-01-05', [
            'first_check_in_at' => '2026-01-05 08:15:00', 'last_check_out_at' => '2026-01-05 17:00:00',
            'late_minutes' => 15, 'late_excused' => true, 'actual_work_minutes' => 525,
        ]);
        // Đi muộn và CHƯA được miễn trừ — vẫn phải tính là "late".
        $this->makeAttendance($employee, $workShift, '2026-01-06', [
            'first_check_in_at' => '2026-01-06 08:20:00', 'last_check_out_at' => '2026-01-06 17:00:00',
            'late_minutes' => 20, 'late_excused' => false, 'actual_work_minutes' => 520,
        ]);

        $token = $this->loginAs($user->email, 'Secret@123');
        $response = $this->getJson('/api/v1/attendances/history/me?date_from=2026-01-05&date_to=2026-01-06', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $byDate = collect($data['rows'])->keyBy('date');

        $this->assertSame('full', $byDate['2026-01-05']['status']);
        $this->assertSame('late', $byDate['2026-01-06']['status']);
        $this->assertSame(1, $data['summary']['late_count']);
    }

    // Ngày 41: đơn nghỉ phép ĐÃ DUYỆT phủ đúng ngày (không có attendance) thì
    // phải hiện "on_leave" thay vì "absent", và KHÔNG tính vào Vắng lẫn Tổng
    // ngày công/giờ làm — nhưng có đếm riêng ở on_leave_count.
    public function test_history_marks_approved_leave_day_as_on_leave(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA-H5', ['work_coefficient' => 1]);
        $this->assignShift($employee, $workShift);
        // 2026-02-02: co don nghi phep DA DUYET, khong cham cong.
        $this->makeLeaveRequest($employee, '2026-02-02', '2026-02-02');
        // 2026-02-03: di lam binh thuong, du cong.
        $this->makeAttendance($employee, $workShift, '2026-02-03', [
            'first_check_in_at' => '2026-02-03 08:00:00', 'last_check_out_at' => '2026-02-03 17:00:00',
            'actual_work_minutes' => 540,
        ]);
        // 2026-02-04: khong co gi ca -> van la "absent" binh thuong (doi chung).

        $token = $this->loginAs($user->email, 'Secret@123');
        $response = $this->getJson('/api/v1/attendances/history/me?date_from=2026-02-02&date_to=2026-02-04', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $byDate = collect($data['rows'])->keyBy('date');

        $this->assertSame('on_leave', $byDate['2026-02-02']['status']);
        $this->assertSame('full', $byDate['2026-02-03']['status']);
        $this->assertSame('absent', $byDate['2026-02-04']['status']);

        // Chi 1 ngay (2026-02-03) tinh vao tong ngay cong — ngay on_leave
        // KHONG tinh, cung KHONG tinh vao vang.
        $this->assertEquals(1.0, $data['summary']['total_work_days']);
        $this->assertSame(540, $data['summary']['total_work_minutes']);
        $this->assertSame(1, $data['summary']['on_leave_count']);
    }

    // Đơn còn pending (chưa duyệt) hoặc đã bị từ chối thì KHÔNG được che
    // ngày đó thành "on_leave" — chỉ đơn approved mới có hiệu lực.
    public function test_history_ignores_pending_or_rejected_leave(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA-H6');
        $this->assignShift($employee, $workShift);
        $this->makeLeaveRequest($employee, '2026-02-02', '2026-02-02', ['status' => 'pending']);
        $this->makeLeaveRequest($employee, '2026-02-03', '2026-02-03', ['status' => 'rejected']);

        $token = $this->loginAs($user->email, 'Secret@123');
        $response = $this->getJson('/api/v1/attendances/history/me?date_from=2026-02-02&date_to=2026-02-03', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $byDate = collect($response->json('data.rows'))->keyBy('date');
        $this->assertSame('absent', $byDate['2026-02-02']['status']);
        $this->assertSame('absent', $byDate['2026-02-03']['status']);
    }

    // Nghỉ theo giờ (hourly, mục 22) chỉ vài tiếng — không nên che mất cả
    // ngày công, dù đã được duyệt.
    public function test_history_ignores_hourly_leave_for_on_leave_status(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA-H7');
        $this->assignShift($employee, $workShift);
        $this->makeLeaveRequest($employee, '2026-02-02', '2026-02-02', [
            'start_session' => 'hourly', 'end_session' => 'hourly',
            'start_time' => '09:00', 'end_time' => '11:00', 'total_days' => 0.25,
        ]);
        $this->makeAttendance($employee, $workShift, '2026-02-02', [
            'first_check_in_at' => '2026-02-02 08:00:00', 'last_check_out_at' => '2026-02-02 17:00:00',
            'actual_work_minutes' => 540,
        ]);

        $token = $this->loginAs($user->email, 'Secret@123');
        $response = $this->getJson('/api/v1/attendances/history/me?date_from=2026-02-02&date_to=2026-02-02', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame('full', $response->json('data.rows.0.status'));
    }

    public function test_history_filters_by_work_shift_id(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $morning = $this->makeWorkShift('CA-H2-S');
        $afternoon = $this->makeWorkShift('CA-H2-C');
        $this->assignShift($employee, $morning);
        $this->assignShift($employee, $afternoon);
        $this->makeAttendance($employee, $morning, '2026-01-05', ['first_check_in_at' => '2026-01-05 08:00:00']);
        $this->makeAttendance($employee, $afternoon, '2026-01-05', ['first_check_in_at' => '2026-01-05 13:30:00']);

        $token = $this->loginAs($user->email, 'Secret@123');
        $response = $this->getJson(
            "/api/v1/attendances/history/me?date_from=2026-01-05&date_to=2026-01-05&work_shift_id={$morning->id}",
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(200);
        $rows = $response->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame($morning->id, $rows[0]['work_shift']['id']);
    }

    public function test_history_filters_by_status(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA-H3');
        $this->assignShift($employee, $workShift);
        $this->makeAttendance($employee, $workShift, '2026-01-05', [
            'first_check_in_at' => '2026-01-05 08:00:00', 'last_check_out_at' => '2026-01-05 17:00:00',
        ]);
        // 2026-01-06 không có attendance -> vắng.

        $token = $this->loginAs($user->email, 'Secret@123');
        $response = $this->getJson(
            '/api/v1/attendances/history/me?date_from=2026-01-05&date_to=2026-01-06&status=absent',
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(200);
        $rows = $response->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertSame('2026-01-06', $rows[0]['date']);
    }

    public function test_history_me_does_not_require_attendance_view_all(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/attendances/history/me', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
    }

    public function test_history_for_employee_requires_attendance_view_all(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson("/api/v1/attendances/history/{$employee->id}", [
            'Authorization' => 'Bearer '.$token,
        ]);
        $response->assertStatus(403);

        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $hrResponse = $this->getJson("/api/v1/attendances/history/{$employee->id}", [
            'Authorization' => 'Bearer '.$hrToken,
        ]);
        $hrResponse->assertStatus(200);
    }

    public function test_history_defaults_to_current_month_when_dates_omitted(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA-H4', ['work_coefficient' => 1]);
        $this->assignShift($employee, $workShift, now()->subMonth()->toDateString());
        $this->makeAttendance($employee, $workShift, now()->toDateString(), [
            'first_check_in_at' => now()->setTime(8, 0),
            'last_check_out_at' => now()->setTime(17, 0),
            'actual_work_minutes' => 540,
        ]);

        $token = $this->loginAs($user->email, 'Secret@123');
        $response = $this->getJson('/api/v1/attendances/history/me', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $dates = array_column($response->json('data.rows'), 'date');
        $this->assertContains(now()->toDateString(), $dates);
    }
}
