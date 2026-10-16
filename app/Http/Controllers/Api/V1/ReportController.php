<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Module Báo cáo (Kế hoạch Ngày 51-58) — quyền report.view; chi phí lương thêm payroll.view_all.
// Phạm vi phòng ban (Manager chỉ phòng mình) xử lý trong ReportService.
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reportService)
    {
    }

    public function filters(Request $request): JsonResponse
    {
        return response()->json($this->reportService->filters($request->user()));
    }

    public function workforce(Request $request): JsonResponse
    {
        [$params, $departments] = $this->input($request);

        return response()->json($this->reportService->workforce($params, $departments));
    }

    public function attendance(Request $request): JsonResponse
    {
        [$params, $departments] = $this->input($request);

        return response()->json($this->reportService->attendance($params, $departments));
    }

    public function payrollCost(Request $request): JsonResponse
    {
        [$params, $departments] = $this->input($request);

        return response()->json($this->reportService->payrollCost($params, $departments));
    }

    public function export(Request $request, ReportExport $export): StreamedResponse
    {
        $type = $request->validate(['type' => ['required', 'in:workforce,attendance,payroll']])['type'];
        abort_if($type === 'payroll' && ! $request->user()->hasPermission('payroll.view_all'), 403, 'Bạn không có quyền xem chi phí lương.');

        [$params, $departments] = $this->input($request);
        $data = match ($type) {
            'workforce' => $this->reportService->workforce($params, $departments),
            'attendance' => $this->reportService->attendance($params, $departments),
            'payroll' => $this->reportService->payrollCost($params, $departments),
        };

        $spreadsheet = $export->toSpreadsheet($type, $data, $params);

        return response()->streamDownload(
            fn () => (new Xlsx($spreadsheet))->save('php://output'),
            sprintf('bao-cao-%s-%s-den-%s.xlsx', $type, $params['from'], $params['to']),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** @return array{0: array, 1: ?array} */
    private function input(Request $request): array
    {
        $params = $request->validate([
            'from' => ['required', 'date_format:Y-m'],
            'to' => ['required', 'date_format:Y-m', 'after_or_equal:from'],
            'group' => ['nullable', 'in:month,quarter'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'fresh' => ['nullable', 'boolean'],
        ], [
            'from.required' => 'Vui lòng chọn tháng bắt đầu',
            'to.required' => 'Vui lòng chọn tháng kết thúc',
            'to.after_or_equal' => 'Tháng kết thúc phải từ tháng bắt đầu trở đi',
        ]);

        $months = (int) round(Carbon::createFromFormat('Y-m', $params['from'])->startOfMonth()
            ->diffInMonths(Carbon::createFromFormat('Y-m', $params['to'])->startOfMonth())) + 1;
        abort_if($months > 24, 422, 'Chỉ xem được tối đa 24 tháng mỗi lần.');

        $params['fresh'] = $request->boolean('fresh');
        $departments = $this->reportService->resolveDepartments($request->user(), $params['department_id'] ?? null);

        // Manager không quản lý phòng nào (hoặc lọc phòng ngoài phạm vi) -> không có dữ liệu để xem.
        abort_if($departments === [], 403, 'Bạn chỉ xem được báo cáo của phòng ban mình quản lý.');

        return [$params, $departments];
    }
}
