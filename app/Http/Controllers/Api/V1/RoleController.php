<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRolePermissionsRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roleService)
    {
    }

    // Chỉ đọc, gate riêng "employee.update" — dùng để đổ danh sách Role vào ô
    // chọn khi tạo tài khoản đăng nhập cho nhân viên (xem EmployeeAccountController).
    // KHÔNG đụng route này khi thêm CRUD/quản lý quyền bên dưới (gate "rbac.manage").
    public function index(): JsonResponse
    {
        return response()->json(Role::query()->orderBy('name')->get(['id', 'name', 'description']));
    }

    public function show(Role $role): JsonResponse
    {
        $role = $this->roleService->find($role->id);

        return response()->json(new RoleResource($role));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated());

        return response()->json(new RoleResource($role), 201);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roleService->update($role, $request->validated());

        return response()->json(new RoleResource($role));
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roleService->delete($role);

        return response()->json(null, 204);
    }

    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $role = $this->roleService->syncPermissions($role, $request->validated('permission_ids'), $request->user());

        return response()->json(new RoleResource($role));
    }
}
