<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Services\DepartmentScopeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Route có {employee}: Trưởng phòng chỉ mở được nhân viên phòng mình quản lý (+ phòng con) — gắn 1 lần cho
// cả nhóm route thay vì kiểm từng Controller (DepartmentScopeService). Route không có {employee} đi qua.
class EnsureEmployeeInScope
{
    public function __construct(private readonly DepartmentScopeService $departmentScope)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->route('employee');

        if ($employee !== null && ! $employee instanceof Employee) {
            $employee = Employee::find($employee);
        }

        if ($employee instanceof Employee && ! $this->departmentScope->canViewEmployee($request->user(), $employee)) {
            return response()->json(['message' => 'Bạn chỉ xem được nhân viên thuộc phòng ban mình quản lý.'], 403);
        }

        return $next($request);
    }
}
