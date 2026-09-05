<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employeeService)
    {
    }
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->only(['search', 'department_id', 'employment_status']);

        return EmployeeResource::collection($this->employeeService->list($filters));
    }
    public function show(Employee $employee): JsonResponse
    {
        return (new EmployeeResource($employee))->response();
    }
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->employeeService->create($request->validated());

        return (new EmployeeResource($employee))->response()->setStatusCode(201);
    }
    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $employee = $this->employeeService->update($employee, $request->validated());

        return (new EmployeeResource($employee))->response();
    }
    public function destroy(Employee $employee): JsonResponse
    {
        $this->employeeService->delete($employee);

        return response()->json(null, 204);
    }
    public function uploadAvatar(Request $request, Employee $employee): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'],
        ]);

        $employee = $this->employeeService->updateAvatar($employee, $request->file('avatar'));

        return (new EmployeeResource($employee))->response();
    }
}
