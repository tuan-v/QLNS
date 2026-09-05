<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeTest extends TestCase
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

    // --- Quyền hạn ---

    public function test_unauthenticated_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/employees');

        $response->assertStatus(401);
    }

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $userWithoutView = User::create([
            'email' => 'noview@qlns.local',
            'user_name' => 'No View',
            'password' => bcrypt('Secret@123'),
            'status' => 'active',
        ]);
        $token = $this->loginAs('noview@qlns.local', 'Secret@123');
        $token = $token ?: '';

        // Tài khoản không gán role nào -> không có permission nào cả
        $response = $this->getJson('/api/v1/employees', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_user_with_view_permission_can_list_employees(): void
    {
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->getJson('/api/v1/employees', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
    }

    public function test_user_without_create_permission_cannot_create(): void
    {
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->postJson('/api/v1/employees', [
            'full_name' => 'Test',
            'company_email' => 'test@qlns.local',
            'hire_date' => '2024-01-01',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    // --- Tạo (Create) ---

    public function test_admin_can_create_employee_with_auto_generated_code(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees', [
            'full_name' => 'Nguyen Van A',
            'company_email' => 'nva@qlns.local',
            'hire_date' => '2024-01-01',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^NV\d{3}$/', $response->json('data.code'));
        $this->assertDatabaseHas('employees', ['company_email' => 'nva@qlns.local']);
    }

    public function test_client_supplied_code_is_ignored_on_create(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees', [
            'full_name' => 'Nguyen Van B',
            'company_email' => 'nvb@qlns.local',
            'hire_date' => '2024-01-01',
            'code' => 'HACK999',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertNotSame('HACK999', $response->json('data.code'));
    }

    public function test_create_requires_full_name_company_email_and_hire_date(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['full_name', 'company_email', 'hire_date']);
    }

    // --- Sửa (Update) ---

    public function test_admin_can_update_employee_name(): void
    {
        $employee = $this->makeEmployee(['full_name' => 'Ten cu']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/employees/'.$employee->id, [
            'full_name' => 'Ten moi',
            'company_email' => $employee->company_email,
            'hire_date' => $employee->hire_date->toDateString(),
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.full_name', 'Ten moi');
    }

    public function test_update_ignores_client_supplied_code(): void
    {
        $employee = $this->makeEmployee(['code' => 'NV001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/employees/'.$employee->id, [
            'full_name' => $employee->full_name,
            'company_email' => $employee->company_email,
            'hire_date' => $employee->hire_date->toDateString(),
            'code' => 'ZZZ',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'code' => 'NV001']);
    }

    public function test_cannot_set_employee_as_its_own_manager(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/employees/'.$employee->id, [
            'full_name' => $employee->full_name,
            'company_email' => $employee->company_email,
            'hire_date' => $employee->hire_date->toDateString(),
            'manager_id' => $employee->id,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manager_id');
    }

    public function test_cannot_move_employee_under_its_own_subordinate(): void
    {
        $boss = $this->makeEmployee(['code' => 'NV001']);
        $subordinate = $this->makeEmployee(['code' => 'NV002', 'manager_id' => $boss->id]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/employees/'.$boss->id, [
            'full_name' => $boss->full_name,
            'company_email' => $boss->company_email,
            'hire_date' => $boss->hire_date->toDateString(),
            'manager_id' => $subordinate->id,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manager_id');
        $this->assertDatabaseHas('employees', ['id' => $boss->id, 'manager_id' => null]);
    }

    // --- Xóa (Delete) ---

    public function test_admin_can_delete_employee(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->deleteJson('/api/v1/employees/'.$employee->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    // --- Ẩn field nhạy cảm theo cấp bậc ---

    public function test_subordinate_cannot_see_superior_sensitive_fields(): void
    {
        $subUser = User::create([
            'email' => 'sub-view@qlns.local',
            'user_name' => 'Sub',
            'password' => bcrypt('Secret@123'),
            'status' => 'active',
        ]);
        $boss = $this->makeEmployee(['cccd' => '111122223333']);
        $subordinate = $this->makeEmployee(['manager_id' => $boss->id, 'user_id' => $subUser->id]);

        // Gan quyen employee.view cho tai khoan nay thong qua role Employee co san
        \App\Models\Role::where('name', 'Employee')->first()
            ->users()->attach($subUser->id);

        $token = $this->loginAs('sub-view@qlns.local', 'Secret@123');

        $response = $this->getJson('/api/v1/employees/'.$boss->id, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.cccd'));
    }

    public function test_employee_can_see_own_sensitive_fields(): void
    {
        $selfUser = User::create([
            'email' => 'self-view@qlns.local',
            'user_name' => 'Self',
            'password' => bcrypt('Secret@123'),
            'status' => 'active',
        ]);
        $employee = $this->makeEmployee(['cccd' => '444455556666', 'user_id' => $selfUser->id]);

        \App\Models\Role::where('name', 'Employee')->first()
            ->users()->attach($selfUser->id);

        $token = $this->loginAs('self-view@qlns.local', 'Secret@123');

        $response = $this->getJson('/api/v1/employees/'.$employee->id, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame('444455556666', $response->json('data.cccd'));
    }

    public function test_nested_manager_field_also_hides_sensitive_fields(): void
    {
        $subUser = User::create([
            'email' => 'sub-nested@qlns.local',
            'user_name' => 'Sub Nested',
            'password' => bcrypt('Secret@123'),
            'status' => 'active',
        ]);
        $boss = $this->makeEmployee(['cccd' => '777788889999']);
        $subordinate = $this->makeEmployee(['manager_id' => $boss->id, 'user_id' => $subUser->id]);

        \App\Models\Role::where('name', 'Employee')->first()
            ->users()->attach($subUser->id);

        $token = $this->loginAs('sub-nested@qlns.local', 'Secret@123');

        // Xem chinh minh (subordinate) -> field 'manager' long ben trong la boss,
        // phai bi an cccd giong het khi xem boss truc tiep
        $response = $this->getJson('/api/v1/employees/'.$subordinate->id, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertNull($response->json('data.manager.cccd'));
    }

    // --- Avatar ---

    public function test_can_upload_avatar(): void
    {
        Storage::fake('public');
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $file = UploadedFile::fake()->create('avatar.jpg', 100, 'image/jpeg');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/avatar', [
            'avatar' => $file,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.avatar_url'));
        $employee->refresh();
        Storage::disk('public')->assertExists($employee->avatar);
    }

    public function test_uploading_new_avatar_deletes_old_one(): void
    {
        Storage::fake('public');
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $first = UploadedFile::fake()->create('first.jpg', 100, 'image/jpeg');
        $this->postJson('/api/v1/employees/'.$employee->id.'/avatar', ['avatar' => $first], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $employee->refresh();
        $oldPath = $employee->avatar;

        $second = UploadedFile::fake()->create('second.jpg', 100, 'image/jpeg');
        $this->postJson('/api/v1/employees/'.$employee->id.'/avatar', ['avatar' => $second], [
            'Authorization' => 'Bearer '.$token,
        ]);

        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_avatar_upload_requires_image_file(): void
    {
        Storage::fake('public');
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $notImage = UploadedFile::fake()->create('document.txt', 10, 'text/plain');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/avatar', [
            'avatar' => $notImage,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422);
    }
}
