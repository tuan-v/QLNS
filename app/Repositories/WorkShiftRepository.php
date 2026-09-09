<?php

namespace App\Repositories;

use App\Models\WorkShift;
use Illuminate\Pagination\LengthAwarePaginator;

class WorkShiftRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return WorkShift::query()->latest()->paginate($perPage);
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
