<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\RoleRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoleService
{
    public function __construct(private readonly RoleRepository $roleRepository)
    {
    }

    public function list(): Collection
    {
        return $this->roleRepository->all();
    }

    public function find(int $id): ?Role
    {
        return $this->roleRepository->find($id);
    }

    public function create(array $data): Role
    {
        $data['guard_name'] ??= 'api';

        return DB::transaction(fn () => $this->roleRepository->create($data));
    }

    public function update(Role $role, array $data): Role
    {
        return DB::transaction(fn () => $this->roleRepository->update($role, $data));
    }

    // Xóa mềm — KHÔNG chặn cứng dù role đang có user (khôi phục được, khác hẳn
    // xóa cứng). Controller/Frontend tự cảnh báo trước bằng usersForRole().
    public function delete(Role $role): void
    {
        $this->roleRepository->delete($role);
    }

    public function usersForRole(Role $role): Collection
    {
        return $this->roleRepository->usersForRole($role);
    }

    /**
     * Đồng bộ toàn bộ danh sách quyền của 1 Role (sync thật — bỏ quyền không
     * còn trong $permissionIds, không chỉ cộng dồn). Chặn tự khóa hệ thống:
     * không cho gỡ "rbac.manage" khỏi Role nếu đây là Role DUY NHẤT còn giữ
     * quyền đó (sẽ không còn ai quản lý được Phân quyền qua giao diện nữa).
     */
    public function syncPermissions(Role $role, array $permissionIds, User $actor): Role
    {
        $this->guardAgainstRbacLockout($role, $permissionIds);

        DB::transaction(function () use ($role, $permissionIds, $actor) {
            $role->permissions()->sync(
                collect($permissionIds)->mapWithKeys(fn (int $id) => [
                    $id => ['granted_by' => $actor->id, 'granted_at' => now()],
                ]),
            );
        });

        $affectedUsers = $this->roleRepository->usersForRole($role);
        foreach ($affectedUsers as $user) {
            Cache::forget("permission:{$user->id}");
        }
        Cache::forget("permission:{$actor->id}");

        return $this->roleRepository->find($role->id);
    }

    private function guardAgainstRbacLockout(Role $role, array $newPermissionIds): void
    {
        $rbacManage = Permission::where('code', 'rbac.manage')->first();

        if ($rbacManage === null) {
            return;
        }

        $roleCurrentlyHasIt = $role->permissions()->whereKey($rbacManage->id)->exists();
        $stillGrantedAfterSync = in_array($rbacManage->id, $newPermissionIds, true);

        if (! $roleCurrentlyHasIt || $stillGrantedAfterSync) {
            return;
        }

        $otherRolesWithIt = $this->roleRepository->countRolesWithPermission($rbacManage->id, exceptRoleId: $role->id);

        if ($otherRolesWithIt === 0) {
            throw ValidationException::withMessages([
                'permission_ids' => 'Không thể bỏ quyền "rbac.manage" khỏi vai trò này vì đây là vai trò DUY NHẤT còn giữ quyền quản lý Phân quyền — sẽ không còn ai vào được màn hình này nữa.',
            ]);
        }
    }
}
