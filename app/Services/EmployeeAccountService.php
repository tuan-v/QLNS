<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeAccountService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordResetService $passwordResetService,
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

            $this->passwordResetService->requestReset($user->email, $request);

            return $user;
        });
    }
}
