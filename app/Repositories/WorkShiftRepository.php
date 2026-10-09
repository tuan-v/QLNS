<?php

namespace App\Repositories;

use App\Models\WorkShift;
use Illuminate\Pagination\LengthAwarePaginator;

class WorkShiftRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        // Ca OT 1 ngày (đơn "OT ngày khác") không phải ca để quản lý/chọn — ẩn khỏi danh sách.
        return WorkShift::query()->where('is_overtime', false)->latest()->paginate($perPage);
    }

    public function find(int $id): ?WorkShift
    {
        return WorkShift::query()->find($id);
    }

    public function create(array $data): WorkShift
    {
        return WorkShift::create($data);
    }

    public function update(WorkShift $workShift, array $data): WorkShift
    {
        $workShift->update($data);

        return $workShift;
    }

    public function delete(WorkShift $workShift): void
    {
        $workShift->delete();
    }
}
