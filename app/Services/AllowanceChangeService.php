<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAllowanceRequest;
use App\Models\Payroll;
use App\Models\SalaryComponent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Ai được đổi phụ cấp của nhân viên (yêu cầu 2026-10-21):
//  - Người có quyền allowance.approve (Admin): đổi là áp dụng ngay, ghi chính mình là người duyệt.
//  - Người chỉ có payroll.manage (HR): MỌI thay đổi (thêm, sửa số tiền, tắt, đặt hàng loạt, áp dụng mức gợi ý)
//    thành ĐỀ XUẤT; Admin duyệt mới ghi vào employee_allowances, hiệu lực từ đúng ngày HR chọn. Trong lúc chờ,
//    bảng lương vẫn dùng mức cũ. Mỗi nhân viên × khoản chỉ có 1 đề xuất chờ — gửi lại thì thay đề xuất cũ.
//  - Mỗi lượt gửi = 1 thông báo (toast + chuông) cho Admin; duyệt / từ chối báo lại người đề xuất. Không email.
// Danh mục khoản (mức gợi ý chung) không qua duyệt — xem AllowanceService::updateComponent().
class AllowanceChangeService
{
    public const APPROVE_PERMISSION = 'allowance.approve';

    public function __construct(
        private readonly AllowanceService $allowances,
        private readonly NotificationService $notifications,
    ) {
    }

    public function canApprove(User $user): bool
    {
        return $user->hasPermission(self::APPROVE_PERMISSION);
    }

    // ------------------------------------------------------------------ thay đổi (áp dụng ngay hoặc đề xuất)

    /** Đặt mức mới cho 1 khoản của 1 nhân viên. Mức 0 = không hưởng. */
    public function set(User $actor, Employee $employee, SalaryComponent $component, float $amount, string $effectiveFrom, ?string $note): array
    {
        $note = $this->allowances->validateChange($employee, $component, $amount, $note);
        $request = $this->change($actor, $employee, $component, $amount, $effectiveFrom, $note);

        return $this->finish($actor, [$request], $request ? 0 : 1);
    }

