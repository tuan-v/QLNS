<?php

namespace App\Services;

use App\Events\ResourceChanged;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\ResignationRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\Realtime;

// Đơn xin nghỉ việc (2026-09-29, theo yêu cầu người dùng: nhân viên nộp đơn,
// HR/Manager mở đơn đọc đủ thông tin rồi mới duyệt/từ chối, duyệt xong trạng
// thái nhân viên TỰ chuyển "Đã nghỉ việc").
//   - Ai duyệt: có resignation.view_all (HR/Admin) duyệt mọi đơn; còn lại
//     (Manager, chỉ có resignation.approve) CHỈ duyệt/xem đơn của nhân viên
//     mình quản lý trực tiếp (employees.manager_id — tự suy từ Trưởng phòng,
//     xem ReportingLineService).
//   - Không phải đơn nào cũng cần duyệt (2026-09-30, BLLĐ 2019 Điều 35): báo
//     trước ĐỦ ngày (xem noticePolicy()) thì đơn chỉ là THÔNG BÁO — status
//     'notified', không ai duyệt, tới ngày làm việc cuối tự nghỉ việc; báo
//     thiếu ngày mới 'pending' chờ HR/Manager đồng ý.
//   - Khi nào đổi trạng thái: SAU ngày làm việc cuối (last_working_date) —
//     duyệt trước hạn thì chờ job `resignations:apply` chạy hằng ngày; duyệt
//     khi ngày đó đã qua thì áp dụng ngay (applyIfDue()).
class ResignationService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly EmployeeAccountService $employeeAccountService,
    ) {
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $employee->resignationRequests()->with('decider')->latest('id')->get();
    }

    public function list(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->visibleTo($user)
            ->with(['employee.department', 'employee.position'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereHas(
                'employee',
                fn ($eq) => $eq->where('full_name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"),
            ))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->latest('id')
            ->paginate($perPage);
    }

    public function findVisible(User $user, int $id): ResignationRequest
    {
        return $this->visibleTo($user)
            ->with([
                'employee.department', 'employee.position', 'employee.manager', 'employee.activeContract', 'decider',
            ])
            ->findOrFail($id);
    }

    // Số ngày báo trước TỐI THIỂU theo hợp đồng đang hiệu lực (BLLĐ 2019 Điều
    // 35; thử việc theo Điều 27): thử việc không cần báo trước; không xác
    // định thời hạn (không có ngày kết thúc) >= 45 ngày; xác định thời hạn
    // 12-36 tháng >= 30 ngày; dưới 12 tháng >= 3 (ngày làm việc — tính gần
    // đúng bằng 3 ngày lịch). Chưa có hợp đồng nào -> áp mức nghiêm nhất (45).
    /** @return array{required_days:int, basis:string, earliest_last_working_date:string} */
    public function noticePolicy(Employee $employee, ?Carbon $today = null): array
    {
        $today ??= now()->startOfDay();
        $contract = $employee->activeContract;

        if ($contract?->contract_type === 'thu_viec') {
            [$days, $basis] = [0, 'Hợp đồng thử việc — không cần báo trước (BLLĐ 2019 Điều 27).'];
        } elseif ($contract === null || $contract->end_date === null) {
            [$days, $basis] = [45, 'Hợp đồng không xác định thời hạn — báo trước ít nhất 45 ngày (BLLĐ 2019 Điều 35).'];
        } else {
            $interval = $contract->start_date->copy()->diff($contract->end_date->copy()->addDay());
            $months = $interval->y * 12 + $interval->m;

            [$days, $basis] = $months >= 12
                ? [30, 'Hợp đồng xác định thời hạn từ 12 đến 36 tháng — báo trước ít nhất 30 ngày (BLLĐ 2019 Điều 35).']
                : [3, 'Hợp đồng xác định thời hạn dưới 12 tháng — báo trước ít nhất 3 ngày làm việc (BLLĐ 2019 Điều 35).'];
        }

        return [
            'required_days' => $days,
            'basis' => $basis,
            'earliest_last_working_date' => $today->copy()->addDays($days)->toDateString(),
        ];
    }

    public function create(Employee $employee, array $data): ResignationRequest
    {
        if (! in_array($employee->employment_status, ['probation', 'active'], true)) {
            throw ValidationException::withMessages([
                'last_working_date' => 'Hồ sơ của bạn không còn ở trạng thái đang làm việc, không thể nộp đơn nghỉ việc.',
            ]);
        }

        $hasOpenRequest = $employee->resignationRequests()
            ->where(fn ($q) => $q->where('status', ResignationRequest::STATUS_PENDING)
                ->orWhere(fn ($q2) => $q2->whereIn('status', [ResignationRequest::STATUS_APPROVED, ResignationRequest::STATUS_NOTIFIED])->whereNull('applied_at')))
            ->exists();

        if ($hasOpenRequest) {
            throw ValidationException::withMessages([
                'last_working_date' => 'Bạn đang có 1 đơn nghỉ việc chờ duyệt, đã duyệt hoặc đã thông báo, không thể nộp thêm.',
            ]);
        }

        $today = now()->startOfDay();
        $policy = $this->noticePolicy($employee, $today);
        $given = (int) $today->diffInDays(Carbon::parse($data['last_working_date'])->startOfDay());
        $enoughNotice = $given >= $policy['required_days'];

        $request = ResignationRequest::create([
            'employee_id' => $employee->id,
            'last_working_date' => $data['last_working_date'],
            'reason' => $data['reason'],
            'notice_days_required' => $policy['required_days'],
            'notice_days_given' => $given,
            'requires_approval' => ! $enoughNotice,
            'status' => $enoughNotice ? ResignationRequest::STATUS_NOTIFIED : ResignationRequest::STATUS_PENDING,
        ]);

        $this->notifyApprovers($employee, $request);
        ResourceChanged::dispatch('resignations');

        return $request;
    }

    public function cancel(Employee $employee, ResignationRequest $request): ResignationRequest
    {
        abort_if($request->employee_id !== $employee->id, 404);

        $withdrawable = $request->status === ResignationRequest::STATUS_PENDING
            || ($request->status === ResignationRequest::STATUS_NOTIFIED && $request->applied_at === null);

        if (! $withdrawable) {
            throw ValidationException::withMessages([
                'status' => 'Chỉ rút được đơn đang chờ duyệt hoặc đã thông báo mà chưa có hiệu lực.',
            ]);
        }

        $request->update(['status' => ResignationRequest::STATUS_CANCELLED]);
        ResourceChanged::dispatch('resignations');

        return $request;
    }

    public function decide(ResignationRequest $request, string $status, ?string $note, User $decidedBy): ResignationRequest
    {
        if (! $this->canDecide($decidedBy, $request)) {
            abort(403, 'Bạn chỉ duyệt được đơn nghỉ việc của nhân viên mình quản lý trực tiếp.');
        }

        if ($request->status === ResignationRequest::STATUS_NOTIFIED) {
            throw ValidationException::withMessages([
                'status' => 'Đơn này chỉ để thông báo (đã báo trước đủ ngày theo luật), không cần duyệt.',
            ]);
        }

        if ($request->status !== ResignationRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Đơn này đã được xử lý trước đó.',
            ]);
        }

        $request = DB::transaction(function () use ($request, $status, $note, $decidedBy) {
            $request->update([
                'status' => $status,
                'decision_note' => $note,
                'decided_by' => $decidedBy->id,
                'decided_at' => now(),
            ]);

            if ($status === ResignationRequest::STATUS_APPROVED) {
                $this->applyIfDue($request);
            }

            return $request;
        });

        $this->notifyEmployeeDecision($request);
        ResourceChanged::dispatch('resignations');
        ResourceChanged::dispatch('employees');

        return $request->fresh(['employee', 'decider']);
    }

    public function canDecide(User $user, ResignationRequest $request): bool
    {
        if (! $user->hasPermission('resignation.approve')) {
            return false;
        }
        if ($user->hasPermission('resignation.view_all')) {
            return true;
        }

        $deciderEmployeeId = $user->employee?->id;

        return $deciderEmployeeId !== null && $request->employee?->manager_id === $deciderEmployeeId;
    }

    // Đơn đã duyệt mà ĐÃ QUA ngày làm việc cuối -> chuyển nhân viên sang "Đã
    // nghỉ việc", ghi termination_date = ngày làm việc cuối, chấm dứt mọi hợp
    // đồng còn hiệu lực/chờ hiệu lực. Cập nhật thẳng hợp đồng (không qua
    // EmployeeContractService::terminate(), hàm đó đổi nhân viên sang "Đã chấm
    // dứt HĐ" — sai nghĩa với trường hợp tự xin nghỉ). Gọi lúc duyệt và từ job
    // hằng ngày `resignations:apply`. Trả true nếu đã áp dụng.
    public function applyIfDue(ResignationRequest $request): bool
    {
        if (
            ! in_array($request->status, [ResignationRequest::STATUS_APPROVED, ResignationRequest::STATUS_NOTIFIED], true)
            || $request->applied_at !== null
            || $request->last_working_date->toDateString() >= now()->toDateString()
        ) {
            return false;
        }

        DB::transaction(function () use ($request) {
            $lastDay = $request->last_working_date->toDateString();

            EmployeeContract::where('employee_id', $request->employee_id)
                ->whereIn('status', ['active', 'pending'])
                ->update(['status' => 'terminated', 'terminated_at' => $lastDay]);

            Employee::whereKey($request->employee_id)->update([
                'employment_status' => 'resigned',
                'termination_date' => $lastDay,
            ]);

            $request->update(['applied_at' => now()]);
        });

        // ->update() hàng loạt ở trên không phát event Eloquent — báo tay.
        Realtime::shared('employee_contracts');
        Realtime::forEmployee((int) $request->employee_id, 'contracts');

        // Đã nghỉ việc -> khóa tài khoản đăng nhập.
        $this->employeeAccountService->deactivateAccountOf(Employee::findOrFail($request->employee_id));

        return true;
    }

    public function applyAllDue(): int
    {
        return ResignationRequest::whereIn('status', [ResignationRequest::STATUS_APPROVED, ResignationRequest::STATUS_NOTIFIED])
            ->whereNull('applied_at')
            ->whereDate('last_working_date', '<', now()->toDateString())
            ->get()
            ->filter(fn (ResignationRequest $request) => $this->applyIfDue($request))
            ->count();
    }

    private function visibleTo(User $user)
    {
        $query = ResignationRequest::query();

        if ($user->hasPermission('resignation.view_all')) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        // Manager: chỉ đơn của nhân viên mình quản lý trực tiếp; không gắn hồ
        // sơ nhân viên thì không thấy đơn nào.
        return $query->whereHas('employee', fn ($q) => $q->where('manager_id', $employeeId ?? 0));
    }

    // Báo người có thể duyệt: mọi user có resignation.view_all (HR/Admin) +
    // quản lý trực tiếp của người nộp (nếu có quyền duyệt). Bỏ qua chính người nộp.
    private function notifyApprovers(Employee $employee, ResignationRequest $request): void
    {
        $recipients = User::withPermission('resignation.view_all')->get();
        $managerUser = $employee->manager?->user;

        if ($managerUser && $managerUser->hasPermission('resignation.approve')) {
            $recipients->push($managerUser);
        }

        $isNotice = ! $request->requires_approval;
        $type = $isNotice ? 'resignation.notice' : 'resignation.pending';
        $title = $isNotice ? 'Nhân viên thông báo nghỉ việc' : 'Đơn xin nghỉ việc mới cần duyệt';
        $message = $isNotice
            ? "{$employee->full_name} thông báo nghỉ việc, ngày làm việc cuối {$request->last_working_date->format('d/m/Y')} (báo trước {$request->notice_days_given} ngày, đủ theo quy định) — không cần duyệt."
            : "{$employee->full_name} vừa nộp đơn xin nghỉ việc, ngày làm việc cuối {$request->last_working_date->format('d/m/Y')} (báo trước {$request->notice_days_given}/{$request->notice_days_required} ngày tối thiểu).";

        $recipients
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $employee->user_id)
            ->each(fn (User $user) => $this->notificationService->send(
                $user,
                $type,
                $title,
                $message,
                ['resignation_request_id' => $request->id],
            ));
    }

    private function notifyEmployeeDecision(ResignationRequest $request): void
    {
        $user = $request->employee?->user;

        if (! $user) {
            return;
        }

        $approved = $request->status === ResignationRequest::STATUS_APPROVED;
        $title = $approved ? 'Đơn xin nghỉ việc của bạn đã được duyệt' : 'Đơn xin nghỉ việc của bạn đã bị từ chối';
        $message = $approved
            ? "Ngày làm việc cuối của bạn là {$request->last_working_date->format('d/m/Y')}."
            : 'Đơn xin nghỉ việc của bạn không được duyệt.';

        if ($request->decision_note) {
            $message .= ($approved ? ' Ghi chú: ' : ' Lý do: ').$request->decision_note;
        }

        $this->notificationService->send($user, 'resignation.decided', $title, $message, [
            'resignation_request_id' => $request->id,
            'status' => $request->status,
        ]);
    }
}
