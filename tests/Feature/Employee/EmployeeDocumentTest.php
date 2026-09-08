<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
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
        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
        ], $overrides));
    }

    private function documentPayload(array $overrides = []): array
    {
        return array_merge([
            'document_type' => 'cccd',
            'document_name' => 'CCCD mat truoc',
            'document_file' => UploadedFile::fake()->create('cccd.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    public function test_unauthenticated_cannot_list_documents(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->getJson('/api/v1/employees/'.$employee->id.'/documents');

        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_cannot_create_document(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $this->documentPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_upload_document(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $this->documentPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.document_name', 'CCCD mat truoc');
        $response->assertJsonPath('data.uploaded_by', 'Quản trị hệ thống');
        $this->assertDatabaseHas('employee_documents', [
            'employee_id' => $employee->id,
            'document_type' => 'cccd',
        ]);
        $document = $employee->documents()->first();
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_document_file_is_required(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $payload = $this->documentPayload();
        unset($payload['document_file']);

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $payload, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('document_file');
    }

    public function test_document_file_rejects_disallowed_type(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $this->documentPayload([
            'document_file' => UploadedFile::fake()->create('malware.exe', 100, 'application/x-msdownload'),
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('document_file');
    }

    public function test_admin_can_upload_word_document(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $this->documentPayload([
            'document_type' => 'resume',
            'document_name' => 'So yeu ly lich',
            'document_file' => UploadedFile::fake()->create(
                'so-yeu-ly-lich.docx',
                100,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ),
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        // file_name phải giữ đuôi .docx: FilePreviewDialog.vue nhận diện loại
        // xem trước bằng chính đuôi file này, mất đuôi là mất luôn bản xem trước.
        $response->assertJsonPath('data.file_name', 'So yeu ly lich.docx');
        Storage::disk('local')->assertExists($employee->documents()->first()->file_path);
    }

    public function test_admin_can_upload_excel_document(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $this->documentPayload([
            'document_type' => 'other',
            'document_name' => 'Bang luong thang 9',
            'document_file' => UploadedFile::fake()->create(
                'bang-luong.xlsx',
                100,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ),
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.file_name', 'Bang luong thang 9.xlsx');
        Storage::disk('local')->assertExists($employee->documents()->first()->file_path);
    }

    /**
     * .doc/.xls (Office 97-2003) cố tình KHÔNG được nhận: docx-preview và
     * SheetJS không dựng được bản xem trước cho định dạng nhị phân cũ.
     */
    public function test_document_file_rejects_legacy_office_formats(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $legacyFiles = [
            UploadedFile::fake()->create('so-yeu-ly-lich.doc', 100, 'application/msword'),
            UploadedFile::fake()->create('bang-luong.xls', 100, 'application/vnd.ms-excel'),
        ];

        foreach ($legacyFiles as $legacyFile) {
            $response = $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $this->documentPayload([
                'document_file' => $legacyFile,
            ]), [
                'Authorization' => 'Bearer '.$token,
            ]);

            $response->assertStatus(422)->assertJsonValidationErrors('document_file');
        }
    }

    public function test_can_list_documents_for_employee(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $this->documentPayload(), [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/employees/'.$employee->id.'/documents', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_can_download_own_document_file(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $this->documentPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $documentId = $created->json('data.id');

        $response = $this->get('/api/v1/employees/'.$employee->id.'/documents/'.$documentId.'/download', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
    }

    public function test_download_file_name_keeps_extension(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $this->documentPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $documentId = $created->json('data.id');

        $response = $this->get('/api/v1/employees/'.$employee->id.'/documents/'.$documentId.'/download', [
            'Authorization' => 'Bearer '.$token,
        ]);

        // `document_name` là tên người dùng gõ, không kèm đuôi — tải xuống mà
        // thiếu ".pdf" thì máy người dùng không biết mở bằng ứng dụng nào.
        $response->assertDownload('CCCD mat truoc.pdf');
    }

    public function test_cannot_download_document_via_mismatched_employee(): void
    {
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employeeA->id.'/documents', $this->documentPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $documentId = $created->json('data.id');

        // Tai lieu thuoc employeeA, nhung URL lai ghep voi employeeB (IDOR)
        $response = $this->get('/api/v1/employees/'.$employeeB->id.'/documents/'.$documentId.'/download', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(404);
    }

    public function test_admin_can_delete_document(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/documents', $this->documentPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $documentId = $created->json('data.id');

        $response = $this->deleteJson('/api/v1/employees/'.$employee->id.'/documents/'.$documentId, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('employee_documents', ['id' => $documentId]);

        // Xóa mềm — file vật lý vẫn còn nguyên (khôi phục được).
        $document = \App\Models\EmployeeDocument::withTrashed()->find($documentId);
        Storage::disk('local')->assertExists($document->file_path);
    }

    public function test_cannot_delete_document_via_mismatched_employee(): void
    {
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employeeA->id.'/documents', $this->documentPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $documentId = $created->json('data.id');

        $response = $this->deleteJson('/api/v1/employees/'.$employeeB->id.'/documents/'.$documentId, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('employee_documents', ['id' => $documentId, 'deleted_at' => null]);
    }
}
