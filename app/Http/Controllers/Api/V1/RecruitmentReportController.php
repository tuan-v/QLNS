<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentCandidate;
use App\Services\RecruitmentReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Báo cáo tuyển dụng — xem RecruitmentReportService. HR + Admin (quyền tuyển dụng).
class RecruitmentReportController extends Controller
{
    public function __construct(private readonly RecruitmentReportService $reportService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'opening_id' => ['nullable', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in([...array_keys(RecruitmentCandidate::SOURCES), 'none'])],
        ], [
            'to.after_or_equal' => 'Ngày kết thúc phải từ ngày bắt đầu trở đi',
        ]);

        return response()->json(['data' => $this->reportService->report($filters)]);
    }
}
