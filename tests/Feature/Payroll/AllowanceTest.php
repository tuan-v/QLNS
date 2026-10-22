<?php

namespace Tests\Feature\Payroll;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Payroll\PersonalIncomeTaxCalculator;
use App\Services\PayrollService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Phụ cấp (Kế hoạch Ngày 48) theo quy chế công ty 2026-10-21: chỉ còn "Phụ cấp ăn trưa" 30.000đ/ngày làm TẠI
// CÔNG TY (ngày online không tính, nửa ngày vẫn đủ 1 suất, mọi người tự hưởng, HR tắt riêng được) và "Phụ cấp
// khác" (cố định/tháng + nội dung). Đặt mức khác gợi ý thì bắt buộc lý do/nội dung, giữ lịch sử.
class AllowanceTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(Carbon::parse('2026-10-20 10:00'));
    }

    // Admin đặt mức là áp dụng ngay — test ở đây kiểm cách tính; luồng HR đề xuất → Admin duyệt ở AllowanceRequestTest.
    private function admin(): array
    {
        $this->tokens['admin'] ??= $this->postJson('/api/v1/auth/login', ['email' => 'admin@qlns.local', 'password' => 'Admin@123'])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens['admin']];
    }

    private function as(User $user): array
    {
        $this->tokens[$user->id] ??= $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret@123'])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$user->id]];
    }

    private function employee(string $contractType = 'chinh_thuc', array $overrides = []): Employee
    {
        $employee = Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(), 'company_email' => uniqid().'@qlns.local', 'code' => 'NV-'.uniqid(),
            'hire_date' => '2025-01-01', 'employment_status' => 'active',
            'department_id' => Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()])->id,
        ], $overrides));
        EmployeeContract::create([
            'employee_id' => $employee->id, 'contract_number' => 'HD-'.uniqid(), 'contract_type' => $contractType,
            'start_date' => '2025-01-01', 'agreed_salary' => 21_000_000, 'insurance_salary' => 21_000_000, 'status' => 'active',
        ]);

        return $employee;
    }

    private function salaryComponent(string $code): SalaryComponent
    {
        return SalaryComponent::where('code', $code)->firstOrFail();
    }

    private function set(Employee $employee, string $code, int $amount, string $from = '2025-01-01', ?string $note = null)
    {
        return $this->postJson("/api/v1/employees/{$employee->id}/allowances", [
            'salary_component_id' => $this->salaryComponent($code)->id, 'amount' => $amount, 'effective_from' => $from, 'note' => $note,
        ], $this->admin());
    }

    private function item(Employee $employee, string $code): array
    {
        return collect($this->getJson("/api/v1/employees/{$employee->id}/allowances", $this->admin())->assertOk()->json('items'))
            ->firstWhere('component.code', $code);
    }

    private function attend(Employee $employee, WorkShift $shift, string $date, array $overrides = []): Attendance
    {
        return Attendance::create(array_merge([
            'employee_id' => $employee->id, 'work_shift_id' => $shift->id, 'attendance_date' => $date, 'work_mode' => 'office',
            'first_check_in_at' => $date.' 08:00:00', 'last_check_out_at' => $date.' 12:00:00',
            'scheduled_work_minutes' => 240, 'actual_work_minutes' => 240, 'status' => 'completed', 'approval_status' => 'approved',
        ], $overrides));
    }

    public function test_catalog_has_only_meal_and_other_allowances(): void
    {
        $codes = collect($this->getJson('/api/v1/allowances', $this->admin())->assertOk()->json('data'))->pluck('code')->all();

        $this->assertSame(['allowance_meal', 'allowance_other'], $codes);
        $meal = $this->salaryComponent('allowance_meal');
        $this->assertSame('Phụ cấp ăn trưa', $meal->name);
        $this->assertSame('per_office_day', $meal->calculation_method);
        $this->assertTrue($meal->applies_to_all);
        $this->assertEquals(30000, $meal->suggested_amounts['chinh_thuc']);
    }

    public function test_meal_applies_to_everyone_by_default_and_hr_can_turn_it_off(): void
    {
        $employee = $this->employee('thuc_tap');

        $meal = $this->item($employee, 'allowance_meal');
        $this->assertTrue($meal['is_default']);
        $this->assertEquals(30000, $meal['effective_amount']);
        $this->assertFalse($meal['differs']);

        // Tắt = khác gợi ý -> bắt buộc lý do.
        $this->set($employee, 'allowance_meal', 0, '2026-10-01')->assertStatus(422)->assertJsonValidationErrors('note');
        $this->set($employee, 'allowance_meal', 0, '2026-10-01', 'Ăn cơm tại xưởng')->assertOk();
        $meal = $this->item($employee, 'allowance_meal');
        $this->assertFalse($meal['is_default']);
        $this->assertEquals(0, $meal['effective_amount']);
    }

    public function test_other_allowance_needs_content_and_keeps_history(): void
    {
        $employee = $this->employee();

        $this->set($employee, 'allowance_other', 500000, '2026-09-01')->assertStatus(422)->assertJsonValidationErrors('note');
        $this->set($employee, 'allowance_other', 500000, '2026-09-01', 'Hỗ trợ gửi xe')->assertOk();
        $this->set($employee, 'allowance_other', 700000, '2026-10-01', 'Hỗ trợ gửi xe + điện thoại')->assertOk();

        $other = $this->item($employee, 'allowance_other');
        $this->assertEquals(700000, $other['current']['amount']);
        $this->assertCount(2, $other['history']);
        $this->assertSame('2026-09-30', $other['history'][1]['effective_to']);
    }

    public function test_payroll_pays_meal_per_office_day_and_lists_other_allowance(): void
    {
        $employee = $this->employee();
        $this->set($employee, 'allowance_other', 500000, '2025-01-01', 'Hỗ trợ gửi xe')->assertOk();
        $morning = WorkShift::create(['code' => 'CA-S', 'name' => 'Sáng', 'start_time' => '08:00', 'end_time' => '12:00', 'standard_work_minutes' => 240, 'work_coefficient' => 1]);
        $afternoon = WorkShift::create(['code' => 'CA-C', 'name' => 'Chiều', 'start_time' => '13:00', 'end_time' => '17:00', 'standard_work_minutes' => 240, 'work_coefficient' => 1]);

        // Tháng 6/2025 có 21 ngày làm việc.
        $days = collect(range(1, 30))->map(fn ($d) => Carbon::create(2025, 6, $d))->filter->isWeekday()->values()->map->toDateString();
        foreach ($days->take(10) as $day) {
            $this->attend($employee, $morning, $day);                                 // 10 ngày tại công ty
        }
        $this->attend($employee, $afternoon, $days[0]);                                // 2 ca cùng ngày: vẫn 1 suất
        $this->attend($employee, $morning, $days[10], ['work_mode' => 'online']);      // online: không tính
        $this->attend($employee, $morning, $days[11], ['work_mode' => 'online']);
        $this->attend($employee, $morning, $days[12], ['actual_work_minutes' => 120, 'last_check_out_at' => $days[12].' 10:00:00']); // nửa ngày: đủ 1 suất
        $this->attend($employee, $morning, $days[13], ['approval_status' => 'rejected']); // bị từ chối: không tính

        $payroll = app(PayrollService::class)->generateForPeriod(6, 2025, User::where('email', 'hr@qlns.local')->first());
        $detail = $payroll->details()->where('employee_id', $employee->id)->with('components')->first();

        // Ăn trưa 11 ngày × 30.000 = 330.000 (miễn thuế, dưới 730.000) + Phụ cấp khác 500.000 (chịu thuế)
        $meal = $detail->components->firstWhere('component_name_snapshot', 'Phụ cấp ăn trưa');
        $other = $detail->components->firstWhere('component_name_snapshot', 'Phụ cấp khác');
        $this->assertEquals(330000, $meal->amount);
        $this->assertEquals(11, $meal->quantity);
        $this->assertSame('Hỗ trợ gửi xe', $other->calculation_snapshot['allowance']['note']);
        $this->assertEquals(830000, $detail->total_allowance);
        $taxable = app(PersonalIncomeTaxCalculator::class)->taxableIncome($detail->gross_salary - $detail->insurance_amount - 330000, 2025);
        $this->assertEquals($taxable, $detail->taxable_income);

        // Người bị tắt ăn trưa thì không có dòng ăn trưa.
        $noMeal = $this->employee();
        $this->set($noMeal, 'allowance_meal', 0, '2025-01-01', 'Không ăn trưa tại công ty')->assertOk();
        $this->attend($noMeal, $morning, $days[0]);
        $payroll->delete();
        $again = app(PayrollService::class)->generateForPeriod(6, 2025, User::where('email', 'hr@qlns.local')->first());
        $this->assertNull($again->details()->where('employee_id', $noMeal->id)->first()->components()->where('component_name_snapshot', 'Phụ cấp ăn trưa')->first());
    }

    public function test_employee_matrix_and_bulk_actions(): void
    {
        $a = $this->employee('chinh_thuc', ['full_name' => 'ZZ Ma tran A']);
        $b = $this->employee('thu_viec', ['full_name' => 'ZZ Ma tran B']);
        $meal = $this->salaryComponent('allowance_meal')->id;
        $other = $this->salaryComponent('allowance_other')->id;

        $this->postJson('/api/v1/allowances/bulk-set', [
            'employee_ids' => [$a->id, $b->id], 'salary_component_id' => $other, 'mode' => 'amount', 'amount' => 300000,
            'effective_from' => '2026-10-01',
        ], $this->admin())->assertOk()->assertJsonPath('done', 0)->assertJsonCount(2, 'failed'); // thiếu nội dung
        $this->postJson('/api/v1/allowances/bulk-set', [
            'employee_ids' => [$a->id], 'salary_component_id' => $other, 'mode' => 'amount', 'amount' => 300000,
            'effective_from' => '2026-10-01', 'note' => 'Hỗ trợ đi lại',
        ], $this->admin())->assertOk()->assertJsonPath('done', 1);
        $this->set($b, 'allowance_meal', 0, '2026-10-01', 'Làm online toàn thời gian')->assertOk();

        // Tháng này (tới 20/10): A có 3 ngày tại công ty đã duyệt, 1 ngày chờ duyệt, 1 ngày online, 1 ngày bị từ chối,
        // và 1 ngày tháng trước (không tính). B bị tắt ăn trưa nên ngày làm không cộng tiền.
        $shift = WorkShift::create(['code' => 'CA-MT', 'name' => 'Ca', 'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480, 'work_coefficient' => 1]);
        foreach (['2026-10-01', '2026-10-02', '2026-10-05'] as $day) {
            $this->attend($a, $shift, $day);
        }
        $this->attend($a, $shift, '2026-10-06', ['approval_status' => 'pending']);
        $this->attend($a, $shift, '2026-10-07', ['work_mode' => 'online']);
        $this->attend($a, $shift, '2026-10-08', ['approval_status' => 'rejected']);
        $this->attend($a, $shift, '2026-09-30');
        $this->attend($b, $shift, '2026-10-01');

        $rows = collect($this->getJson('/api/v1/allowances/employees?search=ZZ Ma tran', $this->admin())->assertOk()->json('data'))->keyBy('employee.id');
        $this->assertSame('default', $rows[$a->id]['cells'][$meal]['state']);
        $this->assertSame('day', $rows[$a->id]['cells'][$meal]['unit']);
        $this->assertSame('on', $rows[$a->id]['cells'][$other]['state']);
        // Ước tính = 3 ngày × 30.000 + 300.000 khoản theo tháng; ngày chờ duyệt để riêng.
        $this->assertEquals(['amount' => 390000, 'office_days' => 3, 'pending_days' => 1, 'pending_amount' => 30000], $rows[$a->id]['estimate']);
        $this->assertSame('off', $rows[$b->id]['cells'][$meal]['state']);
        $this->assertSame('unset', $rows[$b->id]['cells'][$other]['state']);
        $this->assertEquals(0, $rows[$b->id]['estimate']['amount']);

        // Tab Phụ cấp của nhân viên dùng cùng số ước tính với bảng.
        $this->getJson("/api/v1/employees/{$a->id}/allowances", $this->admin())->assertOk()
            ->assertJsonPath('estimate.amount', 390000)
            ->assertJsonPath('estimate.office_days', 3)
            ->assertJsonPath('estimate.pending_days', 1);
    }

    public function test_catalog_management_and_permissions(): void
    {
        $created = $this->postJson('/api/v1/allowances', [
            'name' => 'Trợ cấp nhà ở', 'component_type' => 'subsidy', 'calculation_method' => 'fixed',
            'is_taxable' => true, 'in_insurance_salary' => false,
            'suggested_amounts' => ['chinh_thuc' => 1000000, 'thu_viec' => 0, 'thuc_tap' => 0, 'head' => null],
        ], $this->admin())->assertCreated()->json('data');
        $this->assertSame('allowance_'.$created['id'], $created['code']);
        $this->deleteJson("/api/v1/allowances/{$created['id']}", [], $this->admin())->assertOk();

        // Ăn trưa tự hưởng nên "áp dụng gợi ý cho người chưa có" không tạo thêm gì.
        $this->postJson('/api/v1/allowances/'.$this->salaryComponent('allowance_meal')->id.'/apply-suggested', ['effective_from' => '2026-10-01'], $this->admin())
            ->assertOk()->assertJsonPath('count', 0);

        $user = User::create(['email' => 'pc-'.uniqid().'@qlns.local', 'user_name' => 'Nv', 'password' => bcrypt('Secret@123'), 'status' => 'active']);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $mine = $this->employee('chinh_thuc', ['user_id' => $user->id]);
        $this->set($mine, 'allowance_other', 200000, '2026-10-01', 'Hỗ trợ gửi xe')->assertOk();

        $this->getJson("/api/v1/employees/{$mine->id}/allowances", $this->as($user))->assertForbidden();
        $this->getJson('/api/v1/employees/me/allowances', $this->as($user))->assertOk()
            ->assertJsonPath('total', 200000)
            ->assertJsonPath('items.0.name', 'Phụ cấp ăn trưa')->assertJsonPath('items.0.unit', 'day')
            ->assertJsonPath('items.1.note', 'Hỗ trợ gửi xe')
            // Ước tính tháng này cho tab "Phụ cấp" ở Hồ sơ của tôi: chưa có ngày tại công ty -> chỉ Phụ cấp khác.
            ->assertJsonPath('estimate.amount', 200000)
            ->assertJsonPath('estimate.office_days', 0)
            // Không lộ việc nội bộ HR/Admin (đề xuất chờ duyệt, mức gợi ý).
            ->assertJsonMissingPath('items.0.pending')
            ->assertJsonMissingPath('items.0.suggested_amount');
        $managerToken = $this->postJson('/api/v1/auth/login', ['email' => 'manager@qlns.local', 'password' => 'Manager@123'])->json('access_token');
        $this->getJson('/api/v1/allowances', ['Authorization' => 'Bearer '.$managerToken])->assertForbidden();
        $this->getJson('/api/v1/allowances/employees', ['Authorization' => 'Bearer '.$managerToken])->assertForbidden();
    }
}
