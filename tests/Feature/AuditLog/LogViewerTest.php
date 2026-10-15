<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Services\LogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Nhật ký hoạt động (tài liệu mục 9, API /api/v1/logs) — quyết định người dùng 2026-10-15:
// chỉ Admin xem, che CCCD/MST/số tài khoản, lưu 24 tháng, ghi cả sự kiện đăng nhập.
class LogViewerTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function as(string $email, string $password): array
    {
        $this->tokens[$email] ??= $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$email]];
    }

    private function admin(): array
    {
        return $this->as('admin@qlns.local', 'Admin@123');
    }

    public function test_only_admin_can_view_logs(): void
    {
        $this->getJson('/api/v1/logs', $this->as('hr@qlns.local', 'Hr@123456'))->assertForbidden();
        $this->getJson('/api/v1/logs', $this->admin())->assertOk()->assertJsonStructure(['data', 'meta' => ['total']]);
    }

    public function test_update_shows_only_changed_fields_with_vietnamese_labels(): void
    {
        $headers = $this->admin();
        $department = Department::create(['name' => 'Phong Cu', 'code' => 'PB-LOG']);
        $this->putJson("/api/v1/departments/{$department->id}", ['name' => 'Phong Moi', 'code' => 'PB-LOG'], $headers)->assertOk();

        $log = AuditLog::where('auditable_type', Department::class)->where('action', 'updated')->latest('id')->first();
        $detail = $this->getJson("/api/v1/logs/{$log->id}", $headers)->assertOk()->json('data');

        $this->assertSame('Phòng ban', $detail['module']);
        $this->assertSame('Phong Moi', $detail['subject']);
        $this->assertSame([['field' => 'name', 'label' => 'Tên', 'before' => 'Phong Cu', 'after' => 'Phong Moi']], $detail['changes']);
        $this->assertSame('Đổi Tên', $detail['summary']);
        $this->assertSame('admin@qlns.local', $detail['actor']['email']);
    }

    public function test_sensitive_fields_are_masked(): void
    {
        $employee = Employee::create([
            'full_name' => 'NV Log', 'company_email' => 'nvlog@qlns.local', 'hire_date' => now(), 'code' => 'NV-LOG',
            'employment_status' => 'active', 'cccd' => '001099012345',
        ]);
        EmployeeBankAccount::create(['employee_id' => $employee->id, 'bank_name' => 'VCB', 'account_number' => '0123456789', 'account_holder' => 'NV LOG']);

        $headers = $this->admin();
        $employeeLog = AuditLog::where('auditable_type', Employee::class)->where('auditable_id', $employee->id)->first();
        $bankLog = AuditLog::where('auditable_type', EmployeeBankAccount::class)->latest('id')->first();

        $snapshot = collect($this->getJson("/api/v1/logs/{$employeeLog->id}", $headers)->json('data.snapshot'))->keyBy('field');
        $this->assertSame('••••••••2345', $snapshot['cccd']['value']);
        $bank = collect($this->getJson("/api/v1/logs/{$bankLog->id}", $headers)->json('data.snapshot'))->keyBy('field');
        $this->assertSame('••••••6789', $bank['account_number']['value']);
    }

    public function test_one_operation_shares_a_request_id_and_related_changes_are_listed(): void
    {
        $headers = $this->admin();
        $this->postJson('/api/v1/departments', ['name' => 'Phong A', 'code' => 'PB-RQ1'], $headers)->assertCreated();
        $this->postJson('/api/v1/departments', ['name' => 'Phong B', 'code' => 'PB-RQ2'], $headers)->assertCreated();

        $ids = AuditLog::where('auditable_type', Department::class)->latest('id')->take(2)->pluck('request_id');
        $this->assertNotSame($ids[0], $ids[1]); // 2 lần thao tác khác nhau -> 2 mã khác nhau
    }

    public function test_login_events_are_recorded(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'hr@qlns.local', 'password' => 'SaiMatKhau-1'])->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => 'khong-co@qlns.local', 'password' => 'SaiMatKhau-1'])->assertStatus(401);
        $tokens = $this->postJson('/api/v1/auth/login', ['email' => 'hr@qlns.local', 'password' => 'Hr@123456'])->assertOk()->json();
        $this->postJson('/api/v1/auth/logout', ['refresh_token' => $tokens['refresh_token']], ['Authorization' => 'Bearer '.$tokens['access_token']])->assertSuccessful();

        $events = AuditLog::where('auditable_type', LogService::AUTH_TYPE)->orderBy('id')->get();
        $this->assertSame(['login_failed', 'login_failed', 'login', 'logout'], $events->pluck('action')->all());
        $this->assertNull($events[1]->user_id); // email không tồn tại
        $this->assertSame('khong-co@qlns.local', $events[1]->new_data['email']);

        $list = $this->getJson('/api/v1/logs?module=auth&action[]=login_failed', $this->admin())->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertSame('Đăng nhập sai', $list[0]['action_label']);
    }

    public function test_account_lock_is_recorded(): void
    {
        config(['rate_limits.enabled' => true, 'rate_limits.account_lock_attempts' => 2]);
        foreach (['10.0.0.1', '10.0.0.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/login', ['email' => 'hr@qlns.local', 'password' => 'SaiMatKhau-1'])->assertStatus(401);
        }

        $this->assertSame(
            ['login_failed', 'login_failed', 'login_locked'],
            AuditLog::where('auditable_type', LogService::AUTH_TYPE)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_filters_by_user_module_and_date(): void
    {
        $headers = $this->admin();
        Department::create(['name' => 'He thong tao', 'code' => 'PB-SYS']); // không có người dùng -> "Hệ thống"
        AuditLog::query()->update(['created_at' => now()->subDays(10)]);
        $this->postJson('/api/v1/departments', ['name' => 'Hom nay', 'code' => 'PB-TODAY'], $headers)->assertCreated();

        $today = $this->getJson('/api/v1/logs?module='.urlencode(Department::class).'&date_from='.now()->toDateString(), $headers)->json('data');
        $this->assertSame(['Hom nay'], array_column($today, 'subject'));

        $system = $this->getJson('/api/v1/logs?user_id=system&module='.urlencode(Department::class), $headers)->json('data');
        $this->assertContains('He thong tao', array_column($system, 'subject'));
        $this->assertNull($system[0]['actor']);

        $filters = $this->getJson('/api/v1/logs/filters', $headers)->assertOk()->json();
        $this->assertContains('Phòng ban', array_column($filters['modules'], 'label'));
    }

    public function test_prune_removes_logs_older_than_retention(): void
    {
        config(['audit.retention_months' => 24]);
        AuditLog::query()->delete();
        $old = AuditLog::create(['action' => 'created', 'auditable_type' => Department::class, 'auditable_id' => '1']);
        AuditLog::whereKey($old->id)->update(['created_at' => now()->subMonths(25)]);
        $recent = AuditLog::create(['action' => 'created', 'auditable_type' => Department::class, 'auditable_id' => '2']);
        AuditLog::whereKey($recent->id)->update(['created_at' => now()->subMonths(23)]);

        $this->artisan('logs:prune')->assertSuccessful();

        $this->assertSame([$recent->id], AuditLog::pluck('id')->all());
    }
}
