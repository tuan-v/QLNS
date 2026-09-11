<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;

// Danh mục loại phép — chỉ đọc, không có Sửa/Xóa/Thêm qua API (nạp qua
// LeaveTypeSeeder.php, xem CODE_MAP mục 19). Dùng để đổ dropdown khi nhân
// viên tạo đơn xin nghỉ phép (mục 22).
class LeaveTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            LeaveType::query()->active()->orderBy('name')->get(['id', 'code', 'name', 'annual_entitlement_days', 'is_paid']),
        );
    }
}
