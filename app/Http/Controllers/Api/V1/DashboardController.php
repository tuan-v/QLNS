<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        // ?date=YYYY-MM-DD (tùy chọn) — lọc 3 widget "Tỷ lệ đi làm"/"Xu
        // hướng chấm công"/"Phân bổ phòng ban" (2026-09-25, theo yêu cầu
        // người dùng). Không validate định dạng riêng — cùng cách
        // AttendanceController::overview() đã làm, tin tưởng
        // InputDate.vue luôn gửi đúng "YYYY-MM-DD".
        $date = $request->input('date') ?: null;

        return response()->json($this->dashboardService->forUser($request->user(), $date));
    }
}
