<?php

namespace Tests\Feature\Employee;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Bổ sung dữ liệu còn thiếu của hợp đồng đã tạo (tệp PDF, ngày kết thúc, ngày
// ký) — hợp đồng ĐẦU TIÊN sinh tự động lúc thêm nhân viên không có 3 thứ này
// và trước đây không có đường nào bổ sung. Xem
// EmployeeContractService::fillMissing() và mục 60 CODE_MAP.
class EmployeeContractFillMissingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->seed();
    }

    private function auth(string $email, string $password): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    /** Tạo nhân viên qua API thật để có đúng hợp đồng tự sinh (thiếu 3 trường). */
    private function makeEmployeeWithAutoContract(): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        $employee = Employee::create([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subMonth(),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
        ]);

        app(\App\Services\EmployeeContractService::class)->create($employee, [
            'contract_type' => 'thu_viec',
            'start_date' => $employee->hire_date->toDateString(),
            'agreed_salary' => 10000000,
        ]);

        return $employee;
    }

    private function contractOf(Employee $employee): EmployeeContract
    {
        return $employee->contracts()->firstOrFail();
    }

    public function test_auto_created_contract_starts_without_file_end_date_and_signed_at(): void
    {
        $contract = $this->contractOf($this->makeEmployeeWithAutoContract());

        $this->assertNull($contract->contract_file_path);
        $this->assertNull($contract->end_date);
        $this->assertNull($contract->signed_at);
    }

    public function test_hr_can_fill_in_the_missing_file_end_date_and_signed_at(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $contract = $this->contractOf($employee);

        $this->post("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
            'end_date' => now()->addMonths(2)->toDateString(),
            'signed_at' => now()->subMonth()->toDateString(),
            'contract_file' => UploadedFile::fake()->create('hop-dong.pdf', 100, 'application/pdf'),
        ], $this->auth('hr@qlns.local', 'Hr@123456'))->assertStatus(200);

        $contract->refresh();
        $this->assertNotNull($contract->contract_file_path);
        $this->assertNotNull($contract->end_date);
        $this->assertNotNull($contract->signed_at);
        Storage::disk('local')->assertExists($contract->contract_file_path);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $contract = $this->contractOf($employee);

        $this->postJson("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
            'signed_at' => now()->toDateString(),
        ])->assertStatus(401);
    }

    public function test_user_without_employee_update_permission_is_forbidden(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $contract = $this->contractOf($employee);

        $this->postJson("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
            'signed_at' => now()->toDateString(),
        ], $this->auth('manager@qlns.local', 'Manager@123'))->assertStatus(403);
    }

    // Giấy tờ pháp lý: chỉ điền được ô đang trống, không ghi đè thứ đã có.
    public function test_cannot_overwrite_a_value_that_already_exists(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $contract = $this->contractOf($employee);
        $contract->forceFill(['signed_at' => '2026-01-01'])->save();

        $this->postJson("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
            'signed_at' => '2026-02-02',
        ], $this->auth('hr@qlns.local', 'Hr@123456'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('signed_at');

        $this->assertSame('2026-01-01', $contract->fresh()->signed_at->toDateString());
    }

    // Upload nhầm bản scan là lỗi thao tác dễ xảy ra — cho thay, nhưng phải
    // nêu lý do và không được làm mất dấu vết.
    public function test_replacing_an_existing_file_requires_a_reason(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $contract = $this->contractOf($employee);
        $contract->forceFill(['contract_file_path' => 'contracts/cu.pdf'])->save();

        $this->post("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
            'contract_file' => UploadedFile::fake()->create('moi.pdf', 100, 'application/pdf'),
        ], $this->auth('hr@qlns.local', 'Hr@123456'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('replace_reason');

        $this->assertSame('contracts/cu.pdf', $contract->fresh()->contract_file_path);
    }

    public function test_replacing_with_a_reason_keeps_the_old_file_and_records_the_trail(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $contract = $this->contractOf($employee);

        // Tệp đầu tiên: không cần lý do.
        $this->post("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
            'contract_file' => UploadedFile::fake()->create('ban-nham.pdf', 100, 'application/pdf'),
        ], $this->auth('hr@qlns.local', 'Hr@123456'))->assertStatus(200);

        $oldPath = $contract->fresh()->contract_file_path;

        // Thay bằng bản đúng, có lý do.
        $this->post("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
            'contract_file' => UploadedFile::fake()->create('ban-dung.pdf', 100, 'application/pdf'),
            'replace_reason' => 'Upload nhầm bản scan của nhân viên khác',
        ], $this->auth('hr@qlns.local', 'Hr@123456'))->assertStatus(200);

        $newPath = $contract->fresh()->contract_file_path;
        $this->assertNotSame($oldPath, $newPath);

        // Tệp cũ CỐ Ý không bị xóa — còn nguyên để đối chiếu/khôi phục.
        Storage::disk('local')->assertExists($oldPath);
        Storage::disk('local')->assertExists($newPath);

        // Dấu vết riêng cho lần thay tệp: tệp cũ, tệp mới, lý do, ai làm.
        $log = \App\Models\AuditLog::where('action', 'contract_file_replaced')
            ->where('auditable_id', $contract->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($oldPath, $log->old_data['contract_file_path']);
        $this->assertSame($newPath, $log->new_data['contract_file_path']);
        $this->assertSame('Upload nhầm bản scan của nhân viên khác', $log->new_data['replace_reason']);
        $this->assertNotNull($log->user_id);
    }

    public function test_reason_is_not_required_for_the_first_upload(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $contract = $this->contractOf($employee);

        $this->post("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
            'contract_file' => UploadedFile::fake()->create('lan-dau.pdf', 100, 'application/pdf'),
        ], $this->auth('hr@qlns.local', 'Hr@123456'))->assertStatus(200);

        // Lần đầu thì không sinh dòng audit "thay tệp" nào.
        $this->assertSame(0, \App\Models\AuditLog::where('action', 'contract_file_replaced')
            ->where('auditable_id', $contract->id)->count());
    }

    public function test_end_date_must_be_after_the_contract_start_date(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $contract = $this->contractOf($employee);

        $this->postJson("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
            'end_date' => $contract->start_date->copy()->subDay()->toDateString(),
        ], $this->auth('hr@qlns.local', 'Hr@123456'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('end_date');
    }

    public function test_only_pdf_is_accepted(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $contract = $this->contractOf($employee);

        $this->post("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
            'contract_file' => UploadedFile::fake()->create('anh.png', 100, 'image/png'),
        ], $this->auth('hr@qlns.local', 'Hr@123456'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('contract_file');
    }

    public function test_sending_nothing_to_fill_is_rejected(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $contract = $this->contractOf($employee);

        $this->postJson("/api/v1/employees/{$employee->id}/contracts/{$contract->id}", [
            '_method' => 'PUT',
        ], $this->auth('hr@qlns.local', 'Hr@123456'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('contract');
    }

    // Hợp đồng không thuộc nhân viên trên URL -> 404, cùng khuôn terminate().
    public function test_contract_of_another_employee_returns_404(): void
    {
        $employee = $this->makeEmployeeWithAutoContract();
        $other = $this->makeEmployeeWithAutoContract();
        $otherContract = $this->contractOf($other);

        $this->postJson("/api/v1/employees/{$employee->id}/contracts/{$otherContract->id}", [
            '_method' => 'PUT',
            'signed_at' => now()->toDateString(),
        ], $this->auth('hr@qlns.local', 'Hr@123456'))->assertStatus(404);
    }
}
