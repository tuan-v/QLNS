<?php

namespace Tests\Feature\Employee;

use App\Mail\PasswordResetMail;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmployeeAccountTest extends TestCase
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
        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
        ], $overrides));
    }

    public function test_unauthenticated_cannot_create_account(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/account', [
            'role_ids' => [Role::where('name', 'Employee')->value('id')],
        ]);

        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_cannot_create_account(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/account', [
            'role_ids' => [Role::where('name', 'Employee')->value('id')],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_account_and_reset_email_is_sent(): void
    {
        Mail::fake();

        $employee = $this->makeEmployee(['company_email' => 'nv001@qlns.local']);
        $employeeRoleId = Role::where('name', 'Employee')->value('id');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/account', [
            'role_ids' => [$employeeRoleId],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('email', 'nv001@qlns.local');
        $response->assertJsonPath('roles.0', 'Employee');

        $employee->refresh();
        $this->assertNotNull($employee->user_id);
        $this->assertDatabaseHas('users', ['email' => 'nv001@qlns.local', 'status' => 'active']);
        $this->assertDatabaseHas('user_roles', ['user_id' => $employee->user_id, 'role_id' => $employeeRoleId]);

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo('nv001@qlns.local'));
    }

    public function test_can_assign_multiple_roles(): void
    {
        Mail::fake();

        $employee = $this->makeEmployee();
        $managerRoleId = Role::where('name', 'Manager')->value('id');
        $hrRoleId = Role::where('name', 'HR')->value('id');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/account', [
            'role_ids' => [$managerRoleId, $hrRoleId],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertCount(2, $response->json('roles'));
    }

    public function test_cannot_create_account_twice_for_same_employee(): void
    {
        Mail::fake();

        $employee = $this->makeEmployee();
        $roleId = Role::where('name', 'Employee')->value('id');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->postJson('/api/v1/employees/'.$employee->id.'/account', [
            'role_ids' => [$roleId],
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/account', [
            'role_ids' => [$roleId],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee');
    }

    public function test_cannot_create_account_when_email_already_used(): void
    {
        User::create([
            'email' => 'trung@qlns.local',
            'user_name' => 'Trung',
            'password' => bcrypt('Secret@123'),
            'status' => 'active',
        ]);
        $employee = $this->makeEmployee(['company_email' => 'trung@qlns.local']);
        $roleId = Role::where('name', 'Employee')->value('id');
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/account', [
            'role_ids' => [$roleId],
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('employee');
    }

    public function test_role_ids_is_required(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/account', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('role_ids');
    }

    public function test_positions_endpoint_includes_suggested_roles_after_head_position_created(): void
    {
        $department = Department::create(['name' => 'Phong A', 'code' => 'PB-A']);
        $boss = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->putJson('/api/v1/departments/'.$department->id, [
            'name' => 'Phong A',
            'manager_id' => $boss->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $headPosition = Position::where('department_id', $department->id)->where('type', 'head')->first();

        $response = $this->getJson('/api/v1/positions?department_id='.$department->id, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('id', $headPosition->id);
        $this->assertNotNull($row);
        $this->assertSame(['Manager'], collect($row['suggested_roles'])->pluck('name')->all());
    }
}
