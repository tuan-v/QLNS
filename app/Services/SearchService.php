<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;

// Quick Search (Ctrl+K) Phase 2 — tìm THEO DỮ LIỆU THẬT (2026-09-29, theo
// yêu cầu người dùng), khác Phase 1 (resources/js/components/layout/
// QuickSearch.vue — danh sách trang TĨNH, không gọi API, xem CODE_MAP mục
// 38). Cùng khuôn DashboardService::forUser(): MỖI loại kết quả tự kiểm tra
// ĐÚNG quyền của user trước khi tìm — KHÔNG lấy hết dữ liệu rồi mới ẩn ở
// Frontend. Nhân viên thường (không có employee.view) gọi API này luôn nhận
// `data: []`, không lộ danh sách đồng nghiệp qua "cửa sau" tìm kiếm.
class SearchService
{
    private const LIMIT = 8;

    public function search(User $user, string $query): array
    {
        $results = [];

        if ($user->hasPermission('employee.view')) {
            $results = array_merge($results, $this->searchEmployees($query));
        }

        return $results;
    }

    // Query RIÊNG, KHÔNG tái dùng EmployeeRepository::paginate() — trang
    // "Nhân viên" (Employees.vue) nạp thêm manager/hợp đồng/nghỉ phép cho
    // bảng danh sách, thừa và nặng cho 1 API gợi ý gõ-tới-đâu-tìm-tới-đó;
    // paginate() cũng luôn kèm 1 query COUNT riêng không cần thiết ở đây.
    // Cùng danh sách cột tìm (full_name/code/company_email) với
    // EmployeeRepository::paginate() để hành vi tìm kiếm nhất quán giữa
    // trang "Nhân viên" và Quick Search.
    private function searchEmployees(string $query): array
    {
        return Employee::query()
            ->where(function ($q) use ($query) {
                $q->where('full_name', 'like', "%{$query}%")
                    ->orWhere('code', 'like', "%{$query}%")
                    ->orWhere('company_email', 'like', "%{$query}%");
            })
            ->with(['department', 'position'])
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Employee $employee) => [
                'type' => 'employee',
                'id' => $employee->id,
                'title' => $employee->full_name,
                'description' => collect([$employee->department?->name, $employee->position?->name])
                    ->filter()
                    ->implode(' · '),
                // Trả route NAME + params (giống hệt DashboardService::actionItems()
                // trả `route`/`route_query`) thay vì URL thô — Frontend điều hướng
                // qua router.push({name, params}), không lệ thuộc chuỗi path hardcode
                // dễ lệch nếu router đổi sau này.
                'route' => 'employee-detail',
                'route_params' => ['id' => $employee->id],
            ])
            ->all();
    }
}
