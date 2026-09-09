<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployeeAccount\StoreEmployeeAccountRequest;
use App\Models\Employee;
use App\Services\EmployeeAccountService;
use Illuminate\Http\JsonResponse;

class EmployeeAccountController extends Controller
{
    public function __construct(private readonly EmployeeAccountService $employeeAccountService)
    {
    }

    public function store(StoreEmployeeAccountRequest $request, Employee $employee): JsonResponse
    {
        $user = $this->employeeAccountService->create(
            $employee,
            $request->validated('role_ids'),
            $request,
        );

        $user->load('roles');

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'user_name' => $user->user_name,
            'status' => $user->status,
            'roles' => $user->roles->pluck('name'),
        ], 201);
    }
}
