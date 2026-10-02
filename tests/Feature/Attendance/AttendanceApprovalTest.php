<?php

namespace Tests\Feature\Attendance;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Bước "Duyệt chấm công" (2026-09-21): mọi bản ghi chấm công mới phải được HR
// duyệt, chưa duyệt thì không tính công/lương. Test tính công/lương nằm ở
// AttendanceHistoryTest (tổng ngày công) và PayrollTest (bảng lương).
class AttendanceApprovalTest extends TestCase
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

    private function hrHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->loginAs('hr@qlns.local', 'Hr@123456')];
    }

    private function makeEmployee(): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        return Employee::create([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ]);
    }

    // Mặc định là bản ghi ĐÃ chấm công vào + ra, chờ duyệt (đúng trạng thái
    // sau 1 ca làm việc bình thường).
    private function makeAttendance(array $overrides = []): Attendance
    {
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);

        $data = array_merge([
            'employee_id' => $this->makeEmployee()->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(),
            'first_check_in_at' => now()->setTime(8, 0),
            'last_check_out_at' => now()->setTime(17, 0),
            'actual_work_minutes' => 540,
            'status' => 'completed',
        ], $overrides);

        // Giờ vào/ra duyệt riêng: bản ghi dựng sẵn với approval_status thì coi cả hai phần cùng trạng thái đó.
        if (isset($data['approval_status']) && ! isset($data['check_in_approval_status'])) {
            $data['check_in_approval_status'] = $data['approval_status'];
            $data['check_out_approval_status'] = $data['approval_status'];
        }

        return Attendance::create($data);
    }

    private function approvalUrl(Attendance $attendance): string
    {
        return '/api/v1/attendances/'.$attendance->id.'/approval';
    }

    public function test_new_attendance_defaults_to_pending_approval(): void
    {
        $attendance = $this->makeAttendance();

        $this->assertSame('pending', $attendance->fresh()->approval_status);
        $this->assertNull($attendance->fresh()->approved_by);
    }

    public function test_decide_requires_authentication(): void
    {
        $attendance = $this->makeAttendance();

        $this->putJson($this->approvalUrl($attendance), ['status' => 'approved'])->assertStatus(401);
    }

    public function test_hr_can_approve_a_completed_attendance(): void
    {
        $attendance = $this->makeAttendance();
        $hr = User::where('email', 'hr@qlns.local')->firstOrFail();

        $response = $this->putJson($this->approvalUrl($attendance), [
            'status' => 'approved',
            'decision_note' => 'Da doi chieu voi camera',
        ], $this->hrHeaders());

        $response->assertStatus(200);
        $response->assertJsonPath('approval_status', 'approved');
        $response->assertJsonPath('approved_by.id', $hr->id);
        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'approval_status' => 'approved',
            'approved_by' => $hr->id,
            'approval_note' => 'Da doi chieu voi camera',
        ]);
        $this->assertNotNull($attendance->fresh()->approved_at);
    }

    public function test_hr_can_reject_with_a_reason(): void
    {
        $attendance = $this->makeAttendance();

        $this->putJson($this->approvalUrl($attendance), [
            'status' => 'rejected',
            'decision_note' => 'Khong co mat tai cong ty hom nay',
        ], $this->hrHeaders())->assertStatus(200)->assertJsonPath('approval_status', 'rejected');

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'approval_status' => 'rejected',
            'approval_note' => 'Khong co mat tai cong ty hom nay',
        ]);
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $attendance = $this->makeAttendance();

        $this->putJson($this->approvalUrl($attendance), ['status' => 'rejected'], $this->hrHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('decision_note');

        $this->assertSame('pending', $attendance->fresh()->approval_status);
    }

    public function test_status_must_be_approved_or_rejected(): void
    {
        $attendance = $this->makeAttendance();

        $this->putJson($this->approvalUrl($attendance), ['status' => 'pending'], $this->hrHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // 2026-09-23 (sửa theo yêu cầu người dùng): duyệt được NGAY LÚC CHẤM
    // CÔNG VÀO, không cần chờ chấm công ra — mục đích là xác nhận lượt chấm
    // công vào có thật hay không (đi sớm/trễ, thiết bị/vị trí), không phải
    // xác nhận giờ công cuối ngày.
    public function test_can_decide_an_attendance_that_has_not_checked_out_yet(): void
    {
        $attendance = $this->makeAttendance(['last_check_out_at' => null, 'status' => 'pending']);

        $this->putJson($this->approvalUrl($attendance), ['status' => 'approved'], $this->hrHeaders())
            ->assertStatus(200)
            ->assertJsonPath('approval_status', 'approved');

        $this->assertSame('approved', $attendance->fresh()->approval_status);
    }

    public function test_cannot_decide_an_attendance_that_has_not_checked_in(): void
    {
        // Phòng vệ — trên thực tế bản ghi attendances luôn có first_check_in_at
        // ngay khi tạo (xem AttendanceRepository::findOrCreateForShift()),
        // nhưng vẫn kiểm tra rõ ràng để không lỡ duyệt 1 bản ghi "khống".
        $attendance = $this->makeAttendance(['first_check_in_at' => null, 'last_check_out_at' => null, 'status' => 'pending']);

        $this->putJson($this->approvalUrl($attendance), ['status' => 'approved'], $this->hrHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('pending', $attendance->fresh()->approval_status);
    }

    public function test_cannot_repeat_the_same_decision(): void
    {
        $attendance = $this->makeAttendance(['approval_status' => 'approved']);

        $this->putJson($this->approvalUrl($attendance), ['status' => 'approved'], $this->hrHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_hr_can_change_a_rejected_attendance_to_approved(): void
    {
        // HR bấm nhầm "Từ chối" — phải sửa lại được.
        $attendance = $this->makeAttendance(['approval_status' => 'rejected', 'approval_note' => 'Bam nham']);

        $this->putJson($this->approvalUrl($attendance), ['status' => 'approved'], $this->hrHeaders())
            ->assertStatus(200);

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->id,
            'approval_status' => 'approved',
            // Ghi chú cũ không được dính lại sang quyết định mới.
            'approval_note' => null,
        ]);
    }

    public function test_employee_cannot_decide(): void
    {
        $attendance = $this->makeAttendance();
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $this->putJson($this->approvalUrl($attendance), ['status' => 'approved'], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);

        $this->assertSame('pending', $attendance->fresh()->approval_status);
    }

    public function test_manager_cannot_decide(): void
    {
        $attendance = $this->makeAttendance();
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $this->putJson($this->approvalUrl($attendance), ['status' => 'approved'], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403);
    }

    public function test_admin_can_decide(): void
    {
        $attendance = $this->makeAttendance();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->putJson($this->approvalUrl($attendance), ['status' => 'approved'], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);
    }

    public function test_unknown_attendance_returns_404(): void
    {
        $this->putJson('/api/v1/attendances/999999/approval', ['status' => 'approved'], $this->hrHeaders())
            ->assertStatus(404);
    }

    public function test_list_can_filter_by_approval_status_and_includes_logs(): void
    {
        $pending = $this->makeAttendance();
        $this->makeAttendance(['approval_status' => 'approved']);
        $this->makeAttendance(['approval_status' => 'rejected', 'approval_note' => 'Sai']);
        $pending->logs()->create([
            'employee_id' => $pending->employee_id,
            'event_type' => 'check_in',
            'occurred_at' => now(),
            'method' => 'device',
            'device_name' => 'iPhone (iOS 17.2) · Safari',
            'ip_address' => '10.0.0.5',
        ]);

        $response = $this->getJson('/api/v1/attendances?approval_status=pending', $this->hrHeaders());

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $pending->id);
        // Màn "Duyệt chấm công" cần thấy dữ liệu thiết bị của từng lượt ngay trong danh sách.
        $response->assertJsonPath('data.0.logs.0.device_name', 'iPhone (iOS 17.2) · Safari');
        $response->assertJsonPath('data.0.logs.0.ip_address', '10.0.0.5');
    }

    public function test_hr_approving_a_correction_adjustment_also_approves_the_attendance(): void
    {
        // HR đã xem xét giờ vào/ra khi duyệt yêu cầu điều chỉnh — không bắt duyệt lần hai.
        $employee = $this->makeEmployee();
        $user = User::create([
            'email' => 'appr-'.uniqid().'@qlns.local', 'user_name' => 'Appr User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee->update(['user_id' => $user->id]);
        $attendance = $this->makeAttendance(['employee_id' => $employee->id, 'last_check_out_at' => null, 'status' => 'needs_review']);
        $token = $this->loginAs($user->email, 'Secret@123');

        $adjustmentId = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'correction',
            'attendance_id' => $attendance->id,
            'proposed_check_out_at' => now()->setTime(17, 0)->toDateTimeString(),
            'reason' => 'Quen cham cong ra',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201)->json('id');

        $this->putJson('/api/v1/attendances/adjustments/'.$adjustmentId, [
            'status' => 'approved',
        ], $this->hrHeaders())->assertStatus(200);

        $attendance->refresh();
        $this->assertSame('approved', $attendance->approval_status);
        $this->assertNotNull($attendance->approved_by);
        $this->assertStringContainsString('#'.$adjustmentId, $attendance->approval_note);
    }

    public function test_rejected_or_non_time_adjustments_do_not_approve_the_attendance(): void
    {
        // Duyệt loại đơn không đụng giờ (duyệt OT) KHÔNG được tự duyệt chấm công.
        $employee = $this->makeEmployee();
        $user = User::create([
            'email' => 'appr-'.uniqid().'@qlns.local', 'user_name' => 'Appr User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee->update(['user_id' => $user->id]);
        $attendance = $this->makeAttendance(['employee_id' => $employee->id, 'overtime_minutes' => 40]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $otId = $this->postJson('/api/v1/attendances/adjustments', [
            'type' => 'overtime',
            'attendance_id' => $attendance->id,
            'reason' => 'Lam them cho kip tien do',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201)->json('id');

        $this->putJson('/api/v1/attendances/adjustments/'.$otId, ['status' => 'approved'], $this->hrHeaders())
            ->assertStatus(200);

        $this->assertSame('pending', $attendance->fresh()->approval_status);
        $this->assertTrue((bool) $attendance->fresh()->overtime_approved);
    }

    /* ---------------- Duyệt giờ VÀO và giờ RA riêng ---------------- */

    private function decide(Attendance $attendance, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->putJson($this->approvalUrl($attendance), $payload, $this->hrHeaders());
    }

    public function test_approving_only_check_in_keeps_a_checked_out_record_pending(): void
    {
        $attendance = $this->makeAttendance();

        $this->decide($attendance, ['status' => 'approved', 'part' => 'check_in'])->assertStatus(200);

        $attendance->refresh();
        $this->assertSame('approved', $attendance->check_in_approval_status);
        $this->assertSame('pending', $attendance->check_out_approval_status);
        $this->assertSame('pending', $attendance->approval_status);
    }

    public function test_record_is_approved_only_when_both_check_in_and_check_out_are_approved(): void
    {
        $attendance = $this->makeAttendance();

        $this->decide($attendance, ['status' => 'approved', 'part' => 'check_in'])->assertStatus(200);
        $this->decide($attendance, ['status' => 'approved', 'part' => 'check_out'])->assertStatus(200);

        $this->assertSame('approved', $attendance->fresh()->approval_status);
    }

    public function test_default_part_approves_both_check_in_and_check_out(): void
    {
        $attendance = $this->makeAttendance();

        $this->decide($attendance, ['status' => 'approved'])->assertStatus(200);

        $attendance->refresh();
        $this->assertSame('approved', $attendance->check_in_approval_status);
        $this->assertSame('approved', $attendance->check_out_approval_status);
        $this->assertSame('approved', $attendance->approval_status);
    }

    public function test_rejecting_check_out_rejects_the_whole_record(): void
    {
        $attendance = $this->makeAttendance();

        $this->decide($attendance, ['status' => 'approved', 'part' => 'check_in'])->assertStatus(200);
        $this->decide($attendance, ['status' => 'rejected', 'part' => 'check_out', 'decision_note' => 'Gio ra khong dung'])
            ->assertStatus(200);

        $this->assertSame('rejected', $attendance->fresh()->approval_status);
    }

    public function test_cannot_decide_check_out_before_the_employee_checked_out(): void
    {
        $attendance = $this->makeAttendance(['last_check_out_at' => null, 'actual_work_minutes' => 0, 'status' => 'pending']);

        $this->decide($attendance, ['status' => 'approved', 'part' => 'check_out'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_before_checking_out_only_the_check_in_decides_the_overall_status(): void
    {
        $attendance = $this->makeAttendance(['last_check_out_at' => null, 'actual_work_minutes' => 0, 'status' => 'pending']);

        $this->decide($attendance, ['status' => 'approved'])->assertStatus(200);

        $this->assertSame('approved', $attendance->fresh()->approval_status);
    }

    public function test_overall_status_reopens_when_a_check_out_is_added_after_check_in_was_approved(): void
    {
        $attendance = $this->makeAttendance(['last_check_out_at' => null, 'actual_work_minutes' => 0, 'status' => 'pending']);
        $this->decide($attendance, ['status' => 'approved'])->assertStatus(200);
        $this->assertSame('approved', $attendance->fresh()->approval_status);

        // Chấm công ra: giờ ra bắt đầu ở "chờ duyệt" nên bản ghi quay lại "chờ duyệt".
        $attendance->refresh();
        $attendance->forceFill(['last_check_out_at' => now()->setTime(17, 0), 'check_out_approval_status' => 'pending']);

        $this->assertSame('pending', $attendance->computeOverallApproval());
    }

    public function test_excuse_and_early_leave_requests_are_no_longer_accepted(): void
    {
        $employee = $this->makeEmployee();
        $user = User::create([
            'email' => 'appr-'.uniqid().'@qlns.local', 'user_name' => 'Appr User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee->update(['user_id' => $user->id]);
        $attendance = $this->makeAttendance(['employee_id' => $employee->id, 'late_minutes' => 40]);
        $token = $this->loginAs($user->email, 'Secret@123');

        foreach (['excuse', 'early_leave'] as $type) {
            $this->postJson('/api/v1/attendances/adjustments', [
                'type' => $type,
                'attendance_id' => $attendance->id,
                'reason' => 'Ly do bat ky',
            ], ['Authorization' => 'Bearer '.$token])
                ->assertStatus(422)
                ->assertJsonValidationErrors('type');
        }
    }
}
