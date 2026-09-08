<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeTransfer\StoreEmployeeTransferRequest;
use App\Http\Resources\EmployeeTransferResource;
use App\Models\Employee;
use App\Models\EmployeeTransfer;
use App\Services\EmployeeTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeTransferController extends Controller
{
    public function __construct(private readonly EmployeeTransferService $employeeTransferService)
    {
    }

    public function index(Employee $employee): AnonymousResourceCollection
    {
        $transfers = $this->employeeTransferService->listForEmployee($employee);

        return EmployeeTransferResource::collection($transfers);
    }

    public function store(StoreEmployeeTransferRequest $request, Employee $employee): JsonResponse
    {
        $transfer = $this->employeeTransferService->create(
            $employee,
            $request->validated(),
            $request->user()->id,
            $request->file('decision_file'),
        );

        return (new EmployeeTransferResource($transfer))->response()->setStatusCode(201);
    }

    public function download(Employee $employee, EmployeeTransfer $transfer): StreamedResponse
    {
        if ($transfer->employee_id !== $employee->id) {
            abort(404);
        }

        if (! $transfer->decision_file) {
            abort(404);
        }

        return Storage::disk('local')->download($transfer->decision_file);
    }
}
