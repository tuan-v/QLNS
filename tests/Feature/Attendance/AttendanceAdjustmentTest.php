<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAdjustmentTest extends TestCase
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
            'email' => 'adj-'.uniqid().'@qlns.local', 'user_name' => 'Adj User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(['user_id' => $user->id]);

        return [$employee, $user];
    }

    private function makeAttendance(Employee $employee, array $overrides = []): Attendance
    {
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00',
            'standard_work_minutes' => 480,
        ]);

        return Attendance::create(array_merge([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(),
            'first_check_in_at' => now()->setTime(8, 30),
            'status' => 'needs_review',
        ], $overrides));
    }

    private function assignShift(Employee $employee, WorkShift $workShift, array $workDays, ?string $effectiveFrom = null): EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'effective_from' => $effectiveFrom ?? now()->subMonth()->toDateString(),
            'work_days' => $workDays,
            'status' => 'active',
        ]);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/attendances/adjustments', []);

        $response->assertStatus(401);
    }

    public function test_employee_can_request_adjustment_for_own_attendance(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $attendance = $this->makeAttendance($employee);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'attendance_id' => $attendance->id,
            'proposed_check_in_at' => now()->setTime(8, 0)->toDateTimeString(),
            'reason' => 'Quen bam chinh xac gio vao, thuc te vao dung 8h',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('type', 'correction');
        $this->assertDatabaseHas('attendance_adjustments', [
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'work_shift_id' => $attendance->work_shift_id,
            'requested_by' => $user->id,
            'status' => 'pending',
        ]);
    }

    public function test_cannot_request_adjustment_for_another_employee_attendance(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $otherEmployee = $this->makeEmployee();
        $otherAttendance = $this->makeAttendance($otherEmployee);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'attendance_id' => $otherAttendance->id,
            'proposed_check_in_at' => now()->toDateTimeString(),
            'reason' => 'Thu sua gio cua nguoi khac',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('attendance_id');
    }

    public function test_must_propose_at_least_one_time(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $attendance = $this->makeAttendance($employee);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'attendance_id' => $attendance->id,
            'reason' => 'Khong de xuat gio nao ca',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'proposed_check_in_at', 'proposed_check_out_at',
        ]);
    }

    public function test_proposed_check_out_must_be_after_proposed_check_in(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $attendance = $this->makeAttendance($employee);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'attendance_id' => $attendance->id,
            'proposed_check_in_at' => now()->setTime(17, 0)->toDateTimeString(),
            'proposed_check_out_at' => now()->setTime(8, 0)->toDateTimeString(),
            'reason' => 'Gio ra de xuat truoc gio vao, khong hop le',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('proposed_check_out_at');
    }

    /* --------------------- type=supplement (bổ sung chấm công) --------------------- */

    public function test_employee_can_request_supplement_for_assigned_shift(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $today = now();
        $this->assignShift($employee, $workShift, [$today->dayOfWeekIso]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'supplement',
            'work_shift_id' => $workShift->id,
            'attendance_date' => $today->toDateString(),
            'proposed_check_in_at' => $today->copy()->setTime(8, 0)->toDateTimeString(),
            'proposed_check_out_at' => $today->copy()->setTime(17, 0)->toDateTimeString(),
            'reason' => 'Quen cham cong ca hom nay, khong con may cham cong',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('type', 'supplement');
        $this->assertDatabaseHas('attendance_adjustments', [
            'type' => 'supplement',
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => $today->toDateString(),
            'attendance_id' => null,
            'status' => 'pending',
        ]);
    }

    public function test_supplement_request_requires_shift_assigned_on_that_date(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        // Ca có thật nhưng KHÔNG được gán cho nhân viên này.
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $today = now();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'supplement',
            'work_shift_id' => $workShift->id,
            'attendance_date' => $today->toDateString(),
            'proposed_check_in_at' => $today->copy()->setTime(8, 0)->toDateTimeString(),
            'proposed_check_out_at' => $today->copy()->setTime(17, 0)->toDateTimeString(),
            'reason' => 'Khong duoc gan ca nay',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    public function test_supplement_request_rejected_when_attendance_already_exists(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $today = now();
        $this->assignShift($employee, $workShift, [$today->dayOfWeekIso]);
        // Đã có bản ghi chấm công thật cho đúng ca+ngày này rồi.
        Attendance::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => $today->toDateString(),
            'first_check_in_at' => $today->copy()->setTime(8, 0),
            'status' => 'pending',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'supplement',
            'work_shift_id' => $workShift->id,
            'attendance_date' => $today->toDateString(),
            'proposed_check_in_at' => $today->copy()->setTime(8, 0)->toDateTimeString(),
            'proposed_check_out_at' => $today->copy()->setTime(17, 0)->toDateTimeString(),
            'reason' => 'Da co ban ghi roi, phai dung Xin dieu chinh',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('attendance_date');
    }

    public function test_supplement_request_requires_both_check_in_and_check_out(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $today = now();
        $this->assignShift($employee, $workShift, [$today->dayOfWeekIso]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'supplement',
            'work_shift_id' => $workShift->id,
            'attendance_date' => $today->toDateString(),
            'proposed_check_in_at' => $today->copy()->setTime(8, 0)->toDateTimeString(),
            'reason' => 'Chi nho gio vao, quen gio ra',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('proposed_check_out_at');
    }

    public function test_hr_can_approve_supplement_request_and_it_creates_attendance(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $today = now();
        $this->assignShift($employee, $workShift, [$today->dayOfWeekIso]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $store = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'supplement',
            'work_shift_id' => $workShift->id,
            'attendance_date' => $today->toDateString(),
            'proposed_check_in_at' => $today->copy()->setTime(8, 0)->toDateTimeString(),
            'proposed_check_out_at' => $today->copy()->setTime(17, 0)->toDateTimeString(),
            'reason' => 'Quen cham cong ca hom nay',
        ], ['Authorization' => 'Bearer '.$token]);
        $adjustmentId = $store->json('id');

        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $response = $this->putJson('/api/v1/attendances/adjustments/'.$adjustmentId, [
            'status' => 'approved',
            'decision_note' => 'Da xac nhan qua camera',
        ], ['Authorization' => 'Bearer '.$hrToken]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => $today->toDateString(),
            'status' => 'completed',
            'actual_work_minutes' => 540,
            'late_minutes' => 0,
        ]);
        $newAttendance = Attendance::where('employee_id', $employee->id)
            ->where('work_shift_id', $workShift->id)
            ->where('attendance_date', $today->toDateString())
            ->firstOrFail();
        $this->assertDatabaseHas('attendance_adjustments', [
            'id' => $adjustmentId,
            'attendance_id' => $newAttendance->id,
            'status' => 'approved',
        ]);
    }

    public function test_employee_without_attendance_adjust_cannot_decide(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $attendance = $this->makeAttendance($employee);
        $adjustment = \App\Models\AttendanceAdjustment::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'work_shift_id' => $attendance->work_shift_id,
            'attendance_date' => $attendance->attendance_date,
            'requested_by' => $user->id,
            'proposed_check_in_at' => now()->setTime(8, 0),
            'reason' => 'test',
            'status' => 'pending',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->putJson('/api/v1/attendances/adjustments/'.$adjustment->id, [
            'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(403);
    }

    public function test_hr_can_approve_adjustment_and_it_updates_attendance(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        // Chưa có last_check_out_at — nhân viên coi như còn đang làm việc,
        // duyệt điều chỉnh chỉ sửa giờ vào thì KHÔNG được đánh dấu 'completed'.
        $attendance = $this->makeAttendance($employee, [
            'first_check_in_at' => now()->setTime(8, 30),
            'late_minutes' => 30,
            'status' => 'needs_review',
        ]);
        $correctedTime = now()->setTime(8, 0);
        $adjustment = \App\Models\AttendanceAdjustment::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'work_shift_id' => $attendance->work_shift_id,
            'attendance_date' => $attendance->attendance_date,
            'requested_by' => $user->id,
            'proposed_check_in_at' => $correctedTime,
            'reason' => 'May cham cong bi loi, thuc te vao dung gio',
            'status' => 'pending',
        ]);
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->putJson('/api/v1/attendances/adjustments/'.$adjustment->id, [
            'status' => 'approved',
            'decision_note' => 'Da kiem tra camera, dong y',
        ], ['Authorization' => 'Bearer '.$hrToken]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('attendance_adjustments', [
            'id' => $adjustment->id,
            'status' => 'approved',
        ]);
        $attendance->refresh();
        $this->assertSame(0, $attendance->late_minutes);
        $this->assertSame('pending', $attendance->status);
    }

    public function test_approving_adjustment_marks_completed_only_when_check_out_also_present(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        // Đã có sẵn last_check_out_at trước khi xin điều chỉnh — duyệt xong
        // đủ cả 2 mốc (1 mốc cũ + 1 mốc vừa sửa) thì mới đúng là 'completed'.
        $attendance = $this->makeAttendance($employee, [
            'first_check_in_at' => now()->setTime(8, 30),
            'last_check_out_at' => now()->setTime(17, 30),
            'late_minutes' => 30,
            'status' => 'needs_review',
        ]);
        $correctedTime = now()->setTime(8, 0);
        $adjustment = \App\Models\AttendanceAdjustment::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'work_shift_id' => $attendance->work_shift_id,
            'attendance_date' => $attendance->attendance_date,
            'requested_by' => $user->id,
            'proposed_check_in_at' => $correctedTime,
            'reason' => 'May cham cong bi loi, thuc te vao dung gio',
            'status' => 'pending',
        ]);
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->putJson('/api/v1/attendances/adjustments/'.$adjustment->id, [
            'status' => 'approved',
            'decision_note' => 'Da kiem tra camera, dong y',
        ], ['Authorization' => 'Bearer '.$hrToken]);

        $response->assertStatus(200);
        $attendance->refresh();
        $this->assertSame('completed', $attendance->status);
    }

    public function test_hr_can_reject_adjustment_without_changing_attendance(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $attendance = $this->makeAttendance($employee, ['late_minutes' => 30, 'status' => 'needs_review']);
        $adjustment = \App\Models\AttendanceAdjustment::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'work_shift_id' => $attendance->work_shift_id,
            'attendance_date' => $attendance->attendance_date,
            'requested_by' => $user->id,
            'proposed_check_in_at' => now()->setTime(8, 0),
            'reason' => 'test',
            'status' => 'pending',
        ]);
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->putJson('/api/v1/attendances/adjustments/'.$adjustment->id, [
            'status' => 'rejected',
            'decision_note' => 'Khong co bang chung',
        ], ['Authorization' => 'Bearer '.$hrToken]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'rejected');
        $attendance->refresh();
        $this->assertSame(30, $attendance->late_minutes);
        $this->assertSame('needs_review', $attendance->status);
    }

    public function test_cannot_decide_already_decided_adjustment(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $attendance = $this->makeAttendance($employee);
        $adjustment = \App\Models\AttendanceAdjustment::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'work_shift_id' => $attendance->work_shift_id,
            'attendance_date' => $attendance->attendance_date,
            'requested_by' => $user->id,
            'proposed_check_in_at' => now()->setTime(8, 0),
            'reason' => 'test',
            'status' => 'approved',
            'approved_by' => $user->id,
            'decided_at' => now(),
        ]);
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->putJson('/api/v1/attendances/adjustments/'.$adjustment->id, [
            'status' => 'rejected',
        ], ['Authorization' => 'Bearer '.$hrToken]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_employee_can_list_own_adjustment_requests(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $attendance = $this->makeAttendance($employee);
        \App\Models\AttendanceAdjustment::create([
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'work_shift_id' => $attendance->work_shift_id,
            'attendance_date' => $attendance->attendance_date,
            'requested_by' => $user->id,
            'proposed_check_in_at' => now()->setTime(8, 0),
            'reason' => 'test',
            'status' => 'pending',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/attendances/adjustments/me', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_employee_cannot_list_all_adjustments(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/attendances/adjustments', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_hr_can_list_all_adjustments(): void
    {
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->getJson('/api/v1/attendances/adjustments', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
    }
}
