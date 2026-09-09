<?php

namespace Tests\Feature\WorkShift;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
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
            'break_minutes' => 60,
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
}
