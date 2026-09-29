<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Quick Search (Ctrl+K) Phase 2 — tìm nhân viên theo dữ liệu thật qua
// GET /api/v1/search?q=..., xem app/Services/SearchService.php và CODE_MAP
// mục 38/39. Phase 1 (QuickSearch.vue — danh sách trang tĩnh) không gọi API
// nào nên không có test Backend riêng.
class SearchTest extends TestCase
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

    private function makeEmployeeWithLogin(string $roleName = 'Employee', array $overrides = []): array
    {
        $user = User::create([
            'email' => 'search-'.uniqid().'@qlns.local', 'user_name' => 'Search User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $employee = Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYears(2),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
            'user_id' => $user->id,
        ], $overrides));

        return [$employee, $user];
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/search?q=nguyen')->assertStatus(401);
    }

    public function test_query_shorter_than_two_characters_returns_empty_without_querying(): void
    {
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/search?q=a', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertExactJson(['data' => []]);
    }

    public function test_hr_can_find_employee_by_name(): void
    {
        $department = Department::create(['name' => 'Phong IT', 'code' => 'PB-'.uniqid()]);
        $position = Position::create(['name' => 'Developer', 'code' => 'POS-'.uniqid(), 'department_id' => $department->id]);
        $target = Employee::create([
            'full_name' => 'Nguyen Van A', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYear(), 'code' => 'NV-'.uniqid(),
            'department_id' => $department->id, 'position_id' => $position->id,
        ]);
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/search?q=Nguyen Van A', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'employee');
        $response->assertJsonPath('data.0.id', $target->id);
        $response->assertJsonPath('data.0.title', 'Nguyen Van A');
        $response->assertJsonPath('data.0.description', 'Phong IT · Developer');
        $response->assertJsonPath('data.0.route', 'employee-detail');
        $response->assertJsonPath('data.0.route_params.id', $target->id);
    }

    public function test_search_matches_by_code_and_company_email_too(): void
    {
        $employee = Employee::create([
            'full_name' => 'Tran Thi B', 'company_email' => 'unique-mail-xyz@qlns.local',
            'hire_date' => now()->subYear(), 'code' => 'NV-UNIQUE-999',
            'department_id' => Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()])->id,
        ]);
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $byCode = $this->getJson('/api/v1/search?q=NV-UNIQUE-999', ['Authorization' => 'Bearer '.$token]);
        $byEmail = $this->getJson('/api/v1/search?q=unique-mail-xyz', ['Authorization' => 'Bearer '.$token]);

        $byCode->assertJsonPath('data.0.id', $employee->id);
        $byEmail->assertJsonPath('data.0.id', $employee->id);
    }

    // Nhân viên thường (không có employee.view) gọi được API (đăng nhập hợp
    // lệ) nhưng KHÔNG được thấy đồng nghiệp qua "cửa sau" tìm kiếm — đúng
    // tinh thần DashboardService::forUser() (mỗi loại kết quả tự lọc theo
    // quyền, không lấy hết rồi mới ẩn ở Frontend).
    public function test_plain_employee_gets_no_employee_results(): void
    {
        Employee::create([
            'full_name' => 'Nguyen Van Target', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYear(), 'code' => 'NV-'.uniqid(),
            'department_id' => Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()])->id,
        ]);
        [, $user] = $this->makeEmployeeWithLogin('Employee');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/search?q=Nguyen Van Target', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertExactJson(['data' => []]);
    }

    public function test_result_limited_to_eight_matches(): void
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        for ($i = 0; $i < 10; $i++) {
            Employee::create([
                'full_name' => 'Pham Van Common', 'company_email' => uniqid().'@qlns.local',
                'hire_date' => now()->subYear(), 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
            ]);
        }
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/search?q=Pham Van Common', ['Authorization' => 'Bearer '.$token]);

        $response->assertJsonCount(8, 'data');
    }
}
