<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLocation;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
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
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ], $overrides));
    }

    private function makeEmployeeWithLogin(): array
    {
        $user = User::create([
            'email' => 'att-'.uniqid().'@qlns.local', 'user_name' => 'Att User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        \App\Models\Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(['user_id' => $user->id]);

        return [$employee, $user];
    }

    private function makeWorkShift(string $code, string $start, string $end, array $overrides = []): WorkShift
    {
        return WorkShift::create(array_merge([
            'code' => $code,
            'name' => 'Ca '.$code,
            'start_time' => $start,
            'end_time' => $end,
            'standard_work_minutes' => 480,
            'late_grace_minutes' => 5,
            'early_leave_grace_minutes' => 5,
        ], $overrides));
    }

    private function assignShift(Employee $employee, WorkShift $workShift, array $workDays): EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'effective_from' => now()->subDay()->toDateString(),
            'work_days' => $workDays,
            'status' => 'active',
        ]);
    }

    private ?string $testMondayDate = null;

    // Luôn về đúng Thứ 2 (ISO=1), giờ cố định — mọi test chấm công đều test
    // trong 1 mốc thời gian xác định thay vì phụ thuộc ngày chạy test thật.
    // Chốt NGÀY "Thứ 2" đúng 1 lần (lúc còn ở thời gian thật, chưa time-travel)
    // rồi tái dùng cho các lần gọi sau trong CÙNG 1 test — nếu tính lại
    // "next monday" sau khi đã travelTo() rồi, Carbon sẽ tính tương đối theo
    // thời điểm ĐÃ bị time-travel, nhảy sang tận Thứ 2 tuần SAU thay vì giữ
    // nguyên ngày, làm check-in lúc 8h và check-out lúc 17h rơi vào 2 ngày
    // khác nhau — lỗi thật đã vấp khi viết test check-out.
    private function travelToMonday(string $time): Carbon
    {
        if ($this->testMondayDate === null) {
            $this->testMondayDate = Carbon::parse('next monday')->toDateString();
        }

        $target = Carbon::parse($this->testMondayDate.' '.$time);
        $this->travelTo($target);

        return $target;
    }

    public function test_check_in_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/attendances/check-in', ['method' => 'wifi']);

        $response->assertStatus(401);
    }

    public function test_check_in_without_work_shift_id_is_rejected(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    public function test_check_in_with_unassigned_work_shift_is_rejected(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        // Ca này tồn tại thật nhưng KHÔNG được gán cho nhân viên này hôm nay.
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi',
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    public function test_check_in_via_wifi_matches_location_and_creates_attendance(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'Van phong', 'method' => 'wifi',
            'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi',
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('event_type', 'check_in');
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'status' => 'pending',
            'late_minutes' => 0,
        ]);
    }

    public function test_check_in_via_gps_within_radius_matches_location(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'Cong truong', 'method' => 'gps',
            'latitude' => 10.7769, 'longitude' => 106.7009, 'radius_meters' => 200,
        ]);
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'gps',
            'work_shift_id' => $workShift->id,
            'latitude' => 10.7770,
            'longitude' => 106.7010,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'status' => 'pending']);
    }

    public function test_check_in_via_qr_matches_location(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'Le tan', 'method' => 'qr', 'qr_secret' => 'SECRET-XYZ',
        ]);
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'qr',
            'work_shift_id' => $workShift->id,
            'qr_reference' => 'SECRET-XYZ',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'status' => 'pending']);
    }

    public function test_check_in_without_matching_location_still_succeeds_but_needs_review(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        // Khong tao AttendanceLocation nao ca -> khong the khop.
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi',
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'status' => 'needs_review']);
    }

    public function test_late_check_in_calculates_late_minutes(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00', ['late_grace_minutes' => 5]);
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'VP', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        // 8:20 - ca bat dau 8:00, cham chuoc 5 phut -> tre 15 phut.
        $this->travelToMonday('08:20');
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi',
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'late_minutes' => 15]);
    }

    public function test_cannot_check_in_twice_same_shift_same_day(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'VP', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi',
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi',
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    // 1 nhân viên có nhiều ca cùng ngày (mục 14, vd Ca sáng + Ca chiều) —
    // chấm công/ra Ca sáng xong vẫn chấm công được Ca chiều, ra 2 dòng
    // attendances RIÊNG (khác bản trước — chặn cứng "đã chấm công hôm nay"
    // bất kể ca nào, không hỗ trợ được nhiều ca).
    public function test_can_check_in_to_different_shift_same_day_after_completing_first(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $morning = $this->makeWorkShift('CA001', '06:00', '12:00');
        $afternoon = $this->makeWorkShift('CA002', '13:00', '18:00');
        $this->assignShift($employee, $morning, [1, 2, 3, 4, 5]);
        $this->assignShift($employee, $afternoon, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'VP', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->travelToMonday('06:00');
        $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi', 'work_shift_id' => $morning->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('12:00');
        $this->postJson('/api/v1/attendances/check-out', [
            'method' => 'wifi', 'work_shift_id' => $morning->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('13:05');
        $response = $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi', 'work_shift_id' => $afternoon->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'work_shift_id' => $morning->id, 'status' => 'completed']);
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'work_shift_id' => $afternoon->id, 'status' => 'pending']);
        $this->assertDatabaseCount('attendances', 2);
    }

    public function test_check_out_without_check_in_is_rejected(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        $this->travelToMonday('17:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-out', [
            'method' => 'wifi',
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
    }

    public function test_check_out_calculates_actual_minutes_and_completes(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00', ['standard_work_minutes' => 480]);
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'VP', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->travelToMonday('08:00');
        $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi',
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('17:00');
        $response = $this->postJson('/api/v1/attendances/check-out', [
            'method' => 'wifi',
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'status' => 'completed',
            'actual_work_minutes' => 540,
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
        ]);
    }

    // OT tính từ lúc HẾT CA (end_time), không phải tổng giờ làm trừ giờ
    // chuẩn — ca 8h-17h (540 phút, dài hơn standard_work_minutes=480 do có
    // break_minutes/lệch giờ chuẩn), check-out ĐÚNG giờ tan ca (17:00) thì
    // overtime_minutes phải = 0 dù actual_work_minutes (540) > standard (480).
    public function test_check_out_exactly_at_shift_end_has_no_overtime_even_if_actual_exceeds_standard(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00', ['standard_work_minutes' => 480]);
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'VP', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->travelToMonday('08:00');
        $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi', 'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('17:00');
        $this->postJson('/api/v1/attendances/check-out', [
            'method' => 'wifi', 'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'actual_work_minutes' => 540,
            'overtime_minutes' => 0,
        ]);
    }

    // Chấm công RA muộn hơn giờ tan ca 45 phút -> overtime_minutes = 45,
    // đúng bằng phần thời gian SAU end_time, không phải actual - standard.
    public function test_check_out_after_shift_end_calculates_overtime_from_shift_end(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00', ['standard_work_minutes' => 480]);
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'VP', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->travelToMonday('08:00');
        $this->postJson('/api/v1/attendances/check-in', [
            'method' => 'wifi', 'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('17:45');
        $this->postJson('/api/v1/attendances/check-out', [
            'method' => 'wifi', 'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'overtime_minutes' => 45,
        ]);
    }

    public function test_today_endpoint_returns_empty_array_without_shift_assignment(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/attendances/today', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200)->assertJsonPath('data', []);
    }

    // Có 2 ca hôm nay (Ca sáng + Ca chiều) -> /today trả 2 phần tử, mỗi phần
    // tử kèm attendance=null nếu chưa chấm công ca đó.
    public function test_today_endpoint_lists_one_entry_per_assigned_shift(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $morning = $this->makeWorkShift('CA001', '06:00', '12:00');
        $afternoon = $this->makeWorkShift('CA002', '13:00', '18:00');
        $this->assignShift($employee, $morning, [1, 2, 3, 4, 5]);
        $this->assignShift($employee, $afternoon, [1, 2, 3, 4, 5]);
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/attendances/today', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame($morning->id, $data[0]['work_shift']['id']);
        $this->assertNull($data[0]['attendance']);
        $this->assertSame($afternoon->id, $data[1]['work_shift']['id']);
        $this->assertNull($data[1]['attendance']);
    }

    public function test_listing_own_history_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/attendances/me');

        $response->assertStatus(401);
    }

    public function test_hr_can_list_all_attendances(): void
    {
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->getJson('/api/v1/attendances', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
    }

    public function test_employee_cannot_list_all_attendances(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/attendances', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }
}
