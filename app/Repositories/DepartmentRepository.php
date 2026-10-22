<?php

namespace App\Repositories;

use App\Models\Department;
use Illuminate\Pagination\LengthAwarePaginator;

class DepartmentRepository
{
    public function paginate(int $perPage = 15, ?array $departmentIds = null): LengthAwarePaginator
    {
        return Department::query()
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('id', $departmentIds))
            ->with(['parent', 'manager'])
            ->latest()
            ->paginate($perPage);
    }

    public function find(int $id): ?Department
    {
        return Department::query()->with('manager')->find($id);
    }

    public function create(array $data): Department
    {
        return Department::create($data);
    }

    public function update(Department $department, array $data): Department
    {
        $department->update($data);

        return $department;
    }

    public function delete(Department $department): void
    {
        $department->delete();
    }
    public function wouldCreateCycle(int $departmentId, ?int $newParentId): bool
    {
        if ($newParentId === null) {
            return false;
        }

        if ($newParentId === $departmentId) {
            return true;
        }

        $current = $this->find($newParentId);

        while ($current) {
            if ($current->id === $departmentId) {
                return true;
            }

            $current = $current->parent_id ? $this->find($current->parent_id) : null;
        }

        return false;
    }
    // $departmentIds: phạm vi của người xem (DepartmentScopeService) — null = cả công ty. Phòng nào có phòng
    // cha nằm ngoài phạm vi thì thành gốc của cây (Trưởng phòng thấy cây bắt đầu từ phòng mình).
    public function tree(?array $departmentIds = null): \Illuminate\Support\Collection
    {
        $all = Department::query()
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('id', $departmentIds))
            ->with('manager')
            ->orderBy('name')
            ->get();

        if ($departmentIds === null) {
            return $this->buildTree($all, null);
        }

        $ids = $all->pluck('id')->all();

        return $all->filter(fn (Department $department) => ! in_array($department->parent_id, $ids, true))
            ->map(function (Department $department) use ($all) {
                $department->setRelation('children', $this->buildTree($all, $department->id));

                return $department;
            })
            ->values();
    }

    private function buildTree(\Illuminate\Support\Collection $all, ?int $parentId): \Illuminate\Support\Collection
    {
        return $all->where('parent_id', $parentId)
            ->map(function (Department $department) use ($all) {
                $department->setRelation('children', $this->buildTree($all, $department->id));

                return $department;
            })
            ->values();
    }


}
