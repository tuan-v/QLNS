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

// Duyệt/Từ chối chấm công HÀNG LOẠT (2026-09-25, theo yêu cầu người dùng,
// kèm ảnh tham khảo "Bulk Actions") — xem AttendanceService::bulkDecideApproval().
class AttendanceBulkApprovalTest extends TestCase
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

    private function makeAttendance(array $overrides = []): Attendance
    {
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);

        return Attendance::create(array_merge([
            'employee_id' => $this->makeEmployee()->id,
            'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(),
            'first_check_in_at' => now()->setTime(8, 0),
            'last_check_out_at' => now()->setTime(17, 0),
            'actual_work_minutes' => 540,
            'status' => 'completed',
        ], $overrides));
    }

    public function test_requires_authentication(): void
    {
        $this->putJson('/api/v1/attendances/bulk-approval', [
            'attendance_ids' => [1], 'status' => 'approved',
        ])->assertStatus(401);
    }

    public function test_employee_without_approve_permission_is_forbidden(): void
    {
        $user = User::create([
            'email' => 'bulk-'.uniqid().'@qlns.local', 'user_name' => 'Bulk User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $attendance = $this->makeAttendance();
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->putJson('/api/v1/attendances/bulk-approval', [
            'attendance_ids' => [$attendance->id], 'status' => 'approved',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(403);
    }

    public function test_hr_can_approve_multiple_attendances_at_once(): void
    {
        $a1 = $this->makeAttendance();
        $a2 = $this->makeAttendance();
        $a3 = $this->makeAttendance();

        $response = $this->putJson('/api/v1/attendances/bulk-approval', [
            'attendance_ids' => [$a1->id, $a2->id, $a3->id],
            'status' => 'approved',
        ], $this->hrHeaders());

        $response->assertStatus(200);
        $response->assertJsonPath('succeeded', [$a1->id, $a2->id, $a3->id]);
        $response->assertJsonCount(0, 'failed');
        $this->assertSame('approved', $a1->fresh()->approval_status);
        $this->assertSame('approved', $a2->fresh()->approval_status);
        $this->assertSame('approved', $a3->fresh()->approval_status);
    }

    public function test_rejecting_requires_a_note_even_in_bulk(): void
    {
        $attendance = $this->makeAttendance();

        $this->putJson('/api/v1/attendances/bulk-approval', [
            'attendance_ids' => [$attendance->id], 'status' => 'rejected',
        ], $this->hrHeaders())->assertStatus(422)->assertJsonValidationErrors('decision_note');
    }

    // Không được để 1 bản ghi lỗi (vd đã duyệt từ trước) làm rớt CẢ LOẠT —
    // các bản ghi hợp lệ khác vẫn phải được duyệt bình thường.
    public function test_one_invalid_record_does_not_block_the_rest(): void
    {
        $alreadyApproved = $this->makeAttendance(['approval_status' => 'approved']);
        $stillPending = $this->makeAttendance();

        $response = $this->putJson('/api/v1/attendances/bulk-approval', [
            'attendance_ids' => [$alreadyApproved->id, $stillPending->id],
            'status' => 'approved',
        ], $this->hrHeaders());

        $response->assertStatus(200);
        $response->assertJsonPath('succeeded', [$stillPending->id]);
        $failed = collect($response->json('failed'));
        $this->assertSame($alreadyApproved->id, $failed->first()['id']);
        $this->assertSame('approved', $stillPending->fresh()->approval_status);
    }

    public function test_attendance_id_that_does_not_exist_fails_validation(): void
    {
        $this->putJson('/api/v1/attendances/bulk-approval', [
            'attendance_ids' => [999999], 'status' => 'approved',
        ], $this->hrHeaders())->assertStatus(422)->assertJsonValidationErrors('attendance_ids.0');
    }
}
