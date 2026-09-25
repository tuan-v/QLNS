<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    private function makeEmployee(array $overrides = []): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYears(2),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ], $overrides));
    }

    private function makeEmployeeWithLogin(string $roleName = 'Employee', array $overrides = []): array
    {
        $user = User::create([
            'email' => 'dash-'.uniqid().'@qlns.local', 'user_name' => 'Dash User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $roleName)->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(array_merge(['user_id' => $user->id], $overrides));

        return [$employee, $user];
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
    }

    public function test_hr_sees_company_wide_scope(): void
    {
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('scope', 'company');
        $response->assertJsonPath('date', now()->toDateString());
        $response->assertJsonStructure([
            'employee_stats' => ['total', 'active', 'new_this_month'],
            'attendance_today' => ['present', 'total', 'rate'],
            'leave_pending' => ['pending_manager', 'pending_hr', 'total'],
            'attendance_trend',
            'department_distribution',
            'action_items',
        ]);
    }

    public function test_employee_sees_personal_scope(): void
    {
        [, $user] = $this->makeEmployeeWithLogin('Employee');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('scope', 'personal');
        $response->assertJsonStructure([
            'leave_balance', 'checked_in_today', 'checked_in_at', 'checked_out_at', 'my_pending_leave_count',
            'week_schedule', 'monthly_work' => ['actual_days', 'standard_days'],
            'recent_attendance', 'recent_requests', 'latest_payslip',
        ]);
        // week_schedule luôn đúng 7 ngày (T2-CN), bất kể có bản gán ca nào
        // hay không.
        $this->assertCount(7, $response->json('week_schedule'));
    }

    public function test_leave_pending_counts_split_by_manager_and_hr_correctly(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        [$noManagerEmployee] = $this->makeEmployeeWithLogin('Employee');
        $leaveType = LeaveType::create([
            'code' => 'lt-'.uniqid(), 'name' => 'Loai phep', 'annual_entitlement_days' => 12,
            'is_paid' => true, 'allow_carry_forward' => false, 'is_active' => true,
        ]);

        // Có quản lý, chưa ai duyệt -> tính vào pending_manager.
        LeaveRequest::create([
            'employee_id' => $subordinate->id, 'leave_type_id' => $leaveType->id,
            'from_date' => now()->addDays(3)->toDateString(), 'to_date' => now()->addDays(3)->toDateString(),
            'start_session' => 'full', 'end_session' => 'full', 'total_days' => 1,
            'reason' => 'x', 'status' => 'pending',
        ]);
        // Không có quản lý -> bỏ qua cấp 1, tính vào pending_hr dù status vẫn 'pending'.
        LeaveRequest::create([
            'employee_id' => $noManagerEmployee->id, 'leave_type_id' => $leaveType->id,
            'from_date' => now()->addDays(4)->toDateString(), 'to_date' => now()->addDays(4)->toDateString(),
            'start_session' => 'full', 'end_session' => 'full', 'total_days' => 1,
            'reason' => 'x', 'status' => 'pending',
        ]);
        // Đã qua cấp quản lý, chờ HR.
        LeaveRequest::create([
            'employee_id' => $subordinate->id, 'leave_type_id' => $leaveType->id,
            'from_date' => now()->addDays(5)->toDateString(), 'to_date' => now()->addDays(5)->toDateString(),
            'start_session' => 'full', 'end_session' => 'full', 'total_days' => 1,
            'reason' => 'x', 'status' => 'manager_approved',
        ]);

        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonPath('leave_pending.pending_manager', 1);
        $response->assertJsonPath('leave_pending.pending_hr', 2);
        $response->assertJsonPath('leave_pending.total', 3);
    }

    // "Cần bạn xử lý" (2026-09-25) — mỗi mục lọc theo ĐÚNG quyền của user,
    // xem DashboardService::actionItems().
    public function test_action_items_empty_when_nothing_pending(): void
    {
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $response->assertJsonPath('action_items', []);
    }

    public function test_hr_sees_all_three_action_items_when_pending(): void
    {
        [$employee] = $this->makeEmployeeWithLogin('Employee');
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $attendance = Attendance::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(), 'first_check_in_at' => now(),
        ]);
        AttendanceAdjustment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'attendance_id' => $attendance->id, 'attendance_date' => now()->toDateString(),
            'type' => 'excuse', 'reason' => 'x', 'requested_by' => $employee->user_id, 'status' => 'pending',
        ]);
        $leaveType = LeaveType::create([
            'code' => 'lt-'.uniqid(), 'name' => 'Loai phep', 'annual_entitlement_days' => 12,
            'is_paid' => true, 'allow_carry_forward' => false, 'is_active' => true,
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'from_date' => now()->addDays(3)->toDateString(), 'to_date' => now()->addDays(3)->toDateString(),
            'start_session' => 'full', 'end_session' => 'full', 'total_days' => 1,
            'reason' => 'x', 'status' => 'pending',
        ]);
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $types = collect($response->json('action_items'))->pluck('type')->all();
        $this->assertEqualsCanonicalizing(
            ['attendance_approval', 'leave_approval', 'attendance_adjustment'],
            $types,
        );
    }

    // 2026-09-25 — bug thật người dùng gặp: bấm vào "Chấm công chờ duyệt"
    // luôn đưa tới trang "Tổng hợp chấm công" ở HÔM NAY (mặc định), nhưng
    // bản ghi chờ duyệt lại từ 1 ngày TRƯỚC đó — trang chỉ xem được 1 ngày
    // tại 1 thời điểm nên không thấy đâu mà duyệt. Sửa: kèm ngày sớm nhất
    // còn tồn đọng qua `route_query.date`.
    public function test_attendance_approval_item_points_to_the_date_with_the_oldest_pending_record(): void
    {
        [$employee] = $this->makeEmployeeWithLogin('Employee');
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $twoDaysAgo = now()->subDays(2)->toDateString();
        Attendance::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'attendance_date' => $twoDaysAgo, 'first_check_in_at' => now()->subDays(2),
        ]);
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $item = collect($response->json('action_items'))->firstWhere('type', 'attendance_approval');
        $this->assertNotNull($item);
        $this->assertSame($twoDaysAgo, $item['route_query']['date']);
    }

    public function test_manager_only_sees_leave_action_item_not_attendance_ones(): void
    {
        [$manager, $managerUser] = $this->makeEmployeeWithLogin('Manager');
        [$subordinate] = $this->makeEmployeeWithLogin('Employee', ['manager_id' => $manager->id]);
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $attendance = Attendance::create([
            'employee_id' => $subordinate->id, 'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(), 'first_check_in_at' => now(),
        ]);
        AttendanceAdjustment::create([
            'employee_id' => $subordinate->id, 'work_shift_id' => $workShift->id,
            'attendance_id' => $attendance->id, 'attendance_date' => now()->toDateString(),
            'type' => 'excuse', 'reason' => 'x', 'requested_by' => $subordinate->user_id, 'status' => 'pending',
        ]);
        $leaveType = LeaveType::create([
            'code' => 'lt-'.uniqid(), 'name' => 'Loai phep', 'annual_entitlement_days' => 12,
            'is_paid' => true, 'allow_carry_forward' => false, 'is_active' => true,
        ]);
        LeaveRequest::create([
            'employee_id' => $subordinate->id, 'leave_type_id' => $leaveType->id,
            'from_date' => now()->addDays(3)->toDateString(), 'to_date' => now()->addDays(3)->toDateString(),
            'start_session' => 'full', 'end_session' => 'full', 'total_days' => 1,
            'reason' => 'x', 'status' => 'pending',
        ]);
        $token = $this->loginAs($managerUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $types = collect($response->json('action_items'))->pluck('type')->all();
        $this->assertSame(['leave_approval'], $types);
    }

    // Bộ lọc ngày trên Dashboard (2026-09-25, theo yêu cầu người dùng) — CHỈ
    // ảnh hưởng "Tỷ lệ đi làm"/"Xu hướng chấm công"/"Phân bổ phòng ban",
    // CỐ TÌNH không đụng tới "Cần bạn xử lý"/"Đơn nghỉ phép chờ duyệt" (2 cái
    // đó luôn tính theo hiện tại, không giới hạn theo 1 ngày).
    public function test_date_param_scopes_attendance_today_but_not_action_items_or_leave_pending(): void
    {
        $threeDaysAgo = now()->subDays(3)->toDateString();
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);

        // Nhân viên A: CHỈ được gán ca đúng ngày 3 hôm trước (effective_to
        // cùng ngày) — không xuất hiện ở "hôm nay" theo bất kỳ đường nào.
        $employeeA = $this->makeEmployee();
        EmployeeShiftAssignment::create([
            'employee_id' => $employeeA->id, 'work_shift_id' => $workShift->id,
            'effective_from' => $threeDaysAgo, 'effective_to' => $threeDaysAgo,
            'work_days' => [1, 2, 3, 4, 5, 6, 7], 'status' => 'active',
        ]);
        Attendance::create([
            'employee_id' => $employeeA->id, 'work_shift_id' => $workShift->id,
            'attendance_date' => $threeDaysAgo, 'first_check_in_at' => $threeDaysAgo.' 08:00:00',
            'last_check_out_at' => $threeDaysAgo.' 17:00:00', 'actual_work_minutes' => 540,
            'status' => 'completed', 'approval_status' => 'approved',
        ]);

        // Nhân viên B: việc "cần xử lý" HIỆN TẠI, hoàn toàn KHÔNG liên quan
        // tới $threeDaysAgo — dùng riêng để không chồng lấn với nhân viên A.
        $employeeB = $this->makeEmployee();
        Attendance::create([
            'employee_id' => $employeeB->id, 'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(), 'first_check_in_at' => now(),
        ]);

        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $todayResponse = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);
        $pastResponse = $this->getJson('/api/v1/dashboard?date='.$threeDaysAgo, ['Authorization' => 'Bearer '.$token]);

        // Hôm nay: nhân viên A không được gán ca -> KHÔNG tính vào tổng
        // (nhân viên B có chấm công nhưng không có bản gán ca cho HÔM NAY,
        // "lưới an toàn" ở dailyOverview() vẫn tự thêm họ vào -> tổng = 1).
        $todayResponse->assertJsonPath('attendance_today.total', 1);
        // 3 ngày trước: đúng nhân viên A, đã hoàn tất.
        $pastResponse->assertJsonPath('date', $threeDaysAgo);
        $pastResponse->assertJsonPath('attendance_today.total', 1);
        $pastResponse->assertJsonPath('attendance_today.present', 1);
        // "Cần bạn xử lý" giống hệt nhau ở CẢ 2 lần gọi — không bị ảnh hưởng
        // bởi ?date= (nhân viên B chấm công hôm nay luôn được tính, bất kể
        // đang xem ngày nào).
        $expectedActionItems = collect($todayResponse->json('action_items'))->pluck('type')->all();
        $this->assertSame($expectedActionItems, collect($pastResponse->json('action_items'))->pluck('type')->all());
        $this->assertContains('attendance_approval', $expectedActionItems);
    }

    public function test_department_distribution_excludes_employees_not_yet_hired_on_selected_date(): void
    {
        $viewDate = now()->subDays(5)->toDateString();
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $alreadyHired = Employee::create([
            'full_name' => 'Da vao lam', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subDays(10), 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        Employee::create([
            'full_name' => 'Chua vao lam luc do', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        [, $hrUser] = $this->makeEmployeeWithLogin('HR');
        $token = $this->loginAs($hrUser->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard?date='.$viewDate, ['Authorization' => 'Bearer '.$token]);

        $row = collect($response->json('department_distribution'))->firstWhere('name', $department->name);
        $this->assertSame(1, $row['count']);
        $this->assertNotNull($alreadyHired->id);
    }

    // Dashboard cá nhân mở rộng (2026-09-25, theo yêu cầu người dùng, kèm
    // mockup cụ thể): lịch làm việc tuần này, công tháng này, bảng công gần
    // nhất, đơn của tôi, phiếu lương gần nhất.
    public function test_personal_scope_week_schedule_marks_today_and_includes_assigned_shift(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee');
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'effective_from' => now()->startOfWeek()->toDateString(),
            'work_days' => [1, 2, 3, 4, 5, 6, 7], 'status' => 'active',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $week = collect($response->json('week_schedule'));
        $this->assertCount(7, $week);
        $today = $week->firstWhere('is_today', true);
        $this->assertNotNull($today);
        $this->assertSame(now()->toDateString(), $today['date']);
        $this->assertSame('Ca test', $today['shifts'][0]['name']);
    }

    public function test_personal_scope_monthly_work_reflects_approved_attendance(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee');
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        // history() dựng dòng từ BẢN GÁN CA (giống dailyOverview()), không
        // phải trực tiếp từ attendances — phải có bản gán mới "nhìn thấy"
        // được bản ghi chấm công đúng ngày hôm nay.
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'effective_from' => now()->startOfMonth()->toDateString(),
            'work_days' => [1, 2, 3, 4, 5, 6, 7], 'status' => 'active',
        ]);
        Attendance::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(),
            'first_check_in_at' => now()->startOfDay()->addHours(8),
            'last_check_out_at' => now()->startOfDay()->addHours(17),
            'actual_work_minutes' => 480, 'status' => 'completed', 'approval_status' => 'approved',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        // JSON không giữ ".0" cho số nguyên tròn (round(1.0,2) mã hóa thành
        // 1, không phải 1.0) — assertJsonPath so KHỚP KIỂU, phải khớp đúng int.
        $response->assertJsonPath('monthly_work.actual_days', 1);
        $recent = collect($response->json('recent_attendance'));
        $this->assertSame(now()->toDateString(), $recent->first()['date']);
        // 'full' — nhãn suy diễn của history()/deriveHistoryStatus() (đủ giờ,
        // không đi muộn/về sớm), KHÁC cột Attendance::status thô ('completed').
        $this->assertSame('full', $recent->first()['status']);
    }

    public function test_personal_scope_recent_requests_merges_leave_and_adjustment(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee');
        $leaveType = LeaveType::create([
            'code' => 'lt-'.uniqid(), 'name' => 'Loai phep', 'annual_entitlement_days' => 12,
            'is_paid' => true, 'allow_carry_forward' => false, 'is_active' => true,
        ]);
        LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'from_date' => now()->addDays(2)->toDateString(), 'to_date' => now()->addDays(2)->toDateString(),
            'start_session' => 'full', 'end_session' => 'full', 'total_days' => 1,
            'reason' => 'x', 'status' => 'approved',
        ]);
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        AttendanceAdjustment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $workShift->id,
            'attendance_date' => now()->addDays(1)->toDateString(),
            'type' => 'extra_shift', 'reason' => 'x', 'requested_by' => $employee->user_id, 'status' => 'pending',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        $kinds = collect($response->json('recent_requests'))->pluck('kind')->all();
        $this->assertEqualsCanonicalizing(['leave', 'adjustment'], $kinds);
    }

    public function test_personal_scope_latest_payslip_only_counts_closed_or_paid_periods(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin('Employee');
        $admin = User::where('email', 'admin@qlns.local')->firstOrFail();
        $processingPayroll = Payroll::create([
            'period_month' => now()->subMonth()->month, 'period_year' => now()->subMonth()->year,
            'status' => 'processing', 'currency' => 'VND', 'created_by' => $admin->id,
        ]);
        PayrollDetail::create([
            'payroll_id' => $processingPayroll->id, 'employee_id' => $employee->id,
            'base_salary' => 10000000, 'standard_work_days' => 22, 'actual_work_days' => 22,
            'net_salary' => 9000000,
        ]);
        $closedPayroll = Payroll::create([
            'period_month' => now()->subMonths(2)->month, 'period_year' => now()->subMonths(2)->year,
            'status' => 'closed', 'currency' => 'VND', 'created_by' => $admin->id,
        ]);
        PayrollDetail::create([
            'payroll_id' => $closedPayroll->id, 'employee_id' => $employee->id,
            'base_salary' => 10000000, 'standard_work_days' => 22, 'actual_work_days' => 20,
            'net_salary' => 8500000,
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$token]);

        // Kỳ 'processing' KHÔNG được tính là "đã có phiếu lương" — chỉ kỳ
        // 'closed'/'paid' mới hợp lệ (HR có thể còn sửa số liệu kỳ đang xử lý).
        $response->assertJsonPath('latest_payslip.period_month', $closedPayroll->period_month);
        $response->assertJsonPath('latest_payslip.net_salary', '8500000.00');
    }
}
