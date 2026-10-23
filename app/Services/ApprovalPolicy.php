<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

// Ai được duyệt đơn của ai (2026-10-23, khi cho HR tự chấm công / xin nghỉ phép):
//  - Không ai tự duyệt đơn phép, chấm công, điều chỉnh công của CHÍNH MÌNH.
//  - Đơn / chấm công của nhân viên giữ vai trò HR (không kiêm Admin) CHỈ Admin được duyệt — HR khác hay Trưởng
//    phòng đều không duyệt được (yêu cầu người dùng: "HR gửi thì chỉ cấp trên Admin được duyệt").
// Dùng chung cho LeaveApprovalService, AttendanceService::decideApproval(), AttendanceAdjustmentService::decide()
// và chọn người nhận thông báo "chờ duyệt".
class ApprovalPolicy
{
    public const ADMIN_ROLE = 'Admin';

    public const HR_ROLE = 'HR';

    public function isAdmin(?User $user): bool
    {
        return $user !== null && $user->roles->contains('name', self::ADMIN_ROLE);
    }

    /** Người gửi là HR (không kiêm Admin) -> đơn của họ chỉ Admin duyệt. */
    public function needsAdmin(Employee $subject): bool
    {
        $user = $subject->user;

        return $user !== null && $user->roles->contains('name', self::HR_ROLE) && ! $this->isAdmin($user);
    }

    public function canDecide(?User $decider, Employee $subject): bool
    {
        if ($decider === null || $decider->employee?->id === $subject->id) {
            return false;
        }

        return ! $this->needsAdmin($subject) || $this->isAdmin($decider);
    }

    /** Ném 422 kèm lý do dễ hiểu nếu người này không được duyệt đơn / chấm công của $subject. */
    public function assertCanDecide(?User $decider, Employee $subject, string $field = 'status'): void
    {
        if ($decider !== null && $decider->employee?->id === $subject->id) {
            throw ValidationException::withMessages([$field => 'Bạn không thể tự duyệt đơn / chấm công của chính mình.']);
        }
        if ($this->needsAdmin($subject) && ! $this->isAdmin($decider)) {
            throw ValidationException::withMessages([$field => 'Đơn / chấm công của nhân viên HR chỉ Admin được duyệt.']);
        }
    }

    /**
     * Người nhận thông báo "chờ duyệt": đơn của HR -> mọi Admin; còn lại -> người có $permission. Luôn bỏ chính
     * người gửi.
     *
     * @return Collection<int, User>
     */
    public function approvers(Employee $subject, string $permission): Collection
    {
        $query = $this->needsAdmin($subject)
            ? User::whereHas('roles', fn ($q) => $q->where('name', self::ADMIN_ROLE))
            : User::withPermission($permission);

        return $query->where('status', 'active')
            ->when($subject->user_id, fn ($q, $userId) => $q->where('id', '!=', $userId))
            ->get();
    }

    /** Gắn can_decide cho từng bản ghi có quan hệ employee (đơn phép, đơn điều chỉnh công) — FE ẩn nút Duyệt/Từ chối. */
    public function flagModels(EloquentCollection $records, ?User $decider): void
    {
        $records->loadMissing('employee.user.roles');
        $records->each(fn ($record) => $record->setAttribute(
            'can_decide',
            $record->employee !== null && $this->canDecide($decider, $record->employee),
        ));
    }

    /** Như flagModels() cho các dòng mảng ['employee' => Employee, ...] của Tổng hợp chấm công trong ngày. */
    public function flagRows(Collection $rows, ?User $decider): Collection
    {
        EloquentCollection::make($rows->pluck('employee'))->loadMissing('user.roles');

        return $rows->map(fn (array $row) => $row + ['can_decide' => $this->canDecide($decider, $row['employee'])]);
    }
}