    /**
     * Đặt 1 khoản cho nhiều nhân viên: suggested (mức gợi ý của từng người) | amount (cùng 1 mức) | none (không
     * hưởng). Người lỗi (vd thiếu lý do khi lệch gợi ý) không chặn người khác.
     */
    public function bulkSet(User $actor, array $employeeIds, SalaryComponent $component, string $mode, ?float $amount, string $effectiveFrom, ?string $note): array
    {
        $headIds = Department::whereNotNull('manager_id')->pluck('manager_id')->map(fn ($id) => (int) $id)->all();
        $requests = [];
        $applied = 0;
        $failed = [];

        foreach (Employee::with('activeContract')->whereIn('id', $employeeIds)->get() as $employee) {
            $profile = $this->allowances->suggestionProfile($employee, $headIds);
            $value = match ($mode) {
                'suggested' => $this->allowances->suggestedAmount($component, $profile),
                'none' => 0.0,
                default => (float) $amount,
            };
            try {
                $reason = $this->allowances->validateChange($employee, $component, $value, $mode === 'suggested' ? ($note ?: 'Áp dụng mức gợi ý') : $note, $profile);
                $request = $this->change($actor, $employee, $component, $value, $effectiveFrom, $reason, $profile);
                if ($request) {
                    $requests[] = $request;
                } else {
                    $applied++;
                }
            } catch (ValidationException $e) {
                $failed[] = ['id' => $employee->id, 'full_name' => $employee->full_name, 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['failed' => $failed] + $this->finish($actor, $requests, $applied);
    }

    /** Đưa mọi khoản đang khác gợi ý của 1 nhân viên về mức gợi ý. */
    public function applySuggested(User $actor, Employee $employee, string $effectiveFrom): array
    {
        $requests = [];
        $applied = 0;
        foreach ($this->allowances->forEmployee($employee)['items'] as $item) {
            if (! $item['component']['is_active'] || ! $item['differs']) {
                continue;
            }
            $request = $this->change($actor, $employee, SalaryComponent::find($item['component']['id']), $item['suggested_amount'], $effectiveFrom, 'Áp dụng mức gợi ý');
            if ($request) {
                $requests[] = $request;
            } else {
                $applied++;
            }
        }

        return $this->finish($actor, $requests, $applied);
    }

    /** Áp dụng mức gợi ý của 1 khoản cho mọi nhân viên đang làm việc CHƯA có khoản này. */
    public function applySuggestedToAll(User $actor, SalaryComponent $component, string $effectiveFrom): array
    {
        $requests = [];
        $applied = 0;
        if (! $component->applies_to_all) { // khoản "mọi người tự hưởng" thì không cần tạo dòng riêng
            $withAllowance = $this->allowances->employeeIdsWithComponent($component, $effectiveFrom);
            $employees = Employee::whereIn('employment_status', Employee::WORKING_STATUSES)
                ->whereNotIn('id', $withAllowance)
                ->with('activeContract')
                ->get();
            foreach ($employees as $employee) {
                $profile = $this->allowances->suggestionProfile($employee);
                $suggested = $this->allowances->suggestedAmount($component, $profile);
                if ($suggested > 0) {
                    $request = $this->change($actor, $employee, $component, $suggested, $effectiveFrom, 'Áp dụng mức gợi ý', $profile);
                    if ($request) {
                        $requests[] = $request;
                    } else {
                        $applied++;
                    }
                }
            }
        }

        return $this->finish($actor, $requests, $applied);
    }

    /** Admin: ghi luôn (trả null). HR: tạo / thay đề xuất chờ duyệt của cặp nhân viên × khoản này. */
    private function change(User $actor, Employee $employee, SalaryComponent $component, float $amount, string $effectiveFrom, ?string $note, ?array $profile = null): ?EmployeeAllowanceRequest
    {
        if ($this->canApprove($actor)) {
            $this->allowances->apply($employee, $component, $amount, $effectiveFrom, $note, $actor, $actor);

            return null;
        }

        $previous = $this->allowances->currentState($employee, $component, $profile);

        return DB::transaction(function () use ($actor, $employee, $component, $amount, $effectiveFrom, $note, $previous) {
            $request = EmployeeAllowanceRequest::where('employee_id', $employee->id)
                ->where('salary_component_id', $component->id)
                ->where('status', EmployeeAllowanceRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->first()
                ?? new EmployeeAllowanceRequest([
                    'employee_id' => $employee->id,
                    'salary_component_id' => $component->id,
                    'status' => EmployeeAllowanceRequest::STATUS_PENDING,
                ]);
            $request->fill([
                'amount' => round($amount, 2),
                'effective_from' => Carbon::parse($effectiveFrom)->toDateString(),
                'note' => $note,
                'previous_amount' => $previous['amount'],
                'previous_state' => $previous['state'],
                'requested_by' => $actor->id,
            ]);
            if ($request->exists) {
                $request->created_at = now(); // đề xuất thay thế tính là gửi lúc này
            }
            $request->save();

            return $request->setRelations(['employee' => $employee, 'component' => $component]);
        });
    }

    /** Gửi 1 thông báo cho Admin cho cả lượt, trả kết quả cho giao diện. */
    private function finish(User $actor, array $requests, int $applied): array
    {
        $requests = collect($requests)->filter()->values();
        $this->notifyApprovers($actor, $requests);
        $pending = $requests->count();

        return [
            'done' => $applied + $pending,
            'applied' => $applied,
            'pending' => $pending,
            'message' => match (true) {
                $pending > 0 => ($pending === 1 ? 'Đã gửi đề xuất' : "Đã gửi {$pending} đề xuất")
                    .' cho Admin duyệt. Phụ cấp chỉ áp dụng sau khi được duyệt.',
                $applied > 0 => $applied === 1 ? 'Đã lưu phụ cấp.' : "Đã lưu {$applied} thay đổi phụ cấp.",
                default => 'Không có thay đổi nào.',
            },
        ];
    }

    // ------------------------------------------------------------------ duyệt

    /** Admin duyệt: ghi từng đề xuất vào phụ cấp thật, hiệu lực từ ngày HR chọn. Đề xuất lỗi không chặn cái khác. */
    public function approve(User $admin, array $ids, ?string $note): array
    {
        $note = trim((string) $note) ?: null;
        $approved = collect();
        $failed = [];

        foreach ($this->pendingByIds($ids) as $request) {
            try {
                DB::transaction(function () use ($request, $admin, $note) {
                    $locked = EmployeeAllowanceRequest::lockForUpdate()->find($request->id);
                    if ($locked->status !== EmployeeAllowanceRequest::STATUS_PENDING) {
                        throw ValidationException::withMessages(['ids' => 'Đề xuất này vừa được xử lý.']);
                    }
                    if (! $request->component->is_active) {
                        throw ValidationException::withMessages(['ids' => 'Khoản này đã ngừng sử dụng.']);
                    }
                    $row = $this->allowances->apply(
                        $request->employee,
                        $request->component,
                        $request->amount,
                        $request->effective_from->toDateString(),
                        $request->note,
                        $request->requester,
                        $admin,
                    );
                    $locked->update($this->decision(EmployeeAllowanceRequest::STATUS_APPROVED, $admin, $note) + ['employee_allowance_id' => $row->id]);
                });
                $approved->push($request);
            } catch (ValidationException $e) {
                $failed[] = ['id' => $request->id, 'full_name' => $request->employee->full_name, 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        $this->notifyRequesters($admin, $approved, EmployeeAllowanceRequest::STATUS_APPROVED, $note);

        return ['done' => $approved->count(), 'failed' => $failed, 'payroll_warning' => $this->payrollWarning($approved)];
    }

    /**
     * Bảng lương tạo 1 lần/kỳ và không tự tính lại — duyệt đề xuất có ngày áp dụng rơi vào kỳ đã có bảng lương thì
     * phiếu lương kỳ đó KHÔNG đổi theo. Báo để Admin biết mà xử lý tay (vd khoản bù ở kỳ sau).
     */
    private function payrollWarning(Collection $approved): ?string
    {
        $names = $approved
            ->filter(fn (EmployeeAllowanceRequest $r) => Payroll::whereRaw(
                'period_year * 12 + period_month >= ?',
                [$r->effective_from->year * 12 + $r->effective_from->month]
            )->exists())
            ->map(fn (EmployeeAllowanceRequest $r) => $r->employee->full_name)
            ->unique();

        return $names->isEmpty() ? null
            : 'Lưu ý: kỳ lương chứa ngày áp dụng đã được tạo nên phiếu lương kỳ đó không tự cập nhật ('
                .$names->take(3)->join(', ').($names->count() > 3 ? '…' : '').').';
    }

    /** Admin từ chối — bắt buộc lý do (kiểm ở controller), báo lại người đề xuất. */
    public function reject(User $admin, array $ids, string $note): array
    {
        $rejected = $this->pendingByIds($ids)
            ->each(fn (EmployeeAllowanceRequest $request) => $request->update($this->decision(EmployeeAllowanceRequest::STATUS_REJECTED, $admin, $note)));

        $this->notifyRequesters($admin, $rejected, EmployeeAllowanceRequest::STATUS_REJECTED, $note);

        return ['done' => $rejected->count(), 'failed' => []];
    }

    /** Người đề xuất (hoặc Admin) rút lại đề xuất còn chờ. */
    public function cancel(User $actor, EmployeeAllowanceRequest $request): void
    {
        abort_unless($request->requested_by === $actor->id || $this->canApprove($actor), 403, 'Bạn chỉ rút lại được đề xuất của chính mình.');
        if ($request->status !== EmployeeAllowanceRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['request' => 'Đề xuất này đã được xử lý, không rút lại được.']);
        }
        $request->update($this->decision(EmployeeAllowanceRequest::STATUS_CANCELLED, $actor, null));
    }

    private function pendingByIds(array $ids): Collection
    {
        return EmployeeAllowanceRequest::with(['employee.activeContract', 'component', 'requester.employee'])
            ->whereIn('id', $ids)
            ->where('status', EmployeeAllowanceRequest::STATUS_PENDING)
            ->get();
    }

    private function decision(string $status, User $by, ?string $note): array
    {
        return ['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note];
    }

    // ------------------------------------------------------------------ danh sách

    /** Tab "Đề xuất" của trang Phụ cấp: lọc theo trạng thái (mặc định đang chờ) và tên / mã nhân viên. */
    public function list(User $viewer, array $filters): array
    {
        $status = $filters['status'] ?? EmployeeAllowanceRequest::STATUS_PENDING;
        $paginator = EmployeeAllowanceRequest::with(['employee.department:id,name', 'component', 'requester:id,user_name', 'decider:id,user_name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when(! empty($filters['search']), fn ($q) => $q->whereHas('employee', fn ($q) => $q
                ->where('full_name', 'like', '%'.$filters['search'].'%')
                ->orWhere('code', 'like', '%'.$filters['search'].'%')))
            ->orderByRaw('status = ? desc', [EmployeeAllowanceRequest::STATUS_PENDING])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 25))));

        $canApprove = $this->canApprove($viewer);

        return [
            'data' => collect($paginator->items())->map(fn ($request) => $this->summary($request, $viewer, $canApprove))->values(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'pending' => EmployeeAllowanceRequest::where('status', EmployeeAllowanceRequest::STATUS_PENDING)->count(),
                'can_approve' => $canApprove,
            ],
        ];
    }

    private function summary(EmployeeAllowanceRequest $request, User $viewer, bool $canApprove): array
    {
        $employee = $request->employee;
        $pending = $request->status === EmployeeAllowanceRequest::STATUS_PENDING;

        return [
            'id' => $request->id,
            'employee' => [
                'id' => $employee?->id,
                'code' => $employee?->code,
                'full_name' => $employee?->full_name,
                'avatar_url' => $employee?->avatar ? Storage::disk('public')->url($employee->avatar) : null,
                'department' => $employee?->department?->name,
            ],
            'component' => ['id' => $request->component->id, 'name' => $request->component->name, 'unit' => $this->unit($request->component)],
            'amount' => $request->amount,
            'previous_amount' => $request->previous_amount,
            'previous_state' => $request->previous_state,
            'effective_from' => $request->effective_from->toDateString(),
            'note' => $request->note,
            'status' => $request->status,
            'requested_by' => $request->requester?->user_name,
            'requested_at' => $request->created_at?->toIso8601String(),
            'decided_by' => $request->decider?->user_name,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decision_note' => $request->decision_note,
            'can_decide' => $pending && $canApprove,
            'can_cancel' => $pending && ($canApprove || $request->requested_by === $viewer->id),
        ];
    }

    // ------------------------------------------------------------------ thông báo

    private function notifyApprovers(User $actor, Collection $requests): void
    {
        if ($requests->isEmpty()) {
            return;
        }

        $first = $requests->first();
        $message = $requests->count() === 1
            ? "{$this->personName($actor)} đề xuất {$this->describe($first)}."
            : "{$this->personName($actor)} gửi {$requests->count()} đề xuất thay đổi phụ cấp ("
                .$requests->map(fn ($r) => $r->component->name)->unique()->join(', ').') cho '
                .$requests->pluck('employee_id')->unique()->count().' nhân viên.';

        User::withPermission(self::APPROVE_PERMISSION)->get()
            ->reject(fn (User $user) => $user->id === $actor->id)
            ->each(fn (User $user) => $this->notifications->send(
                $user,
                'allowance.pending',
                'Đề xuất phụ cấp chờ duyệt',
                $message,
                ['request_ids' => $requests->pluck('id')->all(), 'count' => $requests->count()],
            ));
    }

    private function notifyRequesters(User $admin, Collection $requests, string $status, ?string $note): void
    {
        $approved = $status === EmployeeAllowanceRequest::STATUS_APPROVED;

        $requests->groupBy('requested_by')->each(function (Collection $group, $requesterId) use ($admin, $approved, $note, $status) {
            $requester = $group->first()->requester;
            if (! $requester || $requester->id === $admin->id) {
                return;
            }

            $verb = $approved ? 'đã duyệt' : 'đã từ chối';
            $message = $group->count() === 1
                ? "{$this->personName($admin)} {$verb} đề xuất {$this->describe($group->first())}."
                : "{$this->personName($admin)} {$verb} {$group->count()} đề xuất phụ cấp của bạn.";
            if ($note) {
                $message .= ($approved ? ' Ghi chú: ' : ' Lý do: ').$note;
            }

            $this->notifications->send(
                $requester,
                'allowance.decided',
                $approved ? 'Đề xuất phụ cấp đã được duyệt' : 'Đề xuất phụ cấp bị từ chối',
                $message,
                ['request_ids' => $group->pluck('id')->all(), 'status' => $status],
            );
        });
    }

    // "Phụ cấp khác cho Nguyễn Văn A: 500.000đ/tháng từ 01/11/2026 (Hỗ trợ gửi xe)"
    private function describe(EmployeeAllowanceRequest $request): string
    {
        $amount = $request->amount > 0
            ? number_format($request->amount, 0, ',', '.').'đ/'.($this->unit($request->component) === 'day' ? 'ngày' : 'tháng')
            : 'không hưởng';

        return "{$request->component->name} cho {$request->employee->full_name}: {$amount} từ "
            .$request->effective_from->format('d/m/Y')
            .($request->note ? " ({$request->note})" : '');
    }

    private function unit(SalaryComponent $component): string
    {
        return in_array($component->calculation_method, AllowanceService::DAILY_METHODS, true) ? 'day' : 'month';
    }

    private function personName(User $user): string
    {
        return $user->employee?->full_name ?? $user->user_name;
    }
}
