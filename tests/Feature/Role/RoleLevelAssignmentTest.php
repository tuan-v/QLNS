<?php

namespace Tests\Feature\Role;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Cấp bậc vai trò (2026-10-01, theo yêu cầu người dùng: HR hay người quyền thấp
// hơn Admin khi tạo tài khoản cho nhân viên mới không được gán vai trò cao hơn
// hoặc NGANG BẰNG mình) — xem RoleService::assertCanAssign() và mục 59 CODE_MAP.
// Bổ sung cho HardeningTest (chỉ phủ trường hợp hẹp "HR gán vai trò Admin").
class RoleLevelAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed();
    }

    private function auth(string $email, string $password): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function makeEmployee(): Employee
    {
        return Employee::create([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYear(),
            'code' => 'NV-'.uniqid(),
            'department_id' => Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()])->id,
        ]);
    }

    private function createAccountAs(string $email, string $password, int $roleId): \Illuminate\Testing\TestResponse
    {
        $employee = $this->makeEmployee();

        return $this->postJson(
            "/api/v1/employees/{$employee->id}/account",
            ['role_ids' => [$roleId]],
            $this->auth($email, $password),
        );
    }

    public function test_seeded_roles_sit_on_the_discrete_level_ladder(): void
    {
        $this->assertSame(100, Role::where('name', 'Admin')->value('level'));
        $this->assertSame(60, Role::where('name', 'HR')->value('level'));
        $this->assertSame(40, Role::where('name', 'Manager')->value('level'));
        $this->assertSame(20, Role::where('name', 'Employee')->value('level'));

        // Ô chọn cấp bậc ở giao diện chỉ liệt kê các bậc trong Role::LEVELS, nên
        // mọi vai trò phải nằm trên thang — lệch thang là select hiện trống.
        foreach (Role::pluck('level', 'name') as $name => $level) {
            $this->assertContains($level, Role::LEVELS, "Vai trò {$name} lệch thang cấp bậc");
        }
    }

    public function test_hr_cannot_assign_a_role_at_their_own_level(): void
    {
        $hrRoleId = Role::where('name', 'HR')->value('id');

        $this->createAccountAs('hr@qlns.local', 'Hr@123456', $hrRoleId)
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_ids');

        $this->assertDatabaseMissing('user_roles', ['role_id' => $hrRoleId, 'assigned_by' => null]);
    }

    public function test_hr_can_assign_lower_level_roles(): void
    {
        foreach (['Manager', 'Employee'] as $roleName) {
            $this->createAccountAs('hr@qlns.local', 'Hr@123456', Role::where('name', $roleName)->value('id'))
                ->assertStatus(201);
        }
    }

    // Cửa thoát hiểm có chủ ý: chặn luôn cấp cao nhất thì mất tài khoản Admin
    // duy nhất là khóa chết hệ thống, không ai tạo lại được.
    public function test_admin_can_still_assign_the_admin_role(): void
    {
        $this->createAccountAs('admin@qlns.local', 'Admin@123', Role::where('name', 'Admin')->value('id'))
            ->assertStatus(201);
    }

    public function test_role_dropdown_only_lists_roles_the_caller_may_assign(): void
    {
        $names = $this->getJson('/api/v1/roles', $this->auth('hr@qlns.local', 'Hr@123456'))
            ->assertStatus(200)
            ->json('*.name');

        $this->assertEqualsCanonicalizing(['Employee', 'Manager'], $names);
    }

    public function test_admin_dropdown_still_lists_every_role(): void
    {
        $names = $this->getJson('/api/v1/roles', $this->auth('admin@qlns.local', 'Admin@123'))
            ->assertStatus(200)
            ->json('*.name');

        $this->assertEqualsCanonicalizing(['Admin', 'Employee', 'HR', 'Manager'], $names);
    }

    // Mặc định fail-closed: vai trò mới tạo mà quên đặt cấp bậc thì chỉ Admin
    // gán được, tránh biến vai trò nhiều quyền mới thành lỗ leo thang đặc quyền.
    public function test_new_role_defaults_to_the_highest_level_so_hr_cannot_assign_it(): void
    {
        $roleId = $this->postJson('/api/v1/roles', [
            'name' => 'Vai tro moi chua dat cap',
        ], $this->auth('admin@qlns.local', 'Admin@123'))
            ->assertStatus(201)
            ->assertJsonPath('level', 100)
            ->json('id');

        $this->createAccountAs('hr@qlns.local', 'Hr@123456', $roleId)
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_ids');
    }

    public function test_admin_can_lower_a_role_level_to_delegate_it_to_hr(): void
    {
        $roleId = $this->postJson('/api/v1/roles', [
            'name' => 'Tro ly nhan su',
            'level' => 40,
        ], $this->auth('admin@qlns.local', 'Admin@123'))
            ->assertStatus(201)
            ->assertJsonPath('level', 40)
            ->json('id');

        $this->createAccountAs('hr@qlns.local', 'Hr@123456', $roleId)->assertStatus(201);
    }

    // Chỉ nhận đúng các bậc trong Role::LEVELS — chặn cả cấp ngoài khoảng (101)
    // và cấp lệch thang (30), vì ô chọn ở giao diện không có bậc lệch thang.
    #[DataProvider('invalidLevels')]
    public function test_level_must_be_one_of_the_ladder_steps(int $level): void
    {
        $this->postJson('/api/v1/roles', ['name' => 'Cap sai '.$level, 'level' => $level], $this->auth('admin@qlns.local', 'Admin@123'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('level');
    }

    public static function invalidLevels(): array
    {
        return [
            'ngoài khoảng' => [101],
            'âm' => [-20],
            'lệch thang' => [30],
            'lệch thang sát bậc' => [59],
        ];
    }

    // Lưới chặn thứ hai, độc lập cấp bậc: dù vai trò Admin có bị hạ cấp xuống
    // thấp, HR vẫn không gán được vì nó giữ quyền "rbac.manage".
    public function test_role_holding_rbac_manage_stays_blocked_even_if_its_level_is_lowered(): void
    {
        $adminRole = Role::where('name', 'Admin')->first();
        $adminRole->forceFill(['level' => 20])->save();

        $this->createAccountAs('hr@qlns.local', 'Hr@123456', $adminRole->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_ids');
    }
}
