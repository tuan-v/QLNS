<?php

namespace App\Services;

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

        return DB::transaction(fn () => $this->permissionRepository->create($data));
    }

    public function update(Permission $permission, array $data): Permission
    {
        return DB::transaction(fn () => $this->permissionRepository->update($permission, $data));
    }

    public function delete(Permission $permission): void
    {
        if ($this->permissionRepository->isAttachedToAnyRole($permission)) {
            throw ValidationException::withMessages([
                'permission' => 'Không thể xóa quyền đang được gán cho vai trò nào đó — hãy bỏ gán quyền này khỏi tất cả vai trò trước.',
            ]);
        }

        $this->permissionRepository->delete($permission);
    }
}
