<?php

namespace Tests\Feature\Employee;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
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
        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
        ], $overrides));
    }

    public function test_unauthenticated_cannot_list_transfers(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->getJson('/api/v1/employees/'.$employee->id.'/transfers');

        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_cannot_create_transfer(): void
    {
        $department = Department::create(['name' => 'Phong B', 'code' => 'PB-B']);
        $employee = $this->makeEmployee();
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/transfers', [
            'to_department_id' => $department->id,
            'effective_date' => '2026-01-01',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_transfer_employee_and_it_applies_immediately(): void
    {
        $fromDept = Department::create(['name' => 'Phong A', 'code' => 'PB-A']);
        $toDept = Department::create(['name' => 'Phong B', 'code' => 'PB-B']);
        $oldPosition = Position::create(['department_id' => $fromDept->id, 'name' => 'NV cu', 'code' => 'CV-A']);
        $newPosition = Position::create(['department_id' => $toDept->id, 'name' => 'NV moi', 'code' => 'CV-B']);
        $employee = $this->makeEmployee([
            'department_id' => $fromDept->id,
            'position_id' => $oldPosition->id,
        ]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/transfers', [
            'to_department_id' => $toDept->id,
            'new_position_id' => $newPosition->id,
            'effective_date' => '2026-01-15',
            'reason' => 'Tang cuong nhan su',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.from_department.id', $fromDept->id);
        $response->assertJsonPath('data.to_department.id', $toDept->id);
        $response->assertJsonPath('data.old_position.id', $oldPosition->id);
        $response->assertJsonPath('data.new_position.id', $newPosition->id);
        $response->assertJsonPath('data.approver', 'Quản trị hệ thống');

        // Ap dung ngay vao ho so nhan vien, khong phai cho toi effective_date.
        $employee->refresh();
        $this->assertSame($toDept->id, $employee->department_id);
        $this->assertSame($newPosition->id, $employee->position_id);

        $this->assertDatabaseHas('employee_transfers', [
            'employee_id' => $employee->id,
            'from_department_id' => $fromDept->id,
            'to_department_id' => $toDept->id,
        ]);
    }

    public function test_transfer_without_new_position_keeps_current_position(): void
    {
        $fromDept = Department::create(['name' => 'Phong A', 'code' => 'PB-A']);
        $toDept = Department::create(['name' => 'Phong B', 'code' => 'PB-B']);
        $position = Position::create(['department_id' => $fromDept->id, 'name' => 'NV', 'code' => 'CV-A']);
        $employee = $this->makeEmployee([
            'department_id' => $fromDept->id,
            'position_id' => $position->id,
        ]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->postJson('/api/v1/employees/'.$employee->id.'/transfers', [
            'to_department_id' => $toDept->id,
            'effective_date' => '2026-01-15',
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(201);

        $employee->refresh();
        $this->assertSame($toDept->id, $employee->department_id);
        $this->assertSame($position->id, $employee->position_id);
    }

    public function test_cannot_transfer_to_current_department(): void
    {
        $department = Department::create(['name' => 'Phong A', 'code' => 'PB-A']);
        $employee = $this->makeEmployee(['department_id' => $department->id]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/transfers', [
            'to_department_id' => $department->id,
            'effective_date' => '2026-01-01',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('to_department_id');
    }

    public function test_transferring_department_head_clears_old_department_manager_and_demotes_position(): void
    {
        $fromDept = Department::create(['name' => 'Phong A', 'code' => 'PB-A']);
        $toDept = Department::create(['name' => 'Phong B', 'code' => 'PB-B']);
        $headPosition = Position::create([
            'department_id' => $fromDept->id,
            'name' => 'Trưởng phòng',
            'code' => 'CV-H',
            'type' => 'head',
        ]);
        $boss = $this->makeEmployee(['department_id' => $fromDept->id, 'position_id' => $headPosition->id]);
        $fromDept->update(['manager_id' => $boss->id]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->postJson('/api/v1/employees/'.$boss->id.'/transfers', [
            'to_department_id' => $toDept->id,
            'effective_date' => '2026-01-01',
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(201);

        $fromDept->refresh();
        $boss->refresh();
        $this->assertNull($fromDept->manager_id);
        $this->assertSame($toDept->id, $boss->department_id);
        $this->assertSame('default', $boss->position->type);
        $this->assertSame($toDept->id, $boss->position->department_id);
    }

    public function test_cannot_set_new_manager_that_creates_cycle(): void
    {
        $department = Department::create(['name' => 'Phong A', 'code' => 'PB-A']);
        $boss = $this->makeEmployee(['code' => 'NV001']);
        $subordinate = $this->makeEmployee(['code' => 'NV002', 'manager_id' => $boss->id]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        // Chuyen boss sang phong moi, dong thoi dat quan ly moi la subordinate
        // cua chinh minh -> vong lap
        $response = $this->postJson('/api/v1/employees/'.$boss->id.'/transfers', [
            'to_department_id' => $department->id,
            'new_manager_id' => $subordinate->id,
            'effective_date' => '2026-01-15',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('new_manager_id');
        $boss->refresh();
        $this->assertNull($boss->manager_id);
    }

    public function test_can_list_transfer_history_for_employee(): void
    {
        $deptA = Department::create(['name' => 'Phong A', 'code' => 'PB-A']);
        $deptB = Department::create(['name' => 'Phong B', 'code' => 'PB-B']);
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->postJson('/api/v1/employees/'.$employee->id.'/transfers', [
            'to_department_id' => $deptA->id,
            'effective_date' => '2026-01-01',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->postJson('/api/v1/employees/'.$employee->id.'/transfers', [
            'to_department_id' => $deptB->id,
            'effective_date' => '2026-02-01',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $response = $this->getJson('/api/v1/employees/'.$employee->id.'/transfers', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_upload_and_download_decision_file(): void
    {
        $department = Department::create(['name' => 'Phong A', 'code' => 'PB-A']);
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/transfers', [
            'to_department_id' => $department->id,
            'effective_date' => '2026-01-01',
            'decision_file' => UploadedFile::fake()->create('quyet-dinh.pdf', 100, 'application/pdf'),
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $created->assertStatus(201);
        $this->assertNotNull($created->json('data.decision_file_url'));

        $transferId = $created->json('data.id');
        $response = $this->get('/api/v1/employees/'.$employee->id.'/transfers/'.$transferId.'/download', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
    }

    public function test_cannot_download_decision_file_via_mismatched_employee(): void
    {
        $department = Department::create(['name' => 'Phong A', 'code' => 'PB-A']);
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employeeA->id.'/transfers', [
            'to_department_id' => $department->id,
            'effective_date' => '2026-01-01',
            'decision_file' => UploadedFile::fake()->create('quyet-dinh.pdf', 100, 'application/pdf'),
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $transferId = $created->json('data.id');

        $response = $this->get('/api/v1/employees/'.$employeeB->id.'/transfers/'.$transferId.'/download', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(404);
    }
}
