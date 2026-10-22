<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Allowance\SaveAllowanceComponentRequest;
use App\Http\Requests\Allowance\SetEmployeeAllowanceRequest;
use App\Models\Employee;
use App\Models\SalaryComponent;
use App\Services\AllowanceChangeService;
use App\Services\AllowanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Phụ cấp & trợ cấp: danh mục (trang /phu-cap) và mức của từng nhân viên (tab "Phụ cấp"). Đổi mức của nhân viên
// đi qua AllowanceChangeService: Admin áp dụng ngay, HR thành đề xuất chờ duyệt (trả `pending` + `message`).
class AllowanceController extends Controller
{
    public function __construct(
        private readonly AllowanceService $allowances,
        private readonly AllowanceChangeService $changes,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->allowances->catalog()->map(fn ($c) => $this->allowances->componentSummary($c))->values(),
            'types' => AllowanceService::TYPES,
            'methods' => AllowanceService::METHODS,
            'suggestion_keys' => AllowanceService::SUGGESTION_KEYS,
        ]);
    }

    public function store(SaveAllowanceComponentRequest $request): JsonResponse
    {
        $component = $this->allowances->createComponent($request->validated());

        return response()->json(['data' => $this->allowances->componentSummary($component)], 201);
    }

    public function update(SaveAllowanceComponentRequest $request, SalaryComponent $salaryComponent): JsonResponse
    {
        $component = $this->allowances->updateComponent($salaryComponent, $request->validated());

        return response()->json(['data' => $this->allowances->componentSummary($component)]);
    }

    public function destroy(SalaryComponent $salaryComponent): JsonResponse
    {
        $this->allowances->deleteComponent($salaryComponent);

        return response()->json(['message' => 'Đã xóa khoản phụ cấp.']);
    }

    public function applyToAll(Request $request, SalaryComponent $salaryComponent): JsonResponse
    {
        $data = $request->validate(['effective_from' => ['required', 'date']], ['effective_from.required' => 'Vui lòng chọn ngày áp dụng']);
        $result = $this->changes->applySuggestedToAll($request->user(), $salaryComponent, $data['effective_from']);

        return response()->json(['count' => $result['done']] + $result);
    }

    // Tab "Theo nhân viên": bảng nhân viên × khoản.
    public function employees(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'contract_type' => ['nullable', 'in:chinh_thuc,thu_viec,thuc_tap'],
            'only_differs' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->allowances->employeeMatrix($filters));
    }

    // Đặt 1 khoản cho nhiều nhân viên: theo mức gợi ý của từng người / cùng 1 mức / không hưởng.
    public function bulkSet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'salary_component_id' => ['required', 'integer', 'exists:salary_components,id'],
            'mode' => ['required', 'in:suggested,amount,none'],
            'amount' => ['required_if:mode,amount', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'effective_from' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'employee_ids.required' => 'Vui lòng chọn nhân viên',
            'amount.required_if' => 'Vui lòng nhập số tiền',
            'effective_from.required' => 'Vui lòng chọn ngày áp dụng',
        ]);

        $result = $this->changes->bulkSet(
            $request->user(),
            $data['employee_ids'],
            SalaryComponent::findOrFail($data['salary_component_id']),
            $data['mode'],
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['effective_from'],
            $data['note'] ?? null,
        );

        return response()->json($result);
    }

    public function forEmployee(Employee $employee): JsonResponse
    {
        return response()->json($this->allowances->forEmployee($employee));
    }

    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        abort_if(! $employee, 404, 'Tài khoản này chưa liên kết với hồ sơ nhân viên nào.');

        return response()->json($this->allowances->currentForEmployee($employee));
    }

    public function set(SetEmployeeAllowanceRequest $request, Employee $employee): JsonResponse
    {
        $data = $request->validated();
        $result = $this->changes->set(
            $request->user(),
            $employee,
            SalaryComponent::findOrFail($data['salary_component_id']),
            (float) $data['amount'],
            $data['effective_from'],
            $data['note'] ?? null,
        );

        return response()->json($result + $this->allowances->forEmployee($employee));
    }

    public function applySuggested(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate(['effective_from' => ['required', 'date']], ['effective_from.required' => 'Vui lòng chọn ngày áp dụng']);
        $result = $this->changes->applySuggested($request->user(), $employee, $data['effective_from']);

        return response()->json(['count' => $result['done']] + $result + $this->allowances->forEmployee($employee));
    }
}
