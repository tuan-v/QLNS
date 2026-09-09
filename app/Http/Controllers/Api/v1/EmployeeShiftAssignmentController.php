<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeShiftAssignment\StoreEmployeeShiftAssignmentRequest;
use App\Http\Requests\EmployeeShiftAssignment\UpdateEmployeeShiftAssignmentRequest;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Services\EmployeeShiftAssignmentService;
use Illuminate\Http\JsonResponse;

class EmployeeShiftAssignmentController extends Controller
{
    public function __construct(private readonly EmployeeShiftAssignmentService $employeeShiftAssignmentService)
    {
    }

    public function index(Employee $employee): JsonResponse
    {
        return response()->json($this->employeeShiftAssignmentService->listForEmployee($employee));
    }

    public function store(StoreEmployeeShiftAssignmentRequest $request, Employee $employee): JsonResponse
    {
        $assignment = $this->employeeShiftAssignmentService->create($employee, $request->validated());
        $assignment->load('workShift');

        return response()->json($assignment, 201);
    }

    public function update(UpdateEmployeeShiftAssignmentRequest $request, Employee $employee, EmployeeShiftAssignment $shiftAssignment): JsonResponse
    {
        if ($shiftAssignment->employee_id !== $employee->id) {
            abort(404);
        }

        $assignment = $this->employeeShiftAssignmentService->update($employee, $shiftAssignment, $request->validated());
        $assignment->load('workShift');

        return response()->json($assignment);
    }

    public function destroy(Employee $employee, EmployeeShiftAssignment $shiftAssignment): JsonResponse
    {
        if ($shiftAssignment->employee_id !== $employee->id) {
            abort(404);
        }

        $this->employeeShiftAssignmentService->delete($shiftAssignment);

        return response()->json(null, 204);
    }
}
