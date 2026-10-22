<?php

namespace Tests\Feature\Payroll;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeAllowanceRequest;
use App\Models\EmployeeContract;
use App\Models\Notification;
use App\Models\Payroll;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\AllowanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Đề xuất phụ cấp (yêu cầu 2026-10-21): HR thêm/sửa/tắt phụ cấp của nhân viên chỉ tạo đề xuất, Admin duyệt mới
// áp dụng (từ đúng ngày HR chọn), Admin tự sửa thì áp dụng ngay. Mỗi lượt gửi 1 thông báo cho Admin, duyệt /
// từ chối báo lại HR.
class AllowanceRequestTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(Carbon::parse('2026-10-20 10:00'));
    }

    private function as(string $email, string $password): array
    {
        $this->tokens[$email] ??= $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$email]];
    }

    private function hr(): array
    {
        return $this->as('hr@qlns.local', 'Hr@123456');
    }

    private function admin(): array
    {
        return $this->as('admin@qlns.local', 'Admin@123');
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function employee(string $name = 'Nhan vien'): Employee
    {
        $employee = Employee::create([
            'full_name' => $name.' '.uniqid(), 'company_email' => uniqid().'@qlns.local', 'code' => 'NV-'.uniqid(),
            'hire_date' => '2025-01-01', 'employment_status' => 'active',
            'department_id' => Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()])->id,
        ]);
        EmployeeContract::create([
            'employee_id' => $employee->id, 'contract_number' => 'HD-'.uniqid(), 'contract_type' => 'chinh_thuc',
            'start_date' => '2025-01-01', 'agreed_salary' => 21_000_000, 'insurance_salary' => 21_000_000, 'status' => 'active',
        ]);

        return $employee;
    }

    private function other(): int
    {
        return SalaryComponent::where('code', 'allowance_other')->value('id');
    }

    private function setOther(Employee $employee, array $headers, int $amount = 500000, string $from = '2026-10-01', string $note = 'Hỗ trợ gửi xe')
    {
        return $this->postJson("/api/v1/employees/{$employee->id}/allowances", [
            'salary_component_id' => $this->other(), 'amount' => $amount, 'effective_from' => $from, 'note' => $note,
        ], $headers);
    }

    private function notificationsOf(string $email, string $type)
    {
        return Notification::where('user_id', $this->user($email)->id)->where('type', $type)->get();
    }

    public function test_hr_change_waits_for_admin_and_does_not_affect_payroll(): void
    {
        $employee = $this->employee();

        $this->setOther($employee, $this->hr())->assertOk()
            ->assertJsonPath('pending', 1)
            ->assertJsonPath('applied', 0)
            ->assertJsonPath('items.1.pending.amount', 500000)
            ->assertJsonPath('items.1.current', null);

        $this->assertDatabaseCount('employee_allowances', 0);
        $request = EmployeeAllowanceRequest::sole();
        $this->assertSame('pending', $request->status);
        $this->assertSame('unset', $request->previous_state);

        // Chưa duyệt thì bảng lương chưa có "Phụ cấp khác".
        $payroll = app(AllowanceService::class)->forPayroll($employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 22, 22, 22);
        $this->assertSame(['Phụ cấp ăn trưa'], collect($payroll['items'])->map(fn ($i) => $i['component']->name)->all());

        // Admin nhận 1 thông báo (toast + chuông); bảng phụ cấp hiện ô "chờ duyệt".
        $notice = $this->notificationsOf('admin@qlns.local', 'allowance.pending')->sole();
        $this->assertStringContainsString('500.000đ/tháng từ 01/10/2026 (Hỗ trợ gửi xe)', $notice->message);
        $this->assertSame([$request->id], $notice->data['request_ids']);
        $row = collect($this->getJson('/api/v1/allowances/employees?search='.urlencode($employee->full_name), $this->hr())->json('data'))->sole();
        $this->assertEquals(500000, $row['cells'][$this->other()]['pending']['amount']);

        // Gửi lại thì thay đề xuất cũ, không tạo thêm.
        $this->setOther($employee, $this->hr(), 600000)->assertOk();
        $this->assertSame(1, EmployeeAllowanceRequest::where('status', 'pending')->count());
        $this->assertEquals(600000, EmployeeAllowanceRequest::sole()->amount);
    }

    public function test_admin_approval_applies_from_the_date_hr_chose_and_notifies_hr(): void
    {
        $employee = $this->employee();
        $this->setOther($employee, $this->hr(), 500000, '2026-10-01')->assertOk();
        $request = EmployeeAllowanceRequest::sole();

        $this->postJson('/api/v1/allowance-requests/approve', ['ids' => [$request->id]], $this->hr())->assertForbidden();

        // Admin duyệt ngày 20/10 nhưng hiệu lực vẫn từ 01/10 như HR đề xuất.
        $this->postJson('/api/v1/allowance-requests/approve', ['ids' => [$request->id], 'note' => 'OK'], $this->admin())
            ->assertOk()->assertJsonPath('done', 1);

        $row = EmployeeAllowance::sole();
        $this->assertSame('2026-10-01', $row->effective_from->toDateString());
        $this->assertSame($this->user('hr@qlns.local')->id, $row->created_by);
        $this->assertSame($this->user('admin@qlns.local')->id, $row->approved_by);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame($row->id, $request->fresh()->employee_allowance_id);

        $notice = $this->notificationsOf('hr@qlns.local', 'allowance.decided')->sole();
        $this->assertSame('approved', $notice->data['status']);
        $this->assertStringContainsString('Ghi chú: OK', $notice->message);

        // Duyệt lại đề xuất đã xử lý: không làm gì.
        $this->postJson('/api/v1/allowance-requests/approve', ['ids' => [$request->id]], $this->admin())->assertJsonPath('done', 0);
        $this->assertDatabaseCount('employee_allowances', 1);
    }

    public function test_approval_warns_when_payroll_of_that_period_already_exists(): void
    {
        $employee = $this->employee();
        $this->setOther($employee, $this->hr(), 500000, '2026-09-15')->assertOk();
        $request = EmployeeAllowanceRequest::sole();
        Payroll::create(['period_month' => 9, 'period_year' => 2026, 'status' => 'closed', 'currency' => 'VND', 'created_by' => $this->user('hr@qlns.local')->id, 'total_payroll_amount' => 0]);

        $warning = $this->postJson('/api/v1/allowance-requests/approve', ['ids' => [$request->id]], $this->admin())
            ->assertOk()->json('payroll_warning');

        $this->assertStringContainsString('không tự cập nhật', $warning);
        $this->assertStringContainsString($employee->full_name, $warning);
    }

    public function test_reject_needs_reason_and_tells_hr(): void
    {
        $employee = $this->employee();
        $this->setOther($employee, $this->hr())->assertOk();
        $request = EmployeeAllowanceRequest::sole();

        $this->postJson('/api/v1/allowance-requests/reject', ['ids' => [$request->id]], $this->admin())
            ->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson('/api/v1/allowance-requests/reject', ['ids' => [$request->id], 'note' => 'Chưa có quyết định'], $this->admin())
            ->assertOk()->assertJsonPath('done', 1);

        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertDatabaseCount('employee_allowances', 0);
        $notice = $this->notificationsOf('hr@qlns.local', 'allowance.decided')->sole();
        $this->assertSame('rejected', $notice->data['status']);
        $this->assertStringContainsString('Lý do: Chưa có quyết định', $notice->message);
    }

    public function test_admin_changes_apply_immediately_without_request(): void
    {
        $employee = $this->employee();

        $this->setOther($employee, $this->admin())->assertOk()
            ->assertJsonPath('applied', 1)
            ->assertJsonPath('pending', 0)
            ->assertJsonPath('items.1.current.amount', 500000)
            ->assertJsonPath('items.1.current.approved_by', $this->user('admin@qlns.local')->user_name);

        $this->assertDatabaseCount('employee_allowance_requests', 0);
        $this->assertSame(0, Notification::where('type', 'allowance.pending')->count());
    }

    public function test_bulk_from_hr_sends_one_notification_and_list_shows_requests(): void
    {
        $a = $this->employee('ZZ De xuat');
        $b = $this->employee('ZZ De xuat');

        $this->postJson('/api/v1/allowances/bulk-set', [
            'employee_ids' => [$a->id, $b->id], 'salary_component_id' => $this->other(), 'mode' => 'amount', 'amount' => 300000,
            'effective_from' => '2026-11-01', 'note' => 'Hỗ trợ đi lại',
        ], $this->hr())->assertOk()->assertJsonPath('done', 2)->assertJsonPath('pending', 2);

        $notice = $this->notificationsOf('admin@qlns.local', 'allowance.pending')->sole();
        $this->assertStringContainsString('2 đề xuất', $notice->message);

        $list = $this->getJson('/api/v1/allowance-requests', $this->hr())->assertOk();
        $list->assertJsonPath('meta.pending', 2)->assertJsonPath('meta.can_approve', false)->assertJsonCount(2, 'data');
        $this->assertTrue($list->json('data.0.can_cancel'));
        $this->assertFalse($list->json('data.0.can_decide'));
        $this->getJson('/api/v1/allowance-requests', $this->admin())->assertJsonPath('data.0.can_decide', true);

        // Duyệt 1 lượt cả 2 -> HR nhận 1 thông báo.
        $this->postJson('/api/v1/allowance-requests/approve', ['ids' => EmployeeAllowanceRequest::pluck('id')->all()], $this->admin())
            ->assertJsonPath('done', 2);
        $this->assertCount(1, $this->notificationsOf('hr@qlns.local', 'allowance.decided'));
    }

    public function test_requester_can_withdraw_pending_request(): void
    {
        $employee = $this->employee();
        $this->setOther($employee, $this->hr())->assertOk();
        $request = EmployeeAllowanceRequest::sole();

        $this->deleteJson("/api/v1/allowance-requests/{$request->id}", [], $this->hr())->assertOk();
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->deleteJson("/api/v1/allowance-requests/{$request->id}", [], $this->hr())->assertStatus(422);
        $this->postJson('/api/v1/allowance-requests/approve', ['ids' => [$request->id]], $this->admin())->assertJsonPath('done', 0);
    }
}
