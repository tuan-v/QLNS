<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\EmployeeJourneyService;
use Illuminate\Http\JsonResponse;

// Tab "Hành trình" của nhân viên — xem EmployeeJourneyService. Quyền employee.journey.
class EmployeeJourneyController extends Controller
{
    public function __construct(private readonly EmployeeJourneyService $journeyService)
    {
    }

    public function show(Employee $employee): JsonResponse
    {
        return response()->json(['data' => $this->journeyService->build($employee)]);
    }
}
