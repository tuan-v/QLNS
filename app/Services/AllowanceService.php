<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAllowance;
use App\Models\EmployeeAllowanceRequest;
use App\Models\EmployeeContract;
use App\Models\SalaryComponent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Phụ cấp & trợ cấp (Kế hoạch Ngày 48). Quyết định của người dùng (2026-10-20):
//  - Mức gợi ý theo loại hợp đồng (chính thức / thử việc / thực tập) + mức riêng cho Trưởng phòng.
//  - Mỗi khoản tự chọn cách tính: cố định / theo ngày đi làm thực tế / theo tỉ lệ ngày hưởng lương.
//  - Thuế & BH theo luật: mỗi khoản khai chịu thuế không, phần miễn thuế/tháng, có tính vào lương đóng BH.
//  - Khác mức gợi ý thì BẮT BUỘC ghi lý do. Mọi thay đổi vào nhật ký.
//  - (2026-10-21) Thay đổi của HR thành đề xuất chờ Admin duyệt — luồng duyệt ở AllowanceChangeService; service
//    này chỉ tính toán + ghi mức đã duyệt (apply()).
// Quy chế công ty (2026-10-21): chỉ còn "Phụ cấp ăn trưa" 30.000đ/ngày làm TẠI CÔNG TY (cách tính
// per_office_day, ngày online không tính, nửa ngày vẫn đủ 1 suất; mọi người tự hưởng — applies_to_all) và
// "Phụ cấp khác" (cố định/tháng + nội dung ghi ở note).
class AllowanceService
{
    public const TYPES = ['allowance' => 'Phụ cấp', 'subsidy' => 'Trợ cấp'];

    public const METHODS = [
        'fixed' => 'Cố định theo tháng',
        'per_workday' => 'Theo ngày đi làm thực tế',
        'prorated' => 'Theo tỉ lệ ngày hưởng lương',
        'per_office_day' => 'Theo ngày làm tại công ty',
    ];

    /** Cách tính nhập ĐƠN GIÁ MỖI NGÀY (các cách còn lại nhập mức mỗi tháng). */
    public const DAILY_METHODS = ['per_office_day'];

    /** Khóa mức gợi ý: 3 loại hợp đồng + Trưởng phòng (null = dùng mức theo hợp đồng). */
    public const SUGGESTION_KEYS = [
        'chinh_thuc' => 'Chính thức',
        'thu_viec' => 'Thử việc',
        'thuc_tap' => 'Thực tập',
        'head' => 'Trưởng phòng',
    ];

    private const STATUS_TO_CONTRACT = ['active' => 'chinh_thuc', 'probation' => 'thu_viec', 'intern' => 'thuc_tap'];

    // ------------------------------------------------------------------ danh mục

