<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

// Chỉ đọc — dùng để đổ danh sách Role vào ô chọn khi tạo tài khoản đăng nhập
// cho nhân viên (xem EmployeeAccountController). Chưa có Sửa/Xóa/Thêm Role
// qua API, việc đó thuộc màn hình Quản lý Vai trò/Phân quyền đầy đủ (chưa làm).
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Role::query()->orderBy('name')->get(['id', 'name', 'description']));
    }
}
