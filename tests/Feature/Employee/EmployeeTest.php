<?php

namespace Tests\Feature\Employee;

use App\Models\Commune;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Province;
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

    private ?int $departmentId = null;

    private ?int $positionId = null;

    /**
     * Phòng ban + chức vụ dùng chung cho mọi payload trong file này. Tạo một lần
     * rồi giữ lại — department_id bắt buộc và phải tồn tại thật (rule "exists"),
     * position_id không bắt buộc nhưng vẫn tạo sẵn để payload mặc định có đủ.
     */
    private function organisationIds(): array
    {
        if ($this->departmentId === null) {
            $department = Department::create(['name' => 'Phong Test', 'code' => 'PB-TEST']);
            $position = Position::create([
                'department_id' => $department->id,
                'code' => 'CV-TEST',
                'name' => 'Nhan vien',
            ]);

            $this->departmentId = $department->id;
            $this->positionId = $position->id;
        }

        return [$this->departmentId, $this->positionId];
    }

    private ?int $provinceCode = null;

    private ?int $communeCode = null;

    /**
     * Tỉnh/Xã dùng chung cho mọi payload — lấy từ dữ liệu thật đã nạp bởi
     * ProvinceCommuneSeeder (chạy trong setUp() qua $this->seed()), không tự
     * tạo bản ghi giả vì bảng này chỉ đọc, không có endpoint tạo mới.
     */
    private function addressIds(): array
    {
        if ($this->provinceCode === null) {
            $province = Province::query()->firstOrFail();
            $commune = Commune::query()->where('province_code', $province->code)->firstOrFail();

            $this->provinceCode = $province->code;
            $this->communeCode = $commune->code;
        }

        return [$this->provinceCode, $this->communeCode];
    }

    /**
     * Payload đầy đủ mọi trường bắt buộc. Test nào muốn kiểm một rule cụ thể thì
     * ghi đè đúng trường đó, tránh việc thiếu trường khác làm 422 vì lý do khác.
     */
    private function validPayload(array $overrides = []): array
    {
        [$departmentId, $positionId] = $this->organisationIds();
        [$provinceCode, $communeCode] = $this->addressIds();

        return array_merge([
            'full_name' => 'Nguyen Van A',
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => '2024-01-01',
            'date_of_birth' => '1995-05-20',
            'gender' => 'male',
            'phone' => '0900000000',
            'personal_email' => uniqid().'@gmail.com',
            'cccd' => str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'personal_tax_code' => 'MST'.uniqid(),
            'address_detail' => 'So 1, Ha Noi',
            'province_code' => $provinceCode,
            'commune_code' => $communeCode,
            'department_id' => $departmentId,
            'position_id' => $positionId,
        ], $overrides);
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

    public function test_stats_endpoint_requires_view_permission(): void
    {
        $response = $this->getJson('/api/v1/employees/stats');

        $response->assertStatus(401);
    }

    public function test_stats_endpoint_returns_counts_by_employment_status(): void
    {
        $this->makeEmployee(['employment_status' => 'active']);
        $this->makeEmployee(['employment_status' => 'active']);
        $this->makeEmployee(['employment_status' => 'probation']);
        $this->makeEmployee(['employment_status' => 'resigned']);
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->getJson('/api/v1/employees/stats', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'total' => 4,
            'active' => 2,
            'probation' => 1,
            'resigned' => 1,
        ]);
    }

    public function test_can_search_employees_by_name(): void
    {
        $this->makeEmployee(['full_name' => 'Nguyen Van Anh']);
        $this->makeEmployee(['full_name' => 'Tran Thi Binh']);
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->getJson('/api/v1/employees?search=Anh', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('full_name');
        $this->assertTrue($names->contains('Nguyen Van Anh'));
        $this->assertFalse($names->contains('Tran Thi Binh'));
    }

    public function test_can_filter_employees_by_employment_status(): void
    {
        $this->makeEmployee(['full_name' => 'Active One', 'employment_status' => 'active']);
        $this->makeEmployee(['full_name' => 'Probation One', 'employment_status' => 'probation']);
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->getJson('/api/v1/employees?employment_status=probation', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('full_name');
        $this->assertTrue($names->contains('Probation One'));
        $this->assertFalse($names->contains('Active One'));
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

        $response = $this->postJson('/api/v1/employees', $this->validPayload([
            'full_name' => 'Nguyen Van A',
            'company_email' => 'nva@qlns.local',
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^NV\d{3}$/', $response->json('data.code'));
        $this->assertDatabaseHas('employees', ['company_email' => 'nva@qlns.local']);
    }

    public function test_client_supplied_code_is_ignored_on_create(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees', $this->validPayload([
            'full_name' => 'Nguyen Van B',
            'company_email' => 'nvb@qlns.local',
            'code' => 'HACK999',
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertNotSame('HACK999', $response->json('data.code'));
    }

    public function test_create_requires_full_employee_profile(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        // Hồ sơ chỉ được tạo sau khi nhân viên đã ký hợp đồng và đi làm, nên mọi
        // thông tin nhân thân + tổ chức đều phải có ngay từ lúc tạo.
        $response->assertStatus(422)->assertJsonValidationErrors([
            'full_name',
            'company_email',
            'hire_date',
            'date_of_birth',
            'gender',
            'phone',
            'personal_email',
            'cccd',
            'personal_tax_code',
            'address_detail',
            'province_code',
            'commune_code',
            'department_id',
        ]);
    }

    public function test_optional_fields_stay_optional_on_create(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        // manager_id / employment_status / probation_end_date / termination_date
        // cố ý KHÔNG bắt buộc: giám đốc không có quản lý cấp trên, và hai mốc
        // ngày kia chỉ có khi thực sự phát sinh.
        $response = $this->postJson('/api/v1/employees', $this->validPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
    }

    public function test_position_id_is_optional_on_create(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        // Chức vụ có thể chưa xếp ngay lúc tạo hồ sơ (chờ phân công sau) — khác
        // Phòng ban vẫn bắt buộc phải có ngay từ đầu.
        $response = $this->postJson('/api/v1/employees', $this->validPayload([
            'position_id' => null,
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.position'));
    }

    // --- Sửa (Update) ---

    public function test_admin_can_update_employee_name(): void
    {
        $employee = $this->makeEmployee(['full_name' => 'Ten cu']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/employees/'.$employee->id, $this->validPayload([
            'full_name' => 'Ten moi',
            'company_email' => $employee->company_email,
            'hire_date' => $employee->hire_date->toDateString(),
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200)->assertJsonPath('data.full_name', 'Ten moi');
    }

    public function test_update_ignores_client_supplied_code(): void
    {
        $employee = $this->makeEmployee(['code' => 'NV001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/employees/'.$employee->id, $this->validPayload([
            'full_name' => $employee->full_name,
            'company_email' => $employee->company_email,
            'hire_date' => $employee->hire_date->toDateString(),
            'code' => 'ZZZ',
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'code' => 'NV001']);
    }

    public function test_cannot_set_employee_as_its_own_manager(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/employees/'.$employee->id, $this->validPayload([
            'full_name' => $employee->full_name,
            'company_email' => $employee->company_email,
            'hire_date' => $employee->hire_date->toDateString(),
            'manager_id' => $employee->id,
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('manager_id');
    }

    public function test_cannot_move_employee_under_its_own_subordinate(): void
    {
        $boss = $this->makeEmployee(['code' => 'NV001']);
        $subordinate = $this->makeEmployee(['code' => 'NV002', 'manager_id' => $boss->id]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/employees/'.$boss->id, $this->validPayload([
            'full_name' => $boss->full_name,
            'company_email' => $boss->company_email,
            'hire_date' => $boss->hire_date->toDateString(),
            'manager_id' => $subordinate->id,
        ]), [
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

        // Gan quyen employee.view thong qua role Manager co san (role Employee
        // khong con employee.view - chi xem duoc chinh minh qua /employees/me,
        // xem RolePermissionSeeder.php). Test nay kiem logic an field theo
        // quan he cap tren/cap duoi trong EmployeeResource, khong phai kiem
        // pham vi quyen cua role Employee, nen dung role nao co employee.view
        // deu hop le.
        \App\Models\Role::where('name', 'Manager')->first()
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

        // Role Manager (co employee.view) - role Employee gio khong con quyen
        // nay, chi xem duoc chinh minh qua /employees/me.
        \App\Models\Role::where('name', 'Manager')->first()
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

        \App\Models\Role::where('name', 'Manager')->first()
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

    // --- Hồ sơ của chính mình (/employees/me) ---

    public function test_me_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/employees/me');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_view_own_profile_via_me(): void
    {
        $user = User::where('email', 'employee@qlns.local')->firstOrFail();
        $employee = $this->makeEmployee(['user_id' => $user->id, 'company_email' => 'nv-me@qlns.local']);
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $response = $this->getJson('/api/v1/employees/me', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $employee->id);
        $response->assertJsonPath('data.company_email', 'nv-me@qlns.local');
    }

    public function test_me_endpoint_returns_404_when_user_has_no_linked_employee(): void
    {
        // Tai khoan HR mac dinh (UserSeeder) chua duoc lien ket voi Employee nao.
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->getJson('/api/v1/employees/me', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(404);
    }

    // --- Tự sửa thông tin liên hệ (PUT /employees/me) ---

    public function test_employee_can_update_own_contact_info_without_employee_update(): void
    {
        [$provinceCode, $communeCode] = $this->addressIds();
        $user = User::where('email', 'employee@qlns.local')->firstOrFail();
        $employee = $this->makeEmployee(['user_id' => $user->id, 'full_name' => 'Ten cu khong doi']);
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $response = $this->putJson('/api/v1/employees/me', [
            'phone' => '0987001122',
            'personal_email' => 'contact-updated@gmail.com',
            'address_detail' => '123 Duong ABC',
            'province_code' => $provinceCode,
            'commune_code' => $communeCode,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.phone', '0987001122');
        $response->assertJsonPath('data.full_name', 'Ten cu khong doi');
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'phone' => '0987001122',
            'personal_email' => 'contact-updated@gmail.com',
        ]);
    }

    public function test_cannot_update_own_profile_with_duplicate_phone(): void
    {
        [$provinceCode, $communeCode] = $this->addressIds();
        $this->makeEmployee(['phone' => '0900111222']);
        $user = User::where('email', 'employee@qlns.local')->firstOrFail();
        $this->makeEmployee(['user_id' => $user->id]);
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $response = $this->putJson('/api/v1/employees/me', [
            'phone' => '0900111222',
            'personal_email' => 'x@gmail.com',
            'address_detail' => 'abc',
            'province_code' => $provinceCode,
            'commune_code' => $communeCode,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_updating_own_profile_requires_authentication(): void
    {
        $response = $this->putJson('/api/v1/employees/me', []);

        $response->assertStatus(401);
    }

    // --- Tự tải tài liệu lên hồ sơ chính mình (POST /employees/me/documents) ---

    public function test_employee_can_upload_own_document_without_employee_update(): void
    {
        Storage::fake('local');
        $user = User::where('email', 'employee@qlns.local')->firstOrFail();
        $this->makeEmployee(['user_id' => $user->id]);
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $response = $this->postJson('/api/v1/employees/me/documents', [
            'document_type' => 'cccd',
            'document_name' => 'CCCD cua toi',
            'document_file' => UploadedFile::fake()->create('cccd.pdf', 100, 'application/pdf'),
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.document_name', 'CCCD cua toi');
    }

    public function test_uploading_own_document_requires_linked_employee(): void
    {
        Storage::fake('local');
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->postJson('/api/v1/employees/me/documents', [
            'document_type' => 'cccd',
            'document_name' => 'Tai lieu',
            'document_file' => UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(404);
    }
}
