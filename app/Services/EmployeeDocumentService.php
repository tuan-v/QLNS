<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Repositories\EmployeeDocumentRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmployeeDocumentService
{
    public function __construct(private readonly EmployeeDocumentRepository $employeeDocumentRepository)
    {
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->employeeDocumentRepository->listByEmployee($employee);
    }

    public function create(Employee $employee, array $data, UploadedFile $file, ?int $uploadedBy): EmployeeDocument
    {
        $data['employee_id'] = $employee->id;
        $data['uploaded_by'] = $uploadedBy;
        // Riêng tư, giống hợp đồng lao động — không dùng disk "public".
        $data['file_path'] = $file->store('employee-documents', 'local');
        $data['file_size'] = $file->getSize();

        return DB::transaction(fn () => $this->employeeDocumentRepository->create($data));
    }

    public function delete(EmployeeDocument $document): void
    {
        // Chỉ xóa mềm — giữ nguyên file vật lý, đúng tinh thần "thùng rác" của
        // Soft Delete (khôi phục lại được), khác với việc xóa hẳn khỏi ổ đĩa.
        $this->employeeDocumentRepository->delete($document);
    }
}
