<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\DepartmentScopeService;
use App\Services\EmployeeJourneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Tab "Hành trình" của nhân viên — xem EmployeeJourneyService. Quyền employee.journey (Admin + HR) hoặc là
// Trưởng phòng của nhân viên (phòng mình + phòng con) — EmployeeJourneyService::canView(). Phần lương chỉ
// trả cho người có quyền xem lương (2026-10-22 — Trưởng phòng xem lộ trình nhưng không xem lương).
class EmployeeJourneyController extends Controller
{
    public function __construct(
        private readonly EmployeeJourneyService $journeyService,
        private readonly DepartmentScopeService $departmentScope,
    ) {
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($this->journeyService->canView($request->user(), $employee), 403, 'Bạn chỉ xem được lộ trình của nhân viên phòng mình quản lý.');

        $withSalary = $this->departmentScope->canViewSalary($request->user(), $employee->id);

        return response()->json(['data' => $this->journeyService->build($employee, $withSalary)]);
    }
}
