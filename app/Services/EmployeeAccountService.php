<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use App\Support\Realtime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeAccountService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordResetService $passwordResetService,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly RoleService $roleService,
    ) {
    }

    // Tạo tài khoản đăng nhập cho 1 nhân viên chưa có tài khoản (user_id null),
    // gán sẵn các Role được chọn (thường là gợi ý theo Chức vụ — xem
    // PositionService::suggestRole() — nhưng người gọi được sửa/thêm/bớt tự do,
    // đây chỉ là gợi ý mặc định chứ không ép buộc). Không đặt mật khẩu trần ở
    // đây: sinh 1 mật khẩu ngẫu nhiên nội bộ (không ai biết, kể cả Admin) rồi
    // tái dùng NGUYÊN luồng "quên mật khẩu" đã có (PasswordResetService) để gửi
    // email cho nhân viên tự đặt mật khẩu lần đầu — không viết lại cơ chế mới,
    // không lộ mật khẩu qua kênh không an toàn (chat/email trần).
    public function create(Employee $employee, array $roleIds, Request $request): User
    {
        if ($employee->user_id) {
            throw ValidationException::withMessages([
                'employee' => 'Nhân viên này đã có tài khoản đăng nhập.',
            ]);
        }

        if ($this->userRepository->findByEmail($employee->company_email)) {
            throw ValidationException::withMessages([
                'employee' => 'Email công ty của nhân viên đã được dùng cho 1 tài khoản khác.',
            ]);
        }

        $this->assertCanAssignRoles($request->user(), $roleIds);

        return DB::transaction(function () use ($employee, $roleIds, $request) {
            $user = $this->userRepository->create([
                'email' => $employee->company_email,
                'user_name' => $employee->full_name,
                // Mật khẩu ngẫu nhiên nội bộ — không ai đăng nhập được bằng nó,
                // chỉ tồn tại để cột NOT NULL có giá trị hash hợp lệ; nhân viên
                // tự đặt mật khẩu thật qua email requestReset() gửi bên dưới.
                'password' => Hash::make(Str::random(40)),
                'status' => 'active',
            ]);

            $employee->forceFill(['user_id' => $user->id])->save();

            $user->roles()->attach($roleIds, [
                'assigned_by' => $request->user()?->id,
                'assigned_at' => now(),
            ]);

            // Thực tập sinh: HR chọn "Employee" thì tự đổi sang vai trò "Intern".
            $this->syncInternRole($employee->fresh());

            $this->passwordResetService->requestReset($user->email, $request);

            return $user;
        });
    }

    // Vai trò có quyền quản trị phân quyền (rbac.manage, tức Admin) chỉ người CÓ
    // quyền đó mới được gán (2026-09-30, vá lỗ hổng leo thang đặc quyền): trước
    // đây HR (employee.update) tự đặt email công ty của nhân viên rồi tạo tài
    // khoản với vai trò Admin, email đặt mật khẩu về hộp thư của chính HR.
    //
    // 2026-10-01 mở rộng: không chỉ vai trò Admin, mà MỌI vai trò cấp bậc cao
    // hơn hoặc ngang bằng người đang tạo đều bị chặn — luật đặt ở
    // RoleService::assertCanAssign() để màn chọn vai trò (RoleController::index)
    // và chỗ chặn thật dùng chung đúng một định nghĩa.
    private function assertCanAssignRoles(?User $actor, array $roleIds): void
    {
        $assignsPrivilegedRole = Role::whereIn('id', $roleIds)
            ->whereHas('permissions', fn ($query) => $query->where('code', 'rbac.manage'))
            ->exists();

        if ($assignsPrivilegedRole && ! $actor?->hasPermission('rbac.manage')) {
            throw ValidationException::withMessages([
                'role_ids' => 'Bạn không có quyền gán vai trò quản trị hệ thống.',
            ]);
        }

        $this->roleService->assertCanAssign($actor, $roleIds);
    }

    // Nhân viên nghỉ việc / chấm dứt hợp đồng / bị xóa hồ sơ thì KHÓA tài khoản đăng
    // nhập (2026-09-30): access token hiện có mất hiệu lực ngay (JwtGuard kiểm tra
    // status mỗi request), refresh token bị thu hồi để không xin token mới được.
    public function deactivateAccountOf(Employee $employee): void
    {
        $user = $employee->user_id ? User::find($employee->user_id) : null;

        if ($user === null || $user->status !== 'active') {
            return;
        }

        $user->forceFill(['status' => 'inactive'])->save();
        $this->refreshTokenRepository->revokeAllForUser($user);
        Cache::forget("permission:{$user->id}");
    }

    // Tuyển lại (ký hợp đồng mới cho người từng nghỉ) -> mở lại tài khoản đã khóa.
    // Vai trò theo hợp đồng: thực tập sinh dùng vai trò "Intern" (chỉ chấm công, xin
    // nghỉ ốm/không lương, xem phiếu lương); lên thử việc/chính thức thì về "Employee".
    // Chỉ đổi qua lại giữa 2 vai trò này — không đụng tài khoản HR/Admin/Manager.
    public function syncInternRole(Employee $employee): void
    {
        $user = $employee->user_id ? User::find($employee->user_id) : null;
        $roles = Role::whereIn('name', ['Employee', 'Intern'])->pluck('id', 'name');

        if ($user === null || $roles->count() < 2) {
            return;
        }

        $isIntern = $employee->employment_status === 'intern';
        [$from, $to] = $isIntern ? [$roles['Employee'], $roles['Intern']] : [$roles['Intern'], $roles['Employee']];
        $current = $user->roles()->pluck('roles.id');

        if (! $current->contains($from) || $current->diff([$from, $to])->isNotEmpty()) {
            return;
        }

        $user->roles()->detach($from);
        $user->roles()->syncWithoutDetaching([$to => ['assigned_at' => now()]]);
        Cache::forget("permission:{$user->id}");
        Realtime::forUser((int) $user->id, 'account');
    }

    public function reactivateAccountOf(Employee $employee): void
    {
        $user = $employee->user_id ? User::find($employee->user_id) : null;

        if ($user !== null && $user->status === 'inactive') {
            $user->forceFill(['status' => 'active'])->save();
        }
    }
}
