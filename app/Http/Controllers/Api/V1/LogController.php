<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Nhật ký hoạt động — tài liệu yêu cầu mục 9 (API /api/v1/logs). Chỉ quyền system.audit_log.
class LogController extends Controller
{
    public function __construct(private readonly LogService $logService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'regex:/^(system|\d+)$/'],
            'module' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable'],
            'action.*' => ['string', 'in:'.implode(',', array_keys(LogService::ACTIONS))],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ], [
            'date_to.after_or_equal' => 'Ngày kết thúc phải từ ngày bắt đầu trở đi',
        ]);

        $page = $this->logService->list($filters, (int) ($filters['per_page'] ?? 30));

        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(AuditLog $log): JsonResponse
    {
        return response()->json(['data' => $this->logService->show($log)]);
    }

    public function filters(): JsonResponse
    {
        return response()->json($this->logService->filters());
    }
}
