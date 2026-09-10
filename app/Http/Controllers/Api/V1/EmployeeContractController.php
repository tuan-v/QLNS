<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeContract\StoreEmployeeContractRequest;
use App\Http\Resources\EmployeeContractResource;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Services\EmployeeContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeContractController extends Controller
{
    public function __construct(private readonly EmployeeContractService $employeeContractService)
    {
    }

    public function index(Employee $employee): AnonymousResourceCollection
    {
        $contracts = $this->employeeContractService->listForEmployee($employee);
        return EmployeeContractResource::collection($contracts);
    }

    // Hợp đồng của CHÍNH người đang đăng nhập — cùng lý do không gắn
    // permission:employee.view như EmployeeController::me(), xem route riêng.
    public function mine(Request $request): AnonymousResourceCollection
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return EmployeeContractResource::collection($this->employeeContractService->listForEmployee($employee));
    }

    public function store(StoreEmployeeContractRequest $request, Employee $employee): JsonResponse
    {
        $contract = $this->employeeContractService->create($employee, $request->validated(), $request->file('contract_file'));
        return (new EmployeeContractResource($contract))->response()->setStatusCode(201);
    }

    public function download(Request $request, Employee $employee, EmployeeContract $contract): StreamedResponse
    {
        // kiểm tra hợp đồng có thuộc về nhân viên này không
        if ($contract->employee_id !== $employee->id) {
            abort(404);
        }

        // Route này không gắn permission:employee.view (xem routes/api/v1/employees.php)
        // vì còn phục vụ luôn nhân viên tải hợp đồng của CHÍNH họ — tự kiểm
        // tra "chính mình HOẶC có employee.view" ở đây thay cho middleware.
        $isOwnRecord = $request->user()->employee?->id === $employee->id;
        abort_unless($isOwnRecord || $request->user()->hasPermission('employee.view'), 403);

        // trả về file download
        return Storage::disk('local')->download($contract->contract_file_path, $contract->contract_number . '.pdf');
    }
}