    public function catalog(): Collection
    {
        $today = now()->toDateString();

        return SalaryComponent::query()
            ->whereIn('component_type', array_keys(self::TYPES))
            ->withCount([
                'employeeAllowances as active_count' => fn ($q) => $this->activeOn($q, $today)->where('amount', '>', 0),
                'employeeAllowances as off_count' => fn ($q) => $this->activeOn($q, $today)->where('amount', 0),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function createComponent(array $data): SalaryComponent
    {
        return DB::transaction(function () use ($data) {
            $component = SalaryComponent::create($this->componentAttributes($data) + [
                'code' => 'allowance_tmp_'.uniqid(),
                'is_active' => true,
                'sort_order' => (int) SalaryComponent::whereIn('component_type', array_keys(self::TYPES))->max('sort_order') + 1,
            ]);
            $component->update(['code' => 'allowance_'.$component->id]);

            return $component;
        });
    }

    public function updateComponent(SalaryComponent $component, array $data): SalaryComponent
    {
        $this->ensureAllowanceComponent($component);
        $component->update($this->componentAttributes($data) + (isset($data['is_active']) ? ['is_active' => (bool) $data['is_active']] : []));

        return $component->refresh();
    }

    public function deleteComponent(SalaryComponent $component): void
    {
        $this->ensureAllowanceComponent($component);
        if ($component->employeeAllowances()->exists() || $component->payrollDetailComponents()->exists()) {
            throw ValidationException::withMessages([
                'component' => 'Khoản này đã có nhân viên hưởng hoặc đã vào bảng lương nên không xóa được. Hãy chuyển sang "Ngừng sử dụng".',
            ]);
        }
        $component->delete();
    }

    private function componentAttributes(array $data): array
    {
        $suggested = [];
        foreach (array_keys(self::SUGGESTION_KEYS) as $key) {
            $value = $data['suggested_amounts'][$key] ?? null;
            $suggested[$key] = $value === null || $value === '' ? ($key === 'head' ? null : 0) : (float) $value;
        }
        $taxable = (bool) $data['is_taxable'];

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'component_type' => $data['component_type'],
            'calculation_method' => $data['calculation_method'],
            'is_taxable' => $taxable,
            'tax_free_amount' => $taxable && ! empty($data['tax_free_amount']) ? (float) $data['tax_free_amount'] : null,
            'in_insurance_salary' => (bool) ($data['in_insurance_salary'] ?? false),
            'applies_to_all' => (bool) ($data['applies_to_all'] ?? false),
            'suggested_amounts' => $suggested,
        ];
    }

    private function ensureAllowanceComponent(SalaryComponent $component): void
    {
        abort_unless(array_key_exists($component->component_type, self::TYPES), 404);
    }

    // ------------------------------------------------------------------ mức gợi ý

    /**
     * @param  array<int, int>|null  $headIds  id nhân viên đang là Trưởng phòng (truyền sẵn khi xét cả danh sách).
     * @return array{key: string, contract_type: ?string, is_head: bool, label: string}
     */
    public function suggestionProfile(Employee $employee, ?array $headIds = null): array
    {
        $contractType = $employee->activeContract?->contract_type
            ?? EmployeeContract::where('employee_id', $employee->id)->latest('start_date')->value('contract_type')
            ?? (self::STATUS_TO_CONTRACT[$employee->employment_status] ?? 'chinh_thuc');
        $isHead = $headIds !== null
            ? in_array($employee->id, $headIds, true)
            : Department::where('manager_id', $employee->id)->exists();

        $label = 'Hợp đồng '.mb_strtolower(self::SUGGESTION_KEYS[$contractType] ?? $contractType);

        return [
            'key' => $contractType,
            'contract_type' => $contractType,
            'is_head' => $isHead,
            'label' => $isHead ? $label.' · Trưởng phòng' : $label,
        ];
    }

    public function suggestedAmount(SalaryComponent $component, array $profile): float
    {
        $amounts = $component->suggested_amounts ?? [];
        if ($profile['is_head'] && ($amounts['head'] ?? null) !== null) {
            return (float) $amounts['head'];
        }

        return (float) ($amounts[$profile['contract_type']] ?? 0);
    }

    // ------------------------------------------------------------------ phụ cấp của 1 nhân viên

    /** Mọi khoản đang dùng + mức gợi ý + mức đang áp dụng + lịch sử của 1 nhân viên. */
    public function forEmployee(Employee $employee): array
    {
        $profile = $this->suggestionProfile($employee);
        $today = now()->toDateString();
        $rows = EmployeeAllowance::with(['creator:id,user_name', 'approver:id,user_name'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->groupBy('salary_component_id');
        $pending = $this->pendingRequests([$employee->id])->get($employee->id, collect());

        $items = $this->catalog()
            ->filter(fn (SalaryComponent $c) => $c->is_active || $rows->has($c->id))
            ->map(function (SalaryComponent $component) use ($profile, $rows, $today, $pending) {
                $history = $rows->get($component->id, collect());
                $current = $history->first(fn ($r) => $r->effective_from->toDateString() <= $today
                    && ($r->effective_to === null || $r->effective_to->toDateString() >= $today));
                $upcoming = $history->first(fn ($r) => $r->effective_from->toDateString() > $today);
                $suggested = $this->suggestedAmount($component, $profile);
                // Khoản "mọi người tự hưởng" mà chưa đặt riêng = đang hưởng mức gợi ý.
                $isDefault = ! $current && $component->applies_to_all;

                return [
                    'component' => $this->componentSummary($component),
                    'suggested_amount' => $suggested,
                    'current' => $current ? $this->assignmentSummary($current) : null,
                    'is_default' => $isDefault,
                    'effective_amount' => $isDefault ? $suggested : ($current?->amount ?? 0),
                    'upcoming' => $upcoming ? $this->assignmentSummary($upcoming) : null,
                    'pending' => ($request = $pending->get($component->id)) ? $this->pendingSummary($request) : null,
                    'differs' => ! $isDefault && round($current?->amount ?? 0) !== round($suggested),
                    'history' => $history->map(fn ($r) => $this->assignmentSummary($r))->values(),
                ];
            })
            ->values();

        return [
            'profile' => $profile,
            'items' => $items,
            // Tổng chỉ cộng khoản tính theo THÁNG; khoản theo ngày (ăn trưa) phụ thuộc số ngày làm nên hiện riêng.
            'total_current' => $items->where('component.unit', 'month')->sum('effective_amount'),
            'total_suggested' => $items->where('component.is_active', true)->where('component.unit', 'month')->sum('suggested_amount'),
        ];
    }

    /** Mức phụ cấp đang áp dụng hôm nay của chính nhân viên (trang Hồ sơ của tôi). */
    public function currentForEmployee(Employee $employee): array
    {
        $items = collect($this->forEmployee($employee)['items'])
            ->filter(fn ($item) => $item['effective_amount'] > 0)
            ->map(fn ($item) => [
                'name' => $item['component']['name'],
                'type_label' => $item['component']['type_label'],
                'method_label' => $item['component']['method_label'],
                'unit' => $item['component']['unit'],
                'amount' => $item['effective_amount'],
                'note' => $item['current']['note'] ?? null,
                'effective_from' => $item['current']['effective_from'] ?? null,
            ])
            ->values();

        return ['items' => $items, 'total' => $items->where('unit', 'month')->sum('amount')];
    }

    /**
     * Kiểm tra 1 thay đổi trước khi đề xuất / áp dụng: khoản còn dùng; khác mức gợi ý thì bắt buộc lý do.
     * Trả lại lý do đã cắt khoảng trắng (null nếu trống).
     */
    public function validateChange(Employee $employee, SalaryComponent $component, float $amount, ?string $note, ?array $profile = null): ?string
    {
        $this->ensureAllowanceComponent($component);
        if (! $component->is_active) {
            throw ValidationException::withMessages(['salary_component_id' => 'Khoản này đã ngừng sử dụng.']);
        }

        $suggested = $this->suggestedAmount($component, $profile ?? $this->suggestionProfile($employee));
        $note = trim((string) $note) ?: null;
        if (round($amount) !== round($suggested) && $note === null) {
            throw ValidationException::withMessages([
                'note' => 'Mức khác mức gợi ý ('.number_format($suggested, 0, ',', '.').'đ) — vui lòng ghi lý do.',
            ]);
        }

        return $note;
    }

    /** Mức đang áp dụng hôm nay của 1 khoản: state default | on | off | unset (giống ô trong bảng phụ cấp). */
    public function currentState(Employee $employee, SalaryComponent $component, ?array $profile = null): array
    {
        $row = $this->activeOn(
            EmployeeAllowance::where('employee_id', $employee->id)->where('salary_component_id', $component->id),
            now()->toDateString()
        )->first();

        if ($row === null && $component->applies_to_all) {
            return ['state' => 'default', 'amount' => $this->suggestedAmount($component, $profile ?? $this->suggestionProfile($employee))];
        }

        return ['state' => $row === null ? 'unset' : ($row->amount > 0 ? 'on' : 'off'), 'amount' => $row?->amount];
    }

    /** id nhân viên đang có dòng riêng (kể cả 0 = không hưởng) cho khoản này vào ngày $date. */
    public function employeeIdsWithComponent(SalaryComponent $component, string $date): Collection
    {
        return $this->activeOn(EmployeeAllowance::where('salary_component_id', $component->id), $date)->pluck('employee_id');
    }

    /**
     * GHI mức đã duyệt cho 1 khoản từ ngày $effectiveFrom: đóng mức cũ vào ngày hôm trước, thay các mức đã hẹn
     * từ ngày đó trở đi. Mức 0 = ngừng hưởng. Chỉ AllowanceChangeService gọi (Admin tự đặt / duyệt đề xuất của
     * HR) — kiểm tra hợp lệ bằng validateChange() trước đó.
     */
    public function apply(Employee $employee, SalaryComponent $component, float $amount, string $effectiveFrom, ?string $note, ?User $creator, User $approver): EmployeeAllowance
    {
        $from = Carbon::parse($effectiveFrom)->startOfDay();

        return DB::transaction(function () use ($employee, $component, $amount, $from, $note, $creator, $approver) {
            $rows = EmployeeAllowance::where('employee_id', $employee->id)
                ->where('salary_component_id', $component->id)
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                if ($row->effective_from->gte($from)) {
                    $row->delete(); // mức đã hẹn cho cùng ngày / sau ngày này bị thay thế
                } elseif ($row->effective_to === null || $row->effective_to->gte($from)) {
                    $row->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
                }
            }

            return EmployeeAllowance::create([
                'employee_id' => $employee->id,
                'salary_component_id' => $component->id,
                'amount' => round($amount, 2),
                'effective_from' => $from->toDateString(),
                'note' => $note,
                'created_by' => $creator?->id ?? $approver->id,
                'approved_by' => $approver->id,
            ]);
        });
    }

    /**
     * Trang "Phụ cấp & trợ cấp" → tab Theo nhân viên: mỗi nhân viên đang làm việc 1 dòng, mỗi khoản đang dùng
     * 1 ô (đang hưởng bao nhiêu / không hưởng / chưa đặt + mức gợi ý). Lọc + phân trang trong PHP vì bộ lọc
     * "lệch gợi ý" cần tính xong mới biết.
     */
    public function employeeMatrix(array $filters): array
    {
        $today = now()->toDateString();
        $components = SalaryComponent::query()
            ->whereIn('component_type', array_keys(self::TYPES))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $headIds = Department::whereNotNull('manager_id')->pluck('manager_id')->map(fn ($id) => (int) $id)->all();

        $employees = Employee::query()
            ->whereIn('employment_status', Employee::WORKING_STATUSES)
            ->with(['activeContract', 'department:id,name', 'position:id,name'])
            ->when(! empty($filters['department_id']), fn ($q) => $q->whereIn(
                'department_id',
                Department::withDescendantIds([(int) $filters['department_id']])
            ))
            ->when(! empty($filters['search']), fn ($q) => $q->where(fn ($q) => $q
                ->where('full_name', 'like', '%'.$filters['search'].'%')
                ->orWhere('code', 'like', '%'.$filters['search'].'%')))
            ->orderBy('full_name')
            ->get();

        $current = $this->activeOn(EmployeeAllowance::whereIn('employee_id', $employees->pluck('id')), $today)
            ->get()
            ->groupBy('employee_id');
        $officeDays = $this->officeDayCounts($employees->pluck('id')->all(), now()->startOfMonth(), now());
        $pending = $this->pendingRequests($employees->pluck('id')->all());

        $rows = $employees->map(function (Employee $employee) use ($components, $headIds, $current, $officeDays, $pending) {
            $profile = $this->suggestionProfile($employee, $headIds);
            $mine = $current->get($employee->id, collect())->keyBy('salary_component_id');
            $myPending = $pending->get($employee->id, collect());
            $cells = [];
            foreach ($components as $component) {
                $row = $mine->get($component->id);
                $suggested = $this->suggestedAmount($component, $profile);
                $isDefault = $row === null && $component->applies_to_all;
                $cells[$component->id] = [
                    'state' => $isDefault ? 'default' : ($row === null ? 'unset' : ($row->amount > 0 ? 'on' : 'off')),
                    'amount' => $isDefault ? $suggested : $row?->amount,
                    'suggested' => $suggested,
                    'unit' => in_array($component->calculation_method, self::DAILY_METHODS, true) ? 'day' : 'month',
                    'differs' => ! $isDefault && round($row?->amount ?? 0) !== round($suggested),
                    'note' => $row?->note,
                    'effective_from' => $row?->effective_from?->toDateString(),
                    // Đề xuất của HR đang chờ Admin duyệt (chưa có hiệu lực).
                    'pending' => ($request = $myPending->get($component->id)) ? $this->pendingSummary($request) : null,
                ];
            }

            return [
                'employee' => [
                    'id' => $employee->id,
                    'code' => $employee->code,
                    'full_name' => $employee->full_name,
                    'avatar_url' => $employee->avatar ? Storage::disk('public')->url($employee->avatar) : null,
                    'department' => $employee->department?->name,
                    'position' => $employee->position?->name,
                ],
                'profile' => $profile,
                'cells' => $cells,
                'estimate' => $this->monthEstimate($cells, $officeDays[$employee->id] ?? ['approved' => 0, 'pending' => 0]),
                'differs_count' => collect($cells)->filter(fn ($c) => $c['differs'])->count(),
            ];
        })
            ->when(! empty($filters['contract_type']), fn ($rows) => $rows->where('profile.contract_type', $filters['contract_type']))
            ->when(! empty($filters['only_differs']), fn ($rows) => $rows->where('differs_count', '>', 0))
            ->values();

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return [
            'components' => $components->map(fn ($c) => $this->componentSummary($c))->values(),
            'data' => $rows->forPage($page, $perPage)->values(),
            'meta' => [
                'total' => $rows->count(),
                'per_page' => $perPage,
                'current_page' => $page,
                'differs_employees' => $rows->where('differs_count', '>', 0)->count(),
                'pending_requests' => EmployeeAllowanceRequest::where('status', EmployeeAllowanceRequest::STATUS_PENDING)->count(),
            ],
        ];
    }

    /**
     * Phụ cấp ước tính từ đầu tháng tới hôm nay: khoản theo ngày × số ngày làm tại công ty đã duyệt + khoản theo
     * tháng (cả tháng). pending_amount = phần thêm nếu HR duyệt các ngày còn chờ. Chỉ để xem nhanh — số thật vẫn do
     * forPayroll() tính lúc lập bảng lương.
     */
    private function monthEstimate(array $cells, array $days): array
    {
        $amount = $pendingAmount = 0.0;
        foreach ($cells as $cell) {
            if (! in_array($cell['state'], ['on', 'default'], true)) {
                continue;
            }
            if ($cell['unit'] === 'day') {
                $amount += $cell['amount'] * $days['approved'];
                $pendingAmount += $cell['amount'] * $days['pending'];
            } else {
                $amount += $cell['amount'];
            }
        }

        return [
            'amount' => round($amount),
            'office_days' => $days['approved'],
            'pending_days' => $days['pending'],
            'pending_amount' => round($pendingAmount),
        ];
    }

    // ------------------------------------------------------------------ bảng lương

    /**
     * Phụ cấp của 1 nhân viên trong kỳ lương. Lấy mức đang hiệu lực vào ngày cuối kỳ (hoặc ngày nghỉ việc nếu
     * sớm hơn); khoản "mọi người tự hưởng" mà người này chưa đặt riêng thì dùng mức gợi ý. Cách tính:
     *  - fixed: đủ mức tháng
     *  - per_workday: mức tháng × ngày đi làm thực tế / công chuẩn
     *  - prorated: mức tháng × ngày hưởng lương (công + phép có lương) / công chuẩn
     *  - per_office_day: đơn giá × số ngày làm TẠI CÔNG TY (officeDays())
     * Khoản theo ngày vẫn có dòng khi 0 ngày để phiếu lương luôn ghi "Phụ cấp ăn trưa".
     *
     * @return array{items: array<int, array<string, mixed>>, total: float, non_taxable: float, insurance_base: float}
     */
    public function forPayroll(Employee $employee, Carbon $start, Carbon $end, int $standardWorkDays, float $actualWorkDays, float $compensatedDays): array
    {
        $reference = $employee->termination_date && Carbon::parse($employee->termination_date)->lt($end)
            ? Carbon::parse($employee->termination_date)
            : $end;

        $rows = $this->activeOn(EmployeeAllowance::where('employee_id', $employee->id), $reference->toDateString())
            ->get()
            ->keyBy('salary_component_id');
        $components = SalaryComponent::query()
            ->whereIn('component_type', array_keys(self::TYPES))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $rows->keys()))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $profile = null;
        $officeDays = null;
        $items = [];
        $total = $nonTaxable = $insuranceBase = 0.0;
        foreach ($components as $component) {
            $row = $rows->get($component->id);
            if ($row) {
                $rate = (float) $row->amount;
            } elseif ($component->applies_to_all && $component->is_active) {
                $profile ??= $this->suggestionProfile($employee);
                $rate = $this->suggestedAmount($component, $profile);
            } else {
                continue;
            }
            if ($rate <= 0) {
                continue; // không hưởng
            }

            $days = match ($component->calculation_method) {
                'per_office_day' => $officeDays ??= $this->officeDays($employee, $start, $reference),
                'per_workday' => $actualWorkDays,
                'prorated' => $compensatedDays,
                default => null,
            };
            $amount = match (true) {
                $component->calculation_method === 'per_office_day' => round($rate * $days),
                $days === null || $standardWorkDays <= 0 => $rate,
                default => round($rate * min($days, $standardWorkDays) / $standardWorkDays),
            };
            $exempt = ! $component->is_taxable ? $amount : min($amount, (float) ($component->tax_free_amount ?? 0));
            $daily = in_array($component->calculation_method, self::DAILY_METHODS, true);

            $items[] = [
                'component' => $component,
                'amount' => $amount,
                'quantity' => $days,
                'snapshot' => [
                    'allowance' => [
                        'type' => $component->component_type,
                        'method' => $component->calculation_method,
                        'unit' => $daily ? 'day' : 'month',
                        'monthly_amount' => $rate, // đơn giá: theo ngày nếu unit = day
                        'days' => $days,
                        'standard_days' => $standardWorkDays,
                        'note' => $row?->note,
                        'tax_exempt' => $exempt,
                        'in_insurance_salary' => $component->in_insurance_salary,
                    ],
                ],
            ];
            $total += $amount;
            $nonTaxable += $exempt;
            if ($component->in_insurance_salary) {
                $insuranceBase += $daily ? $amount : $rate; // lương đóng BH gồm phụ cấp ghi trong HĐ
            }
        }

        return ['items' => $items, 'total' => round($total, 2), 'non_taxable' => round($nonTaxable, 2), 'insurance_base' => $insuranceBase];
    }

    /**
     * Số ngày làm TẠI CÔNG TY trong kỳ: ngày có lượt chấm công đã duyệt, chọn "Tại công ty", có giờ làm, không
     * phải ca OT ngày khác. Nhiều ca cùng ngày hoặc chỉ làm nửa ngày vẫn tính 1 ngày (1 suất ăn trưa).
     */
    public function officeDays(Employee $employee, Carbon $start, Carbon $end): int
    {
        return $this->officeAttendances([$employee->id], $start, $end)
            ->where('approval_status', Attendance::APPROVAL_APPROVED)
            ->where('actual_work_minutes', '>', 0)
            ->distinct()
            ->count('attendance_date');
    }

    /**
     * Đếm ngày làm tại công ty cho nhiều nhân viên 1 lần (cột "Ước tính tháng này" của bảng phụ cấp):
     * approved = đúng luật officeDays() (bảng lương sẽ trả); pending = ngày chưa đủ điều kiện đó nhưng còn lượt
     * chờ duyệt (kể cả đang làm, chưa chấm ra) — có thể được trả nếu HR duyệt.
     *
     * @return array<int, array{approved: int, pending: int}> theo employee_id
     */
    public function officeDayCounts(array $employeeIds, Carbon $start, Carbon $end): array
    {
        return $this->officeAttendances($employeeIds, $start, $end)
            ->whereIn('approval_status', [Attendance::APPROVAL_APPROVED, Attendance::APPROVAL_PENDING])
            ->get(['employee_id', 'attendance_date', 'approval_status', 'actual_work_minutes'])
            ->groupBy('employee_id')
            ->map(function ($attendances) {
                $days = $attendances->groupBy(fn (Attendance $a) => $a->attendance_date->toDateString());
                $approved = $days->filter(fn ($day) => $day->contains(
                    fn (Attendance $a) => $a->approval_status === Attendance::APPROVAL_APPROVED && $a->actual_work_minutes > 0
                ));

                return [
                    'approved' => $approved->count(),
                    'pending' => $days->diffKeys($approved)
                        ->filter(fn ($day) => $day->contains('approval_status', Attendance::APPROVAL_PENDING))
                        ->count(),
                ];
            })
            ->all();
    }

    // Lượt chấm công "tại công ty" trong kỳ, không tính ca OT ngày khác.
    private function officeAttendances(array $employeeIds, Carbon $start, Carbon $end)
    {
        return Attendance::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->where('work_mode', Attendance::WORK_MODE_OFFICE)
            ->whereHas('workShift', fn ($q) => $q->where('is_overtime', false));
    }

    // ------------------------------------------------------------------ tiện ích

    private function activeOn($query, string $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }

    public function componentSummary(SalaryComponent $component): array
    {
        return [
            'id' => $component->id,
            'code' => $component->code,
            'name' => $component->name,
            'description' => $component->description,
            'component_type' => $component->component_type,
            'type_label' => self::TYPES[$component->component_type] ?? '',
            'calculation_method' => $component->calculation_method,
            'method_label' => self::METHODS[$component->calculation_method] ?? '',
            'is_taxable' => $component->is_taxable,
            'tax_free_amount' => $component->tax_free_amount,
            'in_insurance_salary' => $component->in_insurance_salary,
            'applies_to_all' => $component->applies_to_all,
            // Đơn vị của số tiền nhập: theo ngày (ăn trưa) hay theo tháng.
            'unit' => in_array($component->calculation_method, self::DAILY_METHODS, true) ? 'day' : 'month',
            'suggested_amounts' => $component->suggested_amounts,
            'is_active' => $component->is_active,
            'active_count' => $component->active_count ?? null,
            'off_count' => $component->off_count ?? null,
        ];
    }

    private function assignmentSummary(EmployeeAllowance $row): array
    {
        return [
            'id' => $row->id,
            'amount' => $row->amount,
            'effective_from' => $row->effective_from->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
            'note' => $row->note,
            'created_by' => $row->creator?->user_name,
            // Người duyệt (null = dòng cũ trước khi có luồng duyệt); trùng người đặt = Admin tự đặt.
            'approved_by' => $row->approver?->user_name,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * Đề xuất đang chờ duyệt, nhóm theo nhân viên rồi theo khoản (mỗi cặp chỉ có 1 — đề xuất mới thay đề xuất cũ).
     *
     * @return Collection<int, Collection<int, EmployeeAllowanceRequest>>
     */
    private function pendingRequests(array $employeeIds): Collection
    {
        return EmployeeAllowanceRequest::with('requester:id,user_name')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', EmployeeAllowanceRequest::STATUS_PENDING)
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($requests) => $requests->keyBy('salary_component_id'));
    }

    private function pendingSummary(EmployeeAllowanceRequest $request): array
    {
        return [
            'id' => $request->id,
            'amount' => $request->amount,
            'effective_from' => $request->effective_from->toDateString(),
            'note' => $request->note,
            'requested_by' => $request->requester?->user_name,
            'requested_at' => $request->created_at?->toIso8601String(),
        ];
    }
}
