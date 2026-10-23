<?php

namespace Tests\Feature\Approval;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// HR cũng là nhân viên (2026-10-23): HR tự chấm công / xin nghỉ phép / xin điều chỉnh công, nhưng đơn của HR CHỈ
// Admin được duyệt — HR khác, Trưởng phòng đều không; và không ai tự duyệt đơn của chính mình (ApprovalPolicy).
class HrSelfServiceApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
    }

    private function headers(string $email, string $password = 'Secret@123'): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function adminHeaders(): array
    {
        return $this->headers('admin@qlns.local', 'Admin@123');
    }

    /** HR có sẵn từ seeder — không gắn hồ sơ nhân viên, đóng vai "HR khác". */
    private function otherHrHeaders(): array
    {
        return $this->headers('hr@qlns.local', 'Hr@123456');
    }

    /** @return array{0: Employee, 1: User} */
    private function makeEmployeeWithLogin(string $roleName, array $overrides = []): array
    {
        $user = User::create([
            'email' => 'hrself-'.uniqid().'@qlns.local', 'user_name' => 'HR Self',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $employee = Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYears(3),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
            'user_id' => $user->id,
        ], $overrides));
        // Xin nghỉ phép cần có ca làm trong khoảng nghỉ (LeaveRequestService::create()).
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'work_shift_id' => WorkShift::create([
                'code' => 'CA-'.uniqid(), 'name' => 'Ca hanh chinh',
                'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
            ])->id,
            'effective_from' => now()->subYears(5)->toDateString(),
            'work_days' => [1, 2, 3, 4, 5],
            'status' => 'active',
        ]);

        return [$employee, $user];
    }

    private function makeLeaveType(): LeaveType
    {
        return LeaveType::create([
            'code' => 'lt-'.uniqid(), 'name' => 'Loai phep '.uniqid(), 'annual_entitlement_days' => 12,
            'is_paid' => true, 'allow_carry_forward' => false, 'is_active' => true,
        ]);
    }

    private function makeLeaveRequest(Employee $employee): LeaveRequest
    {
        $monday = Carbon::parse('next monday');
        $leaveType = $this->makeLeaveType();
        LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'year' => $monday->year, 'allocated_days' => 12, 'used_days' => 0,
        ]);

        return LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(), 'to_date' => $monday->toDateString(), 'start_session' => 'full', 'end_session' => 'full',
            'total_days' => 1, 'reason' => 'Nghi phep test', 'status' => 'pending',
        ]);
    }

    private function makeAttendance(Employee $employee): Attendance
    {
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);

        return Attendance::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(),
            'first_check_in_at' => now()->setTime(8, 0), 'last_check_out_at' => now()->setTime(17, 0),
            'actual_work_minutes' => 540, 'status' => 'completed',
        ]);
    }

    public function test_hr_can_use_self_service_attendance_and_leave(): void
    {
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $headers = $this->headers($hrUser->email);

        $this->getJson('/api/v1/attendances/today', $headers)->assertOk();
        $this->getJson('/api/v1/attendances/me', $headers)->assertOk();
        $this->getJson('/api/v1/leave-requests/me', $headers)->assertOk();

        $monday = Carbon::parse('next monday')->toDateString();
        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->makeLeaveType()->id,
            'from_date' => $monday, 'to_date' => $monday, 'reason' => 'HR xin nghi',
        ], $headers)->assertCreated()->assertJsonPath('status', 'pending');
    }

    public function test_hr_leave_request_notifies_only_admins(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [, $hrUser] = $this->makeEmployeeWithLogin('HR', ['manager_id' => $manager->id]);
        $adminUser = User::where('email', 'admin@qlns.local')->firstOrFail();
        $otherHrUser = User::where('email', 'hr@qlns.local')->firstOrFail();

        $monday = Carbon::parse('next monday')->toDateString();
        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $this->makeLeaveType()->id,
            'from_date' => $monday, 'to_date' => $monday, 'reason' => 'HR xin nghi',
        ], $this->headers($hrUser->email))->assertCreated();

        $this->assertDatabaseHas('notifications', ['user_id' => $adminUser->id, 'type' => 'leave.pending_hr']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $otherHrUser->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $managerUser->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $hrUser->id]);
    }

    public function test_hr_leave_request_cannot_be_approved_by_other_hr_or_manager(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$hrEmployee] = $this->makeEmployeeWithLogin('HR', ['manager_id' => $manager->id]);
        $leaveRequest = $this->makeLeaveRequest($hrEmployee);

        foreach ([$this->otherHrHeaders(), $this->headers($managerUser->email)] as $headers) {
            $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", ['status' => 'approved'], $headers)
                ->assertStatus(422)
                ->assertJsonPath('errors.status.0', 'Đơn / chấm công của nhân viên HR chỉ Admin được duyệt.');
        }

        $this->assertSame('pending', $leaveRequest->fresh()->status);
    }

    public function test_admin_approves_hr_leave_request_in_one_step(): void
    {
        [$manager] = $this->makeEmployeeWithLogin('Manager');
        [$hrEmployee, $hrUser] = $this->makeEmployeeWithLogin('HR', ['manager_id' => $manager->id]);
        $leaveRequest = $this->makeLeaveRequest($hrEmployee);

        $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", ['status' => 'approved'], $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('leave_approvals', ['leave_request_id' => $leaveRequest->id, 'approval_level' => 2]);
        $this->assertDatabaseHas('notifications', ['user_id' => $hrUser->id, 'type' => 'leave.decided']);
    }

    public function test_nobody_can_approve_own_leave_request(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee');
        Role::where('name', 'HR')->first()->users()->attach($user->id);
        Role::where('name', 'Admin')->first()->users()->attach($user->id);
        $leaveRequest = $this->makeLeaveRequest($employee);

        $this->putJson("/api/v1/leave-requests/{$leaveRequest->id}/decide", ['status' => 'approved'], $this->headers($user->email))
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Bạn không thể tự duyệt đơn / chấm công của chính mình.');
    }

    public function test_leave_list_flags_which_requests_the_viewer_can_decide(): void
    {
        [$hrEmployee] = $this->makeEmployeeWithLogin('HR');
        [$staff] = $this->makeEmployeeWithLogin('Employee');
        $hrRequest = $this->makeLeaveRequest($hrEmployee);
        $staffRequest = $this->makeLeaveRequest($staff);

        $asHr = collect($this->getJson('/api/v1/leave-requests?per_page=100', $this->otherHrHeaders())->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($asHr[$hrRequest->id]['can_decide']);
        $this->assertTrue($asHr[$staffRequest->id]['can_decide']);

        $asAdmin = collect($this->getJson('/api/v1/leave-requests?per_page=100', $this->adminHeaders())->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($asAdmin[$hrRequest->id]['can_decide']);
    }

    public function test_hr_attendance_is_approved_only_by_admin(): void
    {
        [$hrEmployee] = $this->makeEmployeeWithLogin('HR');
        $attendance = $this->makeAttendance($hrEmployee);

        $this->putJson("/api/v1/attendances/{$attendance->id}/approval", ['status' => 'approved'], $this->otherHrHeaders())
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Đơn / chấm công của nhân viên HR chỉ Admin được duyệt.');
        $this->putJson("/api/v1/attendances/{$attendance->id}/work-mode", ['work_mode' => 'online'], $this->otherHrHeaders())
            ->assertStatus(422);

        $this->putJson("/api/v1/attendances/{$attendance->id}/approval", ['status' => 'approved'], $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('approval_status', 'approved');
    }

    public function test_bulk_attendance_approval_skips_hr_records_for_non_admin(): void
    {
        [$hrEmployee] = $this->makeEmployeeWithLogin('HR');
        [$staff] = $this->makeEmployeeWithLogin('Employee');
        $hrAttendance = $this->makeAttendance($hrEmployee);
        $staffAttendance = $this->makeAttendance($staff);

        $response = $this->putJson('/api/v1/attendances/bulk-approval', [
            'attendance_ids' => [$hrAttendance->id, $staffAttendance->id], 'status' => 'approved',
        ], $this->otherHrHeaders())->assertOk();

        $this->assertSame([$staffAttendance->id], $response->json('succeeded'));
        $this->assertSame($hrAttendance->id, $response->json('failed.0.id'));
    }

    public function test_attendance_overview_flags_rows_the_viewer_can_decide(): void
    {
        [$hrEmployee] = $this->makeEmployeeWithLogin('HR');
        [$staff] = $this->makeEmployeeWithLogin('Employee');
        $this->makeAttendance($hrEmployee);
        $this->makeAttendance($staff);

        $rows = collect($this->getJson('/api/v1/attendances/overview', $this->otherHrHeaders())->assertOk()->json('data.rows'))
            ->keyBy('employee.id');

        $this->assertFalse($rows[$hrEmployee->id]['can_decide']);
        $this->assertTrue($rows[$staff->id]['can_decide']);
    }

    public function test_hr_attendance_adjustment_goes_to_admin_only(): void
    {
        [$hrEmployee, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $attendance = $this->makeAttendance($hrEmployee);
        $adminUser = User::where('email', 'admin@qlns.local')->firstOrFail();
        $otherHrUser = User::where('email', 'hr@qlns.local')->firstOrFail();

        $adjustmentId = $this->postJson('/api/v1/attendances/adjustments', [
            'attendance_id' => $attendance->id,
            'proposed_check_in_at' => now()->setTime(7, 55)->toDateTimeString(),
            'reason' => 'HR quen bam gio vao',
        ], $this->headers($hrUser->email))->assertCreated()->json('id');

        $this->assertDatabaseHas('notifications', ['user_id' => $adminUser->id, 'type' => 'attendance_adjustment.pending']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $otherHrUser->id, 'type' => 'attendance_adjustment.pending']);

        $list = collect($this->getJson('/api/v1/attendances/adjustments', $this->otherHrHeaders())->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($list[$adjustmentId]['can_decide']);

        $this->putJson("/api/v1/attendances/adjustments/{$adjustmentId}", ['status' => 'approved'], $this->otherHrHeaders())
            ->assertStatus(422);
        $this->putJson("/api/v1/attendances/adjustments/{$adjustmentId}", ['status' => 'approved'], $this->adminHeaders())
            ->assertOk();

        $this->assertSame('approved', AttendanceAdjustment::find($adjustmentId)->status);
    }
}
