<?php

namespace Tests\Feature\Role;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleCrudTest extends TestCase
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

    public function test_existing_readonly_index_route_is_untouched_for_employee_update_holders(): void
    {
        // Regression guard: route GET /roles hiện có (gate "employee.update",
        // dùng cho dropdown tạo tài khoản nhân viên) không được đổi khi thêm
        // CRUD mới — HR có "employee.update" nhưng KHÔNG có "rbac.manage".
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->getJson('/api/v1/roles', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
    }

    public function test_hr_without_rbac_manage_cannot_create_role(): void
    {
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Kế toán',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_role(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'Kế toán',
            'description' => 'Nhân sự phòng kế toán',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201)->assertJson(['name' => 'Kế toán']);
        $this->assertDatabaseHas('roles', ['name' => 'Kế toán']);
    }

    public function test_role_name_must_be_unique(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/roles', [
            'name' => 'HR',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_admin_can_update_role(): void
    {
        $role = Role::create(['name' => 'Kế toán', 'guard_name' => 'api']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/roles/'.$role->id, [
            'name' => 'Kế toán trưởng',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200)->assertJson(['name' => 'Kế toán trưởng']);
    }

    public function test_admin_can_delete_role(): void
    {
        $role = Role::create(['name' => 'Kế toán', 'guard_name' => 'api']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->deleteJson('/api/v1/roles/'.$role->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('roles', ['id' => $role->id]);
    }

    public function test_show_includes_permissions_and_users_count(): void
    {
        $role = Role::where('name', 'HR')->first();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->getJson('/api/v1/roles/'.$role->id, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('permissions'));
    }
}
