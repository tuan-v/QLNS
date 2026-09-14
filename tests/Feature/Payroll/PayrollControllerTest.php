<?php

namespace Tests\Feature\Payroll;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature Test cho tầng HTTP (Route + Controller + phân quyền) của Payroll —
// khác PayrollTest.php (gọi thẳng PayrollService, tập trung vào ĐÚNG công
// thức tính tiền). File này KHÔNG lặp lại các phép tính đã kiểm ở đó, chỉ
// nhắm vào: định tuyến, middleware permission, mã trạng thái HTTP, cấu trúc
// JSON trả về — đúng tinh thần tách Unit/Feature đã dùng cho Attendance/Leave
// (LeaveRequestServiceCalculationTest vs LeaveRequestTest).
class PayrollControllerTest extends TestCase
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

    private function makeEmployeeWithContract(array $employeeOverrides = []): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        $employee = Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => '2025-01-01',
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
            'employment_status' => 'active',
        ], $employeeOverrides));

        EmployeeContract::create([
            'employee_id' => $employee->id,
            'contract_number' => 'HD-'.uniqid(),
            'contract_type' => 'chinh_thuc',
            'start_date' => '2025-01-01',
            'agreed_salary' => 10_000_000,
            'insurance_salary' => 10_000_000,
            'status' => 'active',
        ]);

        return $employee;
    }

    /* --------------------------------- Xac thuc/phan quyen --------------------------------- */

    public function test_unauthenticated_cannot_list_payrolls(): void
    {
        $response = $this->getJson('/api/v1/payrolls');

        $response->assertStatus(401);
    }

    public function test_employee_cannot_generate_payroll(): void
    {
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $response = $this->postJson('/api/v1/payrolls/generate', ['month' => 1, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_cannot_list_payrolls(): void
    {
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $response = $this->getJson('/api/v1/payrolls', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_cannot_generate_payroll(): void
    {
        // Manager chi co payroll.view_own (xem phieu luong cua chinh minh),
        // khong co payroll.manage/payroll.view_all.
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->postJson('/api/v1/payrolls/generate', ['month' => 1, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_cannot_close_payroll(): void
    {
        $this->makeEmployeeWithContract();
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $generated = $this->postJson('/api/v1/payrolls/generate', ['month' => 2, 'year' => 2030], [
            'Authorization' => 'Bearer '.$hrToken,
        ]);
        $payrollId = $generated->json('data.id');

        $managerToken = $this->loginAs('manager@qlns.local', 'Manager@123');
        $response = $this->postJson('/api/v1/payrolls/'.$payrollId.'/close', [], [
            'Authorization' => 'Bearer '.$managerToken,
        ]);

        $response->assertStatus(403);
    }

    /* --------------------------------------- generate --------------------------------------- */

    public function test_hr_can_generate_payroll(): void
    {
        $employee = $this->makeEmployeeWithContract();
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->postJson('/api/v1/payrolls/generate', ['month' => 3, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201)->assertJsonStructure([
            'data' => ['id', 'period_month', 'period_year', 'status', 'total_payroll_amount'],
        ]);
        $response->assertJsonPath('data.status', 'processing');
        // generate() cố tình KHÔNG trả kèm "details" (payroll có thể có hàng
        // trăm nhân viên, trả hết ngay lúc tạo tốn băng thông không cần thiết)
        // — khác show() (mục đích xem chi tiết nên tải kèm). Xác nhận dữ liệu
        // thật đã tạo bằng cách đọc thẳng DB thay vì qua response.
        $this->assertDatabaseHas('payroll_details', ['employee_id' => $employee->id]);
    }

    public function test_admin_can_generate_payroll(): void
    {
        $this->makeEmployeeWithContract();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/payrolls/generate', ['month' => 4, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
    }

    public function test_generate_requires_month_and_year(): void
    {
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->postJson('/api/v1/payrolls/generate', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['month', 'year']);
    }

    public function test_generate_rejects_month_out_of_range(): void
    {
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->postJson('/api/v1/payrolls/generate', ['month' => 13, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('month');
    }

    public function test_generating_duplicate_period_returns_422(): void
    {
        $this->makeEmployeeWithContract();
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $this->postJson('/api/v1/payrolls/generate', ['month' => 5, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(201);

        $response = $this->postJson('/api/v1/payrolls/generate', ['month' => 5, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('period');
    }

    /* ----------------------------------- index / show ----------------------------------- */

    public function test_hr_can_list_payrolls(): void
    {
        $this->makeEmployeeWithContract();
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $this->postJson('/api/v1/payrolls/generate', ['month' => 6, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response = $this->getJson('/api/v1/payrolls', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_hr_can_view_payroll_detail_with_nested_employee_info(): void
    {
        $employee = $this->makeEmployeeWithContract();
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $generated = $this->postJson('/api/v1/payrolls/generate', ['month' => 7, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $payrollId = $generated->json('data.id');

        $response = $this->getJson('/api/v1/payrolls/'.$payrollId, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.details.0.employee.id', $employee->id);
    }

    public function test_show_returns_404_for_nonexistent_payroll(): void
    {
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->getJson('/api/v1/payrolls/999999', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(404);
    }

    /* ----------------------------------- close / mark-paid ----------------------------------- */

    public function test_hr_can_close_and_then_mark_payroll_as_paid(): void
    {
        $this->makeEmployeeWithContract();
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $generated = $this->postJson('/api/v1/payrolls/generate', ['month' => 8, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $payrollId = $generated->json('data.id');

        $closed = $this->postJson('/api/v1/payrolls/'.$payrollId.'/close', [], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $closed->assertStatus(200)->assertJsonPath('data.status', 'closed');

        $paid = $this->postJson('/api/v1/payrolls/'.$payrollId.'/mark-paid', [], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $paid->assertStatus(200)->assertJsonPath('data.status', 'paid');
    }

    public function test_closing_an_already_closed_payroll_returns_422(): void
    {
        $this->makeEmployeeWithContract();
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $generated = $this->postJson('/api/v1/payrolls/generate', ['month' => 9, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $payrollId = $generated->json('data.id');
        $this->postJson('/api/v1/payrolls/'.$payrollId.'/close', [], ['Authorization' => 'Bearer '.$token]);

        $response = $this->postJson('/api/v1/payrolls/'.$payrollId.'/close', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422);
    }

    public function test_marking_as_paid_before_closing_returns_422(): void
    {
        $this->makeEmployeeWithContract();
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $generated = $this->postJson('/api/v1/payrolls/generate', ['month' => 10, 'year' => 2030], [
            'Authorization' => 'Bearer '.$token,
        ]);
        $payrollId = $generated->json('data.id');

        $response = $this->postJson('/api/v1/payrolls/'.$payrollId.'/mark-paid', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422);
    }
}
