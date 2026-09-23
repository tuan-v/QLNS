<?php

namespace Tests\Feature\WorkShift;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkShiftTest extends TestCase
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ca hanh chinh',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'break_start_time' => '12:00',
            'break_end_time' => '13:00',
            'standard_work_minutes' => 480,
            'late_grace_minutes' => 5,
            'early_leave_grace_minutes' => 5,
            'work_coefficient' => 1,
        ], $overrides);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/work-shifts');

        $response->assertStatus(401);
    }

    public function test_user_without_manage_permission_cannot_create(): void
    {
        // Manager co shift.view nhung khong co shift.manage.
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->postJson('/api/v1/work-shifts', $this->validPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    // Employee KHÔNG có shift.view (không thấy trang "Ca làm việc") nhưng
    // vẫn phải đọc được danh sách ca đang hoạt động để chọn khi "Xin làm
    // ngoài lịch" (2026-09-23) — route index() cho phép cả attendance.check.
    public function test_employee_without_shift_view_can_still_list_shifts_for_extra_shift_dropdown(): void
    {
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $response = $this->getJson('/api/v1/work-shifts', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
    }

    public function test_user_without_any_shift_permission_cannot_list(): void
    {
        $user = User::create([
            'email' => 'no-shift-perm-'.uniqid().'@qlns.local', 'user_name' => 'No Perm',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/work-shifts', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_work_shift_with_auto_generated_code(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/work-shifts', $this->validPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^CA\d{3}$/', $response->json('code'));
        $this->assertDatabaseHas('work_shifts', ['name' => 'Ca hanh chinh', 'standard_work_minutes' => 480]);
    }

    public function test_client_supplied_code_is_ignored_on_create(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/work-shifts', $this->validPayload(['code' => 'HACK999']), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertNotSame('HACK999', $response->json('code'));
    }

    public function test_create_requires_name_times_and_standard_work_minutes(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/work-shifts', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors([
            'name', 'start_time', 'end_time', 'standard_work_minutes',
        ]);
    }

    public function test_admin_can_update_work_shift(): void
    {
        $workShift = WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001']));
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/work-shifts/'.$workShift->id, $this->validPayload(['name' => 'Ca chieu']), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200)->assertJson(['name' => 'Ca chieu']);
    }

    public function test_update_ignores_client_supplied_code(): void
    {
        $workShift = WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001']));
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/work-shifts/'.$workShift->id, $this->validPayload(['code' => 'ZZZ']), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('work_shifts', ['id' => $workShift->id, 'code' => 'CA001']);
    }

    public function test_deactivating_work_shift_removes_it_from_all_employees(): void
    {
        $workShift = WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001']));
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $employeeA = Employee::create([
            'full_name' => 'Nhan vien A', 'company_email' => 'a-'.uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        $employeeB = Employee::create([
            'full_name' => 'Nhan vien B', 'company_email' => 'b-'.uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        $assignmentA = EmployeeShiftAssignment::create([
            'employee_id' => $employeeA->id, 'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01', 'work_days' => [1, 2, 3, 4, 5], 'status' => 'active',
        ]);
        $assignmentB = EmployeeShiftAssignment::create([
            'employee_id' => $employeeB->id, 'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01', 'work_days' => [1, 2, 3, 4, 5], 'status' => 'active',
        ]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson(
            '/api/v1/work-shifts/'.$workShift->id,
            $this->validPayload(['is_active' => false]),
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(200);
        $this->assertSoftDeleted('employee_shift_assignments', ['id' => $assignmentA->id]);
        $this->assertSoftDeleted('employee_shift_assignments', ['id' => $assignmentB->id]);
    }

    public function test_updating_work_shift_without_deactivating_keeps_assignments(): void
    {
        $workShift = WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001']));
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $employee = Employee::create([
            'full_name' => 'Nhan vien', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        $assignment = EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01', 'work_days' => [1, 2, 3, 4, 5], 'status' => 'active',
        ]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson(
            '/api/v1/work-shifts/'.$workShift->id,
            $this->validPayload(['name' => 'Ca hanh chinh moi', 'is_active' => true]),
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('employee_shift_assignments', ['id' => $assignment->id, 'deleted_at' => null]);
    }

    public function test_admin_can_delete_work_shift(): void
    {
        $workShift = WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001']));
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->deleteJson('/api/v1/work-shifts/'.$workShift->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('work_shifts', ['id' => $workShift->id]);
    }

    // 2026-09-24, sửa lỗi thật: xóa Ca đang còn nhân viên gán vào trước đây
    // để lại EmployeeShiftAssignment "mồ côi" (work_shift_id trỏ tới Ca đã
    // xóa mềm) — AttendanceService::dailyOverview()/history() sập với lỗi
    // "Attempt to read property id on null". Xóa Ca giờ phải gỡ hết bản gán
    // trước, giống hệt hành vi tắt is_active (xem
    // test_deactivating_work_shift_removes_it_from_all_employees ở trên).
    public function test_deleting_work_shift_removes_it_from_all_employees(): void
    {
        $workShift = WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001']));
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $employee = Employee::create([
            'full_name' => 'Nhan vien', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        $assignment = EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01', 'work_days' => [1, 2, 3, 4, 5], 'status' => 'active',
        ]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->deleteJson('/api/v1/work-shifts/'.$workShift->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('work_shifts', ['id' => $workShift->id]);
        $this->assertSoftDeleted('employee_shift_assignments', ['id' => $assignment->id]);
    }

    /* -------------------------- Giờ nghỉ trưa (2026-09-23) -------------------------- */

    public function test_break_end_time_must_be_after_break_start_time(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/work-shifts', $this->validPayload([
            'break_start_time' => '13:00', 'break_end_time' => '12:00',
        ]), ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('break_end_time');
    }

    public function test_break_times_must_be_given_as_a_pair(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/work-shifts', $this->validPayload([
            'break_start_time' => '12:00', 'break_end_time' => null,
        ]), ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('break_end_time');
    }

    public function test_work_shift_without_lunch_break_is_allowed(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/work-shifts', $this->validPayload([
            'break_start_time' => null, 'break_end_time' => null,
        ]), ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
    }

    /* ---------------------------- Ca mặc định (2026-09-23) ---------------------------- */

    public function test_default_endpoint_returns_null_when_none_configured(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->getJson('/api/v1/work-shifts/default', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200)->assertJsonPath('data', null);
    }

    public function test_creating_a_shift_as_default_makes_it_the_only_default(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');
        $first = $this->postJson('/api/v1/work-shifts', $this->validPayload(['is_default' => true]), [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(201)->json();

        $second = $this->postJson('/api/v1/work-shifts', $this->validPayload(['name' => 'Ca dem', 'is_default' => true]), [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(201)->json();

        $this->assertDatabaseHas('work_shifts', ['id' => $first['id'], 'is_default' => false]);
        $this->assertDatabaseHas('work_shifts', ['id' => $second['id'], 'is_default' => true]);

        $response = $this->getJson('/api/v1/work-shifts/default', ['Authorization' => 'Bearer '.$token]);
        $response->assertStatus(200)->assertJsonPath('data.id', $second['id']);
    }

    public function test_setting_default_via_update_unsets_previous_default(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');
        $first = WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001', 'is_default' => true]));
        $second = WorkShift::create(array_merge($this->validPayload(['name' => 'Ca dem']), ['code' => 'CA002']));

        $this->putJson('/api/v1/work-shifts/'.$second->id, $this->validPayload(['name' => 'Ca dem', 'is_default' => true]), [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(200);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
    }

    public function test_cannot_deactivate_the_default_work_shift(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');
        $workShift = WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001', 'is_default' => true]));

        $response = $this->putJson(
            '/api/v1/work-shifts/'.$workShift->id,
            $this->validPayload(['is_active' => false, 'is_default' => true]),
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(422)->assertJsonValidationErrors('is_active');
        // is_active không ép boolean (xem WorkShift::$casts) — so bằng "1" thô.
        $this->assertEquals(1, $workShift->fresh()->is_active);
    }

    public function test_cannot_delete_the_default_work_shift(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');
        $workShift = WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001', 'is_default' => true]));

        $response = $this->deleteJson('/api/v1/work-shifts/'.$workShift->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('is_default');
        $this->assertDatabaseHas('work_shifts', ['id' => $workShift->id, 'deleted_at' => null]);
    }

    // Nhân viên MỚI tạo tự động được gán Ca mặc định (2026-09-23) — gọi
    // thẳng EmployeeService::create() (không qua API tạo nhân viên đầy đủ
    // field tỉnh/xã/CCCD... không liên quan tới Ca làm việc) để test đúng
    // hành vi CỐT LÕI, không phụ thuộc dữ liệu seed hành chính.
    public function test_new_employee_is_auto_assigned_to_the_default_shift(): void
    {
        $defaultShift = WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001', 'is_default' => true]));
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);

        $employee = app(\App\Services\EmployeeService::class)->create([
            'full_name' => 'Nhan vien moi',
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->toDateString(),
            'department_id' => $department->id,
            'agreed_salary' => 0,
        ]);

        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employee->id,
            'work_shift_id' => $defaultShift->id,
            'status' => 'active',
        ]);
    }

    public function test_new_employee_has_no_shift_when_no_default_configured(): void
    {
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);

        $employee = app(\App\Services\EmployeeService::class)->create([
            'full_name' => 'Nhan vien moi',
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->toDateString(),
            'department_id' => $department->id,
            'agreed_salary' => 0,
        ]);

        $this->assertDatabaseCount('employee_shift_assignments', 0);
        $this->assertNotNull($employee->id);
    }

    /* --------------------- Ngày làm việc của Ca mặc định (2026-09-24) --------------------- */

    public function test_work_days_rejects_invalid_weekday_values(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/work-shifts', $this->validPayload([
            'is_default' => true, 'work_days' => [1, 8],
        ]), ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_days.1');
    }

    public function test_work_days_rejects_duplicate_values(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/work-shifts', $this->validPayload([
            'is_default' => true, 'work_days' => [1, 1],
        ]), ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors(['work_days.0', 'work_days.1']);
    }

    // Chưa từng cấu hình work_days ở Cài đặt (cột NULL, giống mọi ca tạo
    // trước 2026-09-24) -> assignDefaultShift() rơi về T2-T6.
    public function test_new_employee_falls_back_to_monday_friday_when_default_work_days_not_configured(): void
    {
        WorkShift::create(array_merge($this->validPayload(), ['code' => 'CA001', 'is_default' => true]));
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);

        $employee = app(\App\Services\EmployeeService::class)->create([
            'full_name' => 'Nhan vien moi',
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->toDateString(),
            'department_id' => $department->id,
            'agreed_salary' => 0,
        ]);

        $this->assertDatabaseHas('employee_shift_assignments', ['employee_id' => $employee->id]);
        $assignment = EmployeeShiftAssignment::where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals([1, 2, 3, 4, 5], $assignment->work_days);
    }

    // Cài đặt đã cấu hình "ngày làm việc" riêng (vd T2-T7) -> nhân viên mới
    // dùng ĐÚNG giá trị đó, không còn hard-code T2-T6.
    public function test_new_employee_uses_configured_default_work_days(): void
    {
        WorkShift::create(array_merge($this->validPayload(), [
            'code' => 'CA001', 'is_default' => true, 'work_days' => [1, 2, 3, 4, 5, 6],
        ]));
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);

        $employee = app(\App\Services\EmployeeService::class)->create([
            'full_name' => 'Nhan vien moi',
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->toDateString(),
            'department_id' => $department->id,
            'agreed_salary' => 0,
        ]);

        $assignment = EmployeeShiftAssignment::where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals([1, 2, 3, 4, 5, 6], $assignment->work_days);
    }

    // Đổi "ngày làm việc" của Ca mặc định qua PUT /work-shifts/{id} (Settings.vue)
    // -> áp dụng NGAY cho nhân viên ĐANG theo ca này (bản gán "đang mở",
    // effective_to = NULL) — không cần HR sửa tay từng người.
    public function test_updating_default_shift_work_days_resyncs_open_ended_assignments(): void
    {
        $defaultShift = WorkShift::create(array_merge($this->validPayload(), [
            'code' => 'CA001', 'is_default' => true, 'work_days' => [1, 2, 3, 4, 5],
        ]));
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $employee = Employee::create([
            'full_name' => 'Nhan vien', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        $assignment = EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $defaultShift->id,
            'effective_from' => '2026-01-01', 'effective_to' => null,
            'work_days' => [1, 2, 3, 4, 5], 'status' => 'active',
        ]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson(
            '/api/v1/work-shifts/'.$defaultShift->id,
            $this->validPayload(['is_default' => true, 'work_days' => [1, 2, 3, 4, 5, 6]]),
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(200);
        $this->assertEquals([1, 2, 3, 4, 5, 6], $assignment->fresh()->work_days);
    }

    // Bản gán 1-NGÀY của "Xin làm ngoài lịch/OT" (effective_to đã chốt,
    // khác NULL) là ngoại lệ đã xong việc — KHÔNG bị đồng bộ theo, dù cùng
    // trỏ tới Ca mặc định.
    public function test_updating_default_shift_work_days_does_not_touch_one_off_assignments(): void
    {
        $defaultShift = WorkShift::create(array_merge($this->validPayload(), [
            'code' => 'CA001', 'is_default' => true, 'work_days' => [1, 2, 3, 4, 5],
        ]));
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $employee = Employee::create([
            'full_name' => 'Nhan vien', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        $oneOff = EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $defaultShift->id,
            'effective_from' => '2026-10-03', 'effective_to' => '2026-10-03',
            'work_days' => [6], 'status' => 'active',
        ]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->putJson(
            '/api/v1/work-shifts/'.$defaultShift->id,
            $this->validPayload(['is_default' => true, 'work_days' => [1, 2, 3, 4, 5, 6, 7]]),
            ['Authorization' => 'Bearer '.$token],
        )->assertStatus(200);

        $this->assertEquals([6], $oneOff->fresh()->work_days);
    }
}
