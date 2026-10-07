<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Danh mục loại phép — chỉ đọc, không có Sửa/Xóa/Thêm qua API (nạp qua
// LeaveTypeSeeder.php, xem CODE_MAP mục 19). Dùng để đổ dropdown khi nhân
// viên tạo đơn xin nghỉ phép (mục 22).
class LeaveTypeController extends Controller
{
    // Thực tập sinh chỉ thấy các loại nghỉ được phép xin (allow_intern) — LeaveRequestService
    // cũng chặn lại ở backend.
    public function index(Request $request): JsonResponse
    {
        $isIntern = $request->user()?->employee?->employment_status === 'intern';

        return response()->json(
            LeaveType::query()->active()
                ->when($isIntern, fn ($query) => $query->where('allow_intern', true))
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'annual_entitlement_days', 'is_paid', 'allow_intern']),
        );
    }
}
