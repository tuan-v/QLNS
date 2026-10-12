<?php

namespace Tests\Feature\Employee;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentOffer;
use App\Models\RecruitmentOpening;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Tab "Hành trình": gom tuyển dụng -> hợp đồng/lương (tăng/giảm %) -> lương thực nhận;
// chỉ Admin + HR (employee.journey) xem được.
class EmployeeJourneyTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(Carbon::parse('2026-10-12 09:00'));
    }

    private function as(string $who): array
    {
        $credentials = [
            'hr' => ['hr@qlns.local', 'Hr@123456'],
            'admin' => ['admin@qlns.local', 'Admin@123'],
            'manager' => ['manager@qlns.local', 'Manager@123'],
            'employee' => ['employee@qlns.local', 'Employee@123'],
        ][$who];

        $this->tokens[$who] ??= $this->postJson('/api/v1/auth/login', ['email' => $credentials[0], 'password' => $credentials[1]])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$who]];
    }

    private function employeeWithHistory(): Employee
    {
        $department = Department::create(['name' => 'Phong IT', 'code' => 'PB-IT']);
        $employee = Employee::create([
            'full_name' => 'Nguyen Hanh Trinh', 'company_email' => 'ht@qlns.local', 'hire_date' => '2026-01-05',
            'code' => 'NV-HT', 'department_id' => $department->id, 'employment_status' => 'active',
        ]);

        // 2 giai đoạn lương: thử việc 10tr -> chính thức 12tr (+20%).
        EmployeeContract::create([
            'employee_id' => $employee->id, 'contract_number' => 'TV-1', 'contract_type' => 'thu_viec',
            'start_date' => '2026-01-05', 'end_date' => '2026-03-04', 'agreed_salary' => 10_000_000, 'insurance_salary' => 10_000_000, 'status' => 'expired',
        ]);
        EmployeeContract::create([
            'employee_id' => $employee->id, 'contract_number' => 'CT-1', 'contract_type' => 'chinh_thuc',
            'start_date' => '2026-03-05', 'agreed_salary' => 12_000_000, 'insurance_salary' => 12_000_000, 'status' => 'active',
        ]);

        // Đến từ tuyển dụng: CV -> offer đã chấp nhận -> nhận việc.
        $opening = RecruitmentOpening::create([
            'title' => 'Lap trinh vien', 'department_id' => $department->id, 'contract_type' => 'tat_ca',
            'headcount' => 1, 'status' => 'full',
        ]);
        $candidate = RecruitmentCandidate::create([
            'recruitment_opening_id' => $opening->id, 'full_name' => 'Nguyen Hanh Trinh', 'email' => 'ht@mail.com',
            'cv_file' => 'x.pdf', 'cv_original_name' => 'cv.pdf', 'status' => 'hired', 'hired_employee_id' => $employee->id,
            'reviewed_at' => '2025-12-20 10:00',
        ]);
        $candidate->forceFill(['created_at' => '2025-12-15 09:00'])->save();
        RecruitmentOffer::create([
            'recruitment_candidate_id' => $candidate->id, 'contract_type' => 'thu_viec', 'salary' => 10_000_000,
            'start_date' => '2026-01-05', 'response_deadline' => '2025-12-30', 'status' => 'accepted',
            'sent_at' => '2025-12-26 09:00', 'responded_at' => '2025-12-27 09:00',
        ]);

        foreach ([[1, 9_500_000], [2, 9_800_000]] as [$month, $net]) {
            $payroll = Payroll::create(['period_month' => $month, 'period_year' => 2026, 'status' => 'closed', 'created_by' => User::first()->id]);
            PayrollDetail::create([
                'payroll_id' => $payroll->id, 'employee_id' => $employee->id, 'base_salary' => 10_000_000,
                'standard_work_days' => 22, 'actual_work_days' => 21, 'gross_salary' => 10_000_000, 'net_salary' => $net,
            ]);
        }

        return $employee;
    }

    public function test_only_admin_and_hr_can_view(): void
    {
        $employee = $this->employeeWithHistory();

        $this->getJson("/api/v1/employees/{$employee->id}/journey", $this->as('hr'))->assertOk();
        $this->getJson("/api/v1/employees/{$employee->id}/journey", $this->as('admin'))->assertOk();
        $this->getJson("/api/v1/employees/{$employee->id}/journey", $this->as('manager'))->assertForbidden();
        $this->getJson("/api/v1/employees/{$employee->id}/journey", $this->as('employee'))->assertForbidden();
    }

    public function test_salary_changes_tenure_and_payslips_are_summarised(): void
    {
        $employee = $this->employeeWithHistory();

        $data = $this->getJson("/api/v1/employees/{$employee->id}/journey", $this->as('hr'))->assertOk()->json('data');

        $this->assertEquals(10_000_000, $data['summary']['starting_salary']);
        $this->assertEquals(12_000_000, $data['summary']['current_salary']);
        $this->assertEquals(20, $data['summary']['total_change_percent']);
        $this->assertSame(['years' => 0, 'months' => 9, 'days' => 7], $data['summary']['tenure']);
        $this->assertEquals(10_000_000, $data['summary']['recruitment']['offer_salary']);

        $this->assertCount(2, $data['salary_history']);
        $this->assertNull($data['salary_history'][0]['change_amount']);
        $this->assertEquals(2_000_000, $data['salary_history'][1]['change_amount']);
        $this->assertEquals(20, $data['salary_history'][1]['change_percent']);

        $this->assertSame(['01/2026', '02/2026'], array_column($data['payslips'], 'period'));
    }

    public function test_events_cover_recruitment_to_contracts_newest_first(): void
    {
        $employee = $this->employeeWithHistory();

        $events = $this->getJson("/api/v1/employees/{$employee->id}/journey", $this->as('hr'))->json('data.events');
        $types = array_column($events, 'type');

        foreach (['cv_submitted', 'cv_approved', 'offer_sent', 'offer_accepted', 'hired', 'contract_started'] as $type) {
            $this->assertContains($type, $types);
        }
        // Mới nhất trước: hợp đồng chính thức (03/2026) đứng trước nhận việc (01/2026).
        $this->assertSame('contract_started', $events[0]['type']);
        $this->assertStringContainsString('tăng 2.000.000 ₫, 20%', $events[0]['description']);
    }
}
