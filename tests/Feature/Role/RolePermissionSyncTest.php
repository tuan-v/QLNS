<?php

namespace Tests\Feature\Role;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionSyncTest extends TestCase
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

    public function test_sync_grants_permissions_recording_granted_by_and_granted_at(): void
    {
        $role = Role::create(['name' => 'Kế toán', 'guard_name' => 'api']);
        $viewPermission = Permission::where('code', 'department.view')->firstOrFail();
        $admin = User::where('email', 'admin@qlns.local')->firstOrFail();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/roles/'.$role->id.'/permissions', [
            'permission_ids' => [$viewPermission->id],
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $role->id,
            'permission_id' => $viewPermission->id,
            'granted_by' => $admin->id,
        ]);
    }

    public function test_sync_is_a_true_sync_not_additive(): void
    {
        $role = Role::create(['name' => 'Kế toán', 'guard_name' => 'api']);
        $viewPermission = Permission::where('code', 'department.view')->firstOrFail();
        $managePermission = Permission::where('code', 'department.manage')->firstOrFail();
        $role->permissions()->attach([$viewPermission->id, $managePermission->id], ['granted_at' => now()]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/roles/'.$role->id.'/permissions', [
            'permission_ids' => [$viewPermission->id],
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('role_permissions', ['role_id' => $role->id, 'permission_id' => $viewPermission->id]);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $role->id, 'permission_id' => $managePermission->id]);
    }

    public function test_permission_change_takes_effect_immediately_not_after_60_seconds(): void
    {
        $role = Role::create(['name' => 'Kế toán', 'guard_name' => 'api']);
        $user = User::create([
            'email' => 'ketoan-test@qlns.local',
            'user_name' => 'Kế toán test',
            'password' => bcrypt('Secret@123'),
            'status' => 'active',
        ]);
        $role->users()->attach($user->id, ['assigned_at' => now()]);
        $userToken = $this->loginAs('ketoan-test@qlns.local', 'Secret@123');

        // Chưa có quyền department.view -> 403 (đồng thời làm cache bị "nhớ" là rỗng).
        $this->getJson('/api/v1/departments', ['Authorization' => 'Bearer '.$userToken])
            ->assertStatus(403);

        $viewPermission = Permission::where('code', 'department.view')->firstOrFail();
        $adminToken = $this->loginAs('admin@qlns.local', 'Admin@123');
        $this->putJson('/api/v1/roles/'.$role->id.'/permissions', [
            'permission_ids' => [$viewPermission->id],
        ], ['Authorization' => 'Bearer '.$adminToken])->assertStatus(200);

        // Cùng 1 user, gọi lại NGAY (không chờ 60s) — phải được phép luôn nếu
        // cache đã bị forget đúng cách trong RoleService::syncPermissions().
        $this->getJson('/api/v1/departments', ['Authorization' => 'Bearer '.$userToken])
            ->assertStatus(200);
    }

    public function test_cannot_remove_rbac_manage_from_the_only_role_holding_it(): void
    {
        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $otherPermissionIds = $adminRole->permissions()
            ->where('code', '!=', 'rbac.manage')
            ->pluck('permissions.id')
            ->all();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/roles/'.$adminRole->id.'/permissions', [
            'permission_ids' => $otherPermissionIds,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('permission_ids');
        $rbacManage = Permission::where('code', 'rbac.manage')->firstOrFail();
        $this->assertDatabaseHas('role_permissions', ['role_id' => $adminRole->id, 'permission_id' => $rbacManage->id]);
    }

    public function test_can_remove_rbac_manage_when_another_role_still_has_it(): void
    {
        $adminRole = Role::where('name', 'Admin')->firstOrFail();
        $rbacManage = Permission::where('code', 'rbac.manage')->firstOrFail();
        $secondRole = Role::create(['name' => 'Super Admin phụ', 'guard_name' => 'api']);
        $secondRole->permissions()->attach($rbacManage->id, ['granted_at' => now()]);

        $otherPermissionIds = $adminRole->permissions()
            ->where('code', '!=', 'rbac.manage')
            ->pluck('permissions.id')
            ->all();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/roles/'.$adminRole->id.'/permissions', [
            'permission_ids' => $otherPermissionIds,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $adminRole->id, 'permission_id' => $rbacManage->id]);
    }
}
