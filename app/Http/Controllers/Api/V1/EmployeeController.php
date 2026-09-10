<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Employee\UpdateMyProfileRequest;
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
        $perPage = (int) $request->input('per_page', 15);

        return EmployeeResource::collection($this->employeeService->list($filters, $perPage));
    }
    public function stats(): JsonResponse
    {
        return response()->json($this->employeeService->stats());
    }
    // Hồ sơ của CHÍNH người đang đăng nhập — cố tình không gắn middleware
    // permission:employee.view (route riêng, xem routes/api/v1/employees.php):
    // xem hồ sơ của bản thân là quyền mặc định của mọi tài khoản, không nên
    // phụ thuộc vào mã quyền "xem toàn bộ nhân viên" (khác nhau về bản chất —
    // 1 role tương lai không có employee.view vẫn phải xem được hồ sơ chính họ).
    public function me(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return (new EmployeeResource($employee))->response();
    }
    // Tự sửa MỘT PHẦN hồ sơ chính mình (chỉ thông tin liên hệ — xem
    // UpdateMyProfileRequest) — cùng không gắn permission:employee.update
    // như me(), tái dùng nguyên EmployeeService::update() (chỉ cập nhật field
    // có trong $data, không đụng field khác của hồ sơ).
    public function updateMine(UpdateMyProfileRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        $employee = $this->employeeService->update($employee, $request->validated());

        return (new EmployeeResource($employee))->response();
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
