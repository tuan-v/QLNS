<?php

namespace App\Repositories;

use App\Models\Role;
use Illuminate\Support\Collection;

class RoleRepository
{
    public function all(): Collection
    {
        return Role::query()->withCount('users')->orderBy('name')->get();
    }

    public function find(int $id): ?Role
    {
        return Role::query()->with('permissions')->withCount('users')->find($id);
    }

    public function create(array $data): Role
    {
        return Role::create($data);
    }

    public function update(Role $role, array $data): Role
    {
        $role->update($data);

        return $role;
    }

    public function delete(Role $role): void
    {
        $role->delete();
    }

    public function usersForRole(Role $role): Collection
    {
        return $role->users()->get();
    }

    /** Đếm số Role khác (loại trừ $exceptRoleId) hiện đang giữ 1 permission cụ thể. */
    public function countRolesWithPermission(int $permissionId, ?int $exceptRoleId = null): int
    {
        return Role::query()
            ->whereHas('permissions', fn ($q) => $q->where('permissions.id', $permissionId))
            ->when($exceptRoleId, fn ($q) => $q->whereKeyNot($exceptRoleId))
            ->count();
    }
}
