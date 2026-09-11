<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
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
            'email' => 'leave-'.uniqid().'@qlns.local', 'user_name' => 'Leave User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        \App\Models\Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee = $this->makeEmployee(['user_id' => $user->id]);

        return [$employee, $user];
    }

    private function makeLeaveType(array $overrides = []): LeaveType
    {
        return LeaveType::create(array_merge([
            'code' => 'lt-'.uniqid(),
            'name' => 'Loai phep '.uniqid(),
            'annual_entitlement_days' => 12,
            'is_paid' => true,
            'allow_carry_forward' => false,
            'is_active' => true,
        ], $overrides));
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/leave-requests', []);

        $response->assertStatus(401);
    }

    public function test_store_requires_leave_type_id_and_dates(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/leave-requests', [], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors(['leave_type_id', 'from_date', 'to_date', 'reason']);
    }

    public function test_leave_type_must_be_active(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType(['is_active' => false]);
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi phep',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('leave_type_id');
    }

    public function test_single_day_request_requires_matching_start_and_end_session(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'start_session' => 'am',
            'end_session' => 'pm',
            'reason' => 'Nghi phep',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('end_session');
    }

    // Thu 6 -> Thu 2 tuan sau (4 ngay lich: Sau, T7, CN, Hai) chi tinh 2 ngay
    // lam viec (Sau + Hai) — T7/CN khong tinh vao phep (xac nhan nghiep vu
    // Ngay 36).
    public function test_total_days_excludes_weekend(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $friday = Carbon::parse('next friday');
        $monday = $friday->copy()->addDays(3);

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $friday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi le',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('total_days', 2);
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'total_days' => 2,
            'status' => 'pending',
        ]);
    }

    public function test_half_day_request_counts_as_half(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'start_session' => 'am',
            'end_session' => 'am',
            'reason' => 'Nghi nua ngay',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('total_days', 0.5);
    }

    // Ca ngay xin nghi roi dung vao T7+CN (khong co ngay lam viec nao) thi
    // chan luon, khong tao don 0 ngay.
    public function test_request_covering_only_weekend_is_rejected(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $saturday = Carbon::parse('next saturday');
        $sunday = $saturday->copy()->addDay();

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $saturday->toDateString(),
            'to_date' => $sunday->toDateString(),
            'reason' => 'Nghi cuoi tuan',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('to_date');
    }

    // Loại phép annual_entitlement_days=0 (ốm, thai sản, không lương, nghỉ
    // khác — như seed thật của LeaveTypeSeeder) không bị giới hạn quỹ ngày,
    // dù chưa từng có leave_balances nào (remaining lúc nào cũng = 0).
    public function test_leave_type_without_entitlement_is_not_quota_limited(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType(['annual_entitlement_days' => 0]);
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');
        $wednesday = $monday->copy()->addDays(2);

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $wednesday->toDateString(),
            'reason' => 'Nghi om 3 ngay',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'total_days' => 3,
        ]);
    }

    public function test_overlapping_leave_request_is_rejected(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $monday = Carbon::parse('next monday');
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->copy()->addDays(2)->toDateString(),
            'start_session' => 'full',
            'end_session' => 'full',
            'total_days' => 3,
            'reason' => 'Don truoc',
            'status' => 'pending',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            // Trung 2 ngay voi don truoc (thu 2 den thu 4).
            'from_date' => $monday->copy()->addDay()->toDateString(),
            'to_date' => $monday->copy()->addDays(3)->toDateString(),
            'reason' => 'Don sau, trung ngay',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('from_date');
    }

    // Đơn cũ đã bị TỪ CHỐI thì không còn chắn đường — được submit đơn mới
    // trùng đúng khoảng ngày đó.
    public function test_rejected_leave_request_does_not_block_overlapping_new_request(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $monday = Carbon::parse('next monday');
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'start_session' => 'full',
            'end_session' => 'full',
            'total_days' => 1,
            'reason' => 'Don da bi tu choi',
            'status' => 'rejected',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Xin lai dung ngay do',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
    }

    // used_days chỉ cộng lúc DUYỆT xong (mục 19) — nếu chỉ so total_days với
    // "remaining" thô thì nhiều đơn PENDING (chưa đơn nào bị trừ) cộng dồn
    // có thể vượt quỹ dù mỗi đơn riêng lẻ đều "đủ phép" lúc tạo. Phải trừ cả
    // tổng ngày đang chờ duyệt (pending_days) mới ra đúng số khả dụng.
    public function test_multiple_pending_requests_cannot_exceed_available_quota(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType(['annual_entitlement_days' => 12]);
        // Don pending co san, 10 ngay, ngay khac hoan toan (khong trung) voi
        // don se gui ben duoi.
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-16',
            'start_session' => 'full',
            'end_session' => 'full',
            'total_days' => 10,
            'reason' => 'Don pending co san',
            'status' => 'pending',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');
        // Con lai chi 2 ngay kha dung (12 - 10), nhung xin 3 ngay lam viec.
        $monday = Carbon::parse('next monday')->addWeeks(4);

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->copy()->addDays(2)->toDateString(),
            'reason' => 'Don thu 2, vuot kha dung',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('to_date');
    }

    public function test_multiple_pending_requests_within_available_quota_still_succeed(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType(['annual_entitlement_days' => 12]);
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => '2026-01-05',
            'to_date' => '2026-01-09',
            'start_session' => 'full',
            'end_session' => 'full',
            'total_days' => 5,
            'reason' => 'Don pending co san',
            'status' => 'pending',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');
        // Con lai 7 ngay kha dung (12 - 5), xin 5 ngay lam viec -> van du.
        $monday = Carbon::parse('next monday')->addWeeks(4);

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->copy()->addDays(4)->toDateString(),
            'reason' => 'Don thu 2, van trong han muc kha dung',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
    }

    public function test_request_exceeding_remaining_balance_is_rejected(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        // Chi 1 ngay phep — xin 2 ngay lam viec lien tiep se vuot quy.
        $leaveType = $this->makeLeaveType(['annual_entitlement_days' => 1]);
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');
        $tuesday = $monday->copy()->addDay();

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $tuesday->toDateString(),
            'reason' => 'Xin nghi 2 ngay',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('to_date');
    }

    // Gui don KHONG duoc tu dong tru quy phep (quyet dinh nghiep vu da chot
    // voi nguoi dung — chi tru khi duyet, Ngay 37) — used_days phai giu = 0
    // ngay sau khi tao don thanh cong.
    public function test_creating_request_does_not_deduct_balance_yet(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi 1 ngay',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => $monday->year,
            'used_days' => 0,
        ]);
    }

    public function test_employee_can_list_own_leave_requests(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi 1 ngay',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $response = $this->getJson('/api/v1/leave-requests/me', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $response->assertJsonPath('0.employee_id', $employee->id);
    }

    public function test_hr_can_list_all_leave_requests(): void
    {
        $token = $this->loginAs('hr@qlns.local', 'Hr@123456');

        $response = $this->getJson('/api/v1/leave-requests', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
    }

    public function test_employee_cannot_list_all_leave_requests(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/leave-requests', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(403);
    }

    public function test_balances_mine_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/leave-requests/balances/me');

        $response->assertStatus(401);
    }

    // Chưa từng có LeaveBalance nào cho nhân viên này -> vẫn phải thấy đúng
    // hạn mức mặc định (annual_entitlement_days), không phải báo lỗi/rỗng.
    public function test_balances_mine_shows_full_entitlement_when_no_balance_row_yet(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/leave-requests/balances/me', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $annual = collect($response->json())->firstWhere('leave_type.code', 'annual');
        $this->assertNotNull($annual);
        $this->assertEquals(12, $annual['allocated_days']);
        $this->assertEquals(0, $annual['used_days']);
        $this->assertEquals(12, $annual['remaining_days']);
    }

    public function test_balances_mine_reflects_used_days(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $annualType = LeaveType::where('code', 'annual')->first();
        LeaveBalance::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annualType->id,
            'year' => now()->year,
            'allocated_days' => 12,
            'used_days' => 5,
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/leave-requests/balances/me', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $annual = collect($response->json())->firstWhere('leave_type.code', 'annual');
        $this->assertEquals(5, $annual['used_days']);
        $this->assertEquals(7, $annual['remaining_days']);
    }

    public function test_balances_mine_shows_pending_and_available_days(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $annualType = LeaveType::where('code', 'annual')->first();
        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $annualType->id,
            'from_date' => now()->addMonth()->toDateString(),
            'to_date' => now()->addMonth()->addDays(3)->toDateString(),
            'start_session' => 'full',
            'end_session' => 'full',
            'total_days' => 4,
            'reason' => 'Don dang cho duyet',
            'status' => 'pending',
        ]);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/leave-requests/balances/me', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $annual = collect($response->json())->firstWhere('leave_type.code', 'annual');
        $this->assertEquals(12, $annual['remaining_days']);
        $this->assertEquals(4, $annual['pending_days']);
        $this->assertEquals(8, $annual['available_days']);
    }

    /* ------------------------------ Nghỉ theo giờ ----------------------------- */

    public function test_hourly_request_calculates_fraction_of_day(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        // 2 gio / 8 gio moi ngay = 0.25 ngay cong.
        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'start_session' => 'hourly',
            'end_session' => 'hourly',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'reason' => 'Di kham benh 2 tieng',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $response->assertJsonPath('total_days', 0.25);
        // SQLite (DB test) lưu TIME dạng chuỗi y nguyên input "09:00" (không
        // tự thêm giây ":00" như kiểu TIME thật của MySQL lúc chạy production).
        $this->assertDatabaseHas('leave_requests', [
            'employee_id' => $employee->id,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'total_days' => 0.25,
        ]);
    }

    public function test_hourly_request_across_two_days_is_rejected(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->copy()->addDay()->toDateString(),
            'start_session' => 'hourly',
            'end_session' => 'hourly',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'reason' => 'Sai — nghi theo gio ma khac ngay',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('to_date');
    }

    public function test_hourly_request_on_weekend_is_rejected(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = $this->makeLeaveType();
        $token = $this->loginAs($user->email, 'Secret@123');
        $saturday = Carbon::parse('next saturday');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $saturday->toDateString(),
            'to_date' => $saturday->toDateString(),
            'start_session' => 'hourly',
            'end_session' => 'hourly',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'reason' => 'Cuoi tuan khong tinh',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('to_date');
    }

    /* --------------------------- Tài liệu đính kèm ---------------------------- */

    public function test_sick_leave_requires_evidence_file(): void
    {
        [, $user] = $this->makeEmployeeWithLogin();
        $leaveType = LeaveType::where('code', 'sick')->first();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $response = $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi om khong dinh kem',
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(422)->assertJsonValidationErrors('evidence_file');
    }

    public function test_sick_leave_with_evidence_file_succeeds_and_stores_file(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = LeaveType::where('code', 'sick')->first();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $response = $this->post('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi om co dinh kem',
            'evidence_file' => UploadedFile::fake()->create('giay-kham-benh.pdf', 100, 'application/pdf'),
        ], ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(201);
        $leaveRequest = LeaveRequest::where('employee_id', $employee->id)->first();
        $this->assertNotNull($leaveRequest->evidence_file_path);
        Storage::disk('local')->assertExists($leaveRequest->evidence_file_path);
    }

    public function test_evidence_download_is_restricted_to_owner_or_hr(): void
    {
        [$employee, $user] = $this->makeEmployeeWithLogin();
        $leaveType = LeaveType::where('code', 'sick')->first();
        $token = $this->loginAs($user->email, 'Secret@123');
        $monday = Carbon::parse('next monday');

        $this->post('/api/v1/leave-requests', [
            'leave_type_id' => $leaveType->id,
            'from_date' => $monday->toDateString(),
            'to_date' => $monday->toDateString(),
            'reason' => 'Nghi om co dinh kem',
            'evidence_file' => UploadedFile::fake()->create('giay-kham-benh.pdf', 100, 'application/pdf'),
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);
        $leaveRequest = LeaveRequest::where('employee_id', $employee->id)->first();

        // Chinh minh tai duoc.
        $this->get("/api/v1/leave-requests/{$leaveRequest->id}/evidence", ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200);

        // Nhan vien khac khong lien quan thi khong tai duoc.
        [, $otherUser] = $this->makeEmployeeWithLogin();
        $otherToken = $this->loginAs($otherUser->email, 'Secret@123');
        $this->get("/api/v1/leave-requests/{$leaveRequest->id}/evidence", ['Authorization' => 'Bearer '.$otherToken])
            ->assertStatus(403);

        // HR (leave.view_all) tai duoc.
        $hrToken = $this->loginAs('hr@qlns.local', 'Hr@123456');
        $this->get("/api/v1/leave-requests/{$leaveRequest->id}/evidence", ['Authorization' => 'Bearer '.$hrToken])
            ->assertStatus(200);
    }
}
