<?php

namespace App\Repositories;

use App\Models\Position;
use Illuminate\Pagination\LengthAwarePaginator;

class PositionRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return Position::with('department')
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
