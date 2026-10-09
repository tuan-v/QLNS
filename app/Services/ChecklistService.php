<?php

namespace App\Services;

use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\Employee;
use App\Models\EmployeeChecklist;
use App\Models\EmployeeChecklistItem;
use App\Models\ResignationRequest;
use App\Models\User;
use App\Repositories\ChecklistRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Onboarding / Offboarding (ngoài tài liệu gốc — người dùng duyệt mở rộng 2026-10-07).
//  - Mẫu checklist (HR soạn, mỗi loại có 1 mẫu mặc định). Tạo checklist cho nhân viên
//    = CHÉP các việc trong mẫu; hạn từng việc = ngày mốc + số ngày (ngày vào làm với
//    onboarding, ngày làm việc cuối với offboarding).
//  - Tự tạo: onboarding khi tạo nhân viên mới (EmployeeService::create); offboarding khi
//    đơn nghỉ việc được duyệt hoặc là thông báo đủ ngày (ResignationService). Rút đơn
//    -> hủy checklist offboarding tạo từ đơn đó. Mỗi nhân viên chỉ có tối đa 1
//    checklist ĐANG LÀM cho mỗi loại.
//  - Ai đánh dấu việc nào: onboarding.manage (HR/Admin) mọi việc; quản lý trực tiếp có
//    onboarding.team -> việc phần "Quản lý"; chính nhân viên -> việc phần "Nhân viên".
//  - Việc có auto_key do hệ thống tự đánh dấu khi điều kiện đúng (đã có tài khoản, đã
//    tải hợp đồng ký, có tài khoản nhận lương đã xác nhận, tài khoản đã khóa, hợp đồng
//    đã chấm dứt) — kiểm tra mỗi lần đọc.
//  - Hoàn thành = mọi việc BẮT BUỘC đã xong; bỏ đánh dấu lại thì quay về "đang làm".
class ChecklistService
{
    public function __construct(
        private readonly ChecklistRepository $checklistRepository,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function list(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $managerEmployeeId = $user->hasPermission('onboarding.manage') ? null : ($user->employee?->id ?? 0);
        $page = $this->checklistRepository->paginate($filters, $perPage, $managerEmployeeId);

        $page->getCollection()->each(fn (EmployeeChecklist $checklist) => $this->prepare($user, $checklist));

        return $page;
    }

    public function show(User $user, EmployeeChecklist $checklist): EmployeeChecklist
    {
        $this->checklistRepository->loadDetail($checklist);
        abort_unless($this->canView($user, $checklist), 404);

        return $this->prepare($user, $checklist);
    }

    /** @return Collection<int, EmployeeChecklist> */
    public function mine(User $user): Collection
    {
        $employee = $user->employee;
        if (! $employee) {
            return collect();
        }

        return $this->checklistRepository->forEmployee($employee)
            ->each(fn (EmployeeChecklist $checklist) => $this->prepare($user, $checklist));
    }

    // Tạo tay (HR). Đã có checklist đang làm cùng loại -> 422.
    public function createManually(Employee $employee, string $type, ?int $templateId, User $actor): EmployeeChecklist
    {
        if ($this->checklistRepository->openChecklist($employee, $type)) {
            throw ValidationException::withMessages([
                'employee_id' => 'Nhân viên này đang có 1 checklist '.$this->typeLabel($type).' chưa hoàn thành.',
            ]);
        }

        $template = $templateId
            ? ChecklistTemplate::with('items')->where('type', $type)->findOrFail($templateId)
            : $this->checklistRepository->defaultTemplate($type);

        if (! $template) {
            throw ValidationException::withMessages([
                'template_id' => 'Chưa có mẫu checklist '.$this->typeLabel($type).' nào đang dùng.',
            ]);
        }

        $referenceDate = $type === ChecklistTemplate::TYPE_OFFBOARDING
            ? ($this->openResignation($employee)?->last_working_date?->toDateString() ?? now()->toDateString())
            : $employee->hire_date->toDateString();

        return $this->createFromTemplate($employee, $template, $referenceDate, $actor, $this->openResignation($employee)?->id);
    }

    // Gọi từ EmployeeService::create (trong transaction tạo nhân viên).
    public function startOnboarding(Employee $employee, ?User $actor = null): ?EmployeeChecklist
    {
        $template = $this->checklistRepository->defaultTemplate(ChecklistTemplate::TYPE_ONBOARDING);
        if (! $template || $this->checklistRepository->openChecklist($employee, ChecklistTemplate::TYPE_ONBOARDING)) {
            return null;
        }

        return $this->createFromTemplate($employee, $template, $employee->hire_date->toDateString(), $actor);
    }

    // Gọi khi đơn nghỉ việc có hiệu lực chắc chắn (duyệt hoặc thông báo đủ ngày).
    public function startOffboarding(ResignationRequest $request): ?EmployeeChecklist
    {
        $employee = $request->employee;
        $template = $this->checklistRepository->defaultTemplate(ChecklistTemplate::TYPE_OFFBOARDING);
        if (! $employee || ! $template) {
            return null;
        }

        // HR đã tạo tay trước đó -> chỉ gắn đơn + dời mốc theo ngày làm việc cuối.
        $existing = $this->checklistRepository->openChecklist($employee, ChecklistTemplate::TYPE_OFFBOARDING);
        if ($existing) {
            $existing->update(['resignation_request_id' => $request->id]);

            return $existing;
        }

        return $this->createFromTemplate($employee, $template, $request->last_working_date->toDateString(), null, $request->id);
    }

    // Rút đơn nghỉ việc -> hủy checklist offboarding được tạo từ đơn đó (nếu chưa xong).
    public function cancelForResignation(ResignationRequest $request): void
    {
        EmployeeChecklist::where('resignation_request_id', $request->id)
            ->where('status', EmployeeChecklist::STATUS_IN_PROGRESS)
            ->get()
            ->each(fn (EmployeeChecklist $checklist) => $checklist->update([
                'status' => EmployeeChecklist::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]));
    }

    public function cancel(EmployeeChecklist $checklist): EmployeeChecklist
    {
        if ($checklist->status !== EmployeeChecklist::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages(['status' => 'Chỉ hủy được checklist đang làm.']);
        }

        $checklist->update(['status' => EmployeeChecklist::STATUS_CANCELLED, 'cancelled_at' => now()]);

        return $checklist;
    }

    public function addItem(EmployeeChecklist $checklist, array $data): EmployeeChecklistItem
    {
        $this->assertEditable($checklist);

        $item = $checklist->items()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'responsible' => $data['responsible'],
            'due_date' => $data['due_date'] ?? null,
            'is_required' => $data['is_required'] ?? true,
            'sort_order' => (int) $checklist->items()->max('sort_order') + 1,
        ]);
        $this->refreshStatus($checklist->fresh('items'));

