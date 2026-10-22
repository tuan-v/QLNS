<?php

namespace Tests\Feature\Employee;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeContract;
use App\Models\InterviewScorecard;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentOffer;
use App\Models\RecruitmentOpening;
use App\Models\SalaryComponent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Tab "Hành trình": gom tuyển dụng -> hợp đồng/lương (tăng/giảm %, lý do) -> dự án & KPI -> chuyên cần, phụ cấp
// -> lương thực nhận; Admin + HR (employee.journey) xem mọi người, Trưởng phòng xem phòng mình + phòng con.
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

    // 2026-10-22: Trưởng phòng xem được lộ trình nhân viên phòng mình (+ phòng con), không xem phòng khác.
    public function test_department_head_can_view_own_department_only(): void
    {
        $employee = $this->employeeWithHistory();
        $managerUser = User::where('email', 'manager@qlns.local')->firstOrFail();
        $head = Employee::create([
            'full_name' => 'Truong Phong', 'company_email' => 'tp@qlns.local', 'hire_date' => '2025-01-01',
            'code' => 'NV-TP', 'employment_status' => 'active', 'user_id' => $managerUser->id,
        ]);
        $parent = Department::create(['name' => 'Khoi Ky thuat', 'code' => 'KT', 'manager_id' => $head->id]);
        Department::where('id', $employee->department_id)->update(['parent_id' => $parent->id]);
        $other = Employee::create([
            'full_name' => 'Phong Khac', 'company_email' => 'pk@qlns.local', 'hire_date' => '2026-01-01', 'code' => 'NV-PK',
            'employment_status' => 'active', 'department_id' => Department::create(['name' => 'Ke toan', 'code' => 'KTO'])->id,
        ]);

        $this->getJson("/api/v1/employees/{$employee->id}/journey", $this->as('manager'))->assertOk();
        $this->getJson("/api/v1/employees/{$employee->id}", $this->as('manager'))->assertJsonPath('meta.can_view_journey', true);
        $this->getJson("/api/v1/employees/{$other->id}/journey", $this->as('manager'))->assertForbidden();
        $this->getJson("/api/v1/employees/{$other->id}", $this->as('manager'))->assertJsonPath('meta.can_view_journey', false);
    }

    // 2026-10-22: lộ trình đủ phỏng vấn (lý do), lý do tăng lương, dự án & KPI, phụ cấp, số liệu ô tóm tắt.
    public function test_full_journey_has_interview_reasons_projects_kpi_and_allowances(): void
    {
        $employee = $this->employeeWithHistory();
        EmployeeContract::where('contract_number', 'CT-1')->update(['salary_change_note' => 'Hoàn thành thử việc xuất sắc']);

        $candidate = RecruitmentCandidate::where('hired_employee_id', $employee->id)->firstOrFail();
        $interview = RecruitmentInterview::create([
            'recruitment_candidate_id' => $candidate->id, 'scheduled_at' => '2025-12-22 09:00', 'format' => 'online',
            'status' => 'completed', 'result' => 'passed', 'result_note' => 'Nắm chắc Laravel, giao tiếp tốt',
        ]);
        InterviewScorecard::create([
            'recruitment_interview_id' => $interview->id, 'question_set_name' => 'Kỹ thuật PHP', 'pass_percent' => 60,
            'status' => 'submitted', 'score_percent' => 82, 'result' => 'passed', 'overall_note' => 'Cần thêm kinh nghiệm test',
        ]);

        $project = Project::create(['code' => 'DA-1', 'name' => 'Cổng nội bộ', 'lead_employee_id' => $employee->id, 'start_date' => '2026-09-01', 'status' => 'active']);
        ProjectMember::create(['project_id' => $project->id, 'employee_id' => $employee->id, 'role' => 'member']);
        ProjectTask::create([
            'project_id' => $project->id, 'title' => 'Trang đăng nhập', 'assignee_employee_id' => $employee->id, 'priority' => 'medium',
            'points' => 3, 'start_date' => '2026-09-20', 'due_date' => '2026-09-30', 'status' => 'done',
            'submitted_at' => '2026-09-29 10:00', 'completed_at' => '2026-09-29 10:00', 'quality_score' => 5,
        ]);

        EmployeeAllowance::create([
            'employee_id' => $employee->id, 'salary_component_id' => SalaryComponent::where('code', 'allowance_other')->value('id'),
            'amount' => 500000, 'effective_from' => '2026-10-01', 'note' => 'Hỗ trợ gửi xe',
            'created_by' => User::where('email', 'hr@qlns.local')->value('id'), 'approved_by' => User::where('email', 'admin@qlns.local')->value('id'),
        ]);

        $data = $this->getJson("/api/v1/employees/{$employee->id}/journey", $this->as('hr'))->assertOk()->json('data');

        $this->assertSame('Nắm chắc Laravel, giao tiếp tốt', $data['interviews'][0]['result_note']);
        $this->assertSame('Cần thêm kinh nghiệm test', $data['interviews'][0]['overall_note']);
        $this->assertSame('Hoàn thành thử việc xuất sắc', $data['salary_history'][1]['note']);

        $this->assertSame('Cổng nội bộ', $data['kpi']['projects'][0]['project']['name']);
        $this->assertEquals(100, $data['kpi']['projects'][0]['metrics']['score']);
        $this->assertSame('A', $data['kpi']['months'][0]['grade']['key']);

        $types = array_column($data['events'], 'type');
        $this->assertContains('project_joined', $types);
        $this->assertContains('allowance_set', $types);
        $allowanceEvent = collect($data['events'])->firstWhere('type', 'allowance_set');
        $this->assertStringContainsString('Nhân sự đề xuất', $allowanceEvent['description']);
        $this->assertNotNull(collect($data['events'])->first(fn ($e) => str_contains($e['description'] ?? '', 'Lý do: Hoàn thành thử việc xuất sắc')));

        // Số liệu cho các ô tóm tắt đầu trang (giao diện tự dựng).
        $this->assertEquals(82, $data['summary']['recruitment']['score_percent']);
        $this->assertSame('passed', $data['summary']['recruitment']['interview_result']);
        $this->assertEquals(100, $data['kpi']['overall']['score']);
        $this->assertContains('Hỗ trợ gửi xe', array_column($data['allowances'], 'note'));
        $this->assertIsArray($data['attendance']);
    }

    // Hợp đồng mới có lương khác hợp đồng trước -> bắt buộc lý do; hợp đồng cũ bổ sung lý do sau được.
    public function test_new_contract_with_different_salary_requires_reason(): void
    {
        Storage::fake('local');
        $employee = $this->employeeWithHistory();
        $payload = [
            'contract_type' => 'chinh_thuc', 'start_date' => '2026-11-01', 'agreed_salary' => 15_000_000,
            'contract_file' => UploadedFile::fake()->create('hd.pdf', 50, 'application/pdf'),
        ];

        $this->postJson("/api/v1/employees/{$employee->id}/contracts", $payload, $this->as('hr'))
            ->assertStatus(422)->assertJsonValidationErrors('salary_change_note');
        $this->postJson("/api/v1/employees/{$employee->id}/contracts", ['salary_change_note' => 'Tăng lương định kỳ'] + $payload, $this->as('hr'))
            ->assertCreated();
        $this->assertDatabaseHas('employee_contracts', ['employee_id' => $employee->id, 'agreed_salary' => 15_000_000, 'salary_change_note' => 'Tăng lương định kỳ']);

        $old = EmployeeContract::where('contract_number', 'CT-1')->firstOrFail();
        $this->putJson("/api/v1/employees/{$employee->id}/contracts/{$old->id}", ['salary_change_note' => 'Lên chính thức'], $this->as('hr'))
            ->assertOk();
        $this->assertSame('Lên chính thức', $old->fresh()->salary_change_note);
    }
}
