<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeContract\StoreEmployeeContractRequest;
use App\Http\Resources\EmployeeContractResource;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Services\EmployeeContractService;
use Illuminate\Http\JsonResponse;
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

    public function store(StoreEmployeeContractRequest $request, Employee $employee): JsonResponse
    {
        $contract = $this->employeeContractService->create($employee, $request->validated(), $request->file('contract_file'));
        return (new EmployeeContractResource($contract))->response()->setStatusCode(201);
    }

    public function download(Employee $employee, EmployeeContract $contract): StreamedResponse
    {
        // kiểm tra hợp đồng có thuộc về nhân viên này không
        if ($contract->employee_id !== $employee->id) {
            abort(404);
        }

        // trả về file download
        return Storage::disk('local')->download($contract->contract_file_path, $contract->contract_number . '.pdf');
    }
}
