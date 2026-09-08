<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeDocument\StoreEmployeeDocumentRequest;
use App\Http\Resources\EmployeeDocumentResource;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\EmployeeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    public function __construct(private readonly EmployeeDocumentService $employeeDocumentService)
    {
    }

    public function index(Employee $employee): AnonymousResourceCollection
    {
        $documents = $this->employeeDocumentService->listForEmployee($employee);

        return EmployeeDocumentResource::collection($documents);
    }

    public function store(StoreEmployeeDocumentRequest $request, Employee $employee): JsonResponse
    {
        $document = $this->employeeDocumentService->create(
            $employee,
            $request->validated(),
            $request->file('document_file'),
            $request->user()?->id,
        );

        return (new EmployeeDocumentResource($document))->response()->setStatusCode(201);
    }

    public function destroy(Employee $employee, EmployeeDocument $document): JsonResponse
    {
        if ($document->employee_id !== $employee->id) {
            abort(404);
        }

        $this->employeeDocumentService->delete($document);

        return response()->json(null, 204);
    }

    public function download(Employee $employee, EmployeeDocument $document): StreamedResponse
    {
        // Chặn IDOR: tải tài liệu qua route lồng 2 tham số độc lập, nếu không
        // kiểm tra thì "employee" và "document" có thể không khớp nhau —
        // giống hệt lỗ hổng đã vá ở EmployeeContractController::download().
        if ($document->employee_id !== $employee->id) {
            abort(404);
        }

        // Tên tệp phải kèm đuôi: `document_name` là tên người dùng gõ (vd
        // "CCCD mặt trước"), tải xuống mà thiếu ".pdf"/".docx" thì máy người
        // dùng không biết mở bằng ứng dụng nào.
        return Storage::disk('local')->download($document->file_path, $document->downloadFileName());
    }
}
