<?php

namespace App\Services;

use App\Events\ResourceChanged;
use App\Models\Permission;
use App\Repositories\PermissionRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PermissionService
{
    public function __construct(private readonly PermissionRepository $permissionRepository)
    {
    }

    public function list(): Collection
    {
        return $this->permissionRepository->all();
    }

    public function create(array $data): Permission
    {
        $data['guard_name'] ??= 'api';

        $permission = DB::transaction(fn () => $this->permissionRepository->create($data));

        ResourceChanged::dispatch('roles');

        return $permission;
    }

    public function update(Permission $permission, array $data): Permission
    {
        $permission = DB::transaction(fn () => $this->permissionRepository->update($permission, $data));

        ResourceChanged::dispatch('roles');

        return $permission;
    }

    public function delete(Permission $permission): void
    {
        if ($this->permissionRepository->isAttachedToAnyRole($permission)) {
            throw ValidationException::withMessages([
                'permission' => 'Không thể xóa quyền đang được gán cho vai trò nào đó — hãy bỏ gán quyền này khỏi tất cả vai trò trước.',
            ]);
        }

        $this->permissionRepository->delete($permission);

        ResourceChanged::dispatch('roles');
    }
}
