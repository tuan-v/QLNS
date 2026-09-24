<?php

namespace App\Repositories;

use App\Models\Permission;
use Illuminate\Support\Collection;

class PermissionRepository
{
    public function all(): Collection
    {
        return Permission::query()->orderBy('code')->get();
    }

    public function find(int $id): ?Permission
    {
        return Permission::find($id);
    }

    public function create(array $data): Permission
    {
        return Permission::create($data);
    }

    public function update(Permission $permission, array $data): Permission
    {
        $permission->update($data);

        return $permission;
    }

    public function delete(Permission $permission): void
    {
        $permission->delete();
    }

    public function isAttachedToAnyRole(Permission $permission): bool
    {
        return $permission->roles()->exists();
    }
}
