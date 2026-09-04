<?php

namespace Tests\Feature\Department;

use App\Models\Department;
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
}
