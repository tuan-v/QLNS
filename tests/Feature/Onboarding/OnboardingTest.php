<?php

namespace Tests\Feature\Onboarding;

use App\Models\ChecklistTemplate;
use App\Models\Commune;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeChecklist;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Onboarding/Offboarding: tự tạo checklist khi tạo nhân viên / đơn nghỉ việc có hiệu
// lực, ai được đánh dấu việc nào, tự đánh dấu việc hệ thống, hoàn thành khi xong việc
// bắt buộc, quản lý chỉ thấy nhân viên của mình.
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(Carbon::parse('2026-10-07 09:00'));
    }

    private function headersFor(string $email, string $password = 'Secret@123'): array
    {
        $this->tokens[$email] ??= $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$email]];
    }

    private function hr(): array
    {
        return $this->headersFor('hr@qlns.local', 'Hr@123456');
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'email' => strtolower($role).'-'.uniqid().'@qlns.local', 'user_name' => $role.' user',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $role)->first()->users()->attach($user->id);

        return $user;
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => '2026-10-05',
            'code' => 'NV-'.uniqid(),
            'employment_status' => 'probation',
        ], $overrides));
    }

    private function startChecklist(Employee $employee, string $type = 'onboarding'): array
    {
        return $this->postJson('/api/v1/onboarding/checklists', ['employee_id' => $employee->id, 'type' => $type], $this->hr())
            ->assertCreated()->json('data');
    }

    private function itemId(array $checklist, string $responsible, int $nth = 0): int
    {
        return array_values(array_filter($checklist['items'], fn ($i) => $i['responsible'] === $responsible && ! $i['auto_key']))[$nth]['id'];
    }

    public function test_creating_employee_starts_onboarding_from_default_template(): void
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $commune = Commune::query()->firstOrFail();

        $id = $this->postJson('/api/v1/employees', [
            'full_name' => 'Nguyen Van Moi', 'company_email' => uniqid().'@qlns.local', 'hire_date' => '2026-10-05',
            'date_of_birth' => '1995-05-20', 'gender' => 'male', 'phone' => '0911222333',
            'personal_email' => uniqid().'@gmail.com', 'cccd' => '012345678901', 'personal_tax_code' => 'MST'.uniqid(),
            'address_detail' => 'So 1', 'province_code' => $commune->province_code, 'commune_code' => $commune->code,
            'department_id' => $department->id, 'contract_type' => 'thu_viec', 'agreed_salary' => 10000000,
        ], $this->hr())->assertCreated()->json('data.id');

        $checklist = EmployeeChecklist::with('items')->where('employee_id', $id)->firstOrFail();
        $template = ChecklistTemplate::with('items')->where('type', 'onboarding')->where('is_default', true)->firstOrFail();

        $this->assertSame('onboarding', $checklist->type);
        $this->assertSame('2026-10-05', $checklist->reference_date->toDateString());
        $this->assertCount($template->items->count(), $checklist->items);
        // Hạn = ngày vào làm + số ngày của mẫu.
        $contractItem = $checklist->items->firstWhere('auto_key', 'contract_signed');
        $this->assertSame('2026-10-08', $contractItem->due_date->toDateString());
    }

    public function test_employee_without_permission_cannot_list_checklists(): void
    {
        $user = $this->makeUser('Employee');

        $this->getJson('/api/v1/onboarding/checklists', $this->headersFor($user->email))->assertForbidden();
    }

    public function test_account_item_is_ticked_automatically_and_employee_sees_own_checklist(): void
    {
        $user = $this->makeUser('Employee');
        $employee = $this->makeEmployee(['user_id' => $user->id]);
        $checklist = $this->startChecklist($employee);

        $account = collect($checklist['items'])->firstWhere('auto_key', 'account_created');
        $this->assertNotNull($account['completed_at']);
        $this->assertNull(collect($checklist['items'])->firstWhere('auto_key', 'contract_signed')['completed_at']);

        $mine = $this->getJson('/api/v1/onboarding/me', $this->headersFor($user->email))->assertOk()->json('data');
        $this->assertSame($checklist['id'], $mine[0]['id']);

        // Nhân viên có việc phần mình -> nhận thông báo.
        $this->assertTrue(Notification::where('user_id', $user->id)->where('type', 'checklist.mine')->exists());
    }

    public function test_employee_can_tick_only_own_items(): void
    {
        $user = $this->makeUser('Employee');
        $employee = $this->makeEmployee(['user_id' => $user->id]);
        $checklist = $this->startChecklist($employee);
        $headers = $this->headersFor($user->email);

        $this->putJson('/api/v1/onboarding/items/'.$this->itemId($checklist, 'employee'), ['done' => true], $headers)
            ->assertOk();
        $this->putJson('/api/v1/onboarding/items/'.$this->itemId($checklist, 'hr'), ['done' => true], $headers)
            ->assertForbidden();

        // Checklist của người khác: không thấy.
        $other = $this->startChecklist($this->makeEmployee());
        $this->putJson('/api/v1/onboarding/items/'.$this->itemId($other, 'employee'), ['done' => true], $headers)
            ->assertNotFound();
    }

    public function test_manager_sees_and_ticks_only_direct_reports(): void
    {
        $managerUser = $this->makeUser('Manager');
        $manager = $this->makeEmployee(['user_id' => $managerUser->id, 'employment_status' => 'active']);
        $mine = $this->startChecklist($this->makeEmployee(['manager_id' => $manager->id]));
        $others = $this->startChecklist($this->makeEmployee());
        $headers = $this->headersFor($managerUser->email);

        $ids = collect($this->getJson('/api/v1/onboarding/checklists', $headers)->assertOk()->json('data'))->pluck('id');
        $this->assertEquals([$mine['id']], $ids->all());

        $this->putJson('/api/v1/onboarding/items/'.$this->itemId($mine, 'manager'), ['done' => true], $headers)->assertOk();
        $this->putJson('/api/v1/onboarding/items/'.$this->itemId($mine, 'hr'), ['done' => true], $headers)->assertForbidden();
        $this->getJson('/api/v1/onboarding/checklists/'.$others['id'], $headers)->assertNotFound();
        $this->postJson('/api/v1/onboarding/checklists', ['employee_id' => $manager->id, 'type' => 'onboarding'], $headers)->assertForbidden();
    }

    public function test_checklist_completes_when_required_items_done_and_reopens_when_unticked(): void
    {
        $checklist = $this->startChecklist($this->makeEmployee());
        $required = collect($checklist['items'])->where('is_required', true);

        foreach ($required as $item) {
            $last = $this->putJson("/api/v1/onboarding/items/{$item['id']}", ['done' => true], $this->hr())->assertOk()->json('data');
        }
        $this->assertSame('completed', $last['status']);

        $this->putJson("/api/v1/onboarding/items/{$required->first()['id']}", ['done' => false], $this->hr())
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    public function test_cannot_start_second_open_checklist_of_same_type(): void
    {
        $employee = $this->makeEmployee();
        $this->startChecklist($employee);

        $this->postJson('/api/v1/onboarding/checklists', ['employee_id' => $employee->id, 'type' => 'onboarding'], $this->hr())
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    public function test_resignation_notice_starts_offboarding_and_withdrawal_cancels_it(): void
    {
        $user = $this->makeUser('Employee');
        $employee = $this->makeEmployee(['user_id' => $user->id, 'employment_status' => 'active']);
        $headers = $this->headersFor($user->email);

        // Chưa có hợp đồng -> phải báo trước 45 ngày; 60 ngày = đủ -> đơn "thông báo".
        $requestId = $this->postJson('/api/v1/resignations', [
            'last_working_date' => '2026-12-06', 'reason' => 'Chuyen cong viec',
        ], $headers)->assertCreated()->json('data.id');

        $checklist = EmployeeChecklist::where('employee_id', $employee->id)->where('type', 'offboarding')->firstOrFail();
        $this->assertSame('in_progress', $checklist->status);
        $this->assertSame('2026-12-06', $checklist->reference_date->toDateString());
        $this->assertSame($requestId, $checklist->resignation_request_id);

        $this->postJson("/api/v1/resignations/{$requestId}/cancel", [], $headers)->assertOk();
        $this->assertSame('cancelled', $checklist->fresh()->status);
    }

    public function test_template_keeps_single_default_and_rejects_wrong_auto_key(): void
    {
        $payload = [
            'type' => 'onboarding', 'name' => 'Mau thuc tap', 'is_default' => true,
            'items' => [['title' => 'Viec 1', 'responsible' => 'hr', 'due_offset_days' => 1]],
        ];
        $this->postJson('/api/v1/onboarding/templates', $payload, $this->hr())->assertCreated();
        $this->assertSame(1, ChecklistTemplate::where('type', 'onboarding')->where('is_default', true)->count());

        $payload['items'][0]['auto_key'] = 'account_locked';
        $this->postJson('/api/v1/onboarding/templates', $payload, $this->hr())
            ->assertStatus(422)->assertJsonValidationErrors('items.0.auto_key');
    }
}
