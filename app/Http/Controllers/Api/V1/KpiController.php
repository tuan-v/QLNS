<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\KpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// KPI tháng từ công việc dự án. Phạm vi xem do KpiService::scope() quyết định theo người đăng nhập
// (HR/Admin, Trưởng phòng, Lead, chính mình) nên route chỉ cần auth:api.
class KpiController extends Controller
{
    public function __construct(private readonly KpiService $kpi)
    {
    }

    public function filters(Request $request): JsonResponse
    {
        return response()->json($this->kpi->filters($request->user()));
    }

    public function index(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->kpi->monthly($request->user(), $data['year'], $data['month'], $data));
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json($this->kpi->detail($request->user(), $employee, $data['year'], $data['month']));
    }

    private function validated(Request $request, array $rules = []): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
        ] + $rules, [
            'year.required' => 'Vui lòng chọn năm',
            'month.required' => 'Vui lòng chọn tháng',
            'month.between' => 'Tháng không hợp lệ',
        ]);
        $data['year'] = (int) $data['year'];
        $data['month'] = (int) $data['month'];

        return $data;
    }
}
