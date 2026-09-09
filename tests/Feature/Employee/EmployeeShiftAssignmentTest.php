<?php

namespace Tests\Feature\Employee;

use App\Models\Department;
use App\Models\Employee;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeShiftAssignmentTest extends TestCase
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

    private function makeWorkShift(string $code, string $start, string $end): WorkShift
    {
        return WorkShift::create([
            'code' => $code,
            'name' => 'Ca '.$code,
            'start_time' => $start,
            'end_time' => $end,
            'standard_work_minutes' => 240,
        ]);
    }

    public function test_unauthenticated_cannot_list(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->getJson('/api/v1/employees/'.$employee->id.'/shift-assignments');

        $response->assertStatus(401);
    }

    public function test_user_without_manage_permission_cannot_create(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift('CA001', '06:00', '12:00');
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $workShift->id,
            'effective_from' => now()->toDateString(),
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(403);
    }

    public function test_admin_can_assign_shift_to_employee(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift('CA001', '06:00', '12:00');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('work_shift.code', 'CA001');
        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
        ]);
    }

    public function test_cannot_assign_inactive_work_shift(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift('CA001', '06:00', '12:00');
        $workShift->update(['is_active' => false]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    public function test_can_assign_two_non_overlapping_shifts_same_days(): void
    {
        $employee = $this->makeEmployee();
        $morning = $this->makeWorkShift('CA001', '06:00', '12:00');
        $afternoon = $this->makeWorkShift('CA002', '13:00', '18:00');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $morning->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $afternoon->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertCount(2, $this->getJson(
            '/api/v1/employees/'.$employee->id.'/shift-assignments',
            ['Authorization' => 'Bearer '.$token],
        )->json());
    }

    public function test_cannot_assign_overlapping_time_on_same_days(): void
    {
        $employee = $this->makeEmployee();
        $morning = $this->makeWorkShift('CA001', '06:00', '14:00');
        $overlapping = $this->makeWorkShift('CA002', '13:00', '18:00');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $morning->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $overlapping->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    public function test_can_assign_overlapping_time_on_different_days(): void
    {
        $employee = $this->makeEmployee();
        $shiftA = $this->makeWorkShift('CA001', '06:00', '14:00');
        $shiftB = $this->makeWorkShift('CA002', '13:00', '18:00');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $shiftA->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        // Cùng khung giờ chồng nhưng khác hẳn ngày trong tuần (cuối tuần) -> không xung đột.
        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $shiftB->id,
            'effective_from' => '2026-01-01',
            'work_days' => [6, 7],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
    }

    public function test_can_update_shift_assignment(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift('CA001', '06:00', '12:00');
        $newShift = $this->makeWorkShift('CA002', '13:00', '18:00');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token]);

        $response = $this->putJson(
            '/api/v1/employees/'.$employee->id.'/shift-assignments/'.$created->json('id'),
            [
                'work_shift_id' => $newShift->id,
                'effective_from' => '2026-02-01',
                'work_days' => [1, 2, 3],
            ],
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(200);
        $response->assertJsonPath('work_shift.code', 'CA002');
        $this->assertDatabaseHas('employee_shift_assignments', [
            'id' => $created->json('id'),
            'work_shift_id' => $newShift->id,
            'effective_from' => '2026-02-01',
        ]);
    }

    public function test_updating_shift_assignment_does_not_conflict_with_itself(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift('CA001', '06:00', '12:00');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token]);

        // Sua nhung khong doi gi lien quan xung dot - phai qua duoc, khong bi
        // tu bao xung dot voi chinh no.
        $response = $this->putJson(
            '/api/v1/employees/'.$employee->id.'/shift-assignments/'.$created->json('id'),
            [
                'work_shift_id' => $workShift->id,
                'effective_from' => '2026-01-01',
                'work_days' => [1, 2, 3, 4, 5, 6],
            ],
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(200);
    }

    public function test_work_days_must_be_valid_iso_weekday_values(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift('CA001', '06:00', '12:00');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01',
            'work_days' => [0, 8],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors(['work_days.0', 'work_days.1']);
    }

    public function test_admin_can_delete_shift_assignment(): void
    {
        $employee = $this->makeEmployee();
        $workShift = $this->makeWorkShift('CA001', '06:00', '12:00');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/shift-assignments', [
            'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token]);

        $response = $this->deleteJson(
            '/api/v1/employees/'.$employee->id.'/shift-assignments/'.$created->json('id'),
            [],
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(204);
        $this->assertSoftDeleted('employee_shift_assignments', ['id' => $created->json('id')]);
    }

    public function test_cannot_update_shift_assignment_via_mismatched_employee(): void
    {
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        $workShift = $this->makeWorkShift('CA001', '06:00', '12:00');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employeeA->id.'/shift-assignments', [
            'work_shift_id' => $workShift->id,
            'effective_from' => '2026-01-01',
            'work_days' => [1, 2, 3, 4, 5],
        ], ['Authorization' => 'Bearer '.$token]);

        $response = $this->putJson(
            '/api/v1/employees/'.$employeeB->id.'/shift-assignments/'.$created->json('id'),
            [
                'work_shift_id' => $workShift->id,
                'effective_from' => '2026-01-01',
                'work_days' => [1, 2, 3, 4, 5],
            ],
            ['Authorization' => 'Bearer '.$token],
        );

        $response->assertStatus(404);
    }
}
