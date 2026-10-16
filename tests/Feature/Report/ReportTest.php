<?php

namespace Tests\Feature\Report;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Module Báo cáo (Kế hoạch Ngày 51-54): biến động nhân sự, chấm công & nghỉ phép, chi phí lương;
// phạm vi Manager chỉ phòng mình, không xem lương; xuất Excel.
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(Carbon::parse('2026-10-16 10:00'));
    }

    private function as(string $email, string $password = 'Secret@123'): array
    {
        $this->tokens[$email] ??= $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$email]];
    }

    private function hr(): array
    {
        return $this->as('hr@qlns.local', 'Hr@123456');
    }

    private function employee(Department $department, array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'full_name' => 'NV '.uniqid(), 'company_email' => uniqid().'@qlns.local', 'code' => 'NV-'.uniqid(),
            'hire_date' => '2026-01-05', 'employment_status' => 'active', 'department_id' => $department->id,
        ], $overrides));
    }

    private function manager(Department $department): User
    {
        $user = User::create(['email' => 'mgr-'.uniqid().'@qlns.local', 'user_name' => 'Manager', 'password' => bcrypt('Secret@123'), 'status' => 'active']);
        Role::where('name', 'Manager')->first()->users()->attach($user->id);
        $employee = $this->employee($department, ['user_id' => $user->id]);
        $department->update(['manager_id' => $employee->id]);

        return $user;
    }

    public function test_workforce_counts_hires_leavers_and_turnover(): void
    {
        $dept = Department::create(['name' => 'Phong RP', 'code' => 'RP-1']);
        $this->employee($dept, ['hire_date' => '2026-01-05']);
        $this->employee($dept, ['hire_date' => '2026-01-10']);
        $this->employee($dept, ['hire_date' => '2026-09-01']);
        $this->employee($dept, ['hire_date' => '2026-01-05', 'termination_date' => '2026-09-20', 'employment_status' => 'resigned']);

        $data = $this->getJson("/api/v1/reports/workforce?from=2026-09&to=2026-10&department_id={$dept->id}", $this->hr())->assertOk()->json();

        $september = $data['periods'][0];
        $this->assertSame('T9/2026', $september['label']);
        $this->assertSame(1, $september['hires']);
        $this->assertSame(1, $september['leavers']);
        $this->assertSame(3, $september['headcount']); // cuối tháng 9: 4 người - 1 nghỉ
        // Nghỉ việc / bình quân (đầu kỳ 3 + cuối kỳ 3) / 2 = 33,3%
        $this->assertSame(33.3, $september['turnover_rate']);
        $this->assertSame(3, $data['totals']['headcount']);
        $this->assertSame([['department' => 'Phong RP', 'headcount' => 3]], $data['by_department']);
    }

    public function test_quarter_grouping(): void
    {
        $dept = Department::create(['name' => 'Phong Q', 'code' => 'RP-Q']);
        $this->employee($dept, ['hire_date' => '2026-07-15']);
        $this->employee($dept, ['hire_date' => '2026-08-15']);

        $periods = $this->getJson("/api/v1/reports/workforce?from=2026-07&to=2026-09&group=quarter&department_id={$dept->id}", $this->hr())->json('periods');

        $this->assertCount(1, $periods);
        $this->assertSame('Quý 3/2026', $periods[0]['label']);
        $this->assertSame(2, $periods[0]['hires']);
    }

    public function test_attendance_counts_late_checkins(): void
    {
        $dept = Department::create(['name' => 'Phong CC', 'code' => 'RP-CC']);
        $employee = $this->employee($dept);
        $shift = WorkShift::create(['code' => 'CA-RP', 'name' => 'Ca', 'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480, 'work_coefficient' => 1]);
        foreach (['2026-10-01' => 0, '2026-10-02' => 15] as $date => $late) {
            Attendance::create([
                'employee_id' => $employee->id, 'work_shift_id' => $shift->id, 'attendance_date' => $date,
                'first_check_in_at' => "{$date} 08:00:00", 'last_check_out_at' => "{$date} 17:00:00",
                'scheduled_work_minutes' => 480, 'actual_work_minutes' => 480, 'late_minutes' => $late, 'approval_status' => 'approved',
            ]);
        }

        $totals = $this->getJson("/api/v1/reports/attendance?from=2026-10&to=2026-10&department_id={$dept->id}", $this->hr())->assertOk()->json('totals');

        $this->assertSame(2, $totals['checkins']);
        $this->assertSame(1, $totals['late_count']);
        $this->assertSame(50.0, (float) $totals['late_rate']);
        $this->assertGreaterThan(0, $totals['work_days']);
    }

    public function test_payroll_cost_adds_employer_insurance(): void
    {
        $dept = Department::create(['name' => 'Phong L', 'code' => 'RP-L']);
        $employee = $this->employee($dept);
        $payroll = Payroll::create(['period_month' => 9, 'period_year' => 2026, 'status' => 'closed', 'currency' => 'VND', 'created_by' => 1]);
        PayrollDetail::create([
            'payroll_id' => $payroll->id, 'employee_id' => $employee->id, 'base_salary' => 10_000_000, 'standard_work_days' => 22,
            'actual_work_days' => 22, 'gross_salary' => 10_000_000, 'insurance_amount' => 1_050_000, 'personal_income_tax' => 0, 'net_salary' => 8_950_000,
        ]);

        $data = $this->getJson("/api/v1/reports/payroll-cost?from=2026-09&to=2026-09&department_id={$dept->id}", $this->hr())->assertOk()->json();

        // BH công ty = lương đóng BH 10tr × 21,5% = 2.150.000
        $this->assertEquals(2_150_000, $data['totals']['employer_insurance']);
        $this->assertEquals(12_150_000, $data['totals']['total_cost']);
        $this->assertSame('Phong L', $data['by_department'][0]['department']);
    }

    public function test_manager_only_sees_own_departments_and_no_payroll(): void
    {
        $own = Department::create(['name' => 'Phong Cua Toi', 'code' => 'RP-OWN']);
        $child = Department::create(['name' => 'To Con', 'code' => 'RP-CHILD', 'parent_id' => $own->id]);
        $other = Department::create(['name' => 'Phong Khac', 'code' => 'RP-OTHER']);
        $this->employee($child);
        $this->employee($other);
        $manager = $this->manager($own);
        $headers = $this->as($manager->email);

        $filters = $this->getJson('/api/v1/reports/filters', $headers)->assertOk()->json();
        $this->assertSame('departments', $filters['scope']);
        $this->assertFalse($filters['can_view_payroll']);
        $this->assertEqualsCanonicalizing(['Phong Cua Toi', 'To Con'], array_column($filters['departments'], 'name'));

        // Không lọc -> chỉ đếm phòng mình + phòng con (manager + 1 người tổ con).
        $this->assertSame(2, $this->getJson('/api/v1/reports/workforce?from=2026-10&to=2026-10', $headers)->json('totals.headcount'));
        $this->getJson("/api/v1/reports/workforce?from=2026-10&to=2026-10&department_id={$other->id}", $headers)->assertForbidden();
        $this->getJson('/api/v1/reports/payroll-cost?from=2026-10&to=2026-10', $headers)->assertForbidden();
        $this->getJson('/api/v1/reports/export?type=payroll&from=2026-10&to=2026-10', $headers)->assertForbidden();
    }

    public function test_employees_without_report_permission_are_blocked(): void
    {
        $this->getJson('/api/v1/reports/workforce?from=2026-10&to=2026-10', $this->as('employee@qlns.local', 'Employee@123'))->assertForbidden();
    }

    public function test_range_is_limited_and_validated(): void
    {
        $this->getJson('/api/v1/reports/workforce?from=2024-01&to=2026-10', $this->hr())->assertStatus(422);
        $this->getJson('/api/v1/reports/workforce?from=2026-10&to=2026-09', $this->hr())->assertStatus(422);
    }

    public function test_results_are_cached_until_refresh(): void
    {
        $dept = Department::create(['name' => 'Phong Cache', 'code' => 'RP-C']);
        $this->employee($dept);
        $url = "/api/v1/reports/workforce?from=2026-10&to=2026-10&department_id={$dept->id}";
        $this->assertSame(1, $this->getJson($url, $this->hr())->json('totals.headcount'));

        $this->employee($dept);
        $this->assertSame(1, $this->getJson($url, $this->hr())->json('totals.headcount')); // từ cache
        $this->assertSame(2, $this->getJson($url.'&fresh=1', $this->hr())->json('totals.headcount')); // làm mới
    }

    public function test_excel_export_downloads_a_spreadsheet(): void
    {
        $response = $this->get('/api/v1/reports/export?type=workforce&from=2026-09&to=2026-10', $this->hr());

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('bao-cao-workforce-2026-09-den-2026-10.xlsx', $response->headers->get('Content-Disposition'));
    }
}
