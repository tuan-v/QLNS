<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\DepartmentService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $departmentService)
    {
    }

    public function index(): AnonymousResourceCollection
    {
        return DepartmentResource::collection($this->departmentService->list());
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = $this->departmentService->create($request->validated());
        $department->loadMissing('manager');

        return response()->json(new DepartmentResource($department), 201);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department = $this->departmentService->update($department, $request->validated());
        $department->loadMissing('manager');

        return response()->json(new DepartmentResource($department));
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->departmentService->delete($department);

        return response()->json(null, 204);
    }
    public function tree(): JsonResponse
    {
        // Trả mảng phẳng (không bọc "data") để không phá cấu trúc frontend
        // đang đọc trực tiếp response.data làm cây phòng ban.
        return response()->json(DepartmentResource::collection($this->departmentService->tree()));
    }

}
