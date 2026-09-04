<?php

namespace App\Repositories;

use App\Models\Department;
use Illuminate\Pagination\LengthAwarePaginator;

class DepartmentRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Department::query()->with('parent')->latest()->paginate($perPage);
    }

    public function find(int $id): ?Department
    {
        return Department::query()->find($id);
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
    public function tree(): \Illuminate\Support\Collection
    {
        $all = Department::query()->orderBy('name')->get();

        return $this->buildTree($all, null);
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
