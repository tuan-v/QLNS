<?php

namespace App\Services;

use App\Events\ResourceChanged;
use App\Models\Permission;
use App\Models\Role;
use App\Repositories\PermissionRepository;
use App\Support\Realtime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

        $admin = Role::systemAdmin();

        $permission = DB::transaction(function () use ($data, $admin) {
            $permission = $this->permissionRepository->create($data);
            // Vai trò Admin luôn có toàn bộ quyền, kể cả quyền mới tạo.
            $admin?->permissions()->attach($permission->id, ['granted_at' => now()]);

            return $permission;
        });

        $this->forgetAdminPermissionCache($admin);
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
        // Admin luôn giữ mọi quyền nên không tính — chỉ chặn khi còn vai trò KHÁC dùng.
        $admin = Role::systemAdmin();
        if ($this->permissionRepository->isAttachedToAnyRole($permission, exceptRoleId: $admin?->id)) {
            throw ValidationException::withMessages([
                'permission' => 'Không thể xóa quyền đang được gán cho vai trò nào đó — hãy bỏ gán quyền này khỏi tất cả vai trò trước.',
            ]);
        }

        DB::transaction(function () use ($permission, $admin) {
            $admin?->permissions()->detach($permission->id);
            $this->permissionRepository->delete($permission);
        });

        $this->forgetAdminPermissionCache($admin);
        ResourceChanged::dispatch('roles');
    }

    private function forgetAdminPermissionCache(?Role $admin): void
    {
        foreach ($admin?->users()->pluck('users.id') ?? [] as $userId) {
            Cache::forget("permission:{$userId}");
            Realtime::forUser((int) $userId, 'account');
        }
    }
}