        return $item;
    }

    public function deleteItem(EmployeeChecklistItem $item): void
    {
        $checklist = $item->checklist;
        $this->assertEditable($checklist);

        if ($checklist->items()->count() <= 1) {
            throw ValidationException::withMessages(['item' => 'Checklist phải còn ít nhất 1 việc — muốn bỏ hẳn thì hủy checklist.']);
        }

        $item->delete();
        $this->refreshStatus($checklist->fresh('items'));
    }

    public function toggleItem(User $user, EmployeeChecklistItem $item, bool $done, ?string $note): EmployeeChecklist
    {
        $checklist = $this->checklistRepository->loadDetail($item->checklist);
        abort_unless($this->canView($user, $checklist), 404);
        abort_unless($this->canToggle($user, $checklist, $item), 403, 'Việc này không thuộc phần bạn phụ trách.');

        DB::transaction(function () use ($item, $done, $note, $user, $checklist) {
            $item->update($done
                ? ['completed_at' => $item->completed_at ?? now(), 'completed_by' => $item->completed_at ? $item->completed_by : $user->id, 'note' => $note]
                : ['completed_at' => null, 'completed_by' => null, 'note' => $note]);
            $this->refreshStatus($checklist->fresh('items'));
        });

        return $this->show($user, $checklist->fresh());
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ChecklistTemplate> */
    public function templates(?string $type)
    {
        return $this->checklistRepository->templates($type);
    }

    public function saveTemplate(array $data, ?ChecklistTemplate $template = null): ChecklistTemplate
    {
        return DB::transaction(function () use ($data, $template) {
            $type = $template?->type ?? $data['type'];
            $attributes = [
                'name' => $data['name'],
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ];

            if ($template) {
                $template->update($attributes);
            } else {
                $template = ChecklistTemplate::create($attributes + ['type' => $type]);
            }

            // Mỗi loại chỉ 1 mẫu mặc định.
            if ($template->is_default) {
                ChecklistTemplate::where('type', $type)->whereKeyNot($template->id)->update(['is_default' => false]);
            }

            $template->items()->delete();
            foreach (array_values($data['items']) as $index => $item) {
                $template->items()->create([
                    'title' => $item['title'],
                    'description' => $item['description'] ?? null,
                    'responsible' => $item['responsible'],
                    'due_offset_days' => (int) ($item['due_offset_days'] ?? 0),
                    'is_required' => (bool) ($item['is_required'] ?? true),
                    'auto_key' => $item['auto_key'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            return $template->load('items');
        });
    }

    public function deleteTemplate(ChecklistTemplate $template): void
    {
        // Checklist đã tạo giữ nguyên (việc đã được chép sang), chỉ mất liên kết tới mẫu.
        $template->delete();
    }

    public function canView(User $user, EmployeeChecklist $checklist): bool
    {
        return $user->hasPermission('onboarding.manage')
            || $this->isTeamManager($user, $checklist)
            || ($checklist->employee?->user_id !== null && $checklist->employee->user_id === $user->id);
    }

    public function canToggle(User $user, EmployeeChecklist $checklist, EmployeeChecklistItem $item): bool
    {
        if ($checklist->status === EmployeeChecklist::STATUS_CANCELLED) {
            return false;
        }

        if ($user->hasPermission('onboarding.manage')) {
            return true;
        }

        if ($item->responsible === 'manager') {
            return $this->isTeamManager($user, $checklist);
        }

        return $item->responsible === 'employee'
            && $checklist->employee?->user_id !== null
            && $checklist->employee->user_id === $user->id;
    }

    // Tự đánh dấu việc hệ thống + gắn cờ quyền cho Resource.
    private function prepare(User $user, EmployeeChecklist $checklist): EmployeeChecklist
    {
        $this->syncAutoItems($checklist);

        $checklist->setAttribute('can_manage', $user->hasPermission('onboarding.manage'));
        $checklist->items->each(fn (EmployeeChecklistItem $item) => $item->setAttribute('can_toggle', $this->canToggle($user, $checklist, $item)));

        return $checklist;
    }

    private function syncAutoItems(EmployeeChecklist $checklist): void
    {
        if ($checklist->status !== EmployeeChecklist::STATUS_IN_PROGRESS || ! $checklist->employee) {
            return;
        }

        $changed = false;
        foreach ($checklist->items as $item) {
            if ($item->auto_key && ! $item->completed_at && $this->autoConditionMet($item->auto_key, $checklist->employee)) {
                $item->update(['completed_at' => now(), 'note' => 'Hệ thống tự ghi nhận.']);
                $changed = true;
            }
        }

        if ($changed) {
            $this->refreshStatus($checklist);
        }
    }

    private function autoConditionMet(string $key, Employee $employee): bool
    {
        $contracts = $employee->contracts;

        return match ($key) {
            'account_created' => $employee->user_id !== null,
            'contract_signed' => $contracts->contains(fn ($c) => $c->status === 'active' && $c->contract_file_path),
            // Đã có tài khoản nhận lương được HR xác nhận (EmployeeBankAccountService).
            'bank_account_verified' => $employee->bankAccounts->contains(fn ($a) => $a->is_primary && $a->status === 'verified'),
            'account_locked' => $employee->user === null || $employee->user->status !== 'active',
            'contract_ended' => ! $contracts->contains(fn ($c) => in_array($c->status, ['active', 'pending'], true)),
            default => false,
        };
    }

    private function refreshStatus(EmployeeChecklist $checklist): void
    {
        if ($checklist->status === EmployeeChecklist::STATUS_CANCELLED) {
            return;
        }

        $done = $checklist->items->where('is_required', true)->every(fn (EmployeeChecklistItem $item) => $item->completed_at !== null);

        $checklist->update($done
            ? ['status' => EmployeeChecklist::STATUS_COMPLETED, 'completed_at' => $checklist->completed_at ?? now()]
            : ['status' => EmployeeChecklist::STATUS_IN_PROGRESS, 'completed_at' => null]);
    }

    private function createFromTemplate(Employee $employee, ChecklistTemplate $template, string $referenceDate, ?User $actor, ?int $resignationId = null): EmployeeChecklist
    {
        $checklist = DB::transaction(function () use ($employee, $template, $referenceDate, $actor, $resignationId) {
            $checklist = EmployeeChecklist::create([
                'employee_id' => $employee->id,
                'type' => $template->type,
                'checklist_template_id' => $template->id,
                'template_name' => $template->name,
                'reference_date' => $referenceDate,
                'status' => EmployeeChecklist::STATUS_IN_PROGRESS,
                'resignation_request_id' => $resignationId,
                'created_by' => $actor?->id,
            ]);

            $base = \Carbon\Carbon::parse($referenceDate);
            /** @var ChecklistTemplateItem $item */
            foreach ($template->items as $item) {
                $checklist->items()->create([
                    'title' => $item->title,
                    'description' => $item->description,
                    'responsible' => $item->responsible,
                    'due_date' => $base->copy()->addDays($item->due_offset_days)->toDateString(),
                    'is_required' => $item->is_required,
                    'auto_key' => $item->auto_key,
                    'sort_order' => $item->sort_order,
                ]);
            }

            return $checklist;
        });

        // Báo sau khi transaction ngoài (vd tạo nhân viên) đã commit.
        DB::afterCommit(fn () => $this->notifyAssignees($checklist->fresh(['items', 'employee.manager.user', 'employee.user'])));

        return $checklist;
    }

    private function notifyAssignees(EmployeeChecklist $checklist): void
    {
        $employee = $checklist->employee;
        if (! $employee) {
            return;
        }

        $label = $this->typeLabel($checklist->type);
        $data = ['employee_checklist_id' => $checklist->id, 'checklist_type' => $checklist->type];

        $ownCount = $checklist->items->where('responsible', 'employee')->count();
        if ($ownCount > 0 && $employee->user) {
            $this->notificationService->send(
                $employee->user,
                'checklist.mine',
                $checklist->type === ChecklistTemplate::TYPE_ONBOARDING ? 'Chào mừng bạn gia nhập công ty' : 'Việc cần làm trước khi nghỉ việc',
                "Bạn có {$ownCount} việc cần hoàn thành trong checklist {$label}. Xem ở trang Tổng quan.",
                $data,
            );
        }

        $managerUser = $employee->manager?->user;
        $managerCount = $checklist->items->where('responsible', 'manager')->count();
        if ($managerCount > 0 && $managerUser && $managerUser->id !== $employee->user_id
            && ($managerUser->hasPermission('onboarding.team') || $managerUser->hasPermission('onboarding.manage'))) {
            $this->notificationService->send(
                $managerUser,
                'checklist.team',
                "Checklist {$label}: {$employee->full_name}",
                "{$employee->full_name} bắt đầu {$label} — bạn có {$managerCount} việc cần làm.",
                $data,
            );
        }
    }

    private function isTeamManager(User $user, EmployeeChecklist $checklist): bool
    {
        $managerEmployeeId = $user->employee?->id;

        return $managerEmployeeId !== null
            && $user->hasPermission('onboarding.team')
            && $checklist->employee?->manager_id === $managerEmployeeId;
    }

    private function assertEditable(EmployeeChecklist $checklist): void
    {
        if ($checklist->status === EmployeeChecklist::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['status' => 'Checklist đã hủy, không sửa được.']);
        }
    }

    private function openResignation(Employee $employee): ?ResignationRequest
    {
        return $employee->resignationRequests()
            ->whereIn('status', [ResignationRequest::STATUS_APPROVED, ResignationRequest::STATUS_NOTIFIED])
            ->latest('id')
            ->first();
    }

    private function typeLabel(string $type): string
    {
        return $type === ChecklistTemplate::TYPE_OFFBOARDING ? 'nghỉ việc' : 'nhận việc';
    }
}
