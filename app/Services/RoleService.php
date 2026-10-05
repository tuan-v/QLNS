<?php

namespace App\Services;

use App\Events\ResourceChanged;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Repositories\RoleRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\Realtime;

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
        // Gán tường minh (không chỉ dựa vào default của cột DB) để bản ghi trả
        // về cho frontend có sẵn cấp bậc, thay vì null cho tới lần đọc lại sau.
        $data['level'] ??= Role::DEFAULT_LEVEL;

        $role = DB::transaction(fn () => $this->roleRepository->create($data));

        ResourceChanged::dispatch('roles');

        return $role;
    }

    public function update(Role $role, array $data): Role
    {
        if ($role->isSystemAdmin()) {
            if (isset($data['name']) && $data['name'] !== $role->name) {
                throw ValidationException::withMessages(['name' => 'Không thể đổi tên vai trò Admin của hệ thống.']);
            }
            if (isset($data['level']) && (int) $data['level'] !== $role->level) {
                throw ValidationException::withMessages(['level' => 'Không thể đổi cấp bậc vai trò Admin của hệ thống.']);
            }
        }

        $role = DB::transaction(fn () => $this->roleRepository->update($role, $data));

        ResourceChanged::dispatch('roles');

        return $role;
    }

    // Xóa mềm — KHÔNG chặn cứng dù role đang có user (khôi phục được, khác hẳn
    // xóa cứng). Controller/Frontend tự cảnh báo trước bằng usersForRole().
    public function delete(Role $role): void
    {
        if ($role->isSystemAdmin()) {
            throw ValidationException::withMessages(['role' => 'Không thể xóa vai trò Admin của hệ thống.']);
        }

        $this->roleRepository->delete($role);

        ResourceChanged::dispatch('roles');
    }

    public function usersForRole(Role $role): Collection
    {
        return $this->roleRepository->usersForRole($role);
    }

    /**
     * Cấp bậc cao nhất trong các vai trò $user đang giữ (0 nếu chưa có vai trò
     * nào) — "cấp của chính mình" dùng để so khi gán vai trò cho người khác.
     */
    public function maxLevelOf(?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        return (int) $user->roles()->max('level');
    }

    /**
     * Danh sách vai trò $actor được phép gán cho NGƯỜI KHÁC.
     *
     * Luật (2026-10-01, theo yêu cầu người dùng): chỉ gán được vai trò có cấp
     * bậc THẤP HƠN cấp của chính mình — cao hơn hay ngang bằng đều chặn. Chặn
     * ngang bằng là chủ ý: ngang cấp không làm người gán mạnh thêm, nhưng cho
     * phép 1 tài khoản HR bị chiếm tự nhân bản thêm tài khoản HR khác làm cửa
     * hậu, lúc đó khóa tài khoản gốc cũng vô nghĩa (separation of duties).
     *
     * Admin (giữ "rbac.manage") được MIỄN TRỪ, gán được mọi vai trò kể cả
     * Admin: nếu chặn luôn cấp cao nhất thì mất tài khoản Admin duy nhất là
     * khóa chết hệ thống, không còn ai tạo lại được. Muốn siết cả Admin thì
     * bỏ nhánh miễn trừ này — chỉ một chỗ duy nhất.
     */
    public function assignableRolesQuery(?User $actor): Builder
    {
        $query = Role::query()->orderBy('name');

        if ($actor?->hasPermission('rbac.manage')) {
            return $query;
        }

        return $query
            ->where('level', '<', $this->maxLevelOf($actor))
            // Lưới chặn thứ hai, độc lập với cấp bậc: vai trò có quyền quản trị
            // phân quyền thì chỉ người CÓ quyền đó mới gán được, kể cả khi ai
            // đó vô tình hạ cấp bậc vai trò Admin xuống thấp.
            ->whereDoesntHave('permissions', fn ($q) => $q->where('code', 'rbac.manage'));
    }

    /** Chặn gán vai trò cao hơn/ngang cấp — xem assignableRolesQuery(). */
    public function assertCanAssign(?User $actor, array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }

        $assignableIds = $this->assignableRolesQuery($actor)->pluck('id')->all();
        $refused = Role::whereIn('id', $roleIds)
            ->whereNotIn('id', $assignableIds)
            ->pluck('name')
            ->all();

        if ($refused === []) {
            return;
        }

        throw ValidationException::withMessages([
            'role_ids' => sprintf(
                'Bạn không được gán vai trò %s vì cấp bậc cao hơn hoặc ngang bằng vai trò của bạn — hãy nhờ Quản trị hệ thống.',
                '"'.implode('", "', $refused).'"',
            ),
        ]);
    }

    /**
     * Đồng bộ toàn bộ danh sách quyền của 1 Role (sync thật — bỏ quyền không
     * còn trong $permissionIds, không chỉ cộng dồn). Chặn tự khóa hệ thống:
     * không cho gỡ "rbac.manage" khỏi Role nếu đây là Role DUY NHẤT còn giữ
     * quyền đó (sẽ không còn ai quản lý được Phân quyền qua giao diện nữa).
     */
    public function syncPermissions(Role $role, array $permissionIds, User $actor): Role
    {
        if ($role->isSystemAdmin()) {
            throw ValidationException::withMessages([
                'permission_ids' => 'Vai trò Admin luôn có toàn bộ quyền — không thể thêm hoặc bỏ quyền của vai trò này.',
            ]);
        }

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

        // Quyền của họ vừa đổi — phiên đang mở tự nạp lại menu/quyền, không F5.
        foreach ($affectedUsers as $user) {
            Realtime::forUser((int) $user->id, 'account');
        }

        ResourceChanged::dispatch('roles');

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
