<?php

namespace App\Repositories;

use App\Models\Position;
use Illuminate\Pagination\LengthAwarePaginator;

class PositionRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        // suggestedRoles: Role gợi ý mặc định để đổ sẵn vào ô chọn Role lúc tạo
        // tài khoản đăng nhập (EmployeeForm.vue/EmployeeDetail.vue) — nạp kèm
        // luôn ở đây, tránh phải gọi API riêng cho từng Chức vụ khi người dùng
        // đổi lựa chọn trên dropdown.
        return Position::with(['department', 'suggestedRoles'])
            ->when($filters['department_id'] ?? null, fn ($query, $departmentId) => $query->where('department_id', $departmentId))
            ->latest()
            ->paginate($perPage);
    }

    public function find(int $id): ?Position
    {
        return Position::query()->with('department')->find($id);
    }

    public function create(array $data): Position
    {
        return Position::create($data);
    }

    public function update(Position $position, array $data): Position
    {
        $position->update($data);

        return $position;
    }

    public function delete(Position $position): void
    {
        $position->delete();
    }
}
