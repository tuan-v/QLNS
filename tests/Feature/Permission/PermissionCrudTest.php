<?php

namespace Tests\Feature\Permission;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionCrudTest extends TestCase
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

    public function test_unauthenticated_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/permissions');

        $response->assertStatus(401);
    }

    public function test_user_without_rbac_manage_is_forbidden(): void
    {
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->getJson('/api/v1/permissions', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_list_permissions(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->getJson('/api/v1/permissions', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(27, count($response->json()));
    }

    public function test_admin_can_create_permission(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/permissions', [
            'code' => 'report.export',
            'name' => 'Xuất báo cáo',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201)->assertJson(['code' => 'report.export']);
        $this->assertDatabaseHas('permissions', ['code' => 'report.export']);
    }

    public function test_code_must_follow_dot_namespaced_format(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/permissions', [
            'code' => 'KhongDungDinhDang',
            'name' => 'Sai định dạng',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_code_must_be_unique(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/permissions', [
            'code' => 'employee.view',
            'name' => 'Trùng mã đã có',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_admin_can_update_permission(): void
    {
        $permission = Permission::create(['code' => 'report.export', 'name' => 'Cu', 'guard_name' => 'api']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/permissions/'.$permission->id, [
            'code' => 'report.export',
            'name' => 'Moi',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200)->assertJson(['name' => 'Moi']);
    }

    public function test_admin_can_delete_unattached_permission(): void
    {
        $permission = Permission::create(['code' => 'report.export', 'name' => 'Xuất báo cáo', 'guard_name' => 'api']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->deleteJson('/api/v1/permissions/'.$permission->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('permissions', ['id' => $permission->id]);
    }

    public function test_cannot_delete_permission_attached_to_a_role(): void
    {
        $permission = Permission::create(['code' => 'report.export', 'name' => 'Xuất báo cáo', 'guard_name' => 'api']);
        $role = Role::where('name', 'HR')->first();
        $role->permissions()->attach($permission->id, ['granted_at' => now()]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->deleteJson('/api/v1/permissions/'.$permission->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('permissions', ['id' => $permission->id, 'deleted_at' => null]);
    }
}
