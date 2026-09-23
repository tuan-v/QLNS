<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "Xin làm ngoài lịch" (2026-09-23, theo yêu cầu người dùng — gộp chung "xin
// làm OT"/"xin làm thêm ngày"/"làm bù T7-CN"): loại 'extra_shift' của
// attendance_adjustments — ĐĂNG KÝ TRƯỚC cho 1 ngày/ca KHÔNG có trong lịch
// gán, HR duyệt xong thì nhân viên tự chấm công vào/ra bình thường vào đúng
// ngày đó (không tạo Attendance trực tiếp như 'supplement'). Test riêng khỏi
// AttendanceAdjustmentTest.php (đã khá dài) — cùng quy ước đặt tên/loginAs/
// makeEmployee như các file test Attendance khác trong session.
class AttendanceExtraShiftTest extends TestCase
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

    private function makeEmployeeWithLogin(): array
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $user = User::create([
            'email' => 'extra-'.uniqid().'@qlns.local', 'user_name' => 'Extra User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee = Employee::create([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
            'user_id' => $user->id,
        ]);

        return [$employee, $user];
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

    // Luôn về đúng 1 ngày Thứ 7 trong tương lai — mọi test đều test trong 1
    // mốc xác định thay vì phụ thuộc ngày chạy test thật (cùng lý do
    // travelToMonday() ở AttendanceTest.php).
    private function nextSaturday(): Carbon
    {
        return Carbon::parse('next saturday');
    }

    private function requestExtraShift(string $token, WorkShift $workShift, string $date, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/attendances/adjustments', array_merge([
            'type' => 'extra_shift',
            'work_shift_id' => $workShift->id,
            'attendance_date' => $date,
            'reason' => 'Lam bu cuoi tuan',
        ], $overrides), ['Authorization' => 'Bearer '.$token]);
    }

    public function test_employee_can_request_extra_shift_for_a_future_date_not_in_schedule(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->requestExtraShift($token, $workShift, $this->nextSaturday()->toDateString());

        $response->assertStatus(201);
        $this->assertDatabaseHas('attendance_adjustments', [
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'type' => 'extra_shift',
            'status' => 'pending',
        ]);
        // Chưa duyệt -> chưa mở khóa gì cả, không có bản gán ca nào được tạo.
        $this->assertDatabaseCount('employee_shift_assignments', 0);
    }

    public function test_cannot_request_extra_shift_for_a_past_date(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->requestExtraShift($token, $workShift, now()->subDay()->toDateString());

        $response->assertStatus(422)->assertJsonValidationErrors('attendance_date');
    }

    public function test_cannot_request_extra_shift_for_inactive_work_shift(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift(['is_active' => false]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->requestExtraShift($token, $workShift, $this->nextSaturday()->toDateString());

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    public function test_cannot_request_extra_shift_when_already_assigned_that_day(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift();
        $saturday = $this->nextSaturday();
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'effective_from' => now()->toDateString(), 'work_days' => [6], 'status' => 'active',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->requestExtraShift($token, $workShift, $saturday->toDateString());

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    public function test_cannot_submit_a_second_pending_request_for_the_same_shift_and_date(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift();
        $token = $this->loginAs($user->email, 'Secret@123');
        $date = $this->nextSaturday()->toDateString();
        $this->requestExtraShift($token, $workShift, $date)->assertStatus(201);

        $response = $this->requestExtraShift($token, $workShift, $date);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    public function test_hr_can_approve_extra_shift_request_and_employee_can_then_check_in(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift(['start_time' => '08:00', 'end_time' => '17:00']);
        $token = $this->loginAs($user->email, 'Secret@123');
        $saturday = $this->nextSaturday();

        $adjustmentId = $this->requestExtraShift($token, $workShift, $saturday->toDateString())
            ->assertStatus(201)->json('id');

        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $this->putJson('/api/v1/attendances/adjustments/'.$adjustmentId, [
            'status' => 'approved',
            'decision_note' => 'Dong y lam bu',
        ], ['Authorization' => 'Bearer '.$hrToken])->assertStatus(200);

        // Duyệt xong CHƯA tạo Attendance nào — chỉ mở khóa lịch.
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'effective_from' => $saturday->toDateString(),
            'effective_to' => $saturday->toDateString(),
        ]);

        // Tới đúng ngày Thứ 7 đó, nhân viên tự chấm công vào bình thường.
        $this->travelTo($saturday->copy()->setTime(8, 0));
        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => $saturday->toDateString(),
        ]);
    }

    public function test_one_off_assignment_does_not_apply_to_the_same_weekday_on_other_dates(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift(['start_time' => '08:00', 'end_time' => '17:00']);
        $token = $this->loginAs($user->email, 'Secret@123');
        $saturday = $this->nextSaturday();

        $adjustmentId = $this->requestExtraShift($token, $workShift, $saturday->toDateString())
            ->assertStatus(201)->json('id');
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $this->putJson('/api/v1/attendances/adjustments/'.$adjustmentId, [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$hrToken])->assertStatus(200);

        // Thứ 7 TUẦN SAU (cùng thứ trong tuần, khác ngày) -> vẫn bị chặn,
        // bản gán ca chỉ áp dụng ĐÚNG 1 ngày đã đăng ký.
        $this->travelTo($saturday->copy()->addWeek()->setTime(8, 0));
        $response = $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_hr_can_reject_extra_shift_request_and_check_in_stays_blocked(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift(['start_time' => '08:00', 'end_time' => '17:00']);
        $token = $this->loginAs($user->email, 'Secret@123');
        $saturday = $this->nextSaturday();

        $adjustmentId = $this->requestExtraShift($token, $workShift, $saturday->toDateString())
            ->assertStatus(201)->json('id');
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $this->putJson('/api/v1/attendances/adjustments/'.$adjustmentId, [
            'status' => 'rejected',
            'decision_note' => 'Khong can thiet',
        ], ['Authorization' => 'Bearer '.$hrToken])->assertStatus(200);

        $this->assertDatabaseCount('employee_shift_assignments', 0);

        $this->travelTo($saturday->copy()->setTime(8, 0));
        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('work_shift_id');
    }

    public function test_reason_is_required_for_extra_shift(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->requestExtraShift($token, $workShift, $this->nextSaturday()->toDateString(), ['reason' => '']);

        $response->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_employee_sees_extra_shift_request_in_own_adjustment_list(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift();
        $token = $this->loginAs($user->email, 'Secret@123');
        $this->requestExtraShift($token, $workShift, $this->nextSaturday()->toDateString())->assertStatus(201);

        $response = $this->getJson('/api/v1/attendances/adjustments/me', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $this->assertSame('extra_shift', $response->json('0.type'));
        $this->assertSame('pending', $response->json('0.status'));
    }

    /* ------------------------- "Tự chọn giờ" (2026-09-23) ------------------------ */

    public function test_employee_can_request_extra_shift_with_a_custom_time_range(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');
        $date = $this->nextSaturday()->toDateString();

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'extra_shift',
            'attendance_date' => $date,
            'custom_start_time' => '09:00',
            'custom_end_time' => '15:00',
            'reason' => 'Lam bu tu chon gio',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        // Tự tạo 1 Ca mới đúng khung giờ, KHÔNG đánh dấu mặc định.
        $this->assertDatabaseHas('work_shifts', [
            'start_time' => '09:00', 'end_time' => '15:00',
            'standard_work_minutes' => 360, 'is_default' => false,
        ]);
        $this->assertDatabaseHas('attendance_adjustments', [
            'employee_id' => Employee::where('user_id', $user->id)->value('id'),
            'type' => 'extra_shift', 'attendance_date' => $date, 'status' => 'pending',
        ]);
    }

    public function test_custom_time_range_requires_both_start_and_end(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'extra_shift',
            'attendance_date' => $this->nextSaturday()->toDateString(),
            'custom_start_time' => '09:00',
            'reason' => 'Thieu gio ket thuc',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('custom_end_time');
    }

    public function test_custom_end_time_must_be_after_start_time(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'extra_shift',
            'attendance_date' => $this->nextSaturday()->toDateString(),
            'custom_start_time' => '15:00',
            'custom_end_time' => '09:00',
            'reason' => 'Gio sai thu tu',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('custom_end_time');
    }

    public function test_must_provide_either_work_shift_or_custom_time(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'extra_shift',
            'attendance_date' => $this->nextSaturday()->toDateString(),
            'reason' => 'Khong chon gi ca',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    public function test_hr_can_approve_custom_time_extra_shift_and_employee_can_then_check_in(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');
        $saturday = $this->nextSaturday();

        $adjustmentId = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'extra_shift',
            'attendance_date' => $saturday->toDateString(),
            'custom_start_time' => '09:00',
            'custom_end_time' => '15:00',
            'reason' => 'Lam bu tu chon gio',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201)->json('id');

        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $this->putJson('/api/v1/attendances/adjustments/'.$adjustmentId, [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$hrToken])->assertStatus(200);

        $customShift = WorkShift::where('start_time', '09:00')->where('end_time', '15:00')->firstOrFail();
        $this->travelTo($saturday->copy()->setTime(9, 0));
        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $customShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id, 'work_shift_id' => $customShift->id,
            'attendance_date' => $saturday->toDateString(),
        ]);
    }
}
