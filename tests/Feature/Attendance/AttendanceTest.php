<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLocation;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private const IPHONE_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // Gửi tọa độ khi chấm công sẽ gọi dịch vụ tra địa chỉ bên ngoài
        // (ReverseGeocoder) — test tuyệt đối không được gọi mạng thật.
        Http::preventStrayRequests();
    }

    private function fakeNominatim(string $displayName = 'So 1 Le Loi, Quan 1, TP. Ho Chi Minh'): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['display_name' => $displayName])]);
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
        $response = $this->postJson('/api/v1/attendances/check-in', []);

        $response->assertStatus(401);
    }

    public function test_check_in_without_work_shift_id_is_rejected(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
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
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('event_type', 'check_in');
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'work_shift_id' => $workShift->id,
            'status' => 'pending',
            'late_minutes' => 0,
            // Mọi bản ghi chấm công mới đều phải chờ HR duyệt (2026-09-21).
            'approval_status' => 'pending',
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
        $this->fakeNominatim();
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
            'latitude' => 10.7770,
            'longitude' => 106.7010,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'status' => 'pending']);
        $this->assertDatabaseHas('attendance_logs', ['employee_id' => $employee->id, 'method' => 'gps']);
    }

    public function test_check_in_outside_gps_radius_needs_review(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'Cong truong', 'method' => 'gps',
            'latitude' => 10.7769, 'longitude' => 106.7009, 'radius_meters' => 200,
        ]);
        $this->fakeNominatim('Ha Noi');
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        // Hà Nội, cách công trường cả ngàn km — vẫn ghi nhận nhưng cần xem lại.
        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
            'latitude' => 21.0285,
            'longitude' => 105.8542,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'status' => 'needs_review']);
        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $employee->id,
            'attendance_location_id' => null,
            'method' => 'device',
            'address' => 'Ha Noi',
        ]);
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
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'status' => 'needs_review']);
    }

    /* ---- 2026-09-21: tự ghi IP + địa chỉ + tên thiết bị của thiết bị chấm công ---- */

    public function test_check_in_records_device_ip_address_and_device_name_automatically(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'Van phong', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $this->fakeNominatim('So 1 Le Loi, Quan 1, TP. Ho Chi Minh');
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        // Client KHÔNG gửi method — chỉ gửi tọa độ trình duyệt lấy được.
        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
            'latitude' => 10.7770,
            'longitude' => 106.7010,
            'accuracy_meters' => 25.5,
        ], ['Authorization' => 'Bearer '.$token, 'User-Agent' => self::IPHONE_USER_AGENT])->assertStatus(201);

        $log = AttendanceLog::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame('iPhone (iOS 17.2) · Safari', $log->device_name);
        $this->assertSame(self::IPHONE_USER_AGENT, $log->user_agent);
        $this->assertSame('So 1 Le Loi, Quan 1, TP. Ho Chi Minh', $log->address);
        $this->assertEqualsWithDelta(10.7770, $log->latitude, 0.00001);
        $this->assertEqualsWithDelta(106.7010, $log->longitude, 0.00001);
        // Khớp điểm Wifi qua IP dù client không nói gì về Wifi.
        $this->assertSame('wifi', $log->method);
        $this->assertNotNull($log->attendance_location_id);
    }

    public function test_check_in_without_location_permission_still_records_ip_and_device(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'Van phong', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        // Nhân viên từ chối quyền vị trí → không có tọa độ, không tra địa chỉ.
        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token, 'User-Agent' => self::IPHONE_USER_AGENT])->assertStatus(201);

        $log = AttendanceLog::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNull($log->latitude);
        $this->assertNull($log->address);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame('iPhone (iOS 17.2) · Safari', $log->device_name);
        // IP vẫn khớp Wifi công ty → không cần HR xem lại.
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'status' => 'pending']);
        Http::assertNothingSent();
    }

    public function test_check_in_succeeds_with_null_address_when_geocoding_fails(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('Service Unavailable', 503)]);
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
            'latitude' => 10.7770,
            'longitude' => 106.7010,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        // Dịch vụ tra địa chỉ sập KHÔNG được làm hỏng chấm công — tọa độ thô vẫn được lưu.
        $log = AttendanceLog::where('employee_id', $employee->id)->firstOrFail();
        $this->assertNull($log->address);
        $this->assertEqualsWithDelta(10.7770, $log->latitude, 0.00001);
    }

    public function test_check_in_with_only_one_coordinate_is_rejected(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
            'latitude' => 10.7770,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('longitude');

        $this->assertDatabaseMissing('attendances', ['employee_id' => $employee->id]);
    }

    public function test_check_in_from_ip_outside_wifi_range_without_location_needs_review(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        // Dải IP công ty khác hẳn IP của request test (127.0.0.1).
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'Van phong', 'method' => 'wifi', 'allowed_ip_cidr' => '192.168.1.0/24',
        ]);
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'status' => 'needs_review']);
        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $employee->id,
            'attendance_location_id' => null,
            'method' => 'device',
            'ip_address' => '127.0.0.1',
        ]);
    }

    public function test_check_in_prefers_qr_match_over_wifi_and_gps(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        // Cả 3 loại điểm đều khớp cùng lúc — QR (nhân viên chủ động quét) thắng.
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'Wifi VP', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        AttendanceLocation::create([
            'code' => 'DD002', 'name' => 'GPS VP', 'method' => 'gps',
            'latitude' => 10.7769, 'longitude' => 106.7009, 'radius_meters' => 200,
        ]);
        $qrLocation = AttendanceLocation::create([
            'code' => 'DD003', 'name' => 'QR le tan', 'method' => 'qr', 'qr_secret' => 'SECRET-XYZ',
        ]);
        $this->fakeNominatim();
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
            'latitude' => 10.7770,
            'longitude' => 106.7010,
            'qr_reference' => 'SECRET-XYZ',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $employee->id,
            'attendance_location_id' => $qrLocation->id,
            'method' => 'qr',
        ]);
    }

    public function test_check_out_also_records_device_info(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'Van phong', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $this->fakeNominatim('Van phong cong ty');
        $token = $this->loginAs($user->email, 'Secret@123');

        $this->travelToMonday('08:00');
        $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('17:00');
        $this->postJson('/api/v1/attendances/check-out', [
            'work_shift_id' => $workShift->id,
            'latitude' => 10.7770,
            'longitude' => 106.7010,
        ], ['Authorization' => 'Bearer '.$token, 'User-Agent' => self::IPHONE_USER_AGENT])->assertStatus(201);

        $this->assertDatabaseHas('attendance_logs', [
            'employee_id' => $employee->id,
            'event_type' => 'check_out',
            'device_name' => 'iPhone (iOS 17.2) · Safari',
            'address' => 'Van phong cong ty',
            'ip_address' => '127.0.0.1',
        ]);
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
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $response = $this->postJson('/api/v1/attendances/check-in', [
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
            'work_shift_id' => $morning->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('12:00');
        $this->postJson('/api/v1/attendances/check-out', [
            'work_shift_id' => $morning->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('13:05');
        $response = $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $afternoon->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'work_shift_id' => $morning->id, 'status' => 'completed']);
        $this->assertDatabaseHas('attendances', ['employee_id' => $employee->id, 'work_shift_id' => $afternoon->id, 'status' => 'pending']);
        $this->assertDatabaseCount('attendances', 2);
    }

    /* --------------------- Ngày 44: khung giờ chấm công VÀO --------------------- */

    // Bug thật đã gặp: gán cả Ca sáng (06:00-12:00) lẫn Ca chiều (13:00-18:00)
    // cùng ngày thì trước đây chấm công được Ca chiều lúc 6h sáng, vì
    // resolveActiveAssignment() chỉ kiểm tra ca có được gán đúng NGÀY, không
    // so giờ hiện tại với start_time/end_time của ca.
    public function test_cannot_check_in_more_than_30_minutes_before_shift_start(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '13:00', '18:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        // Ca bắt đầu 13:00, sớm nhất được phép vào lúc 12:30 -> 8h sáng còn
        // cách xa 4 tiếng rưỡi, phải bị chặn.
        $this->travelToMonday('08:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
        $this->assertDatabaseMissing('attendances', ['employee_id' => $employee->id]);
    }

    public function test_can_check_in_exactly_30_minutes_before_shift_start(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '13:00', '18:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'VP', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $this->travelToMonday('12:30');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
    }

    public function test_cannot_check_in_after_shift_has_ended(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        // Ca đã kết thúc lúc 17:00, quên chấm công cả ca -> phải dùng "Xin
        // bổ sung chấm công" (mục 17), không cho tự chấm công khống nữa.
        $this->travelToMonday('17:30');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('work_shift_id');
        $this->assertDatabaseMissing('attendances', ['employee_id' => $employee->id]);
    }

    public function test_can_check_in_exactly_at_shift_end_time(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        AttendanceLocation::create([
            'code' => 'DD001', 'name' => 'VP', 'method' => 'wifi', 'allowed_ip_cidr' => '127.0.0.1/32',
        ]);
        $this->travelToMonday('17:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-in', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
    }

    public function test_check_out_without_check_in_is_rejected(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $workShift = $this->makeWorkShift('CA001', '08:00', '17:00');
        $this->assignShift($employee, $workShift, [1, 2, 3, 4, 5]);
        $this->travelToMonday('17:00');
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/attendances/check-out', [
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
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('17:00');
        $response = $this->postJson('/api/v1/attendances/check-out', [
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
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('17:00');
        $this->postJson('/api/v1/attendances/check-out', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'actual_work_minutes' => 540,
            'overtime_minutes' => 0,
        ]);
    }

    // Chấm công RA muộn hơn giờ tan ca 45 phút -> overtime_minutes = 40 (45
    // phút thực tế SAU end_time, trừ OVERTIME_GRACE_MINUTES=5 phút ân hạn —
    // 2026-09-21, theo yêu cầu người dùng), không phải actual - standard.
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
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->travelToMonday('17:45');
        $this->postJson('/api/v1/attendances/check-out', [
            'work_shift_id' => $workShift->id,
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('attendances', [
            'employee_id' => $employee->id,
            'overtime_minutes' => 40,
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
