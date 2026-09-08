<?php

namespace Tests\Feature\Department;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentTest extends TestCase
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

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $response = $this->getJson('/api/v1/departments', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(403);
    }
    public function test_unauthenticated_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/departments');

        $response->assertStatus(401);
    }
    public function test_user_with_view_permission_can_list_departments(): void
    {
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');
        $response = $this->getJson('/api/v1/departments', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200);
    }
    public function test_user_without_manage_permission_cannot_create(): void
    {
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');
        $response = $this->postJson('/api/v1/departments', [
            'name' => 'test',
            'description' => 'Description of new department',
        ], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_department_with_auto_generated_code(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Phong Ke toan',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^PB\d{3}$/', $response->json('code'));
        $this->assertDatabaseHas('departments', ['name' => 'Phong Ke toan']);
    }

    public function test_client_supplied_code_is_ignored_on_create(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Phong Marketing',
            'code' => 'HACK999',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertNotSame('HACK999', $response->json('code'));
    }

    public function test_generated_code_ignores_legacy_non_numeric_codes(): void
    {
        // Giả lập dữ liệu cũ tự nhập tay kiểu "PB-01" (trước khi có tự sinh mã).
        // "-01" nếu bị ép kiểu (int) sai cách sẽ ra -1, làm số kế tiếp tính sai
        // thành PB000 thay vì PB001 — đây chính là bug đã gặp thật và đã sửa.
        Department::create(['name' => 'Cu', 'code' => 'PB-01']);

        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Moi',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201)->assertJson(['code' => 'PB001']);
    }

    public function test_generated_code_continues_after_soft_deleted_department(): void
    {
        // Mã của bản ghi đã xóa mềm vẫn chiếm chỗ vì cột code có ràng buộc
        // unique ở DB không loại trừ deleted_at — mã PB001 không được tái sử dụng.
        $deleted = Department::create(['name' => 'Da xoa', 'code' => 'PB001']);
        $deleted->delete();

        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Moi',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201)->assertJson(['code' => 'PB002']);
    }

    public function test_create_requires_name(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/departments', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_admin_can_update_department_name(): void
    {
        $department = Department::create(['name' => 'Ten cu', 'code' => 'PB001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/departments/'.$department->id, [
            'name' => 'Ten moi',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200)->assertJson(['name' => 'Ten moi']);
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'name' => 'Ten moi']);
    }

    public function test_update_ignores_client_supplied_code(): void
    {
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/departments/'.$department->id, [
            'name' => 'Phong',
            'code' => 'ZZZ',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'code' => 'PB001']);
    }

    public function test_cannot_set_department_as_its_own_parent(): void
    {
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/departments/'.$department->id, [
            'name' => 'Phong',
            'parent_id' => $department->id,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_cannot_move_department_under_its_own_child(): void
    {
        $parent = Department::create(['name' => 'Cha', 'code' => 'PB001']);
        $child = Department::create(['name' => 'Con', 'code' => 'PB002', 'parent_id' => $parent->id]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        // Đổi cha thành con của chính con mình -> tạo vòng lặp
        $response = $this->putJson('/api/v1/departments/'.$parent->id, [
            'name' => 'Cha',
            'parent_id' => $child->id,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->assertDatabaseHas('departments', ['id' => $parent->id, 'parent_id' => null]);
    }

    public function test_admin_can_delete_department_without_children(): void
    {
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->deleteJson('/api/v1/departments/'.$department->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('departments', ['id' => $department->id]);
    }

    public function test_cannot_delete_department_with_children(): void
    {
        $parent = Department::create(['name' => 'Cha', 'code' => 'PB001']);
        Department::create(['name' => 'Con', 'code' => 'PB002', 'parent_id' => $parent->id]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->deleteJson('/api/v1/departments/'.$parent->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('departments', ['id' => $parent->id, 'deleted_at' => null]);
    }

    public function test_tree_endpoint_returns_nested_structure(): void
    {
        $parent = Department::create(['name' => 'Cha', 'code' => 'PB001']);
        Department::create(['name' => 'Con', 'code' => 'PB002', 'parent_id' => $parent->id]);
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->getJson('/api/v1/departments/tree', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.name', 'Cha');
        $response->assertJsonPath('0.children.0.name', 'Con');
    }

    // --- Trưởng phòng (manager_id) ---

    public function test_admin_can_set_department_manager(): void
    {
        $boss = $this->makeEmployee(['code' => 'NV001', 'full_name' => 'Nguyen Van Truong']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Phong Ke toan',
            'manager_id' => $boss->id,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertSame($boss->id, $response->json('manager_id'));
        $this->assertSame('Nguyen Van Truong', $response->json('manager.full_name'));
        $this->assertDatabaseHas('departments', ['name' => 'Phong Ke toan', 'manager_id' => $boss->id]);
    }

    public function test_department_manager_id_must_exist(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Phong Ke toan',
            'manager_id' => 999999,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manager_id');
    }

    public function test_setting_department_manager_assigns_head_position(): void
    {
        $department = Department::create(['name' => 'Phong Ke toan', 'code' => 'PB001']);
        $boss = $this->makeEmployee(['code' => 'NV001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->putJson('/api/v1/departments/'.$department->id, [
            'name' => 'Phong Ke toan',
            'manager_id' => $boss->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $boss->refresh();
        $this->assertSame('head', $boss->position->type);
        $this->assertSame('Trưởng phòng', $boss->position->name);
    }

    public function test_replacing_department_manager_demotes_old_one_to_default_position(): void
    {
        $department = Department::create(['name' => 'Phong Ke toan', 'code' => 'PB001']);
        $oldBoss = $this->makeEmployee(['code' => 'NV001']);
        $newBoss = $this->makeEmployee(['code' => 'NV002']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->putJson('/api/v1/departments/'.$department->id, [
            'name' => 'Phong Ke toan',
            'manager_id' => $oldBoss->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $this->putJson('/api/v1/departments/'.$department->id, [
            'name' => 'Phong Ke toan',
            'manager_id' => $newBoss->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(200);

        $oldBoss->refresh();
        $newBoss->refresh();
        $this->assertSame('default', $oldBoss->position->type);
        $this->assertSame('Nhân viên', $oldBoss->position->name);
        $this->assertSame('head', $newBoss->position->type);
    }

    public function test_tree_endpoint_hides_manager_sensitive_fields_from_subordinate_viewer(): void
    {
        $subUser = User::create([
            'email' => 'dept-sub@qlns.local',
            'user_name' => 'Sub',
            'password' => bcrypt('Secret@123'),
            'status' => 'active',
        ]);
        $boss = $this->makeEmployee(['cccd' => '111122223333']);
        $subordinate = $this->makeEmployee(['manager_id' => $boss->id, 'user_id' => $subUser->id]);
        Department::create(['name' => 'Phong Ke toan', 'code' => 'PB001', 'manager_id' => $boss->id]);

        // Role "Manager" có sẵn cả department.view lẫn employee.view.
        Role::where('name', 'Manager')->first()->users()->attach($subUser->id);

        $token = $this->loginAs('dept-sub@qlns.local', 'Secret@123');

        $response = $this->getJson('/api/v1/departments/tree', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame($boss->id, $response->json('0.manager.id'));
        $this->assertNull($response->json('0.manager.cccd'));
    }
}
